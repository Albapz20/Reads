<?php
ini_set('display_errors', '0');  
@set_time_limit(45);

require_once "../src/Auth.php";
require_once "../src/Database.php";

header('Content-Type: application/json; charset=utf-8');

$usuario = Auth::usuario();
if (!$usuario) {
    http_response_code(401);
    echo json_encode(['basadoEn' => [], 'libros' => []]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$db   = new Database();
$year = date("Y");

const NUM_BASE       = 4;   // libros leídos usados como base
const MAX_POR_AUTOR  = 3;   // máx. del mismo autor leído por libro base
const CUPO_POR_LIBRO = 4;   // objetivo de recomendaciones por libro base
const TOTAL_MIN      = 8;
const TOTAL_MAX      = 12;

// Helpers
function limpiarTexto(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $s)));
}

function sinParentesis(string $t): string {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $t)));
}

function tituloBase(string $t): string {
    $t = sinParentesis($t);
    $t = preg_split('/\s*[:\x{2013}\x{2014}]\s*|\s+-\s+/u', $t)[0] ?? $t;
    return limpiarTexto($t);
}

function autorPrincipalCoincide(array $autoresDoc, string $autorBuscado): bool {
    $buscado = limpiarTexto($autorBuscado);
    if ($buscado === '') return true;
    if (empty($autoresDoc)) return false;
    return limpiarTexto($autoresDoc[0]) === $buscado;
}

function subjectUtil(string $s): bool {
    $l = mb_strtolower(trim($s), 'UTF-8');
    if ($l === '' || mb_strlen($l) > 40) return false;
    $prohibidas = ['accessible book', 'protected daisy', 'in library', 'large type',
                   'lending library', 'internet archive', 'overdrive', 'open library',
                   'nyt:', 'reading level', 'new york times', 'bestseller', 'best seller',
                   'juvenile literature', 'english language', 'spanish language',
                   'history and criticism', 'translations', 'textbooks'];
    foreach ($prohibidas as $p) {
        if (str_contains($l, $p)) return false;
    }
    return !preg_match('/\d{4}/', $l);
}

// Últimos libros leídos por el usuario, para basar las recomendaciones
$stmt = $db->pdo->prepare(
    "SELECT libro_id, titulo, autores
     FROM listas_lectura
     WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
     ORDER BY fecha_fin DESC, id DESC
     LIMIT " . NUM_BASE
);
$stmt->execute([$usuario["id"], $year]);
$ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ultimos)) {
    $stmt = $db->pdo->prepare(
        "SELECT libro_id, titulo, autores
         FROM listas_lectura
         WHERE usuario_id = ? AND estado = 'leido'
         ORDER BY fecha_fin DESC, id DESC
         LIMIT " . NUM_BASE
    );
    $stmt->execute([$usuario["id"]]);
    $ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($ultimos)) {
    echo json_encode(['basadoEn' => [], 'libros' => []]);
    exit;
}

// Idioma preferido del usuario, para filtrar resultados de Open Library
$idiomaPref = 'spa';
try {
    $stmt = $db->pdo->prepare("SELECT idioma_lectura FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$usuario["id"]]);
    $v = $stmt->fetchColumn();
    if ($v !== false && $v !== null) $idiomaPref = (string)$v;
} catch (Exception $e) {
    // Si la columna aún no existe, usar español por defecto
}
define('IDIOMA', $idiomaPref);

// Cache de resultados para no saturar la API externa y mejorar tiempos de respuesta
$cacheKey = 'rec8_' . IDIOMA . '_' . md5(json_encode(array_column($ultimos, 'libro_id')));
if (isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]['t']) < 6 * 3600) {
    echo json_encode($_SESSION[$cacheKey]['data'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lo que el usuario ya tiene
$stmt = $db->pdo->prepare("SELECT libro_id, titulo FROM listas_lectura WHERE usuario_id = ?");
$stmt->execute([$usuario["id"]]);
$yaTiene = ['ids' => [], 'bases' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $yaTiene['ids'][] = (string)$r['libro_id'];
    $base = tituloBase((string)$r['titulo']);
    if ($base !== '') $yaTiene['bases'][$base] = true;
}

// Liberar el bloqueo de sesión mientras se consulta la API externa
session_write_close();

// Helpers para Open Library
function buscarVarios(array $peticiones): array {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($peticiones as $k => $params) {
        $params['fields'] = 'key,title,author_name,cover_i,subject,language';

        // Filtrar por idioma (salvo en búsquedas por título o si se indica 'language' => '')
        if (!isset($params['language']) && !isset($params['title']) && IDIOMA !== '') {
            $params['language'] = IDIOMA;
        }
        if (($params['language'] ?? null) === '') unset($params['language']);

        $ch = curl_init('https://openlibrary.org/search.json?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (contacto@tudominio.com)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    $salida = [];
    foreach ($handles as $k => $ch) {
        $resp = curl_multi_getcontent($ch);
        $json = $resp ? json_decode($resp, true) : null;
        $salida[$k] = $json['docs'] ?? [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $salida;
}

function normalizar(array $doc): array {
    $id = str_replace('/works/', '', $doc['key'] ?? '');
    $portada = !empty($doc['cover_i'])
        ? "https://covers.openlibrary.org/b/id/{$doc['cover_i']}-M.jpg?default=false"
        : null;

    return [
        'id'      => $id,
        'titulo'  => $doc['title'] ?? 'Sin título',
        'autor'   => $doc['author_name'][0] ?? 'Autor desconocido',
        'portada' => $portada,
        'autores' => $doc['author_name'] ?? [],
    ];
}

function tematicasDeDocs(array $docs, int $max = 3): array {
    $conteo = [];
    foreach ($docs as $doc) {
        foreach ($doc['subject'] ?? [] as $s) {
            if (!subjectUtil($s)) continue;
            $k = mb_strtolower(trim($s), 'UTF-8');
            $conteo[$k] = ($conteo[$k] ?? 0) + 1;
        }
    }
    arsort($conteo);
    return array_slice(array_keys($conteo), 0, $max);
}

// Autor + libros leídos por el usuario, para limitar recomendaciones de autores ya leídos
$vistos        = [];
$autoresGlobal = [];

$aceptar = function (array &$destino, array $doc, ?string $exigirAutor = null) use (&$vistos, &$autoresGlobal, $yaTiene) {
    $l = normalizar($doc);
    if (empty($l['portada']) || $l['id'] === '') return false;
    if ($exigirAutor !== null && !autorPrincipalCoincide($l['autores'], $exigirAutor)) return false;

    $base = tituloBase($l['titulo']);
    if ($base === '' || isset($vistos[$base]) || isset($yaTiene['bases'][$base])) return false;
    if (in_array($l['id'], $yaTiene['ids'], true)) return false;

    // Variedad: máx. 2 libros por autor ajeno en el total
    $claveAutor = limpiarTexto($l['autor']);
    if ($exigirAutor === null && ($autoresGlobal[$claveAutor] ?? 0) >= 2) return false;

    $autoresGlobal[$claveAutor] = ($autoresGlobal[$claveAutor] ?? 0) + 1;
    $vistos[$base] = true;
    unset($l['autores']);
    $destino[] = $l;
    return true;
};

$autores = [];
$ronda1  = [];
foreach ($ultimos as $i => $libro) {
    $autores[$i] = trim(explode(',', $libro['autores'] ?? '')[0]);

    if ($autores[$i] !== '') {
        $ronda1["a$i"] = ['author' => $autores[$i], 'sort' => 'editions', 'limit' => 40];
    }
    $p = ['title' => sinParentesis($libro['titulo']), 'limit' => 3];
    if ($autores[$i] !== '') $p['author'] = $autores[$i];
    $ronda1["t$i"] = $p;
}
$res1 = buscarVarios($ronda1);

$porLibro          = [];
$tematicasPorLibro = [];
foreach ($ultimos as $i => $libro) {
    $lista    = [];
    $delAutor = 0;

    foreach ($res1["a$i"] ?? [] as $doc) {
        if ($aceptar($lista, $doc, $autores[$i])) $delAutor++;
        if ($delAutor >= MAX_POR_AUTOR) break;
    }

    $porLibro[$i]          = $lista;
    $tematicasPorLibro[$i] = tematicasDeDocs($res1["t$i"] ?? [], 3);
}

// Temáticas más frecuentes entre los libros base, para buscar más libros de esas temáticas
$conteoTematicas = [];
foreach ($tematicasPorLibro as $ts) {
    foreach ($ts as $t) $conteoTematicas[$t] = ($conteoTematicas[$t] ?? 0) + 1;
}
arsort($conteoTematicas);
$subjectsUnicos = array_slice(array_keys($conteoTematicas), 0, 8);

$ronda2 = [];
foreach ($subjectsUnicos as $s) {
    $ronda2["s:$s"] = ['subject' => $s, 'sort' => 'editions', 'limit' => 30];
}
$ronda2['fiction'] = ['subject' => 'fiction', 'sort' => 'rating', 'limit' => 40];
$res2 = buscarVarios($ronda2);

// Completar cada libro base con SUS temáticas
foreach ($ultimos as $i => $libro) {
    foreach ($tematicasPorLibro[$i] as $s) {
        if (count($porLibro[$i]) >= CUPO_POR_LIBRO) break;
        foreach ($res2["s:$s"] ?? [] as $doc) {
            $aceptar($porLibro[$i], $doc);
            if (count($porLibro[$i]) >= CUPO_POR_LIBRO) break;
        }
    }
}

// Mezclar todas las recomendaciones en un solo array, respetando el orden de los libros base y sus temáticas
$resultado = [];
$max = empty($porLibro) ? 0 : max(array_map('count', $porLibro));
for ($k = 0; $k < $max; $k++) {
    foreach ($porLibro as $lista) {
        if (isset($lista[$k])) $resultado[] = $lista[$k];
    }
}

// Si no hay suficientes resultados, completar con temáticas de los libros base
if (count($resultado) < TOTAL_MIN) {
    foreach ($subjectsUnicos as $s) {
        foreach ($res2["s:$s"] ?? [] as $doc) {
            $aceptar($resultado, $doc);
            if (count($resultado) >= TOTAL_MAX) break 2;
        }
    }
}
if (count($resultado) < TOTAL_MIN) {
    foreach ($res2['fiction'] ?? [] as $doc) {
        $aceptar($resultado, $doc);
        if (count($resultado) >= TOTAL_MAX) break;
    }
}

// Red de seguridad: si en mi idioma casi no hay resultados, ficción popular de cualquier idioma
if (count($resultado) < 4) {
    $extra = buscarVarios(['x' => ['subject' => 'fiction', 'sort' => 'rating', 'limit' => 40, 'language' => '']]);
    foreach ($extra['x'] ?? [] as $doc) {
        $aceptar($resultado, $doc);
        if (count($resultado) >= TOTAL_MIN) break;
    }
}

$respuesta = [
    'basadoEn' => array_map(fn($u) => sinParentesis($u['titulo']), $ultimos),
    'libros'   => array_slice($resultado, 0, TOTAL_MAX),
];

if (!empty($respuesta['libros'])) {
    @session_start();
    $_SESSION[$cacheKey] = ['t' => time(), 'data' => $respuesta];
    session_write_close();
}

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
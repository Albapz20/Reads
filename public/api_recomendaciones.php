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

/* Helpers de texto*/
function limpiarTexto(string $s): string {
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                                           'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $s)));
}
function sinParentesis(string $t): string {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $t)));
}
function tituloBase(string $t): string {
    $t = sinParentesis($t);
    return limpiarTexto(preg_split('/\s*[:\x{2013}\x{2014}]\s*|\s+-\s+/u', $t)[0] ?? $t);
}

function claveAutor(string $n): string {
    $t = array_values(array_filter(explode(' ', limpiarTexto($n)), fn($w) => strlen($w) > 1));
    return count($t) >= 2 ? $t[0] . ' ' . end($t) : implode(' ', $t);
}

/* Últimos libros leídos  */
$cols = "libro_id, titulo, autores, categorias";
$stmt = $db->pdo->prepare(
    "SELECT $cols FROM listas_lectura
     WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
     ORDER BY fecha_fin DESC, id DESC LIMIT " . NUM_BASE
);
$stmt->execute([$usuario["id"], $year]);
$ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ultimos)) {
    $stmt = $db->pdo->prepare(
        "SELECT $cols FROM listas_lectura
         WHERE usuario_id = ? AND estado = 'leido'
         ORDER BY fecha_fin DESC, id DESC LIMIT " . NUM_BASE
    );
    $stmt->execute([$usuario["id"]]);
    $ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (empty($ultimos)) {
    echo json_encode(['basadoEn' => [], 'libros' => []]);
    exit;
}

/* Idioma preferido  */
$idiomaPref = 'spa';
try {
    $stmt = $db->pdo->prepare("SELECT idioma_lectura FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$usuario["id"]]);
    $v = $stmt->fetchColumn();
    if ($v !== false && $v !== null) $idiomaPref = (string)$v;
} catch (Exception $e) {}

// Ajustes guarda códigos de 3 letras
$mapaIdioma = ['spa' => 'es', 'eng' => 'en', 'cat' => 'ca', 'fra' => 'fr', 'ita' => 'it', 'por' => 'pt', 'deu' => 'de'];
define('IDIOMA', $idiomaPref);
define('LANG_GB', $mapaIdioma[$idiomaPref] ?? '');   // '' = cualquier idioma

/* Caché en sesión */
$cacheKey = 'rec9_' . IDIOMA . '_' . md5(json_encode(array_column($ultimos, 'libro_id')));
if (!isset($_GET['debug']) && isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]['t']) < 6 * 3600) {
    echo json_encode($_SESSION[$cacheKey]['data'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Lo que el usuario ya tiene  */
$stmt = $db->pdo->prepare("SELECT libro_id, titulo FROM listas_lectura WHERE usuario_id = ?");
$stmt->execute([$usuario["id"]]);
$yaTiene = ['ids' => [], 'bases' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $yaTiene['ids'][] = (string)$r['libro_id'];
    $b = tituloBase((string)$r['titulo']);
    if ($b !== '') $yaTiene['bases'][$b] = true;
}

session_write_close();   // no bloquear otras peticiones mientras se consulta la API

/*  API key de Google Books  */
$apiKey = getenv('GOOGLE_BOOKS_API_KEY') ?: '';
$archivoConfig = __DIR__ . '/../src/config.local.php';
if ($apiKey === '' && is_file($archivoConfig)) {
    $cfg = require $archivoConfig;
    $apiKey = is_array($cfg) ? trim((string)($cfg['google_books_key'] ?? '')) : '';
}
if (str_contains($apiKey, 'PEGA_AQUI')) $apiKey = '';   

/*  Google Books en paralelo  */
function googleVarios(array $peticiones, string $apiKey): array {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($peticiones as $k => $p) {
        $url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query(array_filter([
            'q'          => $p['q'],
            'printType'  => 'books',
            'maxResults' => $p['max'] ?? 40,
            'langRestrict' => (!empty($p['lang']) && LANG_GB !== '') ? LANG_GB : null,
            'key'        => $apiKey !== '' ? $apiKey : null,
        ], fn($v) => $v !== null));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0',
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
        $json = json_decode((string)curl_multi_getcontent($ch), true);
        $salida[$k] = $json['items'] ?? [];
        $GLOBALS['gb_log'][$k] = 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' · ' . count($salida[$k]) . ' resultados'
                               . (isset($json['error']['message']) ? ' · ' . $json['error']['message'] : '');
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $salida;
}

// Convierte un resultado de Google en nuestro formato
function normalizar(array $item): array {
    $vi = $item['volumeInfo'] ?? [];
    $img = $vi['imageLinks']['thumbnail'] ?? $vi['imageLinks']['smallThumbnail'] ?? '';
    if ($img !== '') $img = str_replace(['http://', '&edge=curl'], ['https://', ''], $img);

    return [
        'id'        => (string)($item['id'] ?? ''),
        'titulo'    => (string)($vi['title'] ?? ''),
        'autor'     => (string)($vi['authors'][0] ?? 'Autor desconocido'),
        'autores'   => $vi['authors'] ?? [],
        'portada'   => $img,
        'paginas'   => (int)($vi['pageCount'] ?? 0),
        'idioma'    => (string)($vi['language'] ?? ''),
        'conDesc'   => !empty($vi['description']),
        'categorias'=> $vi['categories'] ?? [],
    ];
}

// sin etiquetas genéricas
function generosDe(array $cadenas, int $max = 2): array {
    $cuenta = [];
    foreach ($cadenas as $c) {
        foreach (preg_split('/[\/,]/', (string)$c) as $g) {
            $g = trim($g);
            if ($g === '' || strcasecmp($g, 'General') === 0) continue;
            $cuenta[$g] = ($cuenta[$g] ?? 0) + 1;
        }
    }
    arsort($cuenta);
    $genericos = ['fiction', 'juvenile fiction', 'literary collections', 'young adult fiction'];
    $buenos = array_filter(array_keys($cuenta), fn($g) => !in_array(mb_strtolower($g), $genericos, true));
    $lista = array_values($buenos) ?: array_keys($cuenta);
    return array_slice($lista, 0, $max);
}

/* Ronda 1: libros del mismo autor + géneros de cada libro leído  */
$vistos        = [];
$autoresGlobal = [];

$aceptar = function (array &$destino, array $item, ?string $exigirAutor = null, bool $filtrarIdioma = true)
        use (&$vistos, &$autoresGlobal, $yaTiene) {
    $l = normalizar($item);
    if ($l['id'] === '' || $l['titulo'] === '' || $l['portada'] === '' || !$l['conDesc']) return false;
    if ($l['paginas'] > 0 && $l['paginas'] < 60) return false;                      
    if ($filtrarIdioma && LANG_GB !== '' && $l['idioma'] !== '' && $l['idioma'] !== LANG_GB) return false;
    if ($exigirAutor !== null && !in_array($exigirAutor, array_map('claveAutor', $l['autores']), true)) return false;

    $base = tituloBase($l['titulo']);
    if ($base === '' || isset($vistos[$base]) || isset($yaTiene['bases'][$base])) return false;
    if (in_array($l['id'], $yaTiene['ids'], true)) return false;

    // Variedad: máx. 2 libros por autor ajeno en el total
    $claveA = claveAutor($l['autor']);
    if ($exigirAutor === null && ($autoresGlobal[$claveA] ?? 0) >= 2) return false;

    $autoresGlobal[$claveA] = ($autoresGlobal[$claveA] ?? 0) + 1;
    $vistos[$base] = true;
    $destino[] = ['id' => $l['id'], 'titulo' => $l['titulo'], 'autor' => $l['autor'], 'portada' => $l['portada']];
    return true;
};

$autores = [];
$ronda1  = [];
foreach ($ultimos as $i => $libro) {
    $autores[$i] = trim(explode(',', $libro['autores'] ?? '')[0]);
    $tituloLimpio = sinParentesis($libro['titulo']);

    if ($autores[$i] !== '') {
        $ronda1["a$i"] = ['q' => 'inauthor:"' . $autores[$i] . '"', 'lang' => true, 'max' => 40];
    }
    // El libro leído (en cualquier idioma) para conocer sus géneros
    $ronda1["t$i"] = ['q' => 'intitle:"' . $tituloLimpio . '"' . ($autores[$i] !== '' ? ' inauthor:"' . $autores[$i] . '"' : ''),
                      'lang' => false, 'max' => 5];
}
$res1 = googleVarios($ronda1, $apiKey);

$porLibro  = [];
$generosPorLibro = [];
foreach ($ultimos as $i => $libro) {
    $lista = [];
    $delAutor = 0;
    $clave = $autores[$i] !== '' ? claveAutor($autores[$i]) : null;

    if ($clave !== null) {
        foreach ($res1["a$i"] ?? [] as $item) {
            if ($aceptar($lista, $item, $clave)) $delAutor++;
            if ($delAutor >= MAX_POR_AUTOR) break;
        }
    }
    $porLibro[$i] = $lista;

    // Géneros: los guardados en la base de datos y los que informa Google
    $cadenas = [(string)($libro['categorias'] ?? '')];
    foreach ($res1["t$i"] ?? [] as $it) {
        foreach ($it['volumeInfo']['categories'] ?? [] as $c) $cadenas[] = $c;
    }
    $generosPorLibro[$i] = generosDe(array_filter($cadenas), 2);
}

/* Ronda 2: libros de los mismos géneros */
$conteoGeneros = [];
foreach ($generosPorLibro as $gs) {
    foreach ($gs as $g) $conteoGeneros[$g] = ($conteoGeneros[$g] ?? 0) + 1;
}
arsort($conteoGeneros);
$generosUnicos = array_slice(array_keys($conteoGeneros), 0, 8);

$ronda2 = [];
foreach ($generosUnicos as $g) {
    $ronda2["g:$g"] = ['q' => 'subject:"' . $g . '"', 'lang' => true, 'max' => 40];
}
$ronda2['ficcion']       = ['q' => 'subject:"Fiction"', 'lang' => true,  'max' => 40];
$ronda2['ficcion_otros'] = ['q' => 'subject:"Fiction"', 'lang' => false, 'max' => 40];   // red de seguridad
$res2 = googleVarios($ronda2, $apiKey);

foreach ($ultimos as $i => $libro) {
    foreach ($generosPorLibro[$i] as $g) {
        if (count($porLibro[$i]) >= CUPO_POR_LIBRO) break;
        foreach ($res2["g:$g"] ?? [] as $item) {
            $aceptar($porLibro[$i], $item);
            if (count($porLibro[$i]) >= CUPO_POR_LIBRO) break;
        }
    }
}

/* Mezclar alternando */
$resultado = [];
$max = empty($porLibro) ? 0 : max(array_map('count', $porLibro));
for ($k = 0; $k < $max; $k++) {
    foreach ($porLibro as $lista) {
        if (isset($lista[$k])) $resultado[] = $lista[$k];
    }
}

/* Rellenos para no dejarlo vacío */
if (count($resultado) < TOTAL_MIN) {
    foreach ($generosUnicos as $g) {
        foreach ($res2["g:$g"] ?? [] as $item) {
            $aceptar($resultado, $item);
            if (count($resultado) >= TOTAL_MAX) break 2;
        }
    }
}
if (count($resultado) < TOTAL_MIN) {
    foreach ($res2['ficcion'] ?? [] as $item) {
        $aceptar($resultado, $item);
        if (count($resultado) >= TOTAL_MAX) break;
    }
}
// Último recurso: si en tu idioma casi no hay nada, ficción de cualquier idioma
if (count($resultado) < 4) {
    foreach ($res2['ficcion_otros'] ?? [] as $item) {
        $aceptar($resultado, $item, null, false);
        if (count($resultado) >= TOTAL_MIN) break;
    }
}

$respuesta = [
    'basadoEn' => array_map(fn($u) => sinParentesis($u['titulo']), $ultimos),
    'libros'   => array_slice($resultado, 0, TOTAL_MAX),
];

if (isset($_GET['debug'])) {
    $respuesta['debug'] = [
        'fuente'        => 'Google Books (si ves este campo, el archivo NUEVO está activo)',
        'idioma_ajuste' => IDIOMA,
        'langRestrict'  => LANG_GB !== '' ? LANG_GB : '(cualquier idioma)',
        'api_key'       => $apiKey !== '' ? 'sí, configurada' : 'NO configurada (Google te limitará)',
        'config'        => [
            'ruta_buscada'     => $archivoConfig,
            'el_archivo_existe' => is_file($archivoConfig),
            'devuelve_array'   => isset($cfg) ? is_array($cfg) : 'no se llegó a cargar',
            'claves_que_trae'  => (isset($cfg) && is_array($cfg)) ? array_keys($cfg) : [],
            'longitud_de_clave' => strlen($apiKey),
            'empieza_por'      => $apiKey !== '' ? substr($apiKey, 0, 4) . '…' : '',
            'variable_entorno' => getenv('GOOGLE_BOOKS_API_KEY') ? 'definida' : 'no definida',
        ],
        'consultas'     => $GLOBALS['gb_log'] ?? [],
    ];
}

if (!empty($respuesta['libros']) && !isset($_GET['debug'])) {
    @session_start();
    $_SESSION[$cacheKey] = ['t' => time(), 'data' => $respuesta];
    session_write_close();
}

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
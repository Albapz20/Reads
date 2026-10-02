<?php

require_once "../src/BookService.php";
require_once "../src/Database.php";
require_once "../src/Auth.php";
require_once "../src/ReviewService.php";
require_once "../src/RatingService.php";
require_once "../src/ListaService.php";
require_once "../src/UserService.php";

$authUser = Auth::usuario();
$reviewService = new ReviewService();
$ratingService = new RatingService();
$service = new BookService();
$db = new Database();
$listaService = new ListaService();

// Tema visual
$tema = "pastel";
if ($authUser) {
    $userService = new UserService();
    $datos = $userService->obtenerUsuarioPorId($authUser["id"]);
    $tema = $datos["tema_visual"] ?? "pastel";
}

function renderStars($rating) {
    $full = floor($rating);
    $half = ($rating - $full >= 0.5) ? 1 : 0;
    $empty = 5 - $full - $half;

    $html = str_repeat('<span class="star full">★</span>', $full);
    if ($half) $html .= '<span class="star half">★</span>';
    $html .= str_repeat('<span class="star empty">★</span>', $empty);

    return $html;
}

if (!isset($_GET['id'])) die("Libro no encontrado");

$id_externo = $_GET['id'];
$descripcionURL = isset($_GET['desc']) ? trim(urldecode($_GET['desc'])) : '';

// Intentar obtener el libro desde la API
$libroAPI = $service->obtenerLibro($id_externo);

// Rescate desde la base de datos si no se encuentra en la API
if (!$libroAPI) {
    $sqlLocal = "SELECT libro_id, titulo, autores, portada FROM listas_lectura WHERE id = ? OR libro_id = ? LIMIT 1";
    $stmtLocal = $db->pdo->prepare($sqlLocal);
    $stmtLocal->execute([$id_externo, $id_externo]);
    $libroBD = $stmtLocal->fetch(PDO::FETCH_ASSOC);

    if ($libroBD) {
        $autoresArray = array_filter(array_map('trim', explode(',', $libroBD["autores"] ?? '')));
        $libroAPI = [
            "volumeInfo" => [
                "title" => $libroBD["titulo"],
                "authors" => !empty($autoresArray) ? $autoresArray : ["Autor desconocido"],
                "imageLinks" => ["thumbnail" => $libroBD["portada"]],
                "description" => "Sin descripción disponible.",
                "pageCount" => 0
            ]
        ];
        if (!empty($libroBD["libro_id"])) {
            $id_externo = $libroBD["libro_id"];
        }
    } else {
        die("No se pudo obtener información del libro.");
    }
}

$paginasTotalesAPI = 0;
$proveedor = "google_books";

// Tratamiento de datos según la fuente
if (isset($libroAPI["volumeInfo"])) {
    // ---- GOOGLE BOOKS ----
    $info = $libroAPI["volumeInfo"];
    $titulo = $info["title"] ?? "Sin título";
    $autor = isset($info["authors"]) ? implode(", ", $info["authors"]) : "Autor desconocido";

    $images = $info["imageLinks"] ?? [];
    $portada = $images["extraLarge"] ?? $images["large"] ?? $images["medium"] ?? $images["thumbnail"] ?? $images["smallThumbnail"] ?? "";

    if (!empty($portada)) {
        $portada = str_replace("http://", "https://", $portada);
    } else {
        $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
    }

    $descripcion = $info["description"] ?? "Sin descripción disponible.";
    $idioma = $info["language"] ?? "";
    $paginasTotalesAPI = (int)($info["pageCount"] ?? 0);
} else {
    // OPEN LIBRARY 
    $proveedor = "open_library";
    $titulo = $libroAPI["title"] ?? "Sin título";

    if (isset($libroAPI["author_name"]) && is_array($libroAPI["author_name"])) {
        $autor = implode(", ", $libroAPI["author_name"]);
    } else {
        $autor = $libroAPI["autor"] ?? "Autor desconocido";
    }

    $descripcion = $libroAPI["description"] ?? "Sin descripción disponible.";

    if (!empty($libroAPI["portada"])) {
        $portada = $libroAPI["portada"];
    } elseif (isset($libroAPI["covers"][0]) && is_numeric($libroAPI["covers"][0])) {
        $portada = "https://covers.openlibrary.org/b/id/" . $libroAPI["covers"][0] . "-L.jpg";
    } elseif (!empty($libroAPI["cover_i"])) {
        $portada = "https://covers.openlibrary.org/b/id/" . $libroAPI["cover_i"] . "-L.jpg";
    } else {
        $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
    }

    $idioma = "";
    $paginasTotalesAPI = (int)($libroAPI["number_of_pages"] ?? 0);
}

// La descripción puede venir como array en Open Library
if (is_array($descripcion)) {
    $descripcion = $descripcion["value"] ?? "Sin descripción disponible.";
}

// Prioridad: si viene por URL
if (!empty($descripcionURL)) {
    $descripcion = $descripcionURL;
}
$descLog = [
    'proveedor' => $proveedor,
    'id'        => $id_externo,
    'titulo'    => $titulo,
    'autor'     => $autor,
    'desc_api_caracteres' => (trim((string)$descripcion) === 'Sin descripción disponible.') ? 0 : mb_strlen(trim((string)$descripcion)),
];

// Función auxiliar para consultar APIs que devuelven JSON
if (!function_exists('lbJson')) {
    function lbJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (contacto@tudominio.com)',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? (json_decode($resp, true) ?: []) : [];
    }
}
$sinDesc = function ($d) {
    return trim((string)$d) === '' || $d === "Sin descripción disponible.";
};

if (session_status() === PHP_SESSION_NONE) @session_start();
$claveFallo = 'desc_fail_' . md5($id_externo . '|' . $titulo);
$saltarRescates = !isset($_GET['debug']) && isset($_SESSION[$claveFallo]) && (time() - $_SESSION[$claveFallo]) < 6 * 3600;

// Si la API no trae descripción, buscarla en la tabla libros y en listas_lectura
if ($sinDesc($descripcion)) {
    $tituloBaseDB = trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo)));
    $consultas = [
        ["SELECT descripcion FROM libros WHERE id_externo = ? AND descripcion IS NOT NULL AND descripcion <> '' LIMIT 1", [$id_externo]],
        ["SELECT descripcion FROM libros WHERE titulo = ? AND descripcion IS NOT NULL AND descripcion <> '' LIMIT 1", [$titulo]],
        ["SELECT descripcion FROM libros WHERE titulo LIKE ? AND descripcion IS NOT NULL AND descripcion <> '' ORDER BY CHAR_LENGTH(titulo) ASC LIMIT 1", ['%' . $tituloBaseDB . '%']],
        ["SELECT descripcion FROM listas_lectura
          WHERE (libro_id = ? OR titulo = ?) AND descripcion IS NOT NULL AND descripcion <> ''
          ORDER BY CHAR_LENGTH(descripcion) DESC LIMIT 1", [$id_externo, $titulo]],
    ];
    foreach ($consultas as $n => [$sqlD, $paramsD]) {
        try {
            $stD = $db->pdo->prepare($sqlD);
            if (!$stD) { $descLog["bd$n"] = 'no se pudo preparar (¿columna inexistente?)'; continue; }
            $stD->execute($paramsD);
            $d = $stD->fetchColumn();
            if (!$sinDesc($d)) { $descripcion = $d; $descLog["bd$n"] = 'ENCONTRADA'; break; }
            $descLog["bd$n"] = 'sin resultado';
        } catch (Throwable $e) {
            $descLog["bd$n"] = 'error: ' . $e->getMessage();
        }
    }
}

// Descripción y portada de rescate con Google Books
if (!$saltarRescates && (empty(trim($descripcion)) || $descripcion === "Sin descripción disponible." || strpos($portada, 'placehold.co') !== false)) {

    $apiKey = getenv('GOOGLE_BOOKS_API_KEY') ?: '';
    $archivoConfig = __DIR__ . '/../src/config.local.php';
    if ($apiKey === '' && is_file($archivoConfig)) {
        $cfg = require $archivoConfig;
        $apiKey = is_array($cfg) ? (string)($cfg['google_books_key'] ?? '') : '';
    }

    $tituloLimpio = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
    $tituloLimpio = trim(explode('/', $tituloLimpio)[0]);
    $tituloLimpio = trim(explode(' - ', $tituloLimpio)[0]);
    $autorLimpio = ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '';

    $intentos = [];
    if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', (string)$id_externo)) {
        $intentos[] = ['isbn:' . $id_externo, false];  
    }
    if ($autorLimpio !== '') {
        $intentos[] = ['intitle:"' . $tituloLimpio . '" inauthor:"' . $autorLimpio . '"', false];
        $intentos[] = [$tituloLimpio . ' ' . $autorLimpio, true];
    }
    $intentos[] = [$tituloLimpio, true];

    $simTitulo = function (string $a, string $b): bool {
        $a = mb_strtolower($a, 'UTF-8'); $b = mb_strtolower($b, 'UTF-8');
        similar_text($a, $b, $pct);
        return $pct >= 55 || str_contains($a, $b) || str_contains($b, $a);
    };

    $descEncontrada = false;
    foreach ($intentos as $n => [$query, $soloEs]) {
        if ($descEncontrada && strpos($portada, 'placehold.co') === false) break;

        $urlAPI = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query)
                . ($apiKey !== '' ? "&key=" . urlencode($apiKey) : '')
                . ($soloEs ? "&langRestrict=es" : '')
                . "&maxResults=5";

        $json = false; $codigoHttp = 0;
        for ($reintento = 0; $reintento < 2; $reintento++) {  
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $urlAPI,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_USERAGENT => 'Mozilla/5.0'
            ]);
            $json = curl_exec($ch);
            $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($codigoHttp < 500) break;
            usleep(400000);
        }
        if ($codigoHttp === 429) { $descLog['google'][] = 429; break; }

        $items = $json ? (json_decode($json, true)['items'] ?? []) : [];
        $conDesc = 0;

        foreach ($items as $it) {
            $vi = $it["volumeInfo"] ?? [];
            if (!$simTitulo($tituloLimpio, (string)($vi["title"] ?? ''))) continue;

            if (!empty($vi["description"])) {
                $conDesc++;
                if (!$descEncontrada) {
                    $descripcion = $vi["description"];
                    $descEncontrada = true;
                }
            }
            if (strpos($portada, 'placehold.co') !== false && !empty($vi["imageLinks"])) {
                $img = $vi["imageLinks"]["thumbnail"] ?? $vi["imageLinks"]["smallThumbnail"] ?? "";
                if ($img) $portada = str_replace("http://", "https://", $img);
            }
        }
        $descLog['google'][] = "intento $n: HTTP $codigoHttp, " . count($items) . " resultados, $conDesc con descripción";
    }
}

// Último recurso: Open Library (título + autor, y luego la ficha de la obra)
if (!$saltarRescates && $sinDesc($descripcion)) {
    $tOL = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
    $qOL = ['title' => $tOL, 'limit' => 3, 'fields' => 'key,title,author_name'];
    if ($autor !== "Autor desconocido") $qOL['author'] = trim(explode(',', $autor)[0]);

    $resOL = lbJson('https://openlibrary.org/search.json?' . http_build_query($qOL));
    foreach ($resOL['docs'] ?? [] as $docOL) {
        if (empty($docOL['key'])) continue;
        $work = lbJson('https://openlibrary.org' . $docOL['key'] . '.json');
        $dOL = $work['description'] ?? '';
        if (is_array($dOL)) $dOL = $dOL['value'] ?? '';
        if (trim((string)$dOL) !== '') { $descripcion = $dOL; break; }
    }
}

// Apple Books (API de iTunes, sin clave)
if (!$saltarRescates && $sinDesc($descripcion)) {
    $tAB = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
    $autorAB = ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '';
    $apellidoAB = mb_strtolower(trim((string)array_slice(explode(' ', $autorAB), -1)[0]), 'UTF-8');

    $peticiones = [];   // [url, es_busqueda_por_isbn]
    if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', (string)$id_externo)) {
        foreach (['es', 'us'] as $pais) {
            $peticiones[] = ["https://itunes.apple.com/lookup?isbn=" . urlencode($id_externo) . "&country=$pais", true];
        }
    }
    foreach (['es', 'us'] as $pais) {
        $peticiones[] = ["https://itunes.apple.com/search?term=" . urlencode(trim($tAB . ' ' . $autorAB)) . "&entity=ebook&country=$pais&limit=5", false];
    }

    foreach ($peticiones as $n => [$urlAB, $porIsbn]) {
        $resAB = lbJson($urlAB);
        $descLog['apple'][] = "petición $n: " . count($resAB['results'] ?? []) . " resultados";

        foreach ($resAB['results'] ?? [] as $itAB) {
            if (empty($itAB['description'])) continue;

            if (!$porIsbn) {   // en la búsqueda por texto, comprobar título y autor
                $tEnc = mb_strtolower((string)($itAB['trackName'] ?? ''), 'UTF-8');
                $aEnc = mb_strtolower((string)($itAB['artistName'] ?? ''), 'UTF-8');
                similar_text(mb_strtolower($tAB, 'UTF-8'), $tEnc, $pctAB);
                $tituloOk = $pctAB >= 55 || str_contains($tEnc, mb_strtolower($tAB, 'UTF-8'));
                $autorOk  = $apellidoAB === '' || str_contains($aEnc, $apellidoAB);
                if (!$tituloOk || !$autorOk) continue;
            }

            $descripcion = preg_replace('/<\s*(br|\/p)\s*\/?>/i', "\n", $itAB['description']);
            $descLog['apple_encontrada'] = $itAB['trackName'] ?? '';
            break 2;
        }
    }
}

// Otra edición del mismo libro buscando por autor
if (!$saltarRescates && $sinDesc($descripcion) && $autor !== "Autor desconocido") {
    $norm = function (string $t): string {
        $t = strtr(mb_strtolower($t, 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $t)));
    };
    $base = function (string $t) use ($norm): string {   // título sin subtítulo ni saga
        $t = preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $t);
        return $norm(preg_split('/\s*:\s*/u', $t)[0]);
    };

    $clave = function (string $n) use ($norm): string {
        $t = array_values(array_filter(explode(' ', $norm($n)), fn($w) => strlen($w) > 1));
        return count($t) >= 2 ? $t[0] . ' ' . end($t) : implode(' ', $t);
    };
    $autorNorm = $clave(trim(explode(',', $autor)[0]));
    $apiKey = $apiKey ?? '';

    // Páginas de referencia (para descartar libros distintos del mismo autor)
    $pagsRef = (int)$paginasTotalesAPI;
    try {
        $stP = $db->pdo->prepare("SELECT paginas_totales FROM listas_lectura WHERE libro_id = ? OR titulo = ? LIMIT 1");
        $stP->execute([$id_externo, $titulo]);
        $pagsRef = max($pagsRef, (int)$stP->fetchColumn());
    } catch (Throwable $e) {}

    $urlA = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode('inauthor:"' . $autorNorm . '"')
          . ($apiKey !== '' ? "&key=" . urlencode($apiKey) : '') . "&printType=books&maxResults=20";
    $resA = lbJson($urlA);

    $candidatos = [];   // título base [descripción, título original]
    $cercanos   = [];   
    foreach ($resA['items'] ?? [] as $itA) {
        $vi = $itA['volumeInfo'] ?? [];
        if (empty($vi['description'])) continue;
        // el autor debe coincidir exactamente
        if (!in_array($autorNorm, array_map($clave, $vi['authors'] ?? []), true)) continue;
        $pc = (int)($vi['pageCount'] ?? 0);
        $kB = $base((string)($vi['title'] ?? ''));
        $candidatos[$kB] ??= [$vi['description'], $vi['title'] ?? ''];
        if (!($pagsRef > 0 && $pc > 0 && abs($pc - $pagsRef) / $pagsRef > 0.30)) {
            $cercanos[$kB] ??= [$vi['description'], $vi['title'] ?? ''];
        }
    }
    // Si el autor solo tiene un libro, se usa sin más; si tiene varios, se desempata por página
    if (count($candidatos) !== 1 && count($cercanos) === 1) $candidatos = $cercanos;

    $descLog['otra_edicion'] = 'Google: ' . count($resA['items'] ?? []) . ' resultados, ' . count($candidatos) . ' candidatos';
    if (count($candidatos) === 1) {            // solo si no hay ambigüedad
        [$descripcion, $tOrig] = array_values($candidatos)[0];
        $descLog['otra_edicion'] = 'usada (Google): ' . $tOrig;
    }

    // Segunda vía: Open Library (obras del mismo autor y descripción de la ficha de la obra)
    if ($sinDesc($descripcion)) {
        $resO = lbJson('https://openlibrary.org/search.json?' . http_build_query([
            'author' => $autorNorm, 'limit' => 20,
            'fields' => 'key,title,author_name,number_of_pages_median',
        ]));
        $obras = [];   
        $obrasCerca = [];
        foreach ($resO['docs'] ?? [] as $dO) {
            if (!in_array($autorNorm, array_map($clave, $dO['author_name'] ?? []), true)) continue;
            $pc = (int)($dO['number_of_pages_median'] ?? 0);
            $kB = $base((string)($dO['title'] ?? ''));
            $obras[$kB] ??= [$dO['key'] ?? '', $dO['title'] ?? ''];
            if (!($pagsRef > 0 && $pc > 0 && abs($pc - $pagsRef) / $pagsRef > 0.30)) {
                $obrasCerca[$kB] ??= [$dO['key'] ?? '', $dO['title'] ?? ''];
            }
        }
        if (count($obras) !== 1 && count($obrasCerca) === 1) $obras = $obrasCerca;
        $descLog['ol_docs'] = array_map(function ($d) {
            return ($d['title'] ?? '?') . ' [' . implode(', ', $d['author_name'] ?? []) . '] ' . ($d['number_of_pages_median'] ?? 0) . ' págs.';
        }, $resO['docs'] ?? []);
        $descLog['otra_edicion_ol'] = count($resO['docs'] ?? []) . ' resultados, ' . count($obras) . ' obras válidas';

        if (count($obras) === 1) {
            [$kO, $tO] = array_values($obras)[0];
            $wO = $kO !== '' ? lbJson('https://openlibrary.org' . $kO . '.json') : [];
            $dO = $wO['description'] ?? '';
            if (is_array($dO)) $dO = $dO['value'] ?? '';
            if (trim((string)$dO) !== '') {
                $descripcion = $dO;
                $descLog['otra_edicion_ol'] .= ' → usada: ' . $tO;
            } else {
                $descLog['otra_edicion_ol'] .= ' → la ficha de "' . $tO . '" no tiene descripción';
            }
        }
    }
}

if (empty(trim($descripcion))) {
    $descripcion = "Sin descripción disponible.";
}
if ($sinDesc($descripcion)) {
    $_SESSION[$claveFallo] = time();   // recordar el fallo para no repetir las búsquedas
}

// Si la descripción se obtuvo por un rescate, guardarla en la lista del usuario
if ($authUser && $descLog['desc_api_caracteres'] === 0 && !$sinDesc($descripcion)) {
    try {
        $db->pdo->prepare(
            "UPDATE listas_lectura SET descripcion = ?
             WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?)
               AND (descripcion IS NULL OR descripcion = '')"
        )->execute([$descripcion, $authUser["id"], $id_externo, $titulo]);
    } catch (Throwable $e) {}
}

// Procesamiento de estado y páginas si el usuario está autenticado
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["accion"]) && $authUser) {
    $estado = trim($_POST["accion"]);

    $listaService->agregarLibro($authUser["id"], $id_externo, $titulo, $portada, $estado);

    $sqlObtenerId = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) ORDER BY id DESC LIMIT 1";
    $stmtId = $db->pdo->prepare($sqlObtenerId);
    $stmtId->execute([$authUser["id"], $id_externo, $titulo]);
    $registro = $stmtId->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        $listaService->cambiarEstado($authUser["id"], $registro["id"], $estado);

        if ($paginasTotalesAPI > 0) {
            $paginasLeidas = ($estado === "leido") ? $paginasTotalesAPI : 0;
            $listaService->actualizarPaginas($authUser["id"], $registro["id"], $paginasTotalesAPI, $paginasLeidas);
        }
    }

    header("Location: perfil.php");
    exit;
}

// Datos del usuario sobre este libro 
$estadoActual = null;
$libroUser = null;
$miReseña = '';
$puntuacionUsuario = null;
if ($authUser) {
    $stmt = $db->pdo->prepare("SELECT * FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) LIMIT 1");
    $stmt->execute([$authUser["id"], $id_externo, $titulo]);
    $libroUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($libroUser) $estadoActual = $libroUser["estado"];

    $puntuacionUsuario = $ratingService->obtenerPuntuacionUsuario($authUser["id"], $id_externo);
    $miReseña = $reviewService->obtenerReseñaUsuario($authUser["id"], $id_externo);

}

$paginasTotales = $libroUser ? (int)$libroUser["paginas_totales"] : 0;
$paginasLeidas  = $libroUser ? (int)$libroUser["paginas_leidas"] : 0;
$progreso = 0;
if ($libroUser) {
    $progreso = $paginasTotales > 0 ? round(($paginasLeidas / $paginasTotales) * 100) : (int)($libroUser["progreso"] ?? 0);
}
$paginasMostrar = $paginasTotales > 0 ? $paginasTotales : $paginasTotalesAPI;

$reseñas = $reviewService->obtenerReseñas($id_externo);
$medias  = $ratingService->obtenerMedias($id_externo);

// URL de portada segura para usarla dentro de CSS url
$portadaCss = str_replace(["'", '"', '(', ')', ' ', "\n"], ['%27', '%22', '%28', '%29', '%20', ''], $portada);

$estados = [
    'guardado'   => ['🎁', 'Wishlist'],
    'tbr'        => ['🎯', 'TBR'],
    'leyendo'    => ['📖', 'Leyendo'],
    'leido'      => ['✅', 'Leído'],
    'abandonado' => ['❌', 'Abandonado'],
];

$metricas = [
    'estrellas'  => ['⭐', 'General',           'estrellas',  '★'],
    'romance'    => ['💖', 'Romance',           'romance',    '💖'],
    'spicy'      => ['🌶️', 'Spicy',             'spicy',      '🌶️'],
    'lagrimas'   => ['💧', 'Lágrimas / Drama',  'lagrimas',   '💧'],
    'plot_twist' => ['⚡', 'Plot Twist',        'plot_twist', '⚡'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?></title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <script src="main.js"></script>
    <style>
        :root {
            --lb-accent: var(--primary-color, var(--color-primario, #d87d8a));
            --lb-border: var(--border-color, rgba(0,0,0,.09));
            --lb-surface: var(--bg-card, #ffffff);
        }
        body { padding-bottom: 100px; }
        .lb-wrap { max-width: 920px; margin: 0 auto; padding: 16px; }

        /* ---- Cabecera ---- */
        .lb-hero {
            position: relative;
            overflow: hidden;
            border-radius: 22px;
            border: 1px solid var(--lb-border);
            background: var(--lb-surface);
            margin-bottom: 18px;
        }
        .lb-hero-bg {
            position: absolute; inset: -30px;
            background-size: cover; background-position: center;
            filter: blur(38px) saturate(1.3);
            opacity: .38;
        }
        .lb-hero-inner {
            position: relative;
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 32px;
            padding: 32px;
            align-items: start;
        }
        .lb-cover img {
            width: 100%; height: auto; display: block;
            border-radius: 10px;
            box-shadow: 0 18px 40px rgba(0,0,0,.28), 0 2px 6px rgba(0,0,0,.2);
        }
        .lb-info h1 { margin: 0 0 6px; font-size: 2rem; line-height: 1.15; }
        .lb-author { margin: 0 0 16px; font-size: 1.05rem; opacity: .8; }
        .lb-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 22px; }
        .lb-chip {
            padding: 5px 12px; border-radius: 999px; font-size: .82rem; font-weight: 600;
            background: rgba(255,255,255,.7); border: 1px solid var(--lb-border);
        }

        /* ---- Selector de estado ---- */
        .lb-estado-label { font-size: .85rem; font-weight: 700; margin-bottom: 8px; display: block; opacity: .75; }
        .lb-estados { display: flex; flex-wrap: wrap; gap: 8px; }
        .lb-estados button {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px; border-radius: 999px;
            border: 1px solid var(--lb-border);
            background: rgba(255,255,255,.85);
            color: inherit; font: inherit; font-size: .88rem; font-weight: 600;
            cursor: pointer; transition: background .15s, color .15s, border-color .15s;
        }
        .lb-estados button:hover { border-color: var(--lb-accent); }
        .lb-estados button:focus-visible { outline: 3px solid var(--lb-accent); outline-offset: 2px; }
        .lb-estados button.active { background: var(--lb-accent); border-color: var(--lb-accent); color: #fff; }
        .lb-login-hint { font-size: .9rem; opacity: .8; }
        .lb-login-hint a { color: var(--lb-accent); font-weight: 700; }

        /* ---- Secciones ---- */
        .lb-grid { display: grid; grid-template-columns: 1fr 340px; gap: 18px; align-items: start; }
        .lb-col { display: flex; flex-direction: column; gap: 18px; min-width: 0; }
        .lb-card {
            background: var(--lb-surface);
            border: 1px solid var(--lb-border);
            border-radius: 16px;
            padding: 22px;
        }
        .lb-card h2 { margin: 0 0 14px; font-size: 1.15rem; }

        .lb-desc { line-height: 1.65; margin: 0; }
        .lb-desc { color: var(--text-color, inherit); white-space: normal; word-wrap: break-word; }
        .lb-desc.clamp { max-height: 10.5em; overflow: hidden; }
        .lb-more { margin-top: 10px; background: none; border: none; padding: 0; color: var(--lb-accent); font: inherit; font-weight: 700; cursor: pointer; }

        /* Progreso */
        .lb-prog-num { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
        .lb-prog-num strong { font-size: 1.6rem; }
        .lb-bar { height: 10px; border-radius: 10px; background: rgba(0,0,0,.08); overflow: hidden; }
        .lb-bar > div { height: 100%; background: var(--lb-accent); border-radius: 10px; }
        .lb-link { display: inline-block; margin-top: 14px; color: var(--lb-accent); font-weight: 700; text-decoration: none; }
        .lb-link:hover { text-decoration: underline; }

        /* Puntuación */
        .lb-rate { display: flex; flex-direction: column; gap: 14px; }
        .lb-rate-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
        .lb-rate-row label { font-weight: 700; font-size: .92rem; }
        .icon-selector { display: flex; gap: 4px; cursor: pointer; font-size: 1.6rem; user-select: none; }
        .lb-btn {
            width: 100%; margin-top: 6px; padding: 11px; border: none; border-radius: 12px;
            background: var(--lb-accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer;
        }
        .lb-btn:hover { opacity: .92; }

        /* Reseñas */
        .lb-card textarea {
            width: 100%; box-sizing: border-box; padding: 12px; border-radius: 12px;
            border: 1px solid var(--lb-border); font: inherit; resize: vertical; margin-bottom: 10px;
        }
        .lb-review { padding: 14px 0; border-top: 1px solid var(--lb-border); }
        .lb-review:first-of-type { border-top: none; padding-top: 0; }
        .lb-review strong { display: block; margin-bottom: 4px; }
        .lb-empty { margin: 0; opacity: .65; }

        /* Comunidad */
        .lb-media-row { display: flex; justify-content: space-between; padding: 7px 0; border-top: 1px solid var(--lb-border); font-size: .92rem; }
        .lb-media-row:first-of-type { border-top: none; }
        .star.full, .star.half { color: #f5b301; }
        .star.empty { color: #d5d5d5; }
        .lb-stars-big { font-size: 1.5rem; margin-bottom: 8px; }

        /* Barra inferior */
        .floating-nav-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: calc(100% - 40px); max-width: 600px; }
        .quick-nav-floating { display: flex; align-items: center; justify-content: space-around; padding: 8px 12px; background: rgba(255,255,255,.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.6); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
        .nav-card-float { display: flex; flex-direction: column; align-items: center; padding: 6px 12px; text-decoration: none; color: #2d3748; font-weight: 600; font-size: .8rem; border-radius: 12px; transition: color .2s, transform .2s; }
        .nav-card-float:hover { color: var(--lb-accent); transform: translateY(-2px); }
        .nav-card-float .nav-icon { font-size: 1.25rem; margin-bottom: 2px; }

        /* ---- Móvil ---- */
        @media (max-width: 820px) {
            .lb-grid { grid-template-columns: 1fr; }
            .lb-hero-inner { grid-template-columns: 1fr; padding: 24px 18px; text-align: center; justify-items: center; }
            .lb-cover { width: 170px; }
            .lb-info h1 { font-size: 1.6rem; }
            .lb-chips, .lb-estados { justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>

<body>
<div class="lb-wrap">

    <!-- Cabecera -->
    <header class="lb-hero">
        <div class="lb-hero-bg" style="background-image: url('<?= htmlspecialchars($portadaCss) ?>');"></div>
        <div class="lb-hero-inner">
            <div class="lb-cover">
                <img src="<?= htmlspecialchars($portada) ?>"
                     alt="Portada de <?= htmlspecialchars($titulo) ?>"
                     onerror="this.onerror=null; this.src='https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada';">
            </div>

            <div class="lb-info">
                <h1><?= htmlspecialchars($titulo) ?></h1>
                <p class="lb-author"><?= htmlspecialchars($autor) ?></p>

                <?php if ($paginasMostrar > 0 || $idioma !== ''): ?>
                <div class="lb-chips">
                    <?php if ($paginasMostrar > 0): ?><span class="lb-chip"><?= number_format($paginasMostrar, 0, '', '.') ?> páginas</span><?php endif; ?>
                    <?php if ($idioma !== ''): ?><span class="lb-chip"><?= htmlspecialchars(strtoupper($idioma)) ?></span><?php endif; ?>
                    <?php if ($medias && $medias["media_estrellas"] !== null): ?><span class="lb-chip">★ <?= round($medias["media_estrellas"], 1) ?> de la comunidad</span><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($authUser): ?>
                    <span class="lb-estado-label">¿En qué punto estás con este libro?</span>
                    <form method="POST" class="lb-estados">
                        <?php foreach ($estados as $clave => [$icono, $nombre]): ?>
                            <button type="submit" name="accion" value="<?= $clave ?>" class="<?= $estadoActual === $clave ? 'active' : '' ?>">
                                <span><?= $icono ?></span> <?= $nombre ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <p class="lb-login-hint"><a href="login.php">Inicia sesión</a> para guardar este libro, puntuarlo y escribir reseñas.</p>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="lb-grid">

        <!-- Columna principal -->
        <div class="lb-col">

            <section class="lb-card">
                <h2>Sobre este libro</h2>
                <?php if (isset($_GET['debug'])): ?>
                    <pre style="font-size:.75rem; background:#f4f4f4; color:#222; padding:10px; border-radius:8px; overflow:auto;"><?= htmlspecialchars(print_r($descLog, true)) ?></pre>
                <?php endif; ?>
                <?php $descLimpia = strip_tags($descripcion); ?>
                <p class="lb-desc <?= mb_strlen($descLimpia) > 550 ? 'clamp' : '' ?>" id="descTexto"><?= nl2br(htmlspecialchars($descLimpia, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                <?php if (mb_strlen($descLimpia) > 550): ?>
                    <button type="button" class="lb-more" id="descBtn">Leer más</button>
                <?php endif; ?>
            </section>

            <?php if ($authUser): ?>
            <section class="lb-card">
                <h2>Tu reseña</h2>
                <form action="guardar_reseña.php" method="POST">
                    <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
                    <textarea name="contenido" rows="4" placeholder="¿Qué te ha parecido?"><?= htmlspecialchars($miReseña) ?></textarea>
                    <button type="submit" class="lb-btn" style="width:auto; padding:10px 22px;">Guardar reseña</button>
                </form>
            </section>
            <?php endif; ?>

            <section class="lb-card">
                <h2>Reseñas de la comunidad</h2>
                <?php if (count($reseñas) === 0): ?>
                    <p class="lb-empty">Todavía no hay reseñas. Sé la primera persona en escribir una.</p>
                <?php else: ?>
                    <?php foreach ($reseñas as $r): ?>
                        <div class="lb-review">
                            <strong><?= htmlspecialchars($r['nombre']) ?></strong>
                            <?= nl2br(htmlspecialchars($r['contenido'])) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <!-- Columna lateral -->
        <aside class="lb-col">

            <?php if ($authUser && $libroUser): ?>
            <section class="lb-card">
                <h2>Tu progreso</h2>
                <div class="lb-prog-num">
                    <strong><?= $progreso ?>%</strong>
                    <span><?= $paginasLeidas ?> / <?= $paginasTotales ?> págs.</span>
                </div>
                <div class="lb-bar"><div style="width: <?= (int)$progreso ?>%"></div></div>
                <a href="editar_libro.php?id=<?= (int)$libroUser["id"] ?>" class="lb-link">✏️ Editar progreso</a>
            </section>

            <section class="lb-card">
                <h2>Gestionar libro</h2>

                <?php if ($libroUser["estado"] === "leido" && !empty($libroUser["fecha_fin"])): ?>
                    <p style="margin:0 0 14px; font-size:.9rem;"><strong>Terminado el:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($libroUser["fecha_fin"]))) ?></p>
                <?php endif; ?>

                <?php if ($libroUser["estado"] === "abandonado"): ?>
                    <form method="POST" action="actualizar_progreso.php" style="margin-bottom:16px;">
                        <input type="hidden" name="libro_id" value="<?= (int)$libroUser["id"] ?>">
                        <label for="motivo_abandono" style="font-weight:700; font-size:.9rem; display:block; margin-bottom:6px;">Motivo de abandono</label>
                        <textarea name="motivo_abandono" id="motivo_abandono" rows="3" placeholder="¿Por qué lo dejaste?"><?= htmlspecialchars($libroUser["motivo_abandono"] ?? "") ?></textarea>
                        <button type="submit" class="lb-btn">Guardar motivo</button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="eliminar_libro.php" onsubmit="return confirm('¿Seguro que quieres eliminar este libro de tu biblioteca?');">
                    <input type="hidden" name="libro_id" value="<?= (int)$libroUser["id"] ?>">
                    <button type="submit" class="lb-btn" style="background:#c0392b;">🗑️ Eliminar de mi biblioteca</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($authUser): ?>
            <section class="lb-card">
                <h2>Tu puntuación</h2>
                <form action="guardar_puntuacion.php" method="POST" class="lb-rate">
                    <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">

                    <?php foreach ($metricas as $clave => [$emoji, $nombre, $campo, $icono]):
                        $valor = $puntuacionUsuario[$campo] ?? 0; ?>
                        <div class="lb-rate-row">
                            <label><?= $emoji ?> <?= $nombre ?>: <span id="val-<?= $campo ?>"><?= $valor ?></span>/5</label>
                            <div class="icon-selector" data-target="input-<?= $campo ?>" data-label="val-<?= $campo ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?><span data-val="<?= $i ?>"><?= $icono ?></span><?php endfor; ?>
                            </div>
                            <input type="hidden" name="<?= $campo ?>" id="input-<?= $campo ?>" value="<?= $valor ?>">
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="lb-btn">Guardar puntuación</button>
                </form>
            </section>
            <?php endif; ?>

            <section class="lb-card">
                <h2>Media de la comunidad</h2>
                <?php if ($medias && $medias["media_estrellas"] !== null): ?>
                    <div class="lb-stars-big"><?= renderStars(round($medias["media_estrellas"], 1)) ?></div>
                    <div class="lb-media-row"><span>⭐ General</span><strong><?= round($medias["media_estrellas"], 1) ?> / 5</strong></div>
                    <div class="lb-media-row"><span>💖 Romance</span><strong><?= round($medias["media_romance"] ?? 0, 1) ?> / 5</strong></div>
                    <div class="lb-media-row"><span>🌶️ Spicy</span><strong><?= round($medias["media_spicy"] ?? 0, 1) ?> / 5</strong></div>
                    <div class="lb-media-row"><span>💧 Lágrimas</span><strong><?= round($medias["media_lagrimas"] ?? 0, 1) ?> / 5</strong></div>
                    <div class="lb-media-row"><span>⚡ Plot Twist</span><strong><?= round($medias["media_plot_twist"] ?? 0, 1) ?> / 5</strong></div>
                <?php else: ?>
                    <p class="lb-empty">Aún no hay puntuaciones.</p>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>

<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi Perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
    </nav>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Leer más / menos
    const btn = document.getElementById('descBtn');
    const txt = document.getElementById('descTexto');
    if (btn && txt) {
        btn.addEventListener('click', () => {
            const cerrado = txt.classList.toggle('clamp');
            btn.textContent = cerrado ? 'Leer más' : 'Leer menos';
        });
    }

    // Selector de puntuación (admite medias puntuaciones)
    function updateVisuals(container, val) {
        container.querySelectorAll('span').forEach(icon => {
            const iconVal = parseInt(icon.dataset.val);
            if (iconVal <= val) {
                icon.style.opacity = '1';
                icon.style.filter = 'grayscale(0%)';
                icon.style.color = container.dataset.target === 'input-estrellas' ? '#f5b301' : '';
            } else if (iconVal - 0.5 === val) {
                icon.style.opacity = '0.6';
                icon.style.filter = 'grayscale(30%)';
                icon.style.color = container.dataset.target === 'input-estrellas' ? '#f5b301' : '';
            } else {
                icon.style.opacity = '0.25';
                icon.style.filter = 'grayscale(100%)';
                icon.style.color = '';
            }
        });
    }

    document.querySelectorAll('.icon-selector').forEach(container => {
        const input = document.getElementById(container.dataset.target);
        const label = document.getElementById(container.dataset.label);
        updateVisuals(container, parseFloat(input.value) || 0);

        container.querySelectorAll('span').forEach(icon => {
            icon.addEventListener('click', (e) => {
                const rect = icon.getBoundingClientRect();
                const baseVal = parseInt(icon.dataset.val);
                const finalVal = ((e.clientX - rect.left) < rect.width / 2) ? baseVal - 0.5 : baseVal;
                input.value = finalVal;
                if (label) label.textContent = finalVal;
                updateVisuals(container, finalVal);
            });
        });
    });
});
</script>

</body>
</html>
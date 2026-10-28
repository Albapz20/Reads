<?php

require_once "../src/BookService.php";
require_once "../src/Database.php";
require_once "../src/Auth.php";
require_once "../src/ReviewService.php";
require_once "../src/RatingService.php";
require_once "../src/ListaService.php";
require_once "../src/UserService.php";
require_once "../src/PortadasVerificadas.php";
require_once "../src/GenerosHelper.php";
require_once "../src/Traductor.php";
require_once "../src/VibesService.php";
require_once "../src/ComunidadService.php";

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
$tema = strtolower(trim($tema));

// Estados de lectura 
$estados = [
    'guardado'   => ['🎁', 'Wishlist'],
    'tbr'        => ['🎯', 'TBR'],
    'leyendo'    => ['📖', 'Leyendo'],
    'leido'      => ['✅', 'Leído'],
    'abandonado' => ['❌', 'Abandonado'],
];

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
   $paginasTotalesAPI = (int)($info["pageCount"] ?? 0) ?: (int)($info["printedPageCount"] ?? 0);
} else {
    // ---- OPEN LIBRARY ----
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
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (' . (defined('APP_CONTACTO') ? APP_CONTACTO : 'contacto') . ')',
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
// Si ya falló hace menos de 6 h, no repetir las búsquedas externas
$saltarRescates = !isset($_GET['debug']) && isset($_SESSION[$claveFallo]) && (time() - $_SESSION[$claveFallo]) < 6 * 3600;

$apiKey = (string)config('google_books_key', '');

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

// Descripción de rescate con Google Books
if (!$saltarRescates && $sinDesc($descripcion)) {

    $tituloLimpio = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
    $tituloLimpio = trim(explode('/', $tituloLimpio)[0]);
    $tituloLimpio = trim(explode(' - ', $tituloLimpio)[0]);
    $autorLimpio = ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '';

    // Cada intento: [consulta, restringir a español]
    $intentos = [];
    if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', (string)$id_externo)) {
        $intentos[] = ['isbn:' . $id_externo, false];   // ISBN exacto, en cualquier idioma
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
        if ($descEncontrada) break;

        $urlAPI = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query)
                . ($apiKey !== '' ? "&key=" . urlencode($apiKey) : '')
                . ($soloEs ? "&langRestrict=es" : '')
                . "&maxResults=5";

        $json = false; $codigoHttp = 0;
        for ($reintento = 0; $reintento < 2; $reintento++) {   // reintentar una vez si Google da 5xx
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

// Apple Books (API de iTunes, sin clave): suele tener descripciones de ediciones en español
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

    $candidatos = [];   // título base => [descripción, título original]
    $cercanos   = [];   // los mismos, pero solo con páginas parecidas
    foreach ($resA['items'] ?? [] as $itA) {
        $vi = $itA['volumeInfo'] ?? [];
        if (empty($vi['description'])) continue;
        // el autor debe coincidir exactamente (evita "Ana Garriga Domínguez" frente a "Ana Garriga")
        if (!in_array($autorNorm, array_map($clave, $vi['authors'] ?? []), true)) continue;
        $pc = (int)($vi['pageCount'] ?? 0);
        $kB = $base((string)($vi['title'] ?? ''));
        $candidatos[$kB] ??= [$vi['description'], $vi['title'] ?? ''];
        if (!($pagsRef > 0 && $pc > 0 && abs($pc - $pagsRef) / $pagsRef > 0.30)) {
            $cercanos[$kB] ??= [$vi['description'], $vi['title'] ?? ''];
        }
    }
    // Si el autor solo tiene un libro, se usa sin más; si tiene varios, se desempata por páginas (±30 %)
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
    $_SESSION[$claveFallo] = time();   
}

// Descripción siempre en español: si está en inglés se traduce 
if (!$sinDesc($descripcion) && Traductor::esIngles((string)$descripcion)) {
    $claveTrad = 'trad_fail_' . md5((string)$descripcion);
    $tradFalloReciente = isset($_SESSION[$claveTrad]) && (time() - $_SESSION[$claveTrad]) < 3600;

    if (!$tradFalloReciente) {
        $esp = (new Traductor($db->pdo))->aEspanol((string)$descripcion);
        if ($esp !== null) {
            $descripcion = $esp;
            $descTraducida = true;
            $descLog['traduccion'] = 'traducida al español';

            // Guardar la versión en español en la lista del usuario 
            if ($authUser) {
                try {
                    $db->pdo->prepare(
                        "UPDATE listas_lectura SET descripcion = ?
                         WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?)
                           AND (descripcion IS NULL OR descripcion <> ?)"
                    )->execute([$descripcion, $authUser["id"], $id_externo, $titulo, $descripcion]);
                } catch (Throwable $e) {}
            }
        } else {
            $descLog['traduccion'] = 'no se pudo traducir (¿falta deepl_key o se agotó el límite?)';
            $_SESSION[$claveTrad] = time();   // no insistir durante una hora
        }
    }
}

// Portada de rescate: solo si no hay ninguna, y solo se acepta si el libro encontrado es el mismo (título y autor coinciden)
if (strpos($portada, 'placehold.co') !== false) {
    $claveCov = 'cov_fail_' . md5($id_externo . '|' . $titulo);
    $covFalloReciente = !isset($_GET['debug']) && isset($_SESSION[$claveCov]) && (time() - $_SESSION[$claveCov]) < 6 * 3600;

    if (!$covFalloReciente) {
        $cov = PortadasVerificadas::buscar(
            $titulo,
            ($autor !== "Autor desconocido") ? $autor : '',
            PortadasVerificadas::esIsbn((string)$id_externo) ? (string)$id_externo : ''
        );
        if ($cov) {
            $portada = $cov['url'];
        } else {
            $_SESSION[$claveCov] = time();   
        }
    }
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

        // Páginas: se conserva el progreso ya guardado (antes se ponía a 0 al cambiar de estado)
        $stPag = $db->pdo->prepare("SELECT paginas_totales, paginas_leidas FROM listas_lectura WHERE id = ? AND usuario_id = ?");
        $stPag->execute([$registro["id"], $authUser["id"]]);
        $actPag = $stPag->fetch(PDO::FETCH_ASSOC) ?: [];

        $pagTotales = (int)($actPag["paginas_totales"] ?? 0);
        if ($pagTotales <= 0) $pagTotales = $paginasTotalesAPI;
        $pagLeidas = (int)($actPag["paginas_leidas"] ?? 0);

        if ($estado === "leido") {
            $pagLeidas = $pagTotales;
        } elseif (in_array($estado, ["guardado", "tbr"], true)) {
            $pagLeidas = 0;
        }

        if ($pagTotales > 0) {
            $listaService->actualizarPaginas($authUser["id"], $registro["id"], $pagTotales, min($pagLeidas, $pagTotales));
        }

        // Detectar el género automáticamente al guardar el libro 
        try {
            $colGen = columnaGeneros($db->pdo);
            if ($colGen !== null) {
                $gens = detectarGenerosLibro($libroAPI, $titulo, $autor, (string)$id_externo, (string)$descripcion, true);
                guardarGenerosSiVacios($db->pdo, (int)$authUser["id"], (int)$registro["id"], $colGen, $gens);
            }
        } catch (Throwable $e) {}
    }

    // Mensaje de confirmación que se mostrará al recargar la ficha
    $_SESSION['libro_msg'] = "Libro guardado como «" . ($estados[$estado][1] ?? $estado) . "».";

    // Volver a la ficha del libro (así se puede puntuar y reseñar justo después)
    $volver = basename($_SERVER['SCRIPT_NAME']) . "?id=" . urlencode((string)$id_externo);
    if (!empty($descripcionURL)) {
        $volver .= "&desc=" . urlencode($descripcionURL);
    }
    header("Location: " . $volver . "#puntuacion");
    exit;
}

// Datos del usuario sobre este libro 
$estadoActual = null;
$libroUser = null;
$puntuacionUsuario = null;
$miReseña = '';
$colGenero = null;
if ($authUser) {
    $stmt = $db->pdo->prepare("SELECT * FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) LIMIT 1");
    $stmt->execute([$authUser["id"], $id_externo, $titulo]);
    $libroUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($libroUser) $estadoActual = $libroUser["estado"];

    // Páginas totales automáticas: si el libro guardado no las tiene y la API sí, se completan solas
    if ($libroUser && (int)$libroUser["paginas_totales"] <= 0 && $paginasTotalesAPI > 0) {
        try {
            $leidasAct = (int)($libroUser["paginas_leidas"] ?? 0);
            $progresoAct = (int)($libroUser["progreso"] ?? 0);
            if ($leidasAct <= 0 && $progresoAct > 0) {
                $leidasAct = (int)round($paginasTotalesAPI * $progresoAct / 100);
            }
            $leidasAct = min($leidasAct, $paginasTotalesAPI);
            $listaService->actualizarPaginas($authUser["id"], $libroUser["id"], $paginasTotalesAPI, $leidasAct);
            $libroUser["paginas_totales"] = $paginasTotalesAPI;
            $libroUser["paginas_leidas"]  = $leidasAct;
        } catch (Throwable $e) {}
    }

    // Libros ya guardados sin género: se detecta al abrir la ficha
    if ($libroUser) {
        try {
            $colGenero = columnaGeneros($db->pdo);
            if ($colGenero !== null && generoPendiente((string)($libroUser[$colGenero] ?? ''))) {
                $gens = detectarGenerosLibro($libroAPI, $titulo, $autor, (string)$id_externo, (string)$descripcion, false);
                if ($gens) {
                    guardarGenerosSiVacios($db->pdo, (int)$authUser["id"], (int)$libroUser["id"], $colGenero, $gens);
                    $libroUser[$colGenero] = implode(', ', $gens);
                }
            }
        } catch (Throwable $e) {}
    }

    $puntuacionUsuario = $ratingService->obtenerPuntuacionUsuario($authUser["id"], $id_externo);
    $miReseña = $reviewService->obtenerReseñaUsuario($authUser["id"], $id_externo);
    $miReseñaSpoiler = $reviewService->reseñaUsuarioEsSpoiler($authUser["id"], $id_externo);
}

$paginasTotales = $libroUser ? (int)$libroUser["paginas_totales"] : 0;
$paginasLeidas  = $libroUser ? (int)$libroUser["paginas_leidas"] : 0;
$progreso = 0;
if ($libroUser) {
    $progreso = $paginasTotales > 0 ? round(($paginasLeidas / $paginasTotales) * 100) : (int)($libroUser["progreso"] ?? 0);
}
$paginasMostrar = $paginasTotales > 0 ? $paginasTotales : $paginasTotalesAPI;

// Géneros actuales del libro
$genActuales = [];
if ($libroUser && $colGenero !== null) {
    $genActuales = normalizarGeneros(separarGeneros((string)($libroUser[$colGenero] ?? '')));
}

$reseñas = $reviewService->obtenerReseñas($id_externo);
$medias  = $ratingService->obtenerMedias($id_externo);

// URL de portada segura para usarla dentro de CSS url
$portadaCss = str_replace(["'", '"', '(', ')', ' ', "\n"], ['%27', '%22', '%28', '%29', '%20', ''], $portada);

$metricas = [
    'estrellas'  => ['⭐', 'General',           'estrellas',  '★'],
    'romance'    => ['💖', 'Romance',           'romance',    '💖'],
    'spicy'      => ['🌶️', 'Spicy',             'spicy',      '🌶️'],
    'lagrimas'   => ['💧', 'Lágrimas / Drama',  'lagrimas',   '💧'],
    'plot_twist' => ['⚡', 'Plot Twist',        'plot_twist', '⚡'],
];

// Amigos que tienen este libro
$amigosConLibro = $authUser
    ? (new ComunidadService($db->pdo))->amigosConLibro((int)$authUser["id"], (string)$id_externo, $titulo)
    : [];

// Tablero de vibes y fanarts
$vibesService = new VibesService($db->pdo);
if ($authUser) {
    $vibesService->autocompletar(
        (string)$id_externo,
        (string)$titulo,
        ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '',
        preg_match('/^(\d{9}[\dXx]|\d{13})$/', (string)$id_externo) ? (string)$id_externo : '',
        (string)$descripcion
    );
    $descLog['deviantart'] = $vibesService->estado ?? 'FALTA actualizar src/VibesService.php (no tiene la propiedad estado)';   // se ve con ?debug
}
$vibes = $vibesService->listar((string)$id_externo);
$vibesPines    = array_values(array_filter($vibes, fn($v) => ($v['tipo'] ?? 'imagen') === 'pin'));
$vibesImagenes = array_values(array_filter($vibes, fn($v) => ($v['tipo'] ?? 'imagen') !== 'pin'));

$vbOnError = isset($_GET['debug'])
    ? "this.style.outline='4px solid red'"
    : "var f=this.closest('.vb-item'),m=f.parentNode;f.remove();if(!m.querySelector('.vb-item')){m.outerHTML='<p class=&quot;lb-empty&quot;>No se han podido cargar las imágenes.</p>';}";
$descLog['vibes_en_bd'] = count($vibes) . ' imágenes' . (isset($vibes[0])
    ? ' · la primera mide ' . mb_strlen($vibes[0]['imagen_url']) . ' caracteres: ' . mb_substr($vibes[0]['imagen_url'], 0, 110) . '…'
    : '');

$vibesMsg = $_SESSION['vibes_msg'] ?? null;
unset($_SESSION['vibes_msg']);

$libroMsg = $_SESSION['libro_msg'] ?? null;
unset($_SESSION['libro_msg']);

if (empty($_SESSION['csrf_vibes'])) {
    $_SESSION['csrf_vibes'] = bin2hex(random_bytes(16));
}
$csrfVibes = $_SESSION['csrf_vibes'];

if (empty($_SESSION['csrf_progreso'])) {
    $_SESSION['csrf_progreso'] = bin2hex(random_bytes(16));
}

$misVibes = $authUser
    ? count(array_filter($vibes, fn($v) => (int)$v['usuario_id'] === (int)$authUser['id']))
    : 0;

// Búsqueda lista en Pinterest / Google / DeviantArt
$tituloBusqueda = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
$autorBusqueda  = ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '';
$qFanart = urlencode(trim($tituloBusqueda . ' ' . $autorBusqueda . ' fanart'));

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?></title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= @filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="/Reads/public/main.js"></script>
    <style>
        .lb-spoiler-check { display:flex; align-items:center; gap:8px; margin:10px 0 14px; font-size:.9rem; font-weight:600; cursor:pointer; }
        .lb-spoiler-check input { width:auto; margin:0; }
        .lb-spoiler-tag { display:inline-block; margin-left:8px; padding:2px 10px; border-radius:999px; background:#fff3cd; color:#7a5b00; font-size:.75rem; font-weight:700; }
        .lb-spoiler { margin-top:6px; }
        .lb-spoiler summary { cursor:pointer; font-size:.85rem; font-weight:600; opacity:.8; }
        .lb-spoiler[open] summary { margin-bottom:6px; }
    </style>
</head>

<body class="page-libro">
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
                <p class="lb-author">
                    <?php if ($autor === 'Autor desconocido'): ?>
                         Autor desconocido
                    <?php else:
                        $enlacesAutor = [];
                        foreach (array_filter(array_map('trim', explode(',', $autor))) as $aNom) {
                            $enlacesAutor[] = '<a href="autor.php?nombre=' . urlencode($aNom) . '" class="lb-author-link">' . htmlspecialchars($aNom) . '</a>';
                        }
                        echo implode(', ', $enlacesAutor);
                    endif; ?>
                </p>

                <?php if ($paginasMostrar > 0 || $idioma !== '' || !empty($genActuales)): ?>
                <div class="lb-chips">
                    <?php if ($paginasMostrar > 0): ?><span class="lb-chip"><?= number_format($paginasMostrar, 0, '', '.') ?> páginas</span><?php endif; ?>
                    <?php if ($idioma !== ''): ?><span class="lb-chip"><?= htmlspecialchars(strtoupper($idioma)) ?></span><?php endif; ?>
                    <?php foreach ($genActuales as $gChip): ?><span class="lb-chip"><?= estiloGenero($gChip)[0] ?> <?= htmlspecialchars($gChip) ?></span><?php endforeach; ?>
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

            <?php if ($libroMsg): ?>
                <p class="vb-msg ok"><?= htmlspecialchars($libroMsg) ?></p>
            <?php endif; ?>

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

            <section class="lb-card" id="sagaBox" style="display:none;"
                data-titulo="<?= htmlspecialchars($titulo) ?>" data-autor="<?= htmlspecialchars($autor) ?>">
                <h2 id="sagaTitulo">Saga</h2>
                <p class="lb-empty" id="sagaSub"></p>
                <ol class="lb-saga" id="sagaLista"></ol>
            </section>

            <!-- Vibes y fanarts -->
            <section class="lb-card" id="vibes">
                <div class="vb-head">
                    <h2>🎨 Vibes y fanarts</h2>
                    <div class="vb-buscar">
                        <span>Buscar inspiración:</span>
                        <a href="https://www.pinterest.com/search/pins/?q=<?= $qFanart ?>" target="_blank" rel="noopener">Pinterest</a>
                        <a href="https://www.google.com/search?tbm=isch&q=<?= $qFanart ?>" target="_blank" rel="noopener">Google</a>
                        <a href="https://www.deviantart.com/search?q=<?= $qFanart ?>" target="_blank" rel="noopener">DeviantArt</a>
                    </div>
                </div>

                <?php if ($vibesMsg): ?>
                    <p class="vb-msg <?= $vibesMsg[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($vibesMsg[1]) ?></p>
                <?php endif; ?>

                <?php if (empty($vibes)): ?>
                    <p class="lb-empty">Todavía no hay imágenes. Busca fanarts o moodboards de este libro y añade el primero.</p>
                <?php else: ?>
                    <?php if ($vibesImagenes): ?>
                    <div class="vb-muro">
                        <?php foreach ($vibesImagenes as $v):
                            $esMia = $authUser && (int)$v['usuario_id'] === (int)$authUser['id']; ?>
                            <figure class="vb-item">
                                <img src="<?= htmlspecialchars($v['imagen_url']) ?>"
                                     alt="<?= htmlspecialchars($v['nota'] ?: 'Fanart de ' . $titulo) ?>"
                                     loading="lazy" referrerpolicy="no-referrer"
                                     onerror="<?= $vbOnError ?>">
                                <figcaption class="vb-pie">
                                    <div class="vb-texto">
                                        <?php if (!empty($v['nota'])): ?>
                                            <span class="vb-nota"><?= htmlspecialchars($v['nota']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($v['credito']) || !empty($v['enlace_origen'])): ?>
                                            <?php $textoCredito = !empty($v['credito']) ? $v['credito'] : 'Ver fuente'; ?>
                                            <?php if (!empty($v['enlace_origen'])): ?>
                                                <a class="vb-credito" href="<?= htmlspecialchars($v['enlace_origen']) ?>" target="_blank" rel="noopener nofollow ugc">🎨 <?= htmlspecialchars($textoCredito) ?></a>
                                            <?php else: ?>
                                                <span class="vb-credito">🎨 <?= htmlspecialchars($textoCredito) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($authUser): ?>
                                        <form method="POST" action="vibes_accion.php" class="vb-mini"
                                              onsubmit="return confirm('<?= $esMia ? '¿Eliminar esta imagen?' : '¿Reportar esta imagen como inapropiada?' ?>');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfVibes) ?>">
                                            <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
                                            <input type="hidden" name="vibe_id" value="<?= (int)$v['id'] ?>">
                                            <input type="hidden" name="accion" value="<?= $esMia ? 'eliminar' : 'reportar' ?>">
                                            <button type="submit" title="<?= $esMia ? 'Eliminar' : 'Reportar' ?>"><?= $esMia ? '🗑️' : '🚩' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($vibesPines): ?>
                        <div class="vb-pines">
                            <?php foreach ($vibesPines as $v):
                                $esMia = $authUser && (int)$v['usuario_id'] === (int)$authUser['id']; ?>
                                <div class="vb-pin">
                                    <a data-pin-do="embedPin" data-pin-width="small" href="<?= htmlspecialchars($v['imagen_url']) ?>"></a>
                                    <?php if ($authUser): ?>
                                        <form method="POST" action="vibes_accion.php" class="vb-pin-acciones"
                                              onsubmit="return confirm('<?= $esMia ? '¿Quitar este pin?' : '¿Reportar este pin como inapropiado?' ?>');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfVibes) ?>">
                                            <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
                                            <input type="hidden" name="vibe_id" value="<?= (int)$v['id'] ?>">
                                            <input type="hidden" name="accion" value="<?= $esMia ? 'eliminar' : 'reportar' ?>">
                                            <button type="submit"><?= $esMia ? '🗑️ Quitar' : '🚩 Reportar' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <script async defer src="https://assets.pinterest.com/js/pinit.js"></script>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($authUser): ?>
                    <details class="vb-add" <?= $vibesMsg && $vibesMsg[0] === 'error' ? 'open' : '' ?>>
                        <summary>＋ Añadir una imagen</summary>
                        <form method="POST" action="vibes_accion.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfVibes) ?>">
                            <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
                            <input type="hidden" name="accion" value="agregar">

                            <label>Dirección de la imagen o enlace de un pin de Pinterest
                                <input type="url" name="imagen_url" required maxlength="2000" placeholder="https://...">
                            </label>
                            <p class="vb-ayuda"><strong>Pinterest:</strong> abre el pin y copia la dirección de la barra del navegador (pinterest.com/pin/...). <strong>Otras webs:</strong> botón derecho sobre la imagen → «Copiar dirección de la imagen». Tiene que empezar por https://</p>

                            <label>Artista (crédito)
                                <input type="text" name="credito" maxlength="120" placeholder="@usuario o nombre del artista">
                            </label>
                            <label>Enlace a la publicación original
                                <input type="url" name="enlace_origen" maxlength="600" placeholder="https://... (opcional, pero se agradece)">
                            </label>
                            <label>Nota
                                <input type="text" name="nota" maxlength="160" placeholder="Ej.: Nora y Cole (opcional)">
                            </label>

                            <p class="vb-ayuda">Solo imágenes aptas para todos los públicos y con crédito al artista. Puedes añadir hasta <?= VibesService::MAX_POR_USUARIO ?> por libro<?= $misVibes > 0 ? " (llevas $misVibes)" : '' ?>.</p>
                            <button type="submit" class="lb-btn">Añadir al tablero</button>
                        </form>
                    </details>
                <?php else: ?>
                    <p class="lb-login-hint"><a href="login.php">Inicia sesión</a> para añadir tus propias imágenes.</p>
                <?php endif; ?>
            </section>

            <?php if ($authUser): ?>
            <section class="lb-card">
                <h2>Tu reseña</h2>
                <form action="guardar_reseña.php" method="POST">
                    <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
                    <textarea name="contenido" rows="4" placeholder="¿Qué te ha parecido?"><?= htmlspecialchars($miReseña) ?></textarea>
                    <input type="hidden" name="spoiler_check" value="1">
                    <label class="lb-spoiler-check">
                        <input type="checkbox" name="spoiler" value="1" <?= !empty($miReseñaSpoiler) ? 'checked' : '' ?>>
                        <span>⚠️ Mi reseña contiene spoilers</span>
                    </label>
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
                            <?php if (!empty($r['spoiler'])): ?>
                                <span class="lb-spoiler-tag">⚠️ Contiene spoilers</span>
                                <details class="lb-spoiler">
                                    <summary>Mostrar reseña (puede destripar el libro)</summary>
                                    <?= nl2br(htmlspecialchars($r['contenido'])) ?>
                                </details>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($r['contenido'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <!-- Columna lateral -->
        <aside class="lb-col">

            <?php if (!empty($amigosConLibro)): ?>
            <section class="lb-card">
                <h2>👥 Tus amigos</h2>
                <?php foreach ($amigosConLibro as $am): ?>
                    <p style="margin:6px 0;"><a href="usuario.php?id=<?= (int)$am['id'] ?>"><?= htmlspecialchars($am['nombre']) ?></a>
                        · <?= htmlspecialchars($am['estado']) ?></p>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($authUser && $libroUser): ?>
            <section class="lb-card">
                <h2>Tu progreso</h2>
                <div class="lb-prog-num">
                    <strong><?= $progreso ?>%</strong>
                    <span><?= $paginasLeidas ?> / <?= $paginasTotales ?> págs.</span>
                </div>
                <div class="lb-bar"><div style="width: <?= (int)$progreso ?>%"></div></div>
                <a href="editar_libro.php?id=<?= (int)$libroUser["id"] ?>&volver=libro" class="lb-link js-editar-progreso" data-id="<?= (int)$libroUser["id"] ?>" data-recargar="1">✏️ Editar progreso</a>
            </section>

            <?php if ($colGenero !== null): ?>
            <section class="lb-card">
                <h2>Géneros</h2>
                <form method="POST" action="guardar_generos.php" class="lb-generos">
                    <input type="hidden" name="registro_id" value="<?= (int)$libroUser["id"] ?>">
                    <input type="hidden" name="libro_ref" value="<?= htmlspecialchars($id_externo) ?>">
                    <div class="lb-generos-chips">
                        <?php foreach (array_keys(reglasGeneros()) as $g): ?>
                            <label class="lb-gen-chip">
                                <input type="checkbox" name="generos[]" value="<?= htmlspecialchars($g) ?>" <?= in_array($g, $genActuales, true) ? 'checked' : '' ?>>
                                <span><?= estiloGenero($g)[0] ?> <?= htmlspecialchars($g) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="lb-empty">Se detectan solos. Si no son correctos, marca hasta 3.</p>
                    <button type="submit" class="lb-btn">Guardar géneros</button>
                </form>
            </section>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($authUser): ?>
            <section class="lb-card" id="puntuacion">
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

            <?php if ($authUser && $libroUser): ?>
            <section class="lb-card">
                <h2>Gestionar libro</h2>

                <?php if ($libroUser["estado"] === "leido" && !empty($libroUser["fecha_fin"])): ?>
                    <p style="margin:0 0 14px; font-size:.9rem;"><strong>Terminado el:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($libroUser["fecha_fin"]))) ?></p>
                <?php endif; ?>

                <?php if ($libroUser["estado"] === "abandonado"): ?>
                    <form method="POST" action="actualizar_progreso.php" style="margin-bottom:16px;">
                        <input type="hidden" name="libro_id" value="<?= (int)$libroUser["id"] ?>">
                        <input type="hidden" name="volver" value="libro">
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
        </aside>
    </div>
</div>

<?php if ($authUser): ?>
    <?php include __DIR__ . '/progreso_modal.php'; ?>
<?php endif; ?>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">

        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>

        <a href="perfil.php" class="nav-card-float">
            <span class="nav-icon">👤</span>
            <span>Mi perfil</span>
        </a>

        <a href="biblioteca.php" class="nav-card-float">
            <span class="nav-icon">📚</span>
            <span>Mi estantería</span>
        </a>

        <a href="estadisticas.php" class="nav-card-float">
            <span class="nav-icon">📊</span>
            <span>Estadísticas</span>
        </a>

        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>
        </a>

        <a href="buscar.php" class="nav-card-float">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>

        <a href="comunidad.php" class="nav-card-float">
            <span class="nav-icon">👥</span>
            <span>Comunidad</span>
        </a>

        <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
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

    // Géneros: máximo 3 marcados a la vez
    document.querySelectorAll('.lb-generos').forEach(f => {
        const limitar = () => {
            const n = f.querySelectorAll('input:checked').length;
            f.querySelectorAll('input[type=checkbox]:not(:checked)').forEach(i => { i.disabled = n >= 3; });
        };
        f.addEventListener('change', limitar);
        limitar();
    });

    // Saga (se carga aparte para no frenar la página)
    const sagaBox = document.getElementById('sagaBox');
    if (sagaBox) {
        const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const qs = new URLSearchParams({ titulo: sagaBox.dataset.titulo, autor: sagaBox.dataset.autor });

        fetch('api_saga.php?' + qs)
            .then(r => r.ok ? r.json() : null)
            .then(d => {
                const s = d && d.saga;
                if (!s) return;

                document.getElementById('sagaTitulo').textContent = 'Saga: ' + s.nombre;
                const partes = [];
                if (s.numero) partes.push('Este es el libro ' + s.numero + ' de la saga');
                if (s.libros.length > 1) partes.push(s.libros.length + ' libros en total');
                document.getElementById('sagaSub').textContent = partes.join(' · ');

                document.getElementById('sagaLista').innerHTML = s.libros.map(l => {
                    const num = l.numero ?? '·';
                    const marca = l.lo_tienes ? ' <small>✅ en tu biblioteca</small>' : '';
                    const cuerpo = l.actual
                        ? `<strong>${esc(l.titulo)}</strong> <small>(este libro)</small>`
                        : `<a href="${esc(l.url)}">${esc(l.titulo)}</a>${marca}`;
                    return `<li class="${l.actual ? 'actual' : ''}"><span class="n">${esc(num)}</span><span>${cuerpo}</span></li>`;
                }).join('');

                sagaBox.style.display = '';
            })
            .catch(() => {});
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
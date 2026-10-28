<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/ListaService.php";
require_once "../src/PaginasService.php";

header('Content-Type: application/json; charset=utf-8');

function responder(array $datos, int $codigo = 200, bool $bonito = false): void {
    http_response_code($codigo);
    $flags = JSON_UNESCAPED_UNICODE | ($bonito ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0);
    echo json_encode($datos, $flags);
    exit;
}

$usuario = Auth::usuario();
if (!$usuario) {
    responder(['ok' => false, 'error' => 'Tu sesión ha caducado. Vuelve a iniciar sesión.'], 401);
}
if (session_status() === PHP_SESSION_NONE) @session_start();

// Se leen los datos de sesión y se libera el bloqueo: así las búsquedas lentas no frenan el resto de la web
$csrfSesion = (string)($_SESSION['csrf_progreso'] ?? '');
$sesionCopia = $_SESSION;
@session_write_close();

function marcarFalloPaginas(int $id, bool $temporal = false): void {
    @session_start();
    // Si el fallo fue temporal (Google caído) solo se recuerda 5 minutos; si no, 1 hora
    $_SESSION['pag_fail_' . $id] = time() - ($temporal ? 3300 : 0);
    @session_write_close();
}

$db = new Database();
$listaService = new ListaService();
$uid = (int)$usuario["id"];

function cargarLibro(PDO $pdo, int $uid, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM listas_lectura WHERE id = ? AND usuario_id = ? LIMIT 1");
    $st->execute([$id, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function datosLibro(array $l): array {
    return [
        'id'              => (int)$l['id'],
        'titulo'          => (string)$l['titulo'],
        'autores'         => (string)($l['autores'] ?? ''),
        'portada'         => (string)($l['portada'] ?? ''),
        'estado'          => (string)($l['estado'] ?? ''),
        'paginas_totales' => (int)($l['paginas_totales'] ?? 0),
        'paginas_leidas'  => (int)($l['paginas_leidas'] ?? 0),
        'progreso'        => (int)($l['progreso'] ?? 0),
    ];
}

// Lanza todas las peticiones en paralelo. Devuelve [clave => ['http' => int, 'json' => array|null, 'error' => string]]
function peticionesParalelas(array $urls, int $timeout = 5): array {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,          
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,      
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }

    $running = null;
    do {
        $estado = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running && $estado === CURLM_OK);

    $salida = [];
    foreach ($handles as $k => $ch) {
        $cuerpo = curl_multi_getcontent($ch);
        $http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json   = ($http >= 200 && $http < 300 && $cuerpo) ? (json_decode($cuerpo, true) ?: null) : null;
        $salida[$k] = ['http' => $http, 'json' => $json, 'error' => curl_error($ch)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $salida;
}

function peticionesConReintento(array $urls, int $timeout = 5, int $reintentos = 2): array {
    $r = peticionesParalelas($urls, $timeout);
    for ($i = 1; $i <= $reintentos; $i++) {
        $pendientes = [];
        foreach ($r as $k => $x) {
            // Solo se reintenta lo que puede arreglarse en segundos 
            if ($x['http'] === 0 || $x['http'] >= 500) $pendientes[$k] = $urls[$k];
        }
        if (!$pendientes) break;
        usleep(700000 * $i);   
        foreach (peticionesParalelas($pendientes, $timeout) as $k => $x) $r[$k] = $x;
    }
    return $r;
}

function normalizarTexto(string $t): string {
    $t = strtr(mb_strtolower($t, 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $t)));
}

function pareceMismoTitulo(string $candidato, array $variantesNorm): bool {
    $c = normalizarTexto($candidato);
    if ($c === '') return false;
    foreach ($variantesNorm as $v) {
        if ($v === '') continue;
        similar_text($c, $v, $pct);
        if ($pct >= 70) return true;
        if (strlen($v) >= 5 && (str_contains($c, $v) || str_contains($v, $c))) return true;
    }
    return false;
}

function pareceMismoAutor(array $autoresCandidato, string $apellido): bool {
    if ($apellido === '') return true;   // sin autor conocido no se puede comprobar
    foreach ($autoresCandidato as $a) {
        if (str_contains(normalizarTexto((string)$a), $apellido)) return true;
    }
    return false;
}

function paginasValidas($n): int {
    $n = (int)$n;
    return ($n >= 20 && $n <= 5000) ? $n : 0;
}

function paginasDeVolumen(array $vi): int {
    $n = paginasValidas($vi['pageCount'] ?? 0);
    return $n > 0 ? $n : paginasValidas($vi['printedPageCount'] ?? 0);
}

function paginasDeFichaOL(array $f): int {
    $n = paginasValidas($f['number_of_pages'] ?? 0);
    if ($n > 0) return $n;
    if (preg_match('/(\d{2,4})\s*(p\b|pp\b|p\.|pág|pag)/iu', (string)($f['pagination'] ?? ''), $m)) {
        return paginasValidas($m[1]);
    }
    return 0;
}

function isbnsDeVolumen(array $vi): array {
    $salida = [];
    foreach (($vi['industryIdentifiers'] ?? []) as $x) {
        $v = preg_replace('/[^0-9Xx]/', '', (string)($x['identifier'] ?? ''));
        if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', $v)) $salida[] = strtoupper($v);
    }
    return $salida;
}

// Devuelve las páginas totales (0 si no se encuentran)
function buscarPaginasTotales(array $libro, ?array &$log = null): int {
    $log = ['peticiones' => [], 'descartados' => [], 'candidatas' => [], 'isbn_extra' => [], 'resultado' => 0];

    $titulo = (string)$libro['titulo'];
    $ref    = trim((string)($libro['libro_id'] ?? ''));

    // Autor principal y su apellido
    $autorPrincipal = trim(explode(',', (string)($libro['autores'] ?? ''))[0]);
    if ($autorPrincipal === 'Autor desconocido') $autorPrincipal = '';
    $partesAutor = array_values(array_filter(explode(' ', normalizarTexto($autorPrincipal))));
    $apellido = $partesAutor ? end($partesAutor) : '';

    // Variantes del título
    $limpio = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
    $variantes = [];
    foreach (preg_split('/\s*\/\s*/u', $limpio) as $parte) {
        $parte = trim($parte);
        if ($parte !== '') $variantes[] = $parte;
        foreach ([' - ', '. ', ': '] as $sep) {
            $corte = trim(explode($sep, $parte)[0]);
            if ($corte !== '' && mb_strlen($corte) >= 3) $variantes[] = $corte;
        }
    }
    $variantes = array_slice(array_values(array_unique($variantes)), 0, 3);
    if (!$variantes) $variantes = [$limpio !== '' ? $limpio : $titulo];
    $variantesNorm = array_map('normalizarTexto', $variantes);

    $clave = function_exists('config') ? (string)config('google_books_key', '') : '';
    $k = $clave !== '' ? '&key=' . urlencode($clave) : '';

    $log['libro_id'] = $ref;
    $log['variantes_titulo'] = $variantes;
    $log['autor'] = $autorPrincipal . ' (apellido buscado: ' . $apellido . ')';
    $log['clave_google_configurada'] = $clave !== '';

    $esIsbn = (bool)preg_match('/^(\d{9}[\dXx]|\d{13})$/', $ref);
    $esIdGoogle = ($ref !== '' && !$esIsbn && !str_starts_with($ref, 'gr_') && preg_match('/^[A-Za-z0-9_-]{8,16}$/', $ref));

    $urls = [];
    if ($esIdGoogle) $urls['g_id'] = "https://www.googleapis.com/books/v1/volumes/" . urlencode($ref) . ($k ? '?' . ltrim($k, '&') : '');
    if ($esIsbn) {
        $urls['g_isbn']  = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($ref) . "&maxResults=3" . $k;
        $urls['ol_isbn'] = "https://openlibrary.org/api/books?bibkeys=ISBN:" . urlencode($ref) . "&format=json&jscmd=data";
        // Alternativa por si la consulta "isbn:" falla: el ISBN como texto sin operador
        $urls['g_isbn_q']  = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($ref) . "&maxResults=3" . $k;
    }
    foreach (array_slice($variantes, 0, 2) as $i => $v) {
        $q = 'intitle:"' . $v . '"' . ($autorPrincipal !== '' ? ' inauthor:"' . $autorPrincipal . '"' : '');
        $urls["g_t$i"] = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($q) . "&printType=books&maxResults=15" . $k;
        // Búsqueda sencilla, sin operadores (más tolerante cuando "intitle/inauthor" no devuelve nada)
        $urls["g_q$i"] = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode(trim($v . ' ' . $autorPrincipal)) . "&printType=books&maxResults=15" . $k;

        $ol = ['title' => $v, 'limit' => 10, 'fields' => 'key,title,author_name,number_of_pages_median,isbn'];
        if ($autorPrincipal !== '') $ol['author'] = $autorPrincipal;
        $urls["ol_t$i"] = 'https://openlibrary.org/search.json?' . http_build_query($ol);
    }

    if ($clave === '') {
        foreach (array_keys($urls) as $kk) {
            if (str_starts_with($kk, 'g_')) unset($urls[$kk]);
        }
        $log['aviso_google'] = 'No hay google_books_key en config/config.local.php: Google Books se omite.';
    }

    $R = peticionesConReintento($urls, 5, 2);
    foreach ($R as $kk => $x) {
        $n = count($x['json']['items'] ?? $x['json']['docs'] ?? []);
        $detalle = str_starts_with($kk, 'ol_isbn')
            ? ($x['json'] !== null ? ' · responde con datos del libro' : ' · sin datos')
            : ($x['json'] !== null ? " · $n resultados" : ' · sin datos');
        $log['peticiones'][$kk] = 'HTTP ' . $x['http'] . ($x['error'] ? ' (' . $x['error'] . ')' : '') . $detalle;
        if ($x['http'] === 0 || $x['http'] >= 500) $log['fallo_temporal'] = true;
        if ($x['http'] === 429 && str_starts_with($kk, 'g_')) {
            $log['aviso_google'] = 'Google respondió 429: se ha agotado la cuota diaria de tu clave (o la clave no es válida para Books). Se renueva cada día.';
        }
    }

    $isbnsExtra = [];

    // Resultados exactos (mismo id o mismo ISBN): se confía directamente
    if (!empty($R['g_id']['json']['volumeInfo'])) {
        $vi = $R['g_id']['json']['volumeInfo'];
        $n = paginasDeVolumen($vi);
        $log['g_id_detalle'] = 'pageCount=' . ($vi['pageCount'] ?? 'no') . ', printedPageCount=' . ($vi['printedPageCount'] ?? 'no');
        if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Google (id del libro)'; return $n; }
        foreach (isbnsDeVolumen($vi) as $i) $isbnsExtra[$i] = true;
    }
    foreach (['g_isbn', 'g_isbn_nk', 'g_isbn_q'] as $kg) {
        foreach (($R[$kg]['json']['items'] ?? []) as $it) {
            $vi = $it['volumeInfo'] ?? [];
            // En la búsqueda sin operador solo vale el volumen que tenga ese mismo ISBN
            if ($kg === 'g_isbn_q' && !in_array(strtoupper($ref), isbnsDeVolumen($vi), true)) continue;
            $n = paginasDeVolumen($vi);
            $log['google_isbn_detalle'][] = "$kg: «" . ($vi['title'] ?? '?') . "» pageCount=" . ($vi['pageCount'] ?? 'no') . ", printedPageCount=" . ($vi['printedPageCount'] ?? 'no');
            if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Google (ISBN)'; return $n; }
        }
    }
    if (!empty($R['ol_isbn']['json']) && is_array($R['ol_isbn']['json'])) {
        $ficha = reset($R['ol_isbn']['json']);
        $log['ol_isbn_detalle'] = 'title=' . ($ficha['title'] ?? '?') . ', number_of_pages=' . ($ficha['number_of_pages'] ?? 'no') . ', pagination=' . ($ficha['pagination'] ?? 'no');
        $n = paginasDeFichaOL(is_array($ficha) ? $ficha : []);
        if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Open Library (ISBN)'; return $n; }
    }

    // Búsquedas por título y autor: solo valen libros que coinciden; se toma la mediana
    $candidatas = [];
    foreach ($R as $kk => $x) {
        $resp = $x['json'];
        if (!$resp) continue;

        if (str_starts_with($kk, 'g_t') || str_starts_with($kk, 'g_q')) {
            foreach (($resp['items'] ?? []) as $it) {
                $vi = $it['volumeInfo'] ?? [];
                $tit = (string)($vi['title'] ?? '');
                $okT = pareceMismoTitulo($tit, $variantesNorm);
                $okA = pareceMismoAutor($vi['authors'] ?? [], $apellido);
                if (!$okT || !$okA) {
                    if (count($log['descartados']) < 10) $log['descartados'][] = "Google: «$tit» de " . implode(', ', $vi['authors'] ?? ['?']) . ' → ' . (!$okT ? 'título distinto' : 'autor distinto');
                    continue;
                }
                $n = paginasDeVolumen($vi);
                if ($n > 0) $candidatas[] = $n;
                else foreach (isbnsDeVolumen($vi) as $i) $isbnsExtra[$i] = true;   // mismo libro sin páginas: se probará por ISBN en Open Library
            }
        } elseif (str_starts_with($kk, 'ol_t')) {
            foreach (($resp['docs'] ?? []) as $d) {
                $tit = (string)($d['title'] ?? '');
                $okT = pareceMismoTitulo($tit, $variantesNorm);
                $okA = pareceMismoAutor($d['author_name'] ?? [], $apellido);
                if (!$okT || !$okA) {
                    if (count($log['descartados']) < 10) $log['descartados'][] = "OpenLibrary: «$tit» de " . implode(', ', $d['author_name'] ?? ['?']) . ' → ' . (!$okT ? 'título distinto' : 'autor distinto');
                    continue;
                }
                $n = paginasValidas($d['number_of_pages_median'] ?? 0);
                if ($n > 0) $candidatas[] = $n;
                foreach (array_slice($d['isbn'] ?? [], 0, 4) as $i) {
                    $i = strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$i));
                    if (preg_match('/^(\d{9}[\dX]|\d{13})$/', $i)) $isbnsExtra[$i] = true;
                }
            }
        }
    }

    // Si no hay páginas, se prueban los ISBN de ese mismo libro en Open Library 
    if (!$candidatas && $isbnsExtra) {
        $lista = array_slice(array_keys($isbnsExtra), 0, 10);
        $log['isbn_extra'] = $lista;
        $claves = implode(',', array_map(fn($i) => 'ISBN:' . $i, $lista));
        $R2 = peticionesConReintento([
            'ol_isbns' => 'https://openlibrary.org/api/books?bibkeys=' . $claves . '&format=json&jscmd=data',
        ], 6);
        $log['peticiones']['ol_isbns'] = 'HTTP ' . $R2['ol_isbns']['http'] . ($R2['ol_isbns']['error'] ? ' (' . $R2['ol_isbns']['error'] . ')' : '');
        foreach (($R2['ol_isbns']['json'] ?? []) as $claveIsbn => $ficha) {
            if (!is_array($ficha)) continue;
            $log['ol_isbns_detalle'][] = $claveIsbn . ': number_of_pages=' . ($ficha['number_of_pages'] ?? 'no') . ', pagination=' . ($ficha['pagination'] ?? 'no');
            $n = paginasDeFichaOL($ficha);
            if ($n > 0) $candidatas[] = $n;
        }
    }

    $log['candidatas'] = $candidatas;
    if ($candidatas) {
        sort($candidatas);
        $n = (int)$candidatas[intdiv(count($candidatas), 2)];
        $log['resultado'] = $n;
        $log['fuente'] = 'mediana de ediciones del mismo libro';
        return $n;
    }
    return 0;
}

/*  GET: datos del libro o búsqueda de páginas  */
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $id = (int)($_GET["id"] ?? 0);

    if ($id <= 0 && !empty($_GET['titulo'])) {
        $stT = $db->pdo->prepare("SELECT id FROM listas_lectura WHERE usuario_id = ? AND titulo LIKE ? ORDER BY id DESC LIMIT 1");
        $stT->execute([$uid, '%' . trim($_GET['titulo']) . '%']);
        $id = (int)$stT->fetchColumn();
    }

    $libro = cargarLibro($db->pdo, $uid, $id);
    if (!$libro) {
        responder([
            'ok'    => false,
            'error' => 'Libro no encontrado.',
            'version_archivo' => 'con-busqueda-por-titulo',
            'id_usado'        => $id,
            'titulo_pedido'   => (string)($_GET['titulo'] ?? ''),
            'tu_usuario_id'   => $uid,
        ], 404, isset($_GET['debug']));
    }

    $claveFallo = 'pag_fail_' . $id;
    $falloReciente = isset($sesionCopia[$claveFallo]) && (time() - $sesionCopia[$claveFallo]) < 3600;

    // Búsqueda de las páginas totales
    if (($_GET['accion'] ?? '') === 'buscar') {
        $debug  = isset($_GET['debug']);         
        $forzar = !empty($_GET['forzar']) || $debug;
        $log = null;

        if ((int)$libro['paginas_totales'] <= 0 && ($forzar || !$falloReciente)) {
            $total = buscarPaginasTotales($libro, $log);
            if ($total > 0) {
                $leidas = (int)($libro['paginas_leidas'] ?? 0);
                $pct    = (int)($libro['progreso'] ?? 0);
                if ($leidas <= 0 && $pct > 0) $leidas = (int)round($total * $pct / 100);
                $listaService->actualizarPaginas($uid, $id, $total, min($leidas, $total));
                $libro = cargarLibro($db->pdo, $uid, $id) ?: $libro;
            } else {
                marcarFalloPaginas($id, !empty($log['fallo_temporal']));
            }
        } elseif ($debug) {
            $log = ['aviso' => 'Este libro ya tiene páginas totales guardadas (' . (int)$libro['paginas_totales'] . ') o hay un fallo reciente; se omitió la búsqueda.'];
        }

        $resp = ['ok' => true, 'encontrado' => (int)$libro['paginas_totales'] > 0] + datosLibro($libro);
        if ($debug) $resp['diagnostico'] = $log;
        responder($resp, 200, $debug);
    }

    responder([
        'ok'     => true,
        'buscar' => ((int)$libro['paginas_totales'] <= 0 && !$falloReciente),
    ] + datosLibro($libro));
}

/*  POST: guardar progreso  */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

if (!hash_equals($csrfSesion, (string)($_POST['csrf'] ?? ''))) {
    responder(['ok' => false, 'error' => 'Petición no válida. Recarga la página e inténtalo de nuevo.'], 403);
}

$id = (int)($_POST["id"] ?? 0);
$libro = cargarLibro($db->pdo, $uid, $id);
if (!$libro) responder(['ok' => false, 'error' => 'Libro no encontrado.'], 404);

$accion = $_POST["accion"] ?? 'guardar';

// Marcar como terminado
if ($accion === 'terminar') {
    $listaService->cambiarEstado($uid, $id, 'leido');
    $libro = cargarLibro($db->pdo, $uid, $id) ?: $libro;
    responder(['ok' => true, 'terminado' => true] + datosLibro($libro));
}

// Guardar progreso (por páginas o por porcentaje)
$modo  = ($_POST['modo'] ?? 'porcentaje') === 'paginas' ? 'paginas' : 'porcentaje';
$valor = max(0, (int)($_POST['valor'] ?? 0));

// Páginas totales
$total = (int)($libro['paginas_totales'] ?? 0);
$manual = (int)($_POST['paginas_totales'] ?? 0);
if ($manual > 0 && ($modo === 'paginas' || $total <= 0)) {
    $total = min($manual, 100000);
}

if ($modo === 'paginas') {
    if ($total <= 0) {
        responder(['ok' => false, 'error' => 'No se conoce el número de páginas. Usa el porcentaje.'], 422);
    }
    $leidas = min($valor, $total);
    $listaService->actualizarPaginas($uid, $id, $total, $leidas);
} else {
    $pct = min(100, $valor);
    if ($total > 0) {
        $listaService->actualizarPaginas($uid, $id, $total, (int)round($total * $pct / 100));
    } else {
        $listaService->actualizarProgreso($uid, $id, $pct);
    }
}

$libro = cargarLibro($db->pdo, $uid, $id) ?: $libro;
responder(['ok' => true] + datosLibro($libro));
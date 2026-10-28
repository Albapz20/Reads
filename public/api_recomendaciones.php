<?php
ini_set('display_errors', '0');
@set_time_limit(45);

require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/RecomendacionesService.php";

header('Content-Type: application/json; charset=utf-8');

$usuario = Auth::usuario();
if (!$usuario) {
    http_response_code(401);
    echo json_encode(['basadoEn' => [], 'libros' => []]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$db     = new Database();
$year   = (int)date("Y");
$userId = (int)$usuario["id"];
$debug  = isset($_GET['debug']);

/* Últimos libros leídos */
$stmt = $db->pdo->prepare(
    "SELECT libro_id FROM listas_lectura
     WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
     ORDER BY fecha_fin DESC, id DESC LIMIT " . RecomendacionesService::NUM_BASE
);
$stmt->execute([$userId, $year]);
$idsBase = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($idsBase)) {
    echo json_encode(['basadoEn' => [], 'libros' => []]);
    exit;
}

/* Idioma elegido en Ajustes */
$stmtIdioma = $db->pdo->prepare("SELECT idioma_lectura FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
$stmtIdioma->execute([$userId]);
$idioma = (string)($stmtIdioma->fetchColumn() ?: 'spa');

/* Caché en sesión (por usuario, libros base e idioma) */
$cacheKey = 'rec11_' . $userId . '_' . $idioma . '_' . md5(json_encode($idsBase));
if (!$debug && isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]['t']) < 6 * 3600) {
    echo json_encode($_SESSION[$cacheKey]['data'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

/* API key de Google Books: variable de entorno o config.local.php */
$apiKey = getenv('GOOGLE_BOOKS_API_KEY') ?: '';
$rutas  = [
    __DIR__ . '/../config/config.local.php',        
    __DIR__ . '/../src/config/config.local.php',
    __DIR__ . '/../src/config.local.php',
];
$archivoUsado = null;
if ($apiKey === '') {
    foreach ($rutas as $ruta) {
        if (is_file($ruta)) {
            $archivoUsado = $ruta;
            $cfg = require $ruta;
            $apiKey = is_array($cfg) ? trim((string)($cfg['google_books_key'] ?? '')) : '';
            break;
        }
    }
}
if (str_contains($apiKey, 'PEGA_AQUI')) $apiKey = '';

/* Recomendar */
$service   = new RecomendacionesService($db->pdo, $apiKey);
$respuesta = $service->recomendar($userId, $year);

if ($debug) {
    $respuesta['debug'] = [
        'archivo_config' => $archivoUsado ?? 'no encontrado',
        'api_key'        => $apiKey !== '' ? 'configurada (' . strlen($apiKey) . ' caracteres)' : 'NO configurada',
        'consultas'      => $service->log,
    ];
}

if (!empty($respuesta['libros']) && !$debug) {
    @session_start();
    $_SESSION[$cacheKey] = ['t' => time(), 'data' => $respuesta];
    session_write_close();
}

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
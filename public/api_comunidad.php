<?php
ini_set('display_errors', '0');   

require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/ComunidadService.php";

header('Content-Type: application/json; charset=utf-8');

$usuario = Auth::usuario();
if (!$usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Inicia sesión para continuar.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!ComunidadService::verificarToken((string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sesión caducada. Recarga la página.']);
    exit;
}

$yo     = (int)$usuario['id'];
$otro   = (int)($_POST['id'] ?? 0);
$accion = (string)($_POST['accion'] ?? '');

$db  = new Database();
$com = new ComunidadService($db->pdo);

if ($accion === 'seguir') {
    if (!$com->seguir($yo, $otro)) {
        echo json_encode(['ok' => false, 'error' => 'No se puede seguir a este usuario.']);
        exit;
    }
} elseif ($accion === 'dejar') {
    $com->dejarDeSeguir($yo, $otro);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no válida.']);
    exit;
}

echo json_encode([
    'ok'         => true,
    'sigo'       => $com->estaSiguiendo($yo, $otro),
    'seguidores' => $com->contadores($otro)['seguidores'],
]);
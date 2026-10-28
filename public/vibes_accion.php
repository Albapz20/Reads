<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/VibesService.php";

if (session_status() === PHP_SESSION_NONE) @session_start();

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$libroId = trim((string)($_POST['libro_id'] ?? ''));
if ($libroId === '') {
    header("Location: index.php");
    exit;
}
$volver = "libro.php?id=" . urlencode($libroId) . "#vibes";

// Protección CSRF: el token se genera en libro.php
$token = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_vibes']) || !hash_equals($_SESSION['csrf_vibes'], $token)) {
    $_SESSION['vibes_msg'] = ['error', 'La sesión ha caducado. Vuelve a intentarlo.'];
    header("Location: $volver");
    exit;
}

$db = new Database();
$vibes = new VibesService($db->pdo);
$usuarioId = (int)$usuario["id"];
$accion = (string)($_POST['accion'] ?? '');

switch ($accion) {
    case 'agregar':
        [$ok, $mensaje] = $vibes->agregar(
            $libroId,
            $usuarioId,
            (string)($_POST['imagen_url'] ?? ''),
            (string)($_POST['credito'] ?? ''),
            (string)($_POST['enlace_origen'] ?? ''),
            (string)($_POST['nota'] ?? '')
        );
        $_SESSION['vibes_msg'] = [$ok ? 'ok' : 'error', $mensaje];
        break;

    case 'eliminar':
        $ok = $vibes->eliminar((int)($_POST['vibe_id'] ?? 0), $usuarioId);
        $_SESSION['vibes_msg'] = $ok ? ['ok', 'Imagen eliminada.'] : ['error', 'No se pudo eliminar la imagen.'];
        break;

    case 'reportar':
        $vibes->reportar((int)($_POST['vibe_id'] ?? 0), $usuarioId);
        $_SESSION['vibes_msg'] = ['ok', 'Gracias, hemos registrado tu reporte.'];
        break;
}

header("Location: $volver");
exit;
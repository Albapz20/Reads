<?php
require_once "../src/Auth.php";
require_once "../src/helpers.php";
require_once "../src/ListaService.php";

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

$usuario = Auth::usuario();   // también inicia la sesión
if (!$usuario) {
    responder(401, ['ok' => false, 'error' => 'Tu sesión ha caducado. Vuelve a iniciar sesión.']);
}

$token = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_estado']) || !hash_equals($_SESSION['csrf_estado'], $token)) {
    responder(403, ['ok' => false, 'error' => 'Recarga la página e inténtalo de nuevo.']);
}

// Solo se permiten estos dos estados desde aquí
$estado = (string)($_POST['estado'] ?? '');
if (!in_array($estado, ['leido', 'tbr'], true)) {
    responder(400, ['ok' => false, 'error' => 'Estado no válido.']);
}

$libroId = trim((string)($_POST['libro_id'] ?? ''));
$titulo  = trim((string)($_POST['titulo'] ?? ''));
$autor   = trim((string)($_POST['autor'] ?? ''));
$portada = trim((string)($_POST['portada'] ?? ''));

if ($libroId === '' || $titulo === '' || mb_strlen($libroId) > 100 || mb_strlen($titulo) > 300) {
    responder(400, ['ok' => false, 'error' => 'Faltan datos del libro.']);
}
if (mb_strlen($autor) > 300) $autor = mb_substr($autor, 0, 300);

// Portada: solo http(s); si no hay, la misma de reserva que usa la ficha
if ($portada === '' || mb_strlen($portada) > 1000 || !preg_match('#^https?://#i', $portada)) {
    $portada = 'https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada';
}
$portada = str_replace('http://', 'https://', $portada);

@set_time_limit(60);   // agregarLibro consulta Google Books / Open Library

try {
    $lista = new ListaService();
    $ok = $lista->agregarLibro((int)$usuario['id'], $libroId, $titulo, $portada, $estado, $autor);

    if (!$ok) {
        responder(500, ['ok' => false, 'error' => 'No se pudo guardar el libro.']);
    }
    responder(200, ['ok' => true, 'estado' => $estado]);
} catch (Throwable $e) {
    error_log('buscar_estado: ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar el libro. Inténtalo más tarde.']);
}
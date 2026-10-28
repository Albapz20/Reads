<?php
ini_set('display_errors', '0');  
@set_time_limit(40);

require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/AutorService.php";

header('Content-Type: application/json; charset=utf-8');

$titulo = trim((string)($_GET['titulo'] ?? ''));
$autor  = trim((string)($_GET['autor'] ?? ''));

if ($titulo === '' || $autor === '' || $autor === 'Autor desconocido') {
    echo json_encode(['saga' => null]);
    exit;
}

$usuario = Auth::usuario();   
$userId  = $usuario ? (int)$usuario["id"] : 0;

$db = new Database();
[$langGb, $langOl] = $userId ? AutorService::idiomasUsuario($db->pdo, $userId) : ['', ''];

// Libros que el usuario ya tiene
$tengo = [];
if ($userId) {
    $st = $db->pdo->prepare("SELECT libro_id, titulo, estado FROM listas_lectura WHERE usuario_id = ?");
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tengo[AutorService::tituloBase((string)$r['titulo'])] = ['estado' => $r['estado'], 'id' => (string)$r['libro_id']];
    }
}

// Liberar la sesión: las consultas externas pueden tardar y no deben bloquear otras páginas
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$apiKey   = function_exists('config') ? (string)config('google_books_key', '') : '';
$servicio = new AutorService($apiKey, $langGb, $langOl);
$saga     = $servicio->saga($titulo, $autor);

if ($saga) {
    $primerAutor = trim(explode(',', $autor)[0]);
    foreach ($saga['libros'] as &$l) {
        $b = AutorService::tituloBase($l['titulo']);
        $propio = $tengo[$b] ?? null;
        $l['lo_tienes'] = $propio['estado'] ?? null;
        $l['url'] = $propio
            ? 'libro.php?id=' . urlencode($propio['id'])
            : 'buscar.php?q=' . urlencode(trim($l['titulo'] . ' ' . $primerAutor)) . '&rec=1';
    }
    unset($l);
}

echo json_encode(['saga' => $saga], JSON_UNESCAPED_UNICODE);
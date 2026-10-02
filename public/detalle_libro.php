<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$idParam = (string)($_GET['id'] ?? '');
$destino = null;

if ($idParam !== '') {
    $db = new Database();
    $st = $db->pdo->prepare(
        "SELECT id, libro_id FROM listas_lectura
         WHERE usuario_id = ? AND (libro_id = ? OR id = ?)
         ORDER BY (libro_id = ?) DESC LIMIT 1"
    );
    $st->execute([$usuario["id"], $idParam, $idParam, $idParam]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    if ($fila) {
        $destino = $fila["libro_id"] !== '' && $fila["libro_id"] !== null ? $fila["libro_id"] : $fila["id"];
    }
}

header("Location: " . ($destino !== null ? "libro.php?id=" . urlencode((string)$destino) : "biblioteca.php"));
exit;
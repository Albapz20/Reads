<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$db = new Database();

// Recoger datos del formulario
$id        = $_POST["libro_id"];   // ← ESTE ES EL ID REAL DEL REGISTRO
$estrellas = $_POST["estrellas"];

// Guardar en la base de datos
$sql = "UPDATE listas_lectura 
        SET estrellas = ?
        WHERE usuario_id = ? AND id = ?";

$stmt = $db->pdo->prepare($sql);
$stmt->execute([$estrellas, $usuario["id"], $id]);

header("Location: perfil.php");
exit;

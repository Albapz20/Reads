<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$db = new Database();

$tema = $_POST["tema_visual"];

// 1. Guardar en Base de Datos
$sql = "UPDATE usuarios SET tema_visual = ? WHERE id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$tema, $usuario["id"]]);

// 2. Actualizar inmediatamente en la Sesión de PHP
Auth::actualizarTema($tema);

header("Location: perfil.php");
exit;
?>
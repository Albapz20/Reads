<?php
require_once "../src/Auth.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$listaService = new ListaService();

$id = $_POST["libro_id"];
$estrellas = $_POST["estrellas"];

$listaService->actualizarEstrellas($usuario["id"], $id, $estrellas);

header("Location: perfil.php?id=" . $id);
exit;

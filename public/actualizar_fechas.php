<?php
require_once "../src/Auth.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$listaService = new ListaService();

$listaService->actualizarFechas(
    $usuario["id"],
    $_POST["libro_id"],
    $_POST["fecha_inicio"],
    $_POST["fecha_fin"]
);

header("Location: perfil.php");
exit;

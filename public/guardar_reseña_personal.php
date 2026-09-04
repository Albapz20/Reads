<?php
require_once "../src/Auth.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$listaService = new ListaService();

$listaService->guardarReseñaPersonal(
    $usuario["id"],
    $_POST["libro_id"],
    $_POST["reseña"]
);

header("Location: perfil.php");
exit;

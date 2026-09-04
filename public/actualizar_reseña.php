<?php
require_once "../src/Auth.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$listaService = new ListaService();

// Validar libro
if (!isset($_POST["libro_id"])) {
    die("ID de libro no especificado.");
}

$libro_id = $_POST["libro_id"];

// PÁGINAS LEÍDAS
if (isset($_POST["paginas_leidas"])) {
    $paginas = (int)$_POST["paginas_leidas"];
    $listaService->actualizarPaginasLeidas($usuario["id"], $libro_id, $paginas);
}

// ESTRELLAS
if (isset($_POST["estrellas"])) {
    $estrellas = (int)$_POST["estrellas"];
    $listaService->actualizarEstrellas($usuario["id"], $libro_id, $estrellas);
}

// RESEÑA
if (isset($_POST["reseñas"])) {
    $reseña = trim($_POST["reseñas"]);
    $listaService->actualizarReseña($usuario["id"], $libro_id, $reseña);
}

// MOTIVO DE ABANDONO
if (isset($_POST["motivo_abandono"])) {
    $motivo = trim($_POST["motivo_abandono"]);
    $listaService->actualizarMotivoAbandono($usuario["id"], $libro_id, $motivo);
}

// FECHA FIN (solo si está leído)
if (isset($_POST["fecha_fin"])) {
    $fecha = $_POST["fecha_fin"];
    $listaService->actualizarFechaFin($usuario["id"], $libro_id, $fecha);
}

header("Location: perfil.php");
exit;

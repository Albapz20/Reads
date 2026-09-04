<?php
require_once "../src/Auth.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$listaService = new ListaService();

$listaService->actualizarProgreso(
    $usuario["id"],
    $_POST["libro_id"],
    $_POST["progreso"]
);

if ($nuevoEstado === "leido") {

    $rutaPortada = $libro["portada"];
    $titulo = $libro["titulo"];
    $idLibro = $libro["id"];

    $lomo = generarLomoDesdePortada($rutaPortada, $titulo, $idLibro);

    if ($lomo) {
        $sql = "UPDATE listas_lectura SET portada_lomo = ? WHERE id = ?";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute([$lomo, $idLibro]);
    }
}


header("Location: perfil.php");
exit;

<?php
require_once "../src/Auth.php";
require_once "../src/ReviewService.php";

Auth::requiereLogin();

$usuario = Auth::usuario();
$reviewService = new ReviewService();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $libroId = $_POST["libro_id"];
    $contenido = $_POST["contenido"];

    $resultado = $reviewService->guardarReseña($usuario["id"], $libroId, $contenido);

    if ($resultado === true) {
        header("Location: libro.php?id=" . $libroId);
        exit;
    } else {
        echo "<p style='color:red;'>$resultado</p>";
        echo "<a href='libro.php?id=$libroId'>Volver</a>";
    }
}

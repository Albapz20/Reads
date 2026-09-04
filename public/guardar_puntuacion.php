<?php
require_once "../src/Auth.php";
require_once "../src/RatingService.php";

Auth::requiereLogin();

$usuario = Auth::usuario();
$ratingService = new RatingService();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $libroId = $_POST["libro_id"];
    $estrellas = $_POST["estrellas"];
    $romance = $_POST["romance"];
    $spicy = $_POST["spicy"];
    $lagrimas = $_POST["lagrimas"] ?? 0;
    $plotTwist = $_POST["plot_twist"] ?? 0;

    // Actualizamos la llamada para enviar las 5 métricas
    $ratingService->guardarPuntuacion($usuario["id"], $libroId, $estrellas, $romance, $spicy, $lagrimas, $plotTwist);

    header("Location: libro.php?id=" . $libroId);
    exit;
}
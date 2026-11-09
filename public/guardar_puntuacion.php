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
    $humor = $_POST["humor"] ?? 0;
    $tension = $_POST["tension"] ?? 0;
        $originalidad = $_POST["originalidad"] ?? 0;


            $limitar = fn($v) => max(0, min(5, round((float)$v * 2) / 2));
    $estrellas = $limitar($estrellas); $romance = $limitar($romance); $spicy = $limitar($spicy);
    $lagrimas = $limitar($lagrimas); $plotTwist = $limitar($plotTwist); $humor = $limitar($humor);
    $tension = $limitar($tension); $originalidad = $limitar($originalidad);

    
    // Enviamos las 8 métricas
       $ratingService->guardarPuntuacion($usuario["id"], $libroId, $estrellas, $romance, $spicy, $lagrimas, $plotTwist, $humor, $tension, $originalidad);

    header("Location: libro.php?id=" . $libroId);
    exit;
}
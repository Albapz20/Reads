<?php
require_once "../src/Auth.php";
require_once "../src/ReviewService.php";

Auth::requiereLogin();

$usuario = Auth::usuario();
$reviewService = new ReviewService();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: perfil.php");
    exit;
}

$libroId   = trim($_POST["libro_id"] ?? "");
$contenido = $_POST["contenido"] ?? "";

// Solo se permite volver a estas dos páginas
$destino = (($_POST["volver"] ?? "") === "perfil")
    ? "perfil.php"
    : "libro.php?id=" . urlencode($libroId);

$resultado = $reviewService->guardarReseña($usuario["id"], $libroId, $contenido);

if ($resultado === true) {
    header("Location: " . $destino);
    exit;
}

echo "<p style='color:red;'>" . htmlspecialchars($resultado) . "</p>";
echo "<a href='" . htmlspecialchars($destino) . "'>Volver</a>";
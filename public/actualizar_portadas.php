<?php
require_once "../src/Database.php";
require_once "../src/PortadasService.php";

$db = new Database();

// Obtener libros sin portada
$sql = "SELECT id, titulo FROM listas_lectura WHERE portada IS NULL OR portada = ''";
$stmt = $db->pdo->prepare($sql);
$stmt->execute();
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Actualizando portadas...</h1>";

foreach ($libros as $l) {
    $titulo = $l["titulo"];
    $id = $l["id"];

    echo "<p>Buscando portada para: <strong>$titulo</strong>...</p>";

    $portada = PortadasService::obtenerPortada($titulo);

    if ($portada) {
        $update = $db->pdo->prepare("UPDATE listas_lectura SET portada=? WHERE id=?");
        $update->execute([$portada, $id]);

        echo "<p style='color:green;'>✔ Portada encontrada y guardada</p>";
    } else {
        echo "<p style='color:red;'>✘ No se encontró portada</p>";
    }
}

echo "<h2>Proceso completado.</h2>";

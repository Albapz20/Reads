<?php
// Activar reporte de errores explícito para evitar pantallas en blanco
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

@set_time_limit(0);
@ini_set('memory_limit', '512M');

// Desactivar el búfer de salida para ver las actualizaciones en tiempo real
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(1);

require_once "../src/Database.php";
require_once "../src/PortadasService.php";

$db = new Database();

// Obtener libros sin portada o que tengan la imagen genérica por defecto
$sql = "SELECT id, titulo, autores FROM listas_lectura WHERE portada IS NULL OR portada = '' OR portada LIKE '%default%'";
$stmt = $db->pdo->prepare($sql);
$stmt->execute();
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Actualizando portadas pendientes...</h1>";
echo "<p>Encontrados <strong>" . count($libros) . "</strong> libros por procesar.</p><hr>";
flush();

foreach ($libros as $l) {
    $titulo = $l["titulo"];
    $autor  = $l["autores"] ?? "";
    $id     = $l["id"];

    echo "<p>Buscando portada para: <strong>" . htmlspecialchars($titulo) . "</strong>...</p>";
    flush();

    $portada = PortadasService::obtenerPortada($titulo, $autor, $id);

    if ($portada) {
        $update = $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ?");
        $update->execute([$portada, $id]);

        echo "<p style='color:green;'>✔ Portada guardada: " . htmlspecialchars($portada) . "</p>";
    } else {
        echo "<p style='color:red;'>✘ No se encontró portada coincidente</p>";
    }
    flush();
}

echo "<hr><h2>Proceso completado.</h2>";
echo "<p><a href='index.php'>Volver al inicio</a></p>";
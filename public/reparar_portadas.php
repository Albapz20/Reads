<?php
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/PortadasService.php';
require_once __DIR__ . '/../src/PortadasVerificadas.php';

$usuario = Auth::usuario();
if (!$usuario) {
    die("Debes iniciar sesión para ejecutar la reparación.");
}

$db = new Database();

$sql = "SELECT id, titulo, autores, libro_id, portada
        FROM listas_lectura
        WHERE usuario_id = ?
          AND (portada IS NULL OR portada = ''
               OR portada = 'uploads/portadas/default.jpg'
               OR portada = '/Reads/img/default_cover.jpg'
               OR portada NOT LIKE 'uploads/portadas/%')";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario['id']]);
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Reparación de portadas</h1>";
echo "<p>Analizando <strong>" . count($libros) . "</strong> libros sin portada válida...</p><hr>";

$reparados = 0;
$fallos = 0;

foreach ($libros as $libro) {
    $titulo  = trim($libro['titulo']);
    $autores = trim($libro['autores'] ?? '');

    // ISBN si libro_id lo es.
    $rawId = preg_replace('/[^0-9X]/i', '', $libro['libro_id'] ?? '');
    $isbn  = (strlen($rawId) === 10 || strlen($rawId) === 13) ? $rawId : '';

    // Conserva el comportamiento de los contadores del script original.
    PortadasVerificadas::$limitado = 0;
    PortadasVerificadas::$errores  = 0;

    $portada = PortadasService::obtenerPortada($titulo, $autores, $libro['id'], $isbn);

    if ($portada) {
        $stmtUp = $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ?");
        $stmtUp->execute([$portada, $libro['id']]);
        echo "<p style='color:green;'>✓ «" . htmlspecialchars($titulo) . "» → $portada</p>";
        $reparados++;
    } else {
        $stmtUp = $db->pdo->prepare("UPDATE listas_lectura SET portada = NULL WHERE id = ?");
        $stmtUp->execute([$libro['id']]);
        echo "<p style='color:gray;'>✗ Sin portada verificada: «" . htmlspecialchars($titulo) . "»</p>";
        $fallos++;
    }

    flush();
    usleep(300000);
}

echo "<hr><h2>Reparadas: $reparados · No encontradas: $fallos</h2>";
echo "<a href__='perfil.php'>Volver al perfil</a>";
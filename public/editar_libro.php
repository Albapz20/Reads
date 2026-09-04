<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/PortadasService.php";

$usuario = Auth::usuario();
$tema = $usuario["tema_visual"] ?? "pastel";
if (!$usuario) { header("Location: login.php"); exit; }

$db = new Database();

if (!isset($_GET["id"])) {
    die("Libro no especificado.");
}

$id = $_GET["id"];

// Obtener datos del libro
$sql = "SELECT * FROM listas_lectura WHERE id = ? AND usuario_id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$id, $usuario["id"]]);
$libro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$libro) {
    die("Libro no encontrado.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $titulo = $_POST["titulo"];
    $estado = $_POST["estado"];
    $fecha_fin = $_POST["fecha_fin"];
    $estrellas = $_POST["estrellas"];
    $paginas_leidas = (int)$_POST["paginas_leidas"];

    // ============================
    // ACTUALIZAR PÁGINAS TOTALES AUTOMÁTICAS
    // ============================

    if ($titulo !== $libro["titulo"]) {

        // Si cambia el título → obtener datos nuevos
        $datos = PortadasService::obtenerDatosLibro($titulo);

        $portada = $datos["portada"] ?? $libro["portada"];

        // Si la API devuelve páginas → usar esas
        // Si no → mantener las que ya tenía
        $paginas_totales = $datos["paginas_totales"] ?? $libro["paginas_totales"];

    } else {

        // Si NO cambia el título → mantener todo igual
        $portada = $libro["portada"];
        $paginas_totales = $libro["paginas_totales"];
    }

    // ============================
    // AJUSTAR PÁGINAS LEÍDAS SEGÚN ESTADO
    // ============================

    if ($estado === "leido") {
        $paginas_leidas = $paginas_totales;
    }

    if ($estado === "pendiente") {
        $paginas_leidas = 0;
    }

    // ============================
    // CALCULAR PROGRESO AUTOMÁTICO
    // ============================

    $progreso = 0;
    if ($paginas_totales > 0) {
        $progreso = round(($paginas_leidas / $paginas_totales) * 100);
    }

    // ============================
    // GUARDAR CAMBIOS
    // ============================

    $sql = "UPDATE listas_lectura 
            SET titulo=?, estado=?, fecha_fin=?, estrellas=?, paginas_leidas=?, paginas_totales=?, progreso=?, portada=?
            WHERE id=? AND usuario_id=?";

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([
        $titulo,
        $estado,
        $fecha_fin,
        $estrellas,
        $paginas_leidas,
        $paginas_totales,
        $progreso,
        $portada,
        $id,
        $usuario["id"]
    ]);

    header("Location: perfil.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
<title>Editar libro</title>
</head>

<body>

<div class="container">

    <div class="panel">
        <div class="panel-header">
            <h1>Editar libro</h1>
        </div>

        <form method="POST">

            <label><strong>Título:</strong></label><br>
            <input type="text" name="titulo" value="<?= htmlspecialchars($libro['titulo']) ?>" required><br><br>

            <label><strong>Estado:</strong></label><br>
            <select name="estado">
                <option value="pendiente" <?= $libro["estado"] === "pendiente" ? "selected" : "" ?>>Pendiente</option>
                <option value="leyendo" <?= $libro["estado"] === "leyendo" ? "selected" : "" ?>>Leyendo</option>
                <option value="leido" <?= $libro["estado"] === "leido" ? "selected" : "" ?>>Leído</option>
            </select><br><br>

            <label><strong>Fecha fin:</strong></label><br>
            <input type="date" name="fecha_fin" value="<?= $libro['fecha_fin'] ?>"><br><br>

            <label><strong>Estrellas:</strong></label><br>
            <input type="number" step="0.5" min="0" max="5" name="estrellas" value="<?= $libro['estrellas'] ?>"><br><br>

            <label><strong>Páginas leídas:</strong></label><br>
            <input type="number" name="paginas_leidas" value="<?= $libro['paginas_leidas'] ?>"><br><br>

            <button type="submit">Guardar cambios</button>

        </form>
    </div>

</div>

</body>
</html>

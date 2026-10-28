<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/PortadasService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }
$tema = $usuario["tema_visual"] ?? "pastel";

$db = new Database();

if (!isset($_GET["id"])) {
    die("Libro no especificado.");
}

$id = $_GET["id"];

$volver = $_GET["volver"] ?? "";

// Obtener datos del libro
$sql = "SELECT * FROM listas_lectura WHERE id = ? AND usuario_id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$id, $usuario["id"]]);
$libro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$libro) {
    die("Libro no encontrado.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $titulo = trim($_POST["titulo"] ?? $libro["titulo"]);
    $estado = $_POST["estado"] ?? $libro["estado"];

    // Fecha vacía = NULL (una cadena vacía rompe la columna DATE)
    $fecha_fin = trim($_POST["fecha_fin"] ?? "");
    $fecha_fin = ($fecha_fin !== "") ? $fecha_fin : null;

    // Estrellas: si llega vacío se conserva la que había
    $estrellas = (isset($_POST["estrellas"]) && $_POST["estrellas"] !== "")
        ? (float)$_POST["estrellas"]
        : $libro["estrellas"];

    $paginas_totales = max(0, (int)($_POST["paginas_totales"] ?? $libro["paginas_totales"]));
    $paginas_leidas  = max(0, (int)($_POST["paginas_leidas"] ?? 0));

    // Si cambia el título, se intenta buscar la portada nueva (PortadasService no tiene obtenerDatosLibro)
    $portada = $libro["portada"];
    if ($titulo !== $libro["titulo"]) {
        $autorPrincipal = trim(explode(',', (string)($libro["autores"] ?? ''))[0]);
        $nueva = PortadasService::obtenerPortada($titulo, $autorPrincipal, (int)$libro["id"], '');
        if ($nueva) $portada = $nueva;
    }

    // Las páginas leídas no pueden superar el total
    if ($paginas_totales > 0 && $paginas_leidas > $paginas_totales) {
        $paginas_leidas = $paginas_totales;
    }

    // Progreso según el estado
    if ($estado === "leido") {
        if ($paginas_totales > 0) $paginas_leidas = $paginas_totales;
        $progreso = 100;
        if ($fecha_fin === null) $fecha_fin = date("Y-m-d");   // para que cuente en estadísticas y Wrap Up
    } elseif (in_array($estado, ["guardado", "tbr", "pendiente"], true)) {
        $paginas_leidas = 0;
        $progreso = 0;
    } else {
        if ($paginas_totales > 0) {
            // Con páginas totales, el porcentaje sale de las páginas leídas
            $progreso = (int)round(($paginas_leidas / $paginas_totales) * 100);
        } else {
            // Sin páginas totales, se usa el porcentaje escrito a mano
            $progreso = max(0, min(100, (int)($_POST["progreso"] ?? $libro["progreso"] ?? 0)));
        }
    }

    // Guardar cambios en la base de datos
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

    if ($volver === "libro") {
        $ref = !empty($libro["libro_id"]) ? $libro["libro_id"] : $libro["id"];
        header("Location: libro.php?id=" . urlencode((string)$ref));
    } else {
        header("Location: perfil.php");
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
<link rel="stylesheet" href="/Reads/public/css/styles.css">
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
                <?php if ($libro["estado"] === "pendiente"): ?>
                    <option value="pendiente" selected>Pendiente</option>
                <?php endif; ?>
                <option value="guardado"   <?= $libro["estado"] === "guardado"   ? "selected" : "" ?>>🎁 Wishlist</option>
                <option value="tbr"        <?= $libro["estado"] === "tbr"        ? "selected" : "" ?>>🎯 TBR</option>
                <option value="leyendo"    <?= $libro["estado"] === "leyendo"    ? "selected" : "" ?>>📖 Leyendo</option>
                <option value="leido"      <?= $libro["estado"] === "leido"      ? "selected" : "" ?>>✅ Leído</option>
                <option value="abandonado" <?= $libro["estado"] === "abandonado" ? "selected" : "" ?>>❌ Abandonado</option>
            </select><br><br>

            <label><strong>Fecha fin:</strong></label><br>
            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($libro['fecha_fin'] ?? '') ?>"><br><br>

            <label><strong>Estrellas:</strong></label><br>
            <input type="number" step="0.5" min="0" max="5" name="estrellas" value="<?= htmlspecialchars((string)($libro['estrellas'] ?? '')) ?>"><br><br>

            <label><strong>Páginas totales:</strong></label><br>
            <input type="number" min="0" name="paginas_totales" value="<?= (int)$libro['paginas_totales'] ?>"><br><br>

            <label><strong>Páginas leídas:</strong></label><br>
            <input type="number" min="0" name="paginas_leidas" value="<?= (int)$libro['paginas_leidas'] ?>"><br><br>

            <label><strong>Progreso (%):</strong></label><br>
            <input type="number" min="0" max="100" name="progreso" value="<?= (int)($libro['progreso'] ?? 0) ?>">
            <br><small>Solo se usa si el libro no tiene páginas totales; si las tiene, el porcentaje se calcula solo con las páginas leídas.</small><br><br>

            <button type="submit">Guardar cambios</button>

        </form>
    </div>

</div>

</body>
</html>
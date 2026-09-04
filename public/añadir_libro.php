<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/BookService.php";

$usuario = Auth::usuario();
$tema = $usuario["tema_visual"] ?? "pastel";
if (!$usuario) { header("Location: login.php"); exit; }

$db = new Database();
$service = new BookService();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $titulo = trim($_POST["titulo"]);
    $paginas_leidas = (int)$_POST["paginas_leidas"];
    $fecha_fin = $_POST["fecha_fin"];
    $estado = $_POST["estado"];
    $estrellas = $_POST["estrellas"];

    // Obtener datos del libro desde Google Books / OpenLibrary
    $resultados = $service->buscarLibros($titulo);
    $libro = $resultados[0] ?? null;

    $portada = $libro["portada"] ?? null;
    $paginas_totales = $libro["paginas_totales"] ?? 0;

    // Ajustar páginas leídas según estado
    if ($estado === "leido") {
        $paginas_leidas = $paginas_totales;
    } elseif ($estado === "pendiente") {
        $paginas_leidas = 0;
    }

    // Calcular progreso
    $progreso = 0;
    if ($paginas_totales > 0) {
        $progreso = round(($paginas_leidas / $paginas_totales) * 100);
    }

    $sql = "INSERT INTO listas_lectura 
        (usuario_id, titulo, paginas_totales, paginas_leidas, progreso, fecha_fin, estado, estrellas, portada)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([
        $usuario["id"],
        $titulo,
        $paginas_totales,
        $paginas_leidas,
        $progreso,
        $fecha_fin,
        $estado,
        $estrellas,
        $portada
    ]);

    header("Location: perfil.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="/temas/<?= $tema ?>.css">

<title>Añadir libro</title>
</head>
<body>

<h1>Añadir libro</h1>

<form method="POST">
    <label>Título:</label><br>
    <input type="text" name="titulo" required><br><br>

    <label>Páginas leídas:</label><br>
    <input type="number" name="paginas_leidas" min="0" value="0"><br><br>

    <label>Fecha fin:</label><br>
    <input type="date" name="fecha_fin"><br><br>

    <label>Estado:</label><br>
    <select name="estado">
        <option value="pendiente">Pendiente</option>
        <option value="leyendo">Leyendo</option>
        <option value="leido">Leído</option>
    </select><br><br>

    <label>Estrellas:</label><br>
    <input type="number" step="0.5" min="0" max="5" name="estrellas"><br><br>

    <button type="submit">Guardar</button>
</form>

</body>
</html>

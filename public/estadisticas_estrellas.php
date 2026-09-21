<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

if (!isset($_GET["valor"])) {
    die("Valor de estrella no especificado.");
}

$valor = floatval($_GET["valor"]);

$db = new Database();
$userService = new UserService();

// Recargar usuario actualizado desde BD
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);

// Tema actualizado
$tema = $datos["tema_visual"] ?? "pastel";

// Obtener libros con esa valoración
$sql = "SELECT titulo, paginas_totales, fecha_fin, portada
        FROM listas_lectura
        WHERE usuario_id = ? AND estado = 'leido' AND estrellas = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"], $valor]);
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas generales
$totalLibros = count($libros);
$totalPaginas = array_sum(array_column($libros, "paginas_totales"));
$promedioPaginas = $totalLibros > 0 ? round($totalPaginas / $totalLibros) : 0;
$maxPaginas = $totalLibros > 0 ? max(array_column($libros, "paginas_totales")) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>⭐ <?= $valor ?> estrellas — Estadísticas</title>

<link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

<div class="container">

    <!-- Panel principal -->
    <div class="panel">
        <div class="panel-header">
            <h1>⭐ <?= $valor ?> estrellas</h1>
        </div>

        <div class="review-card">
            <p><strong><?= $totalLibros ?></strong> libros con esta valoración</p>
            <p><strong><?= $totalPaginas ?></strong> páginas totales</p>
            <p><strong><?= $promedioPaginas ?></strong> páginas de promedio</p>
            <p><strong><?= $maxPaginas ?></strong> páginas del libro más largo</p>
        </div>

        <canvas id="graficoEstrellas"></canvas>
    </div>

    <!-- Lista de libros -->
    <div class="panel">
        <div class="panel-header">
            <h2>📚 Libros con <?= $valor ?> estrellas</h2>
        </div>

        <?php if ($totalLibros === 0): ?>
            <p>No hay libros con esta valoración.</p>
        <?php else: ?>
            <?php foreach ($libros as $l): ?>
                <div class="review-card">

                    <div class="libro-header">
                        <img src="<?= $l['portada'] ?: 'img/sin_portada.png' ?>" class="libro-portada">

                        <div class="libro-info">
                            <strong><?= htmlspecialchars($l["titulo"]) ?></strong><br>
                            <?= $l["paginas_totales"] ?> páginas<br>
                            Finalizado: <?= $l["fecha_fin"] ?: "Sin finalizar" ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="acciones-perfil">
        <a href="estadisticas.php">← Volver</a>
    </div>

</div>

<script>
const ctx = document.getElementById("graficoEstrellas").getContext("2d");

new Chart(ctx, {
    type: "doughnut",
    data: {
        labels: ["Libros con <?= $valor ?> estrellas"],
        datasets: [{
            data: [<?= $totalLibros ?>],
            backgroundColor: ["#ffb400"],
            borderWidth: 0
        }]
    },
    options: {
        cutout: "70%",
        plugins: {
            legend: { display: false }
        }
    }
});
</script>

</body>
</html>

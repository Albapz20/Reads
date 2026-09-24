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
$tema = $datos["tema_visual"] ?? "pastel";

// Sincronizar valoraciones desde puntuaciones
try {
    $sqlSync = "UPDATE listas_lectura l
                JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                SET l.estrellas = p.estrellas
                WHERE l.usuario_id = ? AND (l.estrellas IS NULL OR l.estrellas = 0) AND p.estrellas > 0";
    $stmtSync = $db->pdo->prepare($sqlSync);
    $stmtSync->execute([$usuario["id"]]);
} catch (Exception $e) {}

// Obtener libros con esa valoración
try {
    $sql = "SELECT l.titulo, l.paginas_totales, l.fecha_fin, l.portada,
                   COALESCE(NULLIF(p.estrellas, 0), NULLIF(l.estrellas, 0), 0) AS estrellas_reales
            FROM listas_lectura l
            LEFT JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
            WHERE l.usuario_id = ? AND l.estado = 'leido'
            AND ROUND(COALESCE(NULLIF(p.estrellas, 0), NULLIF(l.estrellas, 0), 0)) = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$usuario["id"], $valor]);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sql = "SELECT titulo, paginas_totales, fecha_fin, portada, estrellas AS estrellas_reales
            FROM listas_lectura
            WHERE usuario_id = ? AND estado = 'leido' AND ROUND(estrellas) = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$usuario["id"], $valor]);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Estadísticas generales
$totalLibros = count($libros);
$paginasArray = array_column($libros, "paginas_totales");
$totalPaginas = array_sum($paginasArray);
$promedioPaginas = $totalLibros > 0 ? round($totalPaginas / $totalLibros) : 0;
$maxPaginas = $totalLibros > 0 ? max($paginasArray) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>⭐ <?= $valor ?> estrellas — Estadísticas</title>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.container { max-width: 680px; margin: 0 auto; padding: 20px; }
.panel { background: #ffffff; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
.panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.panel-header h1, .panel-header h2 { margin: 0; font-size: 1.25rem; }
.review-card { background: #fafafa; border: 1px solid #eee; border-radius: 10px; padding: 12px 15px; margin-bottom: 10px; }
.libro-header { display: flex; gap: 12px; align-items: center; }
.libro-portada { width: 44px; height: 64px; object-fit: cover; border-radius: 4px; }
.acciones-perfil a { background: #f1f3f5; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 0.85rem; color: #333; display: inline-block; }
</style>
</head>
<body>

<div class="container">

    <!-- Panel principal -->
    <div class="panel">
        <div class="panel-header">
            <h1>⭐ <?= $valor ?> estrellas</h1>
        </div>

        <div class="review-card">
            <p style="margin: 4px 0;"><strong><?= $totalLibros ?></strong> libros con esta valoración</p>
            <p style="margin: 4px 0;"><strong><?= number_format($totalPaginas, 0, '', '.') ?></strong> páginas totales</p>
            <p style="margin: 4px 0;"><strong><?= number_format($promedioPaginas, 0, '', '.') ?></strong> páginas de promedio</p>
            <p style="margin: 4px 0;"><strong><?= number_format($maxPaginas, 0, '', '.') ?></strong> páginas del libro más largo</p>
        </div>

        <div style="max-width: 200px; margin: 15px auto;">
            <canvas id="graficoEstrellas"></canvas>
        </div>
    </div>

    <!-- Lista de libros -->
    <div class="panel">
        <div class="panel-header">
            <h2>📚 Libros con <?= $valor ?> estrellas</h2>
        </div>

        <?php if ($totalLibros === 0): ?>
            <p style="color: #777;">No hay libros con esta valoración.</p>
        <?php else: ?>
            <?php foreach ($libros as $l): ?>
                <div class="review-card">
                    <div class="libro-header">
                        <img src="<?= htmlspecialchars($l['portada'] ?: 'img/sin_portada.png') ?>" class="libro-portada" alt="Portada">

                        <div class="libro-info">
                            <strong><?= htmlspecialchars($l["titulo"]) ?></strong><br>
                            <span style="font-size: 0.85rem; color: #555;">
                                <?= number_format((int)$l["paginas_totales"], 0, '', '.') ?> páginas<br>
                                Finalizado: <?= htmlspecialchars($l["fecha_fin"] ?: "Sin fecha") ?>
                            </span>
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
        responsive: true,
        cutout: "70%",
        plugins: {
            legend: { display: false }
        }
    }
});
</script>

</body>
</html>
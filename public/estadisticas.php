<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/AjustesService.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$db = new Database();
$ajustesService = new AjustesService();$userService    = new UserService();

// Procesar formulario de actualización del objetivo anual
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["nuevo_objetivo"])) {
    $nuevoObjetivo = max(1, (int)$_POST["nuevo_objetivo"]);
    
    if (method_exists($ajustesService, 'guardarAjuste')) {$ajustesService->guardarAjuste($usuario["id"], "objetivo_anual", $nuevoObjetivo);
    } else {
        $sqlOpt = "INSERT INTO ajustes (usuario_id, clave, valor) VALUES (?, 'objetivo_anual', ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
        $stmtOpt =$db->pdo->prepare($sqlOpt);$stmtOpt->execute([$usuario["id"], $nuevoObjetivo]);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$ajustes = $ajustesService->obtenerAjustes($usuario["id"]);
$year = date("Y");
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema =$datos["tema_visual"] ?? "pastel";

// Obtener la distribución de libros leídos por mes para el año actual
$sqlMeses = "SELECT MONTH(fecha_fin) AS mes, COUNT(*) AS total
             FROM listas_lectura
             WHERE usuario_id = ? AND estado = 'leido'
             AND YEAR(fecha_fin) = ?
             GROUP BY MONTH(fecha_fin)";

$stmt =$db->pdo->prepare($sqlMeses);$stmt->execute([$usuario["id"], $year]);
$mesesData =$stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mesesNombres = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
$mesesLabels = [];$mesesValores = [];

for ($i = 1; $i <= 12; $i++) {
    $mesesLabels[] =$mesesNombres[$i - 1];$mesesValores[] = isset($mesesData[$i]) ? (int)$mesesData[$i] : 0;
}

// Obtener el total de libros leídos en el año actual
$sqlLeidos = "SELECT COUNT(*) FROM listas_lectura
              WHERE usuario_id = ? AND estado = 'leido'
              AND YEAR(fecha_fin) = ?";

$stmt =$db->pdo->prepare($sqlLeidos);$stmt->execute([$usuario["id"], $year]);
$leidosEsteAño = (int)$stmt->fetchColumn();

// Calcular el porcentaje del objetivo anual
$objetivo = (int)($ajustes["objetivo_anual"] ?? 0);
$porcentajeObjetivo =$objetivo > 0 ? round(($leidosEsteAño / $objetivo) * 100) : 0;

// Calcular el tiempo total de lectura en minutos para el año actual
$sqlTiempo = "SELECT 
                COALESCE(SUM(GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0))), 0) AS total_paginas
              FROM listas_lectura
              WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?";

$stmtTiempo =$db->pdo->prepare($sqlTiempo);$stmtTiempo->execute([$usuario["id"], $year]);
$totalPaginasAño = (int)$stmtTiempo->fetchColumn();

// Estimación: 1 página equivale a 1.5 minutos de lectura
$minutosPorPagina = 1.5; 
$totalMinutosLectura = (int)round($totalPaginasAño * $minutosPorPagina);

// Conversión a días, horas y minutos
$diasLectura = floor($totalMinutosLectura / 1440); // 1440 min = 1 día
$minutosRestantesDias =$totalMinutosLectura % 1440;
$horasLectura = floor($minutosRestantesDias / 60);
$minutosLecturaFinal =$minutosRestantesDias % 60;

// --- FILTRO Y CONSULTA TOP 5 LIBROS POR PÁGINAS ---
$periodoTop =$_GET['periodo'] ?? 'anio';
if (!in_array($periodoTop, ['anio', 'todos'])) {$periodoTop = 'anio';
}

if ($periodoTop === 'todos') {$whereTop = "";
    $paramsTop = [$usuario["id"]];
    $textoFiltroTop = "Todos los tiempos";
} else {
    $whereTop = " AND YEAR(fecha_fin) = ?";
    $paramsTop = [$usuario["id"], $year];
    $textoFiltroTop = "Año " . $year;
}

$sqlPaginas = "SELECT titulo, 
                      GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0)) AS paginas_reales, 
                      portada
               FROM listas_lectura
               WHERE usuario_id = ? AND estado = 'leido' {$whereTop}
               ORDER BY paginas_reales DESC, id DESC
               LIMIT 5";

$stmt = $db->pdo->prepare($sqlPaginas);
$stmt->execute($paramsTop);
$topPaginas =$stmt->fetchAll(PDO::FETCH_ASSOC);
$topValores = array_map("intval", array_column($topPaginas, "paginas_reales"));

// Obtener la distribución de estrellas para los libros leídos
$sqlEstrellas = "SELECT estrellas FROM listas_lectura
                 WHERE usuario_id = ? AND estado = 'leido'";

$stmt = $db->pdo->prepare($sqlEstrellas);
$stmt->execute([$usuario["id"]]);
$estrellasRaw =$stmt->fetchAll(PDO::FETCH_COLUMN);

$estrellasLista = array_filter($estrellasRaw, fn($v) => is_numeric($v));
$estrellasLista = array_map("floatval", $estrellasLista);

$distribucionEstrellas = [
    "5 ★" => 0,
    "4 ★" => 0,
    "3 ★" => 0,
    "2 ★" => 0,
    "1 ★" => 0
];

if (!empty($estrellasLista)) {$promedioEstrellas = round(array_sum($estrellasLista) / count($estrellasLista), 2);
    $estrellasEnteras = array_map(fn($v) => (int)round($v),$estrellasLista);
    $frecuencias = array_count_values($estrellasEnteras);

    if (!empty($frecuencias)) {
        $maxFrecuencia = max($frecuencias);
        $modas = array_keys($frecuencias, $maxFrecuencia);$estrellaComun = $modas[0];     } else {$estrellaComun = 0;
    }

    foreach ($estrellasEnteras as$val) {
        if ($val >= 1 &&$val <= 5) {
            $distribucionEstrellas["{$val} ★"]++;
        }
    }
} else {
    $promedioEstrellas = 0;
    $estrellaComun = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="main.js"></script>
<title>Estadísticas de lectura</title>

<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.container {
    max-width: 680px;
    margin: 0 auto;
    padding: 20px;
}

.panel {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
}

.panel-header h2 {
    margin: 0;
    font-size: 1.25rem;
}

.filtro-pestañas {
    display: flex;
    gap: 6px;
    background: rgba(0,0,0,0.05);
    padding: 4px;
    border-radius: 8px;
}

.filtro-btn {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
}

.filtro-btn.activo {
    background: var(--primary-color, #0078ff);
    color: #ffffff !important;
}

.filtro-btn.inactivo {
    color: #666666;
}

.objetivo-card-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.grafico-dona-contenedor {
    position: relative;
    width: 100%;
    max-width: 240px;
    height: 140px;
}

.objetivo-centro-texto {
    position: absolute;
    top: 65%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
}

.objetivo-centro-texto .porcentaje {
    display: block;
    font-size: 1.8rem;
    font-weight: 800;
    line-height: 1;
}

.objetivo-centro-texto .progreso-sub {
    display: block;
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 4px;
    font-weight: 600;
}

.btn-reto {
    margin-top: 15px;
    background: #e9ecef;
    color: #2b2b2b !important;
    border: 1px solid #ced4da;
    padding: 8px 18px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-reto:hover {
    background: #dee2e6;
    color: #000000 !important;
}

.form-reto-wrapper {
    display: none;
    margin-top: 15px;
    background: #f8f9fa;
    padding: 12px 18px;
    border-radius: 10px;
    border: 1px solid #eee;
    width: 100%;
    max-width: 300px;
}

.form-reto-wrapper form {
    display: flex;
    gap: 8px;
    align-items: center;
}

.form-reto-wrapper input[type="number"] {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 0.9rem;
}

.btn-guardar-reto {
    border: none;
    color: white;
    padding: 7px 14px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: bold;
}

.tiempo-grid {
    display: flex;
    gap: 15px;
    margin-top: 15px;
}

.tiempo-card {
    flex: 1;
    background: #f8f9fa;
    border: 1px solid #eee;
    border-radius: 10px;
    padding: 15px;
    text-align: center;
}

.tiempo-card .numero {
    font-size: 1.8rem;
    font-weight: 800;
    display: block;
}

.tiempo-card .etiqueta {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 600;
}

.grafico-contenedor-barras {
    width: 100%;
    max-width: 500px;
    height: 220px;
    margin: 15px auto 0 auto;
    position: relative;
}

.ranking-lista {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 15px;
}

.ranking-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fafafa;
    border: 1px solid #eee;
    border-radius: 10px;
    padding: 10px 12px;
}

.ranking-portada-box {
    position: relative;
    flex-shrink: 0;
}

.ranking-portada {
    width: 44px;
    height: 64px;
    object-fit: cover;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.12);
}

.ranking-badge {
    position: absolute;
    top: -6px;
    left: -6px;
    background: #2c3e50;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 800;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
}

.pos-1 .ranking-badge { background: #ffd700; color: #5a4300; }
.pos-2 .ranking-badge { background: #c0c0c0; color: #333; }
.pos-3 .ranking-badge { background: #cd7f32; color: #fff; }

.ranking-detalles {
    flex: 1;
    min-width: 0;
}

.ranking-top-info {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 6px;
}

.ranking-titulo {
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ranking-paginas-tag {
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
}

.ranking-barra-wrapper {
    background: #e9ecef;
    height: 6px;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 6px;
}

.ranking-barra-fill {
    height: 100%;
    border-radius: 3px;
}

.ranking-link {
    font-size: 0.75rem;
    color: #6c757d;
    text-decoration: none;
}

.acciones-perfil {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 15px;
}

.acciones-perfil a {
    background: #f1f3f5;
    padding: 6px 12px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 0.85rem;
    color: #333;
}
</style>
</head>

<body>

<div class="container">

    <!-- Objetivo anual -->
    <div class="panel">
        <div class="panel-header">
            <h2>📘 Objetivo anual</h2>
        </div>

        <div class="objetivo-card-wrapper">
            <div class="grafico-dona-contenedor">
                <canvas id="objetivoChart"></canvas>
                <div class="objetivo-centro-texto">
                    <span class="porcentaje"><?= $porcentajeObjetivo ?>%</span>
                    <span class="progreso-sub"><?= $leidosEsteAño ?> / <?= $objetivo ?> libros</span>
                </div>
            </div>

            <!-- Botón Establecer Reto -->
            <button class="btn-reto" onclick="toggleFormReto()">🎯 Establecer reto de lectura</button>

            <div class="form-reto-wrapper" id="formRetoWrapper">
                <form action="" method="POST">
                    <input type="number" name="nuevo_objetivo" min="1" value="<?= $objetivo ?: 1 ?>" required placeholder="Nº de libros">
                    <button type="submit" class="btn-guardar-reto theme-color-bg">Guardar</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Libros leídos por mes -->
    <div class="panel">
        <div class="panel-header">
            <h2>📅 Libros leídos por mes</h2>
        </div>

        <div class="grafico-contenedor-barras">
            <canvas id="mesesChart"></canvas>
        </div>
    </div>

    <!-- TOP 5 páginas con Filtro -->
    <div class="panel">
        <div class="panel-header">
            <h2>📚 Top 5 libros por páginas</h2>
            <div class="filtro-pestañas">
                <a href="estadisticas.php?periodo=anio" class="filtro-btn <?= $periodoTop === 'anio' ? 'activo' : 'inactivo' ?>">
                    📅 Este año (<?= $year ?>)
                </a>
                <a href="estadisticas.php?periodo=todos" class="filtro-btn <?= $periodoTop === 'todos' ? 'activo' : 'inactivo' ?>">
                    🌍 Todos
                </a>
            </div>
        </div>

        <div class="ranking-lista">
            <?php if (empty($topPaginas)): ?>
                <p style="color: #777; font-size: 0.88rem; padding: 10px 0;">No hay libros leídos registrados en este período.</p>
            <?php else: ?>
                <?php 
$maxPaginas = !empty($topValores) && max($topValores) > 0 ? max($topValores) : 1; 
foreach ($topPaginas as $index => $t): 
    $paginasLibro = (int)($t['paginas_reales'] ?? 0);
    
    // Si el libro tiene páginas registradas se muestran; si no, indica "Sin especificar"
    $textoPaginas = ($paginasLibro > 0) ? number_format($paginasLibro, 0, '', '.') . ' pág.' : 'Sin especificar';
    
    $porcentaje = ($maxPaginas > 0 && $paginasLibro > 0) ? round(($paginasLibro / $maxPaginas) * 100) : 0;
    $posicion = $index + 1;
?>
    <div class="ranking-card pos-<?= $posicion ?>">
        <div class="ranking-portada-box">
            <img src="<?= htmlspecialchars($t['portada'] ?: 'img/sin_portada.png') ?>" alt="Portada" class="ranking-portada">
            <span class="ranking-badge">#<?= $posicion ?></span>
        </div>

        <div class="ranking-detalles">
            <div class="ranking-top-info">
                <strong class="ranking-titulo"><?= htmlspecialchars($t['titulo']) ?></strong>
                <span class="ranking-paginas-tag theme-color-text"><?= $textoPaginas ?></span>
            </div>

            <div class="ranking-barra-wrapper">
                <div class="ranking-barra-fill theme-color-bg" style="width: <?= $porcentaje ?>%;"></div>
            </div>

            <a href="estadisticas_paginas.php?titulo=<?= urlencode($t['titulo']) ?>" class="ranking-link">
                Ver detalles →
            </a>
        </div>
    </div>
<?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Estrellas -->
    <div class="panel">
        <div class="panel-header">
            <h2>⭐ Distribución por Estrellas</h2>
        </div>

        <div style="margin-bottom: 10px;">
            <p style="margin: 4px 0;"><strong>Promedio general:</strong> <?= $promedioEstrellas ?> ★</p>
            <p style="margin: 4px 0;"><strong>Más común:</strong> <?= $estrellaComun ?> ★</p>
        </div>

        <div class="grafico-contenedor-barras">
            <canvas id="estrellasChart"></canvas>
        </div>

        <h3 style="font-size: 1rem; margin-top: 20px;">Filtrar por valoración</h3>
        <div class="acciones-perfil">
            <a href="estadisticas_estrellas.php?valor=5">5★</a>
            <a href="estadisticas_estrellas.php?valor=4">4★</a>
            <a href="estadisticas_estrellas.php?valor=3">3★</a>
            <a href="estadisticas_estrellas.php?valor=2">2★</a>
            <a href="estadisticas_estrellas.php?valor=1">1★</a>
        </div>
    </div>

    <!-- Tiempo leyendo en el año -->
    <div class="panel">
        <div class="panel-header">
            <h2>⏱️ Tiempo leyendo en <?= $year ?></h2>
        </div>

        <div class="tiempo-grid">
            <div class="tiempo-card">
                <span class="numero theme-color-text"><?= number_format($totalMinutosLectura, 0, '', '.') ?></span>
                <span class="etiqueta">Minutos totales</span>
            </div>
            <div class="tiempo-card">
                <span class="numero theme-color-text">
                    <?= $diasLectura ?>d <?= $horasLectura ?>h
                </span>
                <span class="etiqueta">Tiempo acumulado</span>
            </div>
        </div>
    </div>

    <div class="acciones-perfil">
        <a href="perfil.php">← Volver al perfil</a>
    </div>

</div>

<script>
function toggleFormReto() {
    const el = document.getElementById("formRetoWrapper");
    el.style.display = (el.style.display === "block") ? "none" : "block";
}

function obtenerColorTema() {
    const estilos = getComputedStyle(document.documentElement);
    
    const variables = [
        '--primary-color', 
        '--color-principal', 
        '--accent-color', 
        '--primary', 
        '--color-primario',
        '--main-color'
    ];
    
    for (let v of variables) {
        let val = estilos.getPropertyValue(v).trim();
        if (val && val !== '' && val !== 'black' && val !== '#000000') {
            return val;
        }
    }

    const titulo = document.querySelector('.panel-header h2') || document.querySelector('h2');
    if (titulo) {
        let colorTitulo = window.getComputedStyle(titulo).color;
        if (colorTitulo && colorTitulo !== 'rgb(0, 0, 0)') {
            return colorTitulo;
        }
    }

    return '#d4a373';
}

function colorToRgba(color, alpha) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = color;
    let hex = ctx.fillStyle;
    
    if (hex.startsWith('#')) {
        let r = parseInt(hex.slice(1, 3), 16);
        let g = parseInt(hex.slice(3, 5), 16);
        let b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }
    return color;
}

const colorTema = obtenerColorTema();
const colorTexto = getComputedStyle(document.documentElement).getPropertyValue('--text-color').trim() || '#555555';

document.querySelectorAll('.theme-color-text').forEach(el => el.style.color = colorTema);
document.querySelectorAll('.theme-color-bg').forEach(el => el.style.backgroundColor = colorTema);

// Objetivo anual 
new Chart(document.getElementById("objetivoChart"), {
    type: "doughnut",
    data: {
        labels: ["Completado", "Restante"],
        datasets: [{
            data: [<?= $porcentajeObjetivo ?>, <?= max(0, 100 - $porcentajeObjetivo) ?>],
            backgroundColor: [colorTema, "#e9ecef"],
            borderWidth: 0,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        rotation: -90,
        circumference: 180,
        cutout: "78%",
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});

// Libros leídos por mes
const ctxMeses = document.getElementById("mesesChart").getContext("2d");

const gradient = ctxMeses.createLinearGradient(0, 0, 0, 200);
gradient.addColorStop(0, colorToRgba(colorTema, 0.35));
gradient.addColorStop(1, colorToRgba(colorTema, 0.0));

new Chart(ctxMeses, {
    type: "line",
    data: {
        labels: <?= json_encode($mesesLabels) ?>,
        datasets: [{
            label: "Libros",
            data: <?= json_encode($mesesValores) ?>,
            borderColor: colorTema,
            backgroundColor: gradient,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: "#ffffff",
            pointBorderColor: colorTema,
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(0,0,0,0.8)',
                padding: 10,
                displayColors: false,
                callbacks: {
                    label: (context) => `${context.raw} ${context.raw === 1 ? 'libro' : 'libros'}`
                }
            }
        },
        scales: {
            x: { 
                grid: { display: false },
                ticks: { color: colorTexto, font: { size: 11 } }
            },
            y: { 
                beginAtZero: true, 
                ticks: { stepSize: 1, precision: 0, color: colorTexto, font: { size: 11 } },
                grid: { color: "#f0f0f0" }
            }
        }
    }
});

// Distribución por estrellas
new Chart(document.getElementById("estrellasChart"), {
    type: "bar",
    data: {
        labels: <?= json_encode(array_keys($distribucionEstrellas)) ?>,
        datasets: [{
            label: "Cantidad",
            data: <?= json_encode(array_values($distribucionEstrellas)) ?>,
            backgroundColor: colorTema,
            borderRadius: 5,
            borderSkipped: false,
            maxBarThickness: 36
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false } },
            y: { 
                beginAtZero: true, 
                ticks: { stepSize: 1, precision: 0 },
                grid: { color: "#f0f0f0" }
            }
        }
    }
});
</script>

</body>
</html>
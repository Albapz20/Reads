<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

/*  Notas agrupadas por estrella entera  */
if (!function_exists('grupoNota')) {
    // Agrupa cualquier nota en su estrella entera: 4, 4.1, 4.35 y 4.99 -> 4 (el 5 es solo 5; menos de 1 cuenta como 1)
    function grupoNota(float $v): int { return max(1, min(5, (int)floor($v + 0.00001))); }
    // Nota exacta para mostrar: 4.35 -> "4,35", 4.5 -> "4,5", 4 -> "4"
    function formatoNota(float $v): string { return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')); }
}

// Estrella pedida (1 a 5): incluye todos los libros con nota decimal
$valor = isset($_GET['valor']) ? (int)floor((float)str_replace(',', '.', (string)$_GET['valor'])) : 0;
if ($valor < 1) { header("Location: estadisticas.php"); exit; }
$valor = min(5, $valor);

// Periodo: año o todos los años.
$periodo = (($_GET['notas'] ?? 'todos') === 'anio') ? 'anio' : 'todos';
$year = date("Y");

$db = new Database();
$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

// Sincronizar valoraciones desde puntuaciones
try {
    $stmtSync = $db->pdo->prepare("UPDATE listas_lectura l
                JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                SET l.estrellas = p.estrellas
                WHERE l.usuario_id = ? AND (l.estrellas IS NULL OR l.estrellas = 0) AND p.estrellas > 0");
    $stmtSync->execute([$usuario["id"]]);
} catch (Exception $e) {}

// Todos los libros leídos con su nota 
$whereAnio = ($periodo === 'anio') ? " AND YEAR(l.fecha_fin) = ?" : "";
$params = [$usuario["id"]];
if ($periodo === 'anio') $params[] = $year;

try {
    $sql = "SELECT l.id, l.libro_id, l.titulo, l.autores, l.paginas_totales, l.paginas_leidas, l.fecha_fin, l.portada,
                   COALESCE(
                       (SELECT p.estrellas FROM puntuaciones p
                         WHERE p.usuario_id = l.usuario_id
                           AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                           AND p.estrellas > 0
                         ORDER BY (p.libro_id = l.libro_id) DESC LIMIT 1),
                       NULLIF(l.estrellas, 0), 0) AS estrellas_reales
            FROM listas_lectura l
            WHERE l.usuario_id = ? AND l.estado = 'leido' {$whereAnio}
            ORDER BY l.fecha_fin DESC, l.id DESC";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sql = "SELECT l.id, l.libro_id, l.titulo, l.autores, l.paginas_totales, l.paginas_leidas, l.fecha_fin, l.portada,
                   l.estrellas AS estrellas_reales
            FROM listas_lectura l
            WHERE l.usuario_id = ? AND l.estado = 'leido' {$whereAnio}
            ORDER BY l.fecha_fin DESC, l.id DESC";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$conteos = [];
$libros  = [];
foreach ($filas as $f) {
    $nota = (float)$f['estrellas_reales'];
    if ($nota <= 0) continue;
    $grupo = grupoNota($nota);
    $conteos[$grupo] = ($conteos[$grupo] ?? 0) + 1;
    if ($grupo === $valor) { $f['nota_exacta'] = $nota; $libros[] = $f; }
}
$totalPuntuados = array_sum($conteos);

// Estadísticas de este tramo
$totalLibros = count($libros);
$paginasLibros = array_map(fn($l) => max((int)$l['paginas_totales'], (int)$l['paginas_leidas']), $libros);
$totalPaginas = array_sum($paginasLibros);
$promedioPaginas = $totalLibros > 0 ? (int)round($totalPaginas / $totalLibros) : 0;
$maxPaginas = $totalLibros > 0 ? max($paginasLibros) : 0;
$libroLargo = null;
foreach ($libros as $i => $l) {
    if ($paginasLibros[$i] === $maxPaginas && $maxPaginas > 0) { $libroLargo = $l; break; }
}
$porcentajeDelTotal = $totalPuntuados > 0 ? round(($totalLibros / $totalPuntuados) * 100) : 0;
$relleno = round(($valor / 5) * 100, 1);
$portadasAbanico = array_slice(array_values(array_filter($libros, fn($l) => !empty($l['portada']))), 0, 5);

function urlNota(int $v, string $periodo): string {
    return 'estadisticas_estrellas.php?valor=' . $v . '&notas=' . $periodo;
}
$rangoTexto = ($valor === 5) ? 'solo libros de 5★' : 'notas de ' . $valor . ' a ' . $valor . ',99';
$volver = 'estadisticas.php?notas=' . $periodo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⭐ <?= htmlspecialchars($valor . '★') ?> — Estadísticas</title>
<script src="main.js"></script>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
</head>
<body class="page-estadisticas-estrellas">

<div class="ne-wrap">

    <a href="<?= htmlspecialchars($volver) ?>" class="ne-volver">← Estadísticas</a>

    <!-- Cabecera visual -->
    <section class="ne-hero">
        <div class="ne-hero-texto">
            <span class="ne-etiqueta">Tus lecturas de</span>
            <div class="ne-nota-linea">
                <span class="ne-numero"><?= $valor ?></span>
                <span class="ne-estrellas" style="--p: <?= $relleno ?>%;" aria-hidden="true">★★★★★</span>
            </div>
            <p class="ne-resumen">
                <strong><?= $totalLibros ?></strong> <?= $totalLibros === 1 ? 'libro' : 'libros' ?>
                <?php if ($totalPuntuados > 0): ?> · <?= $porcentajeDelTotal ?>% de tus <?= $totalPuntuados ?> libros puntuados<?php endif; ?>
            </p>
            <div class="ne-barra" aria-hidden="true"><div style="width: <?= $porcentajeDelTotal ?>%;"></div></div>
        </div>

        <?php if (!empty($portadasAbanico)): ?>
            <div class="ne-abanico" aria-hidden="true">
                <?php
                $giros = [-9, -4, 0, 4, 9];
                $offset = intdiv(5 - count($portadasAbanico), 2);
                foreach ($portadasAbanico as $i => $p): ?>
                    <span class="ne-mini" style="--r: <?= $giros[$i + $offset] ?>deg; z-index: <?= 10 - abs($i + $offset - 2) ?>;">
                        <img src="<?= htmlspecialchars($p['portada']) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Selector de notas: pasar de una a otra sin volver atrás -->
    <nav class="ne-selector" aria-label="Elegir valoración">
        <?php for ($k = 5; $k >= 1; $k--):
            $cant = $conteos[$k] ?? 0;
            if ($cant === 0 && $k !== $valor) continue; ?>
            <a href="<?= htmlspecialchars(urlNota($k, $periodo)) ?>" class="ne-chip <?= $k === $valor ? 'activo' : '' ?>">
                <?= $k ?>★ <span class="n"><?= $cant ?></span>
            </a>
        <?php endfor; ?>

        <span class="ne-periodo">
            <a href="<?= htmlspecialchars('estadisticas_estrellas.php?valor=' . $valor . '&notas=anio') ?>" class="<?= $periodo === 'anio' ? 'activo' : '' ?>">📅 <?= htmlspecialchars($year) ?></a>
            <a href="<?= htmlspecialchars('estadisticas_estrellas.php?valor=' . $valor . '&notas=todos') ?>" class="<?= $periodo === 'todos' ? 'activo' : '' ?>">🌍 Todos</a>
        </span>
    </nav>

    <!-- Cifras -->
    <section class="ne-kpis">
        <div class="ne-kpi"><span class="valor"><?= $totalLibros ?></span><span class="etq">Libros</span></div>
        <div class="ne-kpi"><span class="valor"><?= number_format($totalPaginas, 0, '', '.') ?></span><span class="etq">Páginas en total</span></div>
        <div class="ne-kpi"><span class="valor"><?= number_format($promedioPaginas, 0, '', '.') ?></span><span class="etq">Páginas de media</span></div>
        <div class="ne-kpi">
            <span class="valor"><?= number_format($maxPaginas, 0, '', '.') ?></span>
            <span class="etq">El más largo<?= $libroLargo ? ' · ' . htmlspecialchars(mb_strimwidth($libroLargo['titulo'], 0, 28, '…')) : '' ?></span>
        </div>
    </section>

    <!-- Libros -->
    <section class="ne-card">
        <h2>📚 Libros de <?= $valor ?>★ <small class="ne-rango">(<?= $rangoTexto ?>)</small></h2>

        <?php if ($totalLibros === 0): ?>
            <p class="vacio">No hay libros con esta valoración<?= $periodo === 'anio' ? ' en ' . htmlspecialchars($year) : '' ?>.</p>
        <?php else: ?>
            <div class="ne-lista">
                <?php foreach ($libros as $i => $l):
                    $idLink = ($l['libro_id'] ?? '') !== '' ? $l['libro_id'] : $l['id'];
                    $fecha = !empty($l['fecha_fin']) ? date('d/m/Y', strtotime($l['fecha_fin'])) : 'Sin fecha';
                    $autor = trim(explode(',', (string)($l['autores'] ?? ''))[0]);
                ?>
                    <a class="ne-libro" href="libro.php?id=<?= urlencode((string)$idLink) ?>">
                        <span class="ne-cover">
                            <span class="sin" aria-hidden="true">📖</span>
                            <?php if (!empty($l['portada'])): ?>
                                <img src="<?= htmlspecialchars($l['portada']) ?>" alt="Portada de <?= htmlspecialchars($l['titulo']) ?>" loading="lazy" onerror="this.style.display='none'">
                            <?php endif; ?>
                        </span>
                        <span class="ne-info">
                            <strong><?= htmlspecialchars($l['titulo']) ?></strong>
                            <span class="nota-exacta"><?= formatoNota((float)$l['nota_exacta']) ?>★</span>
                            <?php if ($autor !== ''): ?><span class="autor"><?= htmlspecialchars($autor) ?></span><?php endif; ?>
                            <span class="meta">
                                <?= $paginasLibros[$i] > 0 ? number_format($paginasLibros[$i], 0, '', '.') . ' págs.' : 'Sin páginas' ?>
                                · <?= htmlspecialchars($fecha) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="estadisticas.php" class="nav-card-float active"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="calendario.php" class="nav-card-float"><span class="nav-icon">📅</span><span>Calendario</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="ajustes.php" class="nav-card-float"><span class="nav-icon">⚙️</span><span>Ajustes</span></a>
    </nav>
</div>

</body>
</html>
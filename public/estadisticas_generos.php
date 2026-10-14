<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/GenerosHelper.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$genero = trim((string)($_GET['genero'] ?? ''));
if ($genero === '') { header("Location: estadisticas.php"); exit; }

// Periodo: "anio" (solo el año actual) o "todos"
$periodo = (($_GET['generos'] ?? 'anio') === 'todos') ? 'todos' : 'anio';
$year = date("Y");

$db = new Database();
$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

$col = columnaGeneros($db->pdo);
if ($col === null) { header("Location: estadisticas.php"); exit; }

// Libros leídos con su género y su nota (una sola por libro)
$whereAnio = ($periodo === 'anio') ? " AND YEAR(l.fecha_fin) = ?" : "";
$params = [$usuario["id"]];
if ($periodo === 'anio') $params[] = $year;

try {
    $sql = "SELECT l.id, l.libro_id, l.titulo, l.autores, l.paginas_totales, l.paginas_leidas, l.fecha_fin, l.portada,
                   l.`{$col}` AS generos_raw,
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
                   l.`{$col}` AS generos_raw, l.estrellas AS estrellas_reales
            FROM listas_lectura l
            WHERE l.usuario_id = ? AND l.estado = 'leido' {$whereAnio}
            ORDER BY l.fecha_fin DESC, l.id DESC";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Se agrupa en PHP con las mismas funciones que el mapa de estadisticas.php
$conteos = [];
$libros  = [];
$conGenero = 0;
foreach ($filas as $f) {
    $lista = normalizarGeneros(separarGeneros((string)($f['generos_raw'] ?? '')));
    if (!$lista) continue;
    $conGenero++;
    foreach ($lista as $g) $conteos[$g] = ($conteos[$g] ?? 0) + 1;
    if (in_array($genero, $lista, true)) $libros[] = $f;
}
arsort($conteos);

// Cifras de este género
$totalLibros = count($libros);
$paginasLibros = array_map(fn($l) => max((int)$l['paginas_totales'], (int)$l['paginas_leidas']), $libros);
$totalPaginas = array_sum($paginasLibros);
$promedioPaginas = $totalLibros > 0 ? (int)round($totalPaginas / $totalLibros) : 0;
$notas = array_values(array_filter(array_map(fn($l) => (float)$l['estrellas_reales'], $libros), fn($v) => $v > 0));
$notaMedia = !empty($notas) ? round(array_sum($notas) / count($notas), 2) : null;
$porcentaje = $conGenero > 0 ? round(($totalLibros / $conGenero) * 100) : 0;
$portadasAbanico = array_slice(array_values(array_filter($libros, fn($l) => !empty($l['portada']))), 0, 5);

[$emoji, $color] = estiloGenero($genero);

function urlGenero(string $g, string $periodo): string {
    return 'estadisticas_generos.php?genero=' . urlencode($g) . '&generos=' . $periodo;
}
$volver = 'estadisticas.php?generos=' . $periodo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($emoji . ' ' . $genero) ?> — Estadísticas</title>
<script src="main.js"></script>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
</head>
<body class="page-estadisticas-estrellas">

<div class="ne-wrap">

    <a href="<?= htmlspecialchars($volver) ?>" class="ne-volver">← Estadísticas</a>

    <!-- Cabecera visual -->
    <section class="ne-hero genero" style="--g: <?= htmlspecialchars($color) ?>;">
        <div class="ne-hero-texto">
            <span class="ne-etiqueta">Tus lecturas de</span>
            <div class="ne-nota-linea">
                <span class="ne-genero-emoji" aria-hidden="true"><?= $emoji ?></span>
                <span class="ne-genero-titulo"><?= htmlspecialchars($genero) ?></span>
            </div>
            <p class="ne-resumen">
                <strong><?= $totalLibros ?></strong> <?= $totalLibros === 1 ? 'libro' : 'libros' ?>
                <?php if ($conGenero > 0): ?> · <?= $porcentaje ?>% de tus <?= $conGenero ?> libros con género<?php endif; ?>
            </p>
            <div class="ne-barra" aria-hidden="true"><div style="width: <?= $porcentaje ?>%;"></div></div>
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

    <!-- Selector de géneros: pasar de uno a otro sin volver atrás -->
    <nav class="ne-selector" aria-label="Elegir género">
        <?php
        $lista = $conteos;
        if (!isset($lista[$genero])) $lista[$genero] = 0;
        foreach ($lista as $g => $cant):
            [$emo] = estiloGenero($g); ?>
            <a href="<?= htmlspecialchars(urlGenero($g, $periodo)) ?>" class="ne-chip <?= $g === $genero ? 'activo' : '' ?>">
                <?= $emo ?> <?= htmlspecialchars($g) ?> <span class="n"><?= (int)$cant ?></span>
            </a>
        <?php endforeach; ?>

        <span class="ne-periodo">
            <a href="<?= htmlspecialchars(urlGenero($genero, 'anio')) ?>" class="<?= $periodo === 'anio' ? 'activo' : '' ?>">📅 <?= htmlspecialchars($year) ?></a>
            <a href="<?= htmlspecialchars(urlGenero($genero, 'todos')) ?>" class="<?= $periodo === 'todos' ? 'activo' : '' ?>">🌍 Todos</a>
        </span>
    </nav>

    <!-- Cifras -->
    <section class="ne-kpis">
        <div class="ne-kpi"><span class="valor"><?= $totalLibros ?></span><span class="etq">Libros</span></div>
        <div class="ne-kpi"><span class="valor"><?= number_format($totalPaginas, 0, '', '.') ?></span><span class="etq">Páginas en total</span></div>
        <div class="ne-kpi"><span class="valor"><?= number_format($promedioPaginas, 0, '', '.') ?></span><span class="etq">Páginas de media</span></div>
        <div class="ne-kpi">
            <span class="valor"><?= $notaMedia !== null ? formatoNota($notaMedia) . '★' : '—' ?></span>
            <span class="etq">Nota media<?= $notaMedia !== null ? ' · ' . count($notas) . ($totalLibros === 1 ? ' puntuado' : ' puntuados') : '' ?></span>
        </div>
    </section>

    <!-- Libros -->
    <section class="ne-card">
        <h2><?= $emoji ?> Libros de <?= htmlspecialchars($genero) ?></h2>

        <?php if ($totalLibros === 0): ?>
            <p class="vacio">No hay libros de este género<?= $periodo === 'anio' ? ' en ' . htmlspecialchars($year) : '' ?>.</p>
        <?php else: ?>
            <div class="ne-lista">
                <?php foreach ($libros as $i => $l):
                    $idLink = ($l['libro_id'] ?? '') !== '' ? $l['libro_id'] : $l['id'];
                    $fecha = !empty($l['fecha_fin']) ? date('d/m/Y', strtotime($l['fecha_fin'])) : 'Sin fecha';
                    $autor = trim(explode(',', (string)($l['autores'] ?? ''))[0]);
                    $nota = (float)$l['estrellas_reales'];
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
                            <?php if ($nota > 0): ?><span class="nota-exacta"><?= formatoNota($nota) ?>★</span><?php endif; ?>
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
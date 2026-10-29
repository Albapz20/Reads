<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/AjustesService.php";
require_once "../src/UserService.php";
require_once "../src/GenerosHelper.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

// Token para el relleno automático de páginas en segundo plano
if (empty($_SESSION['csrf_progreso'])) {
    $_SESSION['csrf_progreso'] = bin2hex(random_bytes(16));
}
$csrfPaginas = $_SESSION['csrf_progreso'];

if (!function_exists('grupoNota')) {
    // Agrupa cualquier nota en su estrella entera: 4, 4.1, 4.35 y 4.99 -> 4
    function grupoNota(float $v): int { return max(1, min(5, (int)floor($v + 0.00001))); }
}

$db = new Database();
$ajustesService = new AjustesService();
$userService    = new UserService();

// Procesar formulario de actualización del objetivo anual
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["nuevo_objetivo"])) {
    $nuevoObjetivo = max(1, (int)$_POST["nuevo_objetivo"]);

    $stmtCheck = $db->pdo->prepare("SELECT id FROM ajustes_usuario WHERE usuario_id = ?");
    $stmtCheck->execute([$usuario["id"]]);
    $existe = $stmtCheck->fetchColumn();

    if ($existe) {
        $stmtOpt = $db->pdo->prepare("UPDATE ajustes_usuario SET objetivo_anual = ? WHERE usuario_id = ?");
        $stmtOpt->execute([$nuevoObjetivo, $usuario["id"]]);
    } else {
        $stmtOpt = $db->pdo->prepare("INSERT INTO ajustes_usuario (usuario_id, objetivo_anual) VALUES (?, ?)");
        $stmtOpt->execute([$usuario["id"], $nuevoObjetivo]);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$stmtAjustes = $db->pdo->prepare("SELECT objetivo_anual FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
$stmtAjustes->execute([$usuario["id"]]);
$ajustes = $stmtAjustes->fetch(PDO::FETCH_ASSOC) ?: [];
$year = date("Y");
$yearPrev = (int)$year - 1;
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$esAdmin = (strtolower(trim($datos['email'] ?? $usuario['email'] ?? '')) === 'admin@admin.com');

// Páginas reales de un libro leído.
$exprPaginas = "COALESCE(NULLIF(CAST(paginas_totales AS SIGNED), 0), NULLIF(CAST(paginas_leidas AS SIGNED), 0), 0)";

// Filtro Top 5
$periodoTop = $_GET['periodo'] ?? 'anio';
if (!in_array($periodoTop, ['anio', 'todos'])) $periodoTop = 'anio';

if ($periodoTop === 'todos') {
    $whereTop = "";
    $paramsTop = [$usuario["id"]];
} else {
    $whereTop = " AND YEAR(fecha_fin) = ?";
    $paramsTop = [$usuario["id"], $year];
}

// Obtener libros leídos
$sqlPaginas = "SELECT id, libro_id, titulo, autores,
                      paginas_totales, paginas_leidas,
                      {$exprPaginas} AS paginas_reales,
                      portada, estado
               FROM listas_lectura
               WHERE usuario_id = ? AND estado = 'leido' {$whereTop}";
$stmt = $db->pdo->prepare($sqlPaginas);
$stmt->execute($paramsTop);
$todosLosLibrosPeriodo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Solo entran en el ranking los libros que tienen páginas; el resto se avisa debajo
$librosSinPaginasPeriodo = count(array_filter($todosLosLibrosPeriodo, fn($b) => (int)$b['paginas_reales'] <= 0));
$conPaginasPeriodo = array_values(array_filter($todosLosLibrosPeriodo, fn($b) => (int)$b['paginas_reales'] > 0));
$sinPaginasLista = array_values(array_filter($todosLosLibrosPeriodo, fn($b) => (int)$b['paginas_reales'] <= 0));

// Ordenar por mayor número de páginas y extraer el Top 5
usort($conPaginasPeriodo, fn($a, $b) => (int)$b['paginas_reales'] <=> (int)$a['paginas_reales']);
$topPaginas = array_slice($conPaginasPeriodo, 0, 5);
$topValores = array_map(fn($item) => (int)$item['paginas_reales'], $topPaginas);

$stmtFalta = $db->pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN {$exprPaginas} = 0 THEN 1 ELSE 0 END), 0) AS sin_paginas,
        COALESCE(SUM(CASE WHEN paginas_totales = 300 THEN 1 ELSE 0 END), 0) AS con_300
    FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido'");
$stmtFalta->execute([$usuario["id"]]);
$falta = $stmtFalta->fetch(PDO::FETCH_ASSOC) ?: ['sin_paginas' => 0, 'con_300' => 0];
$sinPaginasN = (int)$falta['sin_paginas'];
$con300N     = (int)$falta['con_300'];

// Libros leídos por mes
$stmt = $db->pdo->prepare("SELECT MONTH(fecha_fin) AS mes, COUNT(*) AS total
                           FROM listas_lectura
                           WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                           GROUP BY MONTH(fecha_fin)");
$stmt->execute([$usuario["id"], $year]);
$mesesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mesesNombres = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
$mesesCompletos = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
$mesesLabels = [];
$mesesValores = [];
for ($i = 1; $i <= 12; $i++) {
    $mesesLabels[] = $mesesNombres[$i - 1];
    $mesesValores[] = isset($mesesData[$i]) ? (int)$mesesData[$i] : 0;
}

// Total de libros leídos en el año
$stmt = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmt->execute([$usuario["id"], $year]);
$leidosEsteAño = (int)$stmt->fetchColumn();

// Objetivo, porcentaje y mensaje de ritmo
$objetivo = (int)($ajustes["objetivo_anual"] ?? 0);
$porcentajeObjetivo = $objetivo > 0 ? min(100, round(($leidosEsteAño / $objetivo) * 100)) : 0;
$faltan = max(0, $objetivo - $leidosEsteAño);

$diaDelAno = (int)date("z") + 1;
$diasTotalesAno = (date("L") == 1) ? 366 : 365;
$esperados = $objetivo > 0 ? ($objetivo / $diasTotalesAno) * $diaDelAno : 0;
if ($objetivo <= 0) {
    $mensajeReto = "Fija un reto para seguir tu progreso.";
} elseif ($leidosEsteAño >= $objetivo) {
    $mensajeReto = "¡Reto completado!";
} elseif ($leidosEsteAño >= floor($esperados)) {
    $mensajeReto = "Vas bien, te faltan $faltan " . ($faltan === 1 ? "libro" : "libros") . ".";
} else {
    $mensajeReto = "Te faltan $faltan " . ($faltan === 1 ? "libro" : "libros") . ". ¡Aún puedes lograrlo!";
}

// Tiempo de lectura
$stmtTiempo = $db->pdo->prepare("SELECT COALESCE(SUM({$exprPaginas}), 0) FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmtTiempo->execute([$usuario["id"], $year]);
$totalPaginasAño = (int)$stmtTiempo->fetchColumn();

$minutosPorPagina = 1.5;
$totalMinutosLectura = (int)round($totalPaginasAño * $minutosPorPagina);
$diasLectura = floor($totalMinutosLectura / 1440);
$horasLectura = floor(($totalMinutosLectura % 1440) / 60);

// Comparativa con el año anterior
$stmtPrev = $db->pdo->prepare("SELECT COUNT(*) AS libros,
                                      COALESCE(SUM({$exprPaginas}), 0) AS paginas
                               FROM listas_lectura
                               WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmtPrev->execute([$usuario["id"], $yearPrev]);
$prev = $stmtPrev->fetch(PDO::FETCH_ASSOC) ?: ['libros' => 0, 'paginas' => 0];
$leidosAnioPrev  = (int)$prev['libros'];
$paginasAnioPrev = (int)$prev['paginas'];

// Pinta la diferencia con el año anterior
function badgeComparativa(int $actual, int $anterior, int $anioPrev): string {
    if ($anterior <= 0) {
        return '<span class="st-delta neutro">Sin datos de ' . $anioPrev . '</span>';
    }
    $dif = $actual - $anterior;
    if ($dif > 0) {
        return '<span class="st-delta sube">+' . number_format($dif, 0, '', '.') . ' vs ' . $anioPrev . '</span>';
    }
    if ($dif < 0) {
        return '<span class="st-delta baja">' . number_format($dif, 0, '', '.') . ' vs ' . $anioPrev . '</span>';
    }
    return '<span class="st-delta neutro">Igual que en ' . $anioPrev . '</span>';
}

// Libros del año (para autores y datos curiosos)
$librosAnio = [];
try {
    $stmt = $db->pdo->prepare("SELECT titulo, autores, fecha_inicio, fecha_fin,
                                      {$exprPaginas} AS paginas_reales
                               FROM listas_lectura
                               WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
    $stmt->execute([$usuario["id"], $year]);
    $librosAnio = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $librosAnio = [];
}

// Autores más leídos
$conteoAutores = [];
$nombreAutor   = [];
foreach ($librosAnio as $libro) {
    $autoresLibro = array_filter(array_map('trim', explode(',', (string)($libro['autores'] ?? ''))));
    foreach (array_unique($autoresLibro) as $aut) {
        $clave = mb_strtolower($aut, 'UTF-8');
        $conteoAutores[$clave] = ($conteoAutores[$clave] ?? 0) + 1;
        $nombreAutor[$clave] = $nombreAutor[$clave] ?? $aut;
    }
}
arsort($conteoAutores);
$topAutores = [];
foreach (array_slice($conteoAutores, 0, 5, true) as $clave => $n) {
    $topAutores[] = ['nombre' => $nombreAutor[$clave], 'libros' => $n];
}
$maxLibrosAutor = !empty($topAutores) ? max(array_column($topAutores, 'libros')) : 1;

// Datos curiosos
$conPaginas = array_values(array_filter($librosAnio, fn($b) => (int)$b['paginas_reales'] > 0));
$libroLargo = null;
$libroCorto = null;
foreach ($conPaginas as $b) {
    if ($libroLargo === null || (int)$b['paginas_reales'] > (int)$libroLargo['paginas_reales']) $libroLargo = $b;
    if ($libroCorto === null || (int)$b['paginas_reales'] < (int)$libroCorto['paginas_reales']) $libroCorto = $b;
}

$mediaPaginasLibro = $leidosEsteAño > 0 ? (int)round($totalPaginasAño / $leidosEsteAño) : 0;
$paginasPorDia = $diaDelAno > 0 ? round($totalPaginasAño / $diaDelAno, 1) : 0;

$maxMes = max($mesesValores);
$mejorMes = $maxMes > 0 ? ucfirst($mesesCompletos[array_search($maxMes, $mesesValores)]) : null;

// Días medios para terminar un libro (usa fecha_inicio y fecha_fin)
$duraciones = [];
foreach ($librosAnio as $b) {
    $ini = strtotime((string)($b['fecha_inicio'] ?? ''));
    $fin = strtotime((string)($b['fecha_fin'] ?? ''));
    if ($ini && $fin && $fin >= $ini) {
        $duraciones[] = max(1, (int)round(($fin - $ini) / 86400) + 1);
    }
}
$diasMediosLibro = !empty($duraciones) ? (int)round(array_sum($duraciones) / count($duraciones)) : 0;

// Sincronizar y obtener distribución por estrellas
try {
    $stmtSync = $db->pdo->prepare("UPDATE listas_lectura l
                JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                SET l.estrellas = p.estrellas
                WHERE l.usuario_id = ? AND (l.estrellas IS NULL OR l.estrellas = 0) AND p.estrellas > 0");
    $stmtSync->execute([$usuario["id"]]);
} catch (Exception $e) {}

// Una sola nota por libro
try {
    $stmt = $db->pdo->prepare("SELECT COALESCE(
                           (SELECT p.estrellas FROM puntuaciones p
                             WHERE p.usuario_id = l.usuario_id
                               AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                               AND p.estrellas > 0
                             ORDER BY (p.libro_id = l.libro_id) DESC LIMIT 1),
                           NULLIF(l.estrellas, 0), 0) AS estrellas,
                       YEAR(l.fecha_fin) AS anio
                     FROM listas_lectura l
                     WHERE l.usuario_id = ? AND l.estado = 'leido'");
    $stmt->execute([$usuario["id"]]);
    $filasNotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stmt = $db->pdo->prepare("SELECT estrellas, YEAR(fecha_fin) AS anio FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido'");
    $stmt->execute([$usuario["id"]]);
    $filasNotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Resume una lista de notas: promedio, nota más común y distribución (5, 4, 3, 2, 1)
// Cada nota va a su estrella entera: 4, 4.1, 4.35 o 4.9 cuentan como 4 estrellas
function resumenEstrellas(array $valores): array {
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    if (empty($valores)) return ['promedio' => 0, 'comun' => 0, 'dist' => $dist, 'total' => 0];

    foreach ($valores as $v) {
        $dist[grupoNota((float)$v)]++;
    }

    // Nota más común (si hay empate, la más alta)
    $maxCant = max($dist);
    $comun = 0;
    foreach ($dist as $n => $cant) {
        if ($cant === $maxCant && $n > $comun) $comun = $n;
    }

    return [
        'promedio' => round(array_sum($valores) / count($valores), 2),
        'comun'    => $comun,
        'dist'     => $dist,
        'total'    => count($valores),
    ];
}

$notasTodasLista = [];
$notasAnioLista  = [];
foreach ($filasNotas as $fila) {
    $v = (float)$fila['estrellas'];
    if ($v <= 0) continue;
    $notasTodasLista[] = $v;
    if ((string)$fila['anio'] === (string)$year) $notasAnioLista[] = $v;
}
$notasTodos = resumenEstrellas($notasTodasLista);
$notasAnio  = resumenEstrellas($notasAnioLista);

// Pestaña elegida para la sección de estrellas (independiente del Top 5)
$periodoNotas = $_GET['notas'] ?? 'anio';
if (!in_array($periodoNotas, ['anio', 'todos'])) $periodoNotas = 'anio';
$notasMostradas = ($periodoNotas === 'todos') ? $notasTodos : $notasAnio;

$promedioEstrellas     = $notasMostradas['promedio'];
$estrellaComun         = $notasMostradas['comun'];
$distribucionEstrellas = $notasMostradas['dist'];
$totalDistribucion     = array_sum($distribucionEstrellas);
$porcentajeRelleno     = round(($promedioEstrellas / 5) * 100, 1);

/*  Mapa de géneros  */
$periodoGeneros = $_GET['generos'] ?? 'anio';
if (!in_array($periodoGeneros, ['anio', 'todos'])) $periodoGeneros = 'anio';

$colGeneros = columnaGeneros($db->pdo);
$mapaGeneros = [];
$librosConGenero = 0;
$librosSinGenero = 0;

if ($colGeneros !== null) {
    $whereGen  = ($periodoGeneros === 'anio') ? " AND YEAR(fecha_fin) = ?" : "";
    $paramsGen = [$usuario["id"]];
    if ($periodoGeneros === 'anio') $paramsGen[] = $year;

    try {
        $stmt = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, `{$colGeneros}` AS generos_raw
                                   FROM listas_lectura
                                   WHERE usuario_id = ? AND estado = 'leido' {$whereGen}");
        $stmt->execute($paramsGen);
        $filasGen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $filasGen = [];
    }

    // Cuenta libros por género (un libro puede estar en varios)
    $conteoGeneros = [];
    $titulosGeneros = [];
    foreach ($filasGen as $g) {
        $lista = normalizarGeneros(separarGeneros((string)($g['generos_raw'] ?? '')));
        if (!$lista) { $librosSinGenero++; continue; }
        $librosConGenero++;
        foreach ($lista as $nombre) {
            $conteoGeneros[$nombre] = ($conteoGeneros[$nombre] ?? 0) + 1;
            $titulosGeneros[$nombre][] = $g['titulo'];
        }
    }
    arsort($conteoGeneros);

    $i = 0;
    $restoN = 0;
    foreach ($conteoGeneros as $nombre => $n) {
        if ($i < 11) {
            $mapaGeneros[] = ['nombre' => $nombre, 'n' => $n, 'libros' => array_slice($titulosGeneros[$nombre], 0, 6)];
        } else {
            $restoN += $n;
        }
        $i++;
    }
    if ($restoN > 0) $mapaGeneros[] = ['nombre' => 'Más géneros', 'n' => $restoN, 'libros' => [], 'resto' => true];
}

// Libros de toda la biblioteca pendientes de género 
$pendientesGeneros = 0;
if ($colGeneros !== null) {
    $c = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ? AND " . condicionGeneroPendiente($colGeneros));
    $c->execute([$usuario["id"]]);
    $pendientesGeneros = (int)$c->fetchColumn();
}

// Enlaces de las pestañas: cada una cambia solo su parámetro y conserva los otros
function urlEstadisticas(array $cambios): string {
    global $periodoTop, $periodoNotas, $periodoGeneros;
    return 'estadisticas.php?' . http_build_query(array_merge(['periodo' => $periodoTop, 'notas' => $periodoNotas, 'generos' => $periodoGeneros], $cambios));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="main.js"></script>
<title>Estadísticas de lectura</title>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="page-estadisticas">

<div class="st-wrap">

    <header class="st-header">
        <h1>Estadísticas de lectura</h1>
        <p>Tu año lector <?= htmlspecialchars($year) ?> de un vistazo</p>
    </header>

    <!-- Resumen -->
    <section class="st-kpis">
        <div class="st-kpi">
            <span class="valor"><?= number_format($leidosEsteAño, 0, '', '.') ?></span>
            <span class="etiqueta">Libros en <?= htmlspecialchars($year) ?></span>
            <?= badgeComparativa($leidosEsteAño, $leidosAnioPrev, $yearPrev) ?>
        </div>
        <div class="st-kpi">
            <span class="valor"><?= number_format($totalPaginasAño, 0, '', '.') ?></span>
            <span class="etiqueta">Páginas leídas</span>
            <?= badgeComparativa($totalPaginasAño, $paginasAnioPrev, $yearPrev) ?>
        </div>
        <div class="st-kpi">
            <span class="valor"><?= $diasLectura ?>d <?= $horasLectura ?>h</span>
            <span class="etiqueta"><?= number_format($totalMinutosLectura, 0, '', '.') ?> minutos leyendo</span>
        </div>
        <div class="st-kpi">
            <span class="valor"><?= $notasAnio['promedio'] > 0 ? $notasAnio['promedio'] : '—' ?></span>
            <span class="etiqueta">Nota media <?= htmlspecialchars($year) ?> · total <?= $notasTodos['promedio'] > 0 ? $notasTodos['promedio'] : '—' ?></span>
        </div>
    </section>

    <div class="st-grid">

        <!-- Objetivo anual -->
        <section class="st-card">
            <div class="st-card-head"><h2>Objetivo anual</h2></div>
            <div class="objetivo-card-wrapper">
                <div class="grafico-dona-contenedor">
                    <canvas id="objetivoChart"></canvas>
                    <div class="objetivo-centro-texto">
                        <span class="porcentaje"><?= $porcentajeObjetivo ?>%</span>
                        <span class="progreso-sub"><?= $leidosEsteAño ?> / <?= $objetivo ?> libros</span>
                    </div>
                </div>
                <p class="objetivo-mensaje"><?= htmlspecialchars($mensajeReto) ?></p>
                <button class="btn-reto" onclick="toggleFormReto()">Establecer reto de lectura</button>
                <div class="form-reto-wrapper" id="formRetoWrapper">
                    <form action="" method="POST">
                        <input type="number" name="nuevo_objetivo" min="1" value="<?= $objetivo ?: 1 ?>" required placeholder="Nº de libros">
                        <button type="submit" class="btn-guardar-reto">Guardar</button>
                    </form>
                </div>
            </div>
        </section>

        <!-- Libros por mes -->
        <section class="st-card">
            <div class="st-card-head"><h2>Libros leídos por mes</h2></div>
            <div class="grafico-contenedor"><canvas id="mesesChart"></canvas></div>
        </section>

        <!-- Top 5 por páginas -->
        <section class="st-card ancho">
            <div class="st-card-head">
                <h2>Top 5 libros por páginas</h2>
                <div class="filtro-pestañas">
                    <a href="<?= htmlspecialchars(urlEstadisticas(['periodo' => 'anio'])) ?>" class="filtro-btn <?= $periodoTop === 'anio' ? 'activo' : 'inactivo' ?>"><?= htmlspecialchars($year) ?></a>
                    <a href="<?= htmlspecialchars(urlEstadisticas(['periodo' => 'todos'])) ?>" class="filtro-btn <?= $periodoTop === 'todos' ? 'activo' : 'inactivo' ?>">Todos</a>
                </div>
            </div>

            <div class="ranking-lista">
                <?php if (empty($topPaginas)): ?>
                    <p class="vacio">No hay libros leídos con páginas registradas en este período.</p>
                <?php else:
                    $maxPaginas = (!empty($topValores) && max($topValores) > 0) ? max($topValores) : 1;
                    foreach ($topPaginas as $index => $t):
                        $paginasLibro = (int)$t['paginas_reales'];
                        $porcentaje = $paginasLibro > 0 ? round(($paginasLibro / $maxPaginas) * 100) : 0;
                        $posicion = $index + 1;
                        $idLink = ($t['libro_id'] ?? '') !== '' ? $t['libro_id'] : $t['id'];
                ?>
                    <div class="ranking-card pos-<?= $posicion ?>">
                        <div class="ranking-portada-box">
                            <img src="<?= htmlspecialchars($t['portada'] ?: 'img/sin_portada.png') ?>" alt="Portada de <?= htmlspecialchars($t['titulo']) ?>"
                                 class="ranking-portada" loading="lazy" onerror="this.style.display='none'">
                            <span class="ranking-badge">#<?= $posicion ?></span>
                        </div>
                        <div class="ranking-detalles">
                            <div class="ranking-top-info">
                                <strong class="ranking-titulo"><?= htmlspecialchars($t['titulo']) ?></strong>
                                <span class="ranking-paginas-tag"><?= number_format($paginasLibro, 0, '', '.') ?> pág.</span>
                            </div>
                            <div class="ranking-barra-wrapper"><div class="ranking-barra-fill" style="width: <?= $porcentaje ?>%;"></div></div>
                            <a href="libro.php?id=<?= urlencode((string)$idLink) ?>" class="ranking-link">Ver detalles →</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <?php if ($librosSinPaginasPeriodo > 0): ?>
                <p class="mapa-pie"><?= (int)$librosSinPaginasPeriodo ?> <?= $librosSinPaginasPeriodo === 1 ? 'libro leído no tiene' : 'libros leídos no tienen' ?> páginas registradas y no entra<?= $librosSinPaginasPeriodo === 1 ? '' : 'n' ?> en el ranking.</p>
                <details class="mapa-pie">
                    <summary>Ver cuáles son</summary>
                    <ul style="margin:8px 0 0 18px; padding:0;">
                        <?php foreach ($sinPaginasLista as $sp):
                            $idSp = ($sp['libro_id'] ?? '') !== '' ? $sp['libro_id'] : $sp['id']; ?>
                            <li><a href="libro.php?id=<?= urlencode((string)$idSp) ?>"><?= htmlspecialchars($sp['titulo']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
            <p class="mapa-pie" id="estadoPaginas" hidden></p>
            <?php if ($con300N > 0): ?>
                <p class="mapa-pie">
                    <?= (int)$con300N ?> <?= $con300N === 1 ? 'libro tiene' : 'libros tienen' ?> exactamente 300 páginas (a veces es un valor por defecto de las importaciones).
                    <button type="button" id="btnRevisar300" class="filtro-btn inactivo">Revisar con datos exactos</button>
                </p>
            <?php endif; ?>
        </section>

        <!-- Estrellas -->
        <section class="st-card">
            <div class="st-card-head">
                <h2>Distribución por estrellas</h2>
                <div class="filtro-pestañas">
                    <a href="<?= htmlspecialchars(urlEstadisticas(['notas' => 'anio'])) ?>" class="filtro-btn <?= $periodoNotas === 'anio' ? 'activo' : 'inactivo' ?>"><?= htmlspecialchars($year) ?></a>
                    <a href="<?= htmlspecialchars(urlEstadisticas(['notas' => 'todos'])) ?>" class="filtro-btn <?= $periodoNotas === 'todos' ? 'activo' : 'inactivo' ?>">Todos</a>
                </div>
            </div>

            <?php if ($totalDistribucion === 0): ?>
                <p class="vacio">Todavía no has puntuado ningún libro en este período.</p>
            <?php else: ?>
                <div class="estrellas-layout">
                    <div class="estrellas-media">
                        <span class="nota"><?= $promedioEstrellas ?></span>
                        <span class="estrellas-vis" style="--p: <?= $porcentajeRelleno ?>%;" aria-hidden="true">★★★★★</span>
                        <span class="sub"><?= (int)$notasMostradas['total'] ?> <?= (int)$notasMostradas['total'] === 1 ? 'libro puntuado' : 'libros puntuados' ?></span>
                    </div>
                    <div class="estrellas-filas">
                        <?php for ($n = 5; $n >= 1; $n--):
                            $cantidad = (int)$distribucionEstrellas[$n];
                            $pctFila = round(($cantidad / $totalDistribucion) * 100);
                        ?>
                            <a class="estrella-fila <?= $cantidad === 0 ? 'vacia' : '' ?>" href="estadisticas_estrellas.php?valor=<?= $n ?>&notas=<?= htmlspecialchars($periodoNotas) ?>" title="Ver libros de <?= $n ?> estrellas">
                                <span class="num"><?= $n ?>★</span>
                                <span class="barra"><span class="relleno" style="display:block; width: <?= $pctFila ?>%;"></span></span>
                                <span class="cuenta"><?= $cantidad ?> · <?= $pctFila ?>%</span>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
                <p class="estrellas-pie">Nota más común: <strong><?= $estrellaComun ? $estrellaComun . ' ★' : '—' ?></strong> · Pulsa una fila para ver esos libros (incluye los decimales, p. ej. 4,5 va en 4★).</p>
            <?php endif; ?>
        </section>

        <!-- Autores más leídos -->
        <section class="st-card">
            <div class="st-card-head"><h2>Autores más leídos <?= htmlspecialchars($year) ?></h2></div>
            <?php if (empty($topAutores)): ?>
                <p class="vacio">Aún no hay autores registrados este año.</p>
            <?php else: ?>
                <div class="autores-lista">
                    <?php foreach ($topAutores as $a):
                        $pctAutor = round(($a['libros'] / $maxLibrosAutor) * 100);
                    ?>
                        <div class="autor-fila">
                            <span class="autor-nombre"><?= htmlspecialchars($a['nombre']) ?></span>
                            <span class="autor-num"><?= (int)$a['libros'] ?> <?= $a['libros'] === 1 ? 'libro' : 'libros' ?></span>
                            <div class="autor-barra"><div style="width: <?= $pctAutor ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Mapa de géneros -->
        <section class="st-card ancho">
            <div class="st-card-head">
                <h2>Mapa de géneros</h2>
                <div class="filtro-pestañas">
                    <a href="<?= htmlspecialchars(urlEstadisticas(['generos' => 'anio'])) ?>" class="filtro-btn <?= $periodoGeneros === 'anio' ? 'activo' : 'inactivo' ?>"><?= htmlspecialchars($year) ?></a>
                    <a href="<?= htmlspecialchars(urlEstadisticas(['generos' => 'todos'])) ?>" class="filtro-btn <?= $periodoGeneros === 'todos' ? 'activo' : 'inactivo' ?>">Todos</a>
                </div>
            </div>

            <?php if ($colGeneros === null): ?>
                <p class="vacio">No se ha podido preparar el almacenamiento de géneros en la base de datos.</p>
            <?php elseif (empty($mapaGeneros)): ?>
                <p class="vacio"><?= $pendientesGeneros > 0 ? 'Tus géneros irán apareciendo aquí en unos minutos.' : 'Todavía no hay géneros para este período.' ?></p>
            <?php else: ?>
                <div class="mapa-generos" id="mapaGeneros">
                    <div class="mg-burbujas" role="img" aria-label="Burbujas de géneros literarios: cuanto más grande, más libros"></div>
                    <ol class="mg-ranking"></ol>
                </div>
                <p class="mapa-pie">
                    <?= (int)$librosConGenero ?> <?= $librosConGenero === 1 ? 'libro' : 'libros' ?> con género
                    <?php if ($esAdmin && $librosSinGenero > 0): ?> · <?= (int)$librosSinGenero ?> sin género · <a href="rellenar_generos.php">Completar</a><?php endif; ?>
                    · Cuanto más grande la burbuja, más libros de ese género (un libro puede tener varios). Pulsa un género para ver sus libros.
                </p>
            <?php endif; ?>
            <?php if ($colGeneros !== null && $esAdmin): ?><p class="mapa-pie" id="estadoGeneros" hidden></p><?php endif; ?>
        </section>

        <!-- Datos curiosos -->
        <section class="st-card ancho">
            <div class="st-card-head"><h2>Datos curiosos de <?= htmlspecialchars($year) ?></h2></div>
            <?php if ($leidosEsteAño === 0): ?>
                <p class="vacio">Termina tu primer libro del año para ver tus datos curiosos.</p>
            <?php else: ?>
                <div class="curiosos-grid">
                    <div class="curioso">
                        <span class="c-etiqueta">Libro más largo</span>
                        <span class="c-valor"><?= $libroLargo ? number_format((int)$libroLargo['paginas_reales'], 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle"><?= $libroLargo ? htmlspecialchars($libroLargo['titulo']) : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-etiqueta">Libro más corto</span>
                        <span class="c-valor"><?= $libroCorto ? number_format((int)$libroCorto['paginas_reales'], 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle"><?= $libroCorto ? htmlspecialchars($libroCorto['titulo']) : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-etiqueta">Media por libro</span>
                        <span class="c-valor"><?= $mediaPaginasLibro > 0 ? number_format($mediaPaginasLibro, 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle">Páginas por libro leído</span>
                    </div>
                    <div class="curioso">
                        <span class="c-etiqueta">Mejor mes</span>
                        <span class="c-valor"><?= $mejorMes ? htmlspecialchars($mejorMes) : '—' ?></span>
                        <span class="c-detalle"><?= $maxMes > 0 ? $maxMes . ($maxMes === 1 ? ' libro terminado' : ' libros terminados') : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-etiqueta">Tiempo por libro</span>
                        <span class="c-valor"><?= $diasMediosLibro > 0 ? $diasMediosLibro . ($diasMediosLibro === 1 ? ' día' : ' días') : '—' ?></span>
                        <span class="c-detalle">De media, de inicio a fin</span>
                    </div>
                    <div class="curioso">
                        <span class="c-etiqueta">Ritmo diario</span>
                        <span class="c-valor"><?= $paginasPorDia > 0 ? str_replace('.', ',', (string)$paginasPorDia) . ' pág.' : '—' ?></span>
                        <span class="c-detalle">Por día en lo que va de año</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>
        <a href="perfil.php" class="nav-card-float">
            <span class="nav-icon">👤</span>
            <span>Mi perfil</span>
        </a>
        <a href="biblioteca.php" class="nav-card-float">
            <span class="nav-icon">📚</span>
            <span>Mi estantería</span>
        </a>
        <a href="estadisticas.php" class="nav-card-float active">
            <span class="nav-icon">📊</span>
            <span>Estadísticas</span>
        </a>
        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>
        </a>
        <a href="buscar.php" class="nav-card-float">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>
        <a href="comunidad.php" class="nav-card-float">
            <span class="nav-icon">👥</span>
            <span>Comunidad</span>
        </a>
        <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<script>
function toggleFormReto() {
    const el = document.getElementById("formRetoWrapper");
    el.style.display = (el.style.display === "block") ? "none" : "block";
}

function obtenerColorTema() {
    const estilos = getComputedStyle(document.documentElement);
    const variables = ['--primary-color', '--color-principal', '--accent-color', '--primary', '--color-primario', '--main-color'];
    for (let i = 0; i < variables.length; i++) {
        let val = estilos.getPropertyValue(variables[i]).trim();
        if (val && val !== 'black' && val !== '#000000') return val;
    }
    return '#d87d8a';
}

function colorToRgba(color, alpha) {
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.fillStyle = color;
    const hex = ctx.fillStyle;
    if (hex.startsWith('#')) {
        const r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }
    return color;
}

const colorTema = obtenerColorTema();
let colorTexto = getComputedStyle(document.documentElement).getPropertyValue('--text-color').trim() || '#666666';
const colorRejilla = 'rgba(128,128,128,0.15)';
const colorResto = 'rgba(128,128,128,0.18)';

new Chart(document.getElementById("objetivoChart"), {
    type: "doughnut",
    data: {
        labels: ["Completado", "Restante"],
        datasets: [{
            data: [<?= $porcentajeObjetivo ?>, <?= max(0, 100 - $porcentajeObjetivo) ?>],
            backgroundColor: [colorTema, colorResto],
            borderWidth: 0,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        rotation: -90, circumference: 180, cutout: "78%",
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});

const ctxMeses = document.getElementById("mesesChart").getContext("2d");
const gradient = ctxMeses.createLinearGradient(0, 0, 0, 220);
gradient.addColorStop(0, colorToRgba(colorTema, 0.35));
gradient.addColorStop(1, colorToRgba(colorTema, 0.0));

new Chart(ctxMeses, {
    type: "line",
    data: {
        labels: <?= json_encode($mesesLabels) ?>,
        datasets: [{
            label: "Libros",
            data: <?= json_encode($mesesValores) ?>,
            borderColor: colorTema, backgroundColor: gradient, fill: true, tension: 0.4,
            pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: "#ffffff",
            pointBorderColor: colorTema, pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: colorTexto, font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: colorTexto, font: { size: 11 } }, grid: { color: colorRejilla } }
        }
    }
});

// Mapa de géneros
(function () {
    const caja = document.getElementById("mapaGeneros");
    if (!caja) return;
    const burbujas = caja.querySelector(".mg-burbujas");
    const ranking = caja.querySelector(".mg-ranking");
    const datos = <?= json_encode($mapaGeneros, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const estilos = {
        "Fantasía": ["🐉", "#7c4dcc"], "Romance": ["💕", "#e0508a"], "Thriller": ["🔪", "#3d4b6e"],
        "Misterio y crimen": ["🔍", "#506683"], "Terror": ["👻", "#7a2a3c"], "Ciencia ficción": ["🚀", "#2aa6c9"],
        "Histórica": ["🏰", "#b8803c"], "Juvenil": ["🎒", "#f0a23a"], "Distopía": ["🏚️", "#6b8294"],
        "Aventuras": ["🧭", "#3fa56b"], "Humor": ["😄", "#e9b72c"], "Biografías": ["👤", "#8a6a4f"],
        "Desarrollo personal": ["🌱", "#4fae98"], "Poesía": ["🪶", "#c77dd1"], "Drama": ["🎭", "#d46a4b"],
        "Cómic y manga": ["💥", "#ef6c6c"], "Paranormal": ["🦇", "#8250a8"], "Erótica": ["🌶️", "#c2185b"],
        "Contemporánea": ["🏙️", "#4f8fd6"], "Literatura": ["📖", "#9a7b5a"], "Ficción": ["📚", "#8892a6"],
        "Otros": ["✨", "#9aa0a6"]
    };
    const infoDe = d => d.resto ? ["➕", "#9aa0a6"] : (estilos[d.nombre] || ["📚", "#8892a6"]);
    const textoLibros = n => n + (n === 1 ? " libro" : " libros");
    const periodoGen = <?= json_encode($periodoGeneros) ?>;
    const urlGenero = n => "estadisticas_generos.php?genero=" + encodeURIComponent(n) + "&generos=" + periodoGen;

    function empaquetar(items) {
        const puestos = [];
        items.forEach(function (it, i) {
            if (i === 0) { it.x = 0; it.y = 0; puestos.push(it); return; }
            for (let dist = items[0].r * 0.5; dist < 4000; dist += 4) {
                const pasos = Math.max(24, Math.round(dist / 5));
                let ok = false;
                for (let k = 0; k < pasos; k++) {
                    const ang = (k / pasos) * Math.PI * 2 + i * 0.9;
                    const x = Math.cos(ang) * dist, y = Math.sin(ang) * dist * 0.8;
                    if (puestos.every(c => Math.hypot(c.x - x, c.y - y) >= c.r + it.r + 6)) {
                        it.x = x; it.y = y; puestos.push(it); ok = true; break;
                    }
                }
                if (ok) break;
            }
        });
    }

    function resaltar(i, on) {
        burbujas.querySelectorAll('[data-i="' + i + '"]').forEach(e => e.classList.toggle("resalta", on));
        ranking.querySelectorAll('[data-i="' + i + '"]').forEach(e => e.classList.toggle("resalta", on));
    }

    // Ranking
    function pintarRanking() {
        ranking.textContent = "";
        const maxN = Math.max.apply(null, datos.map(d => d.n));
        datos.forEach(function (d, i) {
            const inf = infoDe(d);
            const li = document.createElement("li");
            const fila = document.createElement(d.resto ? "div" : "a");
            fila.className = "mg-fila";
            fila.dataset.i = i;
            if (!d.resto) fila.href = urlGenero(d.nombre);
            fila.style.setProperty("--c", inf[1]);

            const emo = document.createElement("span"); emo.className = "emo"; emo.textContent = inf[0];
            const nom = document.createElement("span"); nom.className = "nom"; nom.textContent = d.nombre;
            const cnt = document.createElement("span"); cnt.className = "cnt"; cnt.textContent = textoLibros(d.n);
            const barra = document.createElement("span"); barra.className = "barra";
            const rel = document.createElement("i"); rel.style.width = Math.max(6, Math.round(d.n / maxN * 100)) + "%";
            barra.appendChild(rel);

            [emo, nom, cnt, barra].forEach(e => fila.appendChild(e));
            fila.addEventListener("mouseenter", () => resaltar(i, true));
            fila.addEventListener("mouseleave", () => resaltar(i, false));
            li.appendChild(fila);
            ranking.appendChild(li);
        });
    }

    let primera = true;
    function pintarBurbujas() {
        burbujas.textContent = "";
        const W = burbujas.clientWidth, H = burbujas.clientHeight;
        if (!W || !H) return;

        const maxN = Math.max.apply(null, datos.map(d => d.n));
        const items = datos.map((d, i) => ({ d: d, i: i, r: 28 + Math.sqrt(d.n / maxN) * 72 }));
        empaquetar(items);

        const minX = Math.min.apply(null, items.map(c => c.x - c.r)), maxX = Math.max.apply(null, items.map(c => c.x + c.r));
        const minY = Math.min.apply(null, items.map(c => c.y - c.r)), maxY = Math.max.apply(null, items.map(c => c.y + c.r));
        const esc = Math.min(W / (maxX - minX), H / (maxY - minY));
        const ox = W / 2 - ((minX + maxX) / 2) * esc, oy = H / 2 - ((minY + maxY) / 2) * esc;

        items.forEach(function (c) {
            const d = c.d, inf = infoDe(d), diam = c.r * 2 * esc;
            const el = document.createElement(d.resto ? "div" : "a");
            if (!d.resto) el.href = urlGenero(d.nombre);
            el.className = "mg-burbuja";
            el.dataset.i = c.i;
            el.style.setProperty("--c", inf[1]);
            el.style.setProperty("--i", c.i);
            el.style.width = el.style.height = diam + "px";
            el.style.left = (ox + c.x * esc - diam / 2) + "px";
            el.style.top = (oy + c.y * esc - diam / 2) + "px";
            if (!primera) el.style.animation = "none";
            el.title = d.nombre + ": " + textoLibros(d.n) + (d.libros && d.libros.length ? "\n• " + d.libros.join("\n• ") : "");

            const emo = document.createElement("span");
            emo.className = "b-emo"; emo.textContent = inf[0];
            emo.style.fontSize = (diam >= 70 ? diam * 0.32 : diam * 0.45) + "px";
            el.appendChild(emo);

            if (diam >= 100) {
                const nom = document.createElement("span"); nom.className = "b-nom"; nom.textContent = d.nombre;
                const num = document.createElement("span"); num.className = "b-num"; num.textContent = textoLibros(d.n);
                el.appendChild(nom); el.appendChild(num);
            } else if (diam >= 60) {
                const num = document.createElement("span"); num.className = "b-num"; num.textContent = d.n;
                el.appendChild(num);
            }
            el.addEventListener("mouseenter", () => resaltar(c.i, true));
            el.addEventListener("mouseleave", () => resaltar(c.i, false));
            burbujas.appendChild(el);
        });
        primera = false;
    }

    pintarRanking();
    pintarBurbujas();
    let t;
    window.addEventListener("resize", function () { clearTimeout(t); t = setTimeout(pintarBurbujas, 150); });
})();

<?php if ($colGeneros !== null && $pendientesGeneros > 0): ?>
// Detección automática de géneros en segundo plano (invisible para el usuario)
(async function () {
    const estado = document.getElementById("estadoGeneros");
    let guardados = 0;
    try {
        for (let i = 0; i < 60; i++) {
            const fd = new FormData();
            fd.append("accion", "procesar");
            fd.append("ajax", "1");
            const r = await fetch("rellenar_generos.php", { method: "POST", body: fd, credentials: "same-origin" });
            if (!r.ok) break;
            const j = await r.json();
            guardados += j.procesados;
            if (estado) {
                estado.hidden = false;
                estado.textContent = "Detectando géneros… quedan " + j.pendientes + (j.pausa ? " (en pausa: límite de Google)" : "");
            }
            if (j.pausa || !j.pendientes || !j.procesados) break;
            await new Promise(r => setTimeout(r, 800));
        }
    } catch (e) {}
    if (guardados > 0) location.reload();
})();
<?php endif; ?>

// Páginas de los libros leídos: se completan solas en segundo plano 
const CSRF_PAGINAS = <?= json_encode($csrfPaginas) ?>;
let buscandoPaginas = false;

async function completarPaginas(incluir300) {
    if (buscandoPaginas) return;
    buscandoPaginas = true;

    const estado = document.getElementById("estadoPaginas");
    let actualizados = 0, vueltas = 0, intentados = 0, aviso = '', fallo = '';
    try {
        // Máximo 10 llamadas (20 libros) por visita, para cuidar la cuota diaria de Google
        for (; vueltas < 10; vueltas++) {
            const fd = new FormData();
            fd.append("csrf", CSRF_PAGINAS);
            if (incluir300) fd.append("incluir300", "1");
            const r = await fetch("rellenar_paginas.php", { method: "POST", body: fd, credentials: "same-origin" });
            if (!r.ok) { fallo = 'El servidor respondió con error ' + r.status + ' (revisa rellenar_paginas.php).'; break; }
            const j = await r.json();
            if (!j.ok) { fallo = j.error || 'Respuesta no válida.'; break; }

            actualizados += j.actualizados;
            intentados += j.procesados;
            if (j.aviso) aviso = j.aviso;
            if (estado && j.procesados > 0) {
                estado.hidden = false;
                estado.textContent = "Buscando las páginas de tus libros… quedan " + j.pendientes;
            }
            if (!j.pendientes || !j.procesados) break;
            await new Promise(r => setTimeout(r, 1500));   // pausa para no agotar el límite por minuto de Google
        }
    } catch (e) {
        fallo = 'Respuesta no válida del servidor: probablemente un aviso o error de PHP en rellenar_paginas.php o PaginasService.php.';
    }

    buscandoPaginas = false;
    if (actualizados > 0) {
        location.reload();
    } else if (estado && fallo) {
        estado.hidden = false;
        estado.textContent = "No se pudieron buscar las páginas: " + fallo;
    } else if (estado) {
        if (incluir300) {
            estado.hidden = false;
            estado.textContent = "Revisión terminada: no hay datos exactos distintos de 300 páginas.";
        } else if (intentados > 0) {
            estado.hidden = false;
            estado.textContent = "No se han encontrado las páginas de " + intentados + " libros ahora mismo." + (aviso ? " " + aviso : "");
        } else {
            estado.hidden = true;
        }
    }
}

<?php if ($sinPaginasN > 0): ?>
completarPaginas(false);
<?php endif; ?>

const btnRevisar300 = document.getElementById("btnRevisar300");
if (btnRevisar300) btnRevisar300.addEventListener("click", () => completarPaginas(true));
</script>

</body>
</html>
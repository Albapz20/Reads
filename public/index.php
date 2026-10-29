<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/helpers.php";
require_once "../src/ComunidadService.php";
require_once "../src/ComunidadVistas.php";

$usuario = Auth::usuario();

if (!$usuario) {
    header("Location: login.php");
    exit;
}

// Token para actualizar el progreso desde el recuadro (sin salir de la página)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_progreso'])) {
    $_SESSION['csrf_progreso'] = bin2hex(random_bytes(16));
}

$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

$db = new Database();

// Comunidad: lo que están leyendo las personas a las que sigues
$comunidad     = new ComunidadService($db->pdo);
$amigosLeyendo = $comunidad->amigosLeyendo((int)$usuario["id"], 10);
$siguiendoN    = $comunidad->contadores((int)$usuario["id"])['siguiendo'];

// Año actual para métricas dinámicas
$year = date("Y");
$diaDelAno = date("z") + 1; // Días transcurridos en el año actual

// Libros que el usuario está leyendo actualmente
$sqlMisLecturas = "SELECT l.* 
                   FROM listas_lectura l 
                   WHERE l.usuario_id = ? AND l.estado = 'leyendo' 
                   ORDER BY l.id DESC";
$stmtMisLecturas = $db->pdo->prepare($sqlMisLecturas);
$stmtMisLecturas->execute([$usuario["id"]]);
$misLecturasActuales = $stmtMisLecturas->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas de libros leídos y páginas leídas este año
$sql = "SELECT 
            COUNT(*) AS libros_leidos,
            COALESCE(SUM(CASE WHEN paginas_leidas > 0 THEN paginas_leidas ELSE paginas_totales END), 0) AS paginas_leidas
        FROM listas_lectura
        WHERE usuario_id = ? 
          AND estado = 'leido' 
          AND YEAR(fecha_fin) = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"], $year]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$librosLeidosNum   = (int)($stats["libros_leidos"] ?? 0);
$paginasLeidasNum  = (int)($stats["paginas_leidas"] ?? 0);

// Objetivo de lectura desde ajustes_usuario
$sqlAjustes = "SELECT objetivo_anual FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1";
$stmtAjustes = $db->pdo->prepare($sqlAjustes);
$stmtAjustes->execute([$usuario["id"]]);
$rowAjustes = $stmtAjustes->fetch(PDO::FETCH_ASSOC);

$metaLibrosAnual = !empty($rowAjustes["objetivo_anual"]) ? (int)$rowAjustes["objetivo_anual"] : 20;
$porcentajeMeta  = ($metaLibrosAnual > 0) ? min(100, round(($librosLeidosNum / $metaLibrosAnual) * 100)) : 0;

$librosLeidos   = $librosLeidosNum;
$objetivoAnual  = $metaLibrosAnual;
$porcentajeReto = $porcentajeMeta;

// Anillo del reto 
$anilloCirc   = 2 * M_PI * 52;
$anilloOffset = $anilloCirc * (1 - $porcentajeReto / 100);

// Métricas
$paginasPorDia           = ($diaDelAno > 0) ? round($paginasLeidasNum / $diaDelAno, 1) : 0;
$horasLeidasEstimadas    = round($paginasLeidasNum / 60, 1);
$promedioPaginasPorLibro = ($librosLeidosNum > 0) ? round($paginasLeidasNum / $librosLeidosNum) : 0;

// Proyección a fin de año
$diasTotalesAno   = (date("L") == 1) ? 366 : 365;
$proyeccionLibros = ($diaDelAno > 0) ? round(($librosLeidosNum / $diaDelAno) * $diasTotalesAno) : 0;

// Estado del reto 
if ($porcentajeMeta >= 100) {
    $estadoReto = "¡Reto completado!";
} elseif ($librosLeidosNum >= round(($metaLibrosAnual / $diasTotalesAno) * $diaDelAno)) {
    $estadoReto = "Vas bien, ¡sigue así!";
} else {
    $estadoReto = "¡Aún puedes lograrlo!";
}

// Saludo dinámico
$hora = date("H");
if ($hora < 12) $saludo = "Buenos días";
elseif ($hora < 19) $saludo = "Buenas tardes";
else $saludo = "Buenas noches";

// Libros leídos este año
$sqlBiblioteca = "SELECT id, libro_id AS id_externo, titulo, autores, portada, descripcion 
                  FROM listas_lectura
                  WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                  ORDER BY fecha_fin DESC LIMIT 10";
$stmt = $db->pdo->prepare($sqlBiblioteca);
$stmt->execute([$usuario["id"], $year]);
$librosPreview = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Historial de búsqueda
$sql = "SELECT termino, fecha 
        FROM historial_busqueda 
        WHERE usuario_id = ?
        ORDER BY fecha DESC
        LIMIT 10";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"]]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

$busquedasUnicas = [];
$vistos = [];
foreach ($historial as $h) {
    $term  = trim($h['termino'] ?? '');
    $clave = mb_strtolower($term);
    if ($term !== '' && !isset($vistos[$clave])) {
        $vistos[$clave]    = true;
        $busquedasUnicas[] = $term;
    }
}
$busquedasUnicas = array_slice($busquedasUnicas, 0, 5);

/* Wrap up */
$mesesNombre = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
                7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

// Fecha de hoy en español para la cabecera
$diasSemana = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
$fechaHoy = $diasSemana[(int)date('N')] . ', ' . (int)date('j') . ' de ' . $mesesNombre[(int)date('n')];

// Años en los que hay libros terminados
$stmtAnios = $db->pdo->prepare("SELECT DISTINCT YEAR(fecha_fin) AS anio
                                FROM listas_lectura
                                WHERE usuario_id = ? AND estado = 'leido' AND fecha_fin IS NOT NULL
                                ORDER BY anio DESC");
$stmtAnios->execute([$usuario["id"]]);
$aniosWrap = [];
foreach ($stmtAnios->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    if ((int)$fila['anio'] > 0) $aniosWrap[] = (int)$fila['anio'];
}

$anioWrap = (int)($_GET['wrap'] ?? 0);
if (!in_array($anioWrap, $aniosWrap, true)) {
    $anioWrap = $aniosWrap[0] ?? (int)$year;
}

// Libros terminados en el año elegido
$stmtWrap = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, portada,
                                      CASE WHEN paginas_leidas > 0 THEN paginas_leidas ELSE paginas_totales END AS paginas,
                                      fecha_fin
                               FROM listas_lectura
                               WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                               ORDER BY fecha_fin DESC, id DESC");
$stmtWrap->execute([$usuario["id"], $anioWrap]);
$wuLibros = $stmtWrap->fetchAll(PDO::FETCH_ASSOC);

$wuTotalLibros = count($wuLibros);
$wuPaginas = 0;
$wuMeses = array_fill(1, 12, 0);
$wuAutores = [];
$wuLargo = null;
$wuCorto = null;

foreach ($wuLibros as $libroWrap) {
    $p = (int)($libroWrap['paginas'] ?? 0);
    $libroWrap['paginas'] = $p;
    $wuPaginas += $p;

    $mes = (int)date('n', strtotime($libroWrap['fecha_fin']));
    if ($mes >= 1 && $mes <= 12) $wuMeses[$mes]++;

    $autor = trim(explode(',', $libroWrap['autores'] ?? '')[0]);
    if ($autor !== '') $wuAutores[$autor] = ($wuAutores[$autor] ?? 0) + 1;

    if ($p > 0) {
        if ($wuLargo === null || $p > $wuLargo['paginas']) $wuLargo = $libroWrap;
        if ($wuCorto === null || $p < $wuCorto['paginas']) $wuCorto = $libroWrap;
    }
}

arsort($wuAutores);
$wuAutorTop = $wuAutores ? array_key_first($wuAutores) : null;
$wuAutorTopNum = $wuAutorTop !== null ? $wuAutores[$wuAutorTop] : 0;

$wuMesTopNum = max($wuMeses);
$wuMesTop = ($wuMesTopNum > 0) ? array_search($wuMesTopNum, $wuMeses) : null;

$wuDias = ($anioWrap === (int)$year) ? $diaDelAno : ((date('L', mktime(0, 0, 0, 1, 1, $anioWrap)) == 1) ? 366 : 365);
$wuPagDia = round($wuPaginas / $wuDias, 1);
$wuHoras = round($wuPaginas / 60, 1);
$wuMedia = ($wuTotalLibros > 0) ? round($wuPaginas / $wuTotalLibros) : 0;

// Portada del libro más largo
$wuLargoPortada = $wuLargo ? obtenerPortadaValida($wuLargo['portada'] ?? '', (int)$wuLargo['id']) : '';

// Día de la semana en el que más libros terminas
$diasPlural = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados', 7 => 'domingos'];
$wuDiasCuenta = array_fill(1, 7, 0);
foreach ($wuLibros as $libroWrap) {
    $d = (int)date('N', strtotime($libroWrap['fecha_fin']));
    if ($d >= 1 && $d <= 7) $wuDiasCuenta[$d]++;
}
$wuDiaTopNum = max($wuDiasCuenta);
$wuDiaTop = ($wuDiaTopNum > 0) ? array_search($wuDiaTopNum, $wuDiasCuenta) : null;

// Mejor racha de meses seguidos con algún libro terminado
$wuRachaMax = 0; $wuRachaIni = 0; $wuRachaFin = 0;
$rachaAct = 0;  $rachaActIni = 0;
for ($m = 1; $m <= 12; $m++) {
    if ($wuMeses[$m] > 0) {
        if ($rachaAct === 0) $rachaActIni = $m;
        $rachaAct++;
        if ($rachaAct > $wuRachaMax) {
            $wuRachaMax = $rachaAct;
            $wuRachaIni = $rachaActIni;
            $wuRachaFin = $m;
        }
    } else {
        $rachaAct = 0;
    }
}

// Texto para compartir
$wuTextoCompartir = "Mi Wrap Up {$anioWrap}: {$wuTotalLibros} libros, "
    . number_format($wuPaginas, 0, ',', '.') . " páginas (~{$wuHoras} h de lectura)."
    . ($wuAutorTop ? " Autor más leído: {$wuAutorTop}." : '')
    . ($wuLargo ? " Libro más largo: {$wuLargo['titulo']} ({$wuLargo['paginas']} págs)." : '')
    . ($wuMesTop ? " Mes estrella: {$mesesNombre[$wuMesTop]}." : '')
    . " #Reads";

if (!function_exists('e')) {
    function e($texto) {
        return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="main.js"></script>
    <title>Inicio - Dashboard Reads</title>
</head>

<body class="page-index">

<div class="container hm-wrap">

    <!-- Cabecera: saludo + buscador -->
    <header class="hm-top">
        <p class="hm-fecha"><?= e($fechaHoy) ?></p>
        <h1><?= $saludo ?>, <span><?= e($usuario["nombre"]) ?></span></h1>
        <p class="hm-lema">¿Qué libro tienes en mente para hoy?</p>

        <div class="hm-buscador">
            <form action="buscar.php" method="GET">
                <input type="text" name="q" placeholder="Título, autor o tema..." autocomplete="off" aria-label="Buscar libros">
                <button type="submit">Buscar</button>
            </form>
            <div id="sugerencias"></div>
        </div>

        <?php if (!empty($busquedasUnicas)): ?>
            <div class="hm-recientes">
                <span>Recientes:</span>
                <?php foreach ($busquedasUnicas as $b): ?>
                    <a href="buscar.php?q=<?= urlencode($b) ?>"><?= e($b) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="hm-grid">

        <!--  Columna principal  -->
        <div class="hm-main">

            <!-- Leyendo ahora -->
            <section aria-labelledby="t-leyendo">
                <div class="hm-sec-head">
                    <h2 id="t-leyendo">Qué estás leyendo ahora</h2>
                    <?php if (count($misLecturasActuales) > 1): ?>
                        <small>Desliza para ver más</small>
                    <?php endif; ?>
                </div>

                <?php if (!empty($misLecturasActuales)): ?>
                    <div class="hm-leyendo my-reading-swipe-container">
                        <?php foreach ($misLecturasActuales as $miLibro):
                            $miPct = ($miLibro['paginas_totales'] > 0)
                                ? round(($miLibro['paginas_leidas'] / $miLibro['paginas_totales']) * 100)
                                : ($miLibro['progreso'] ?? 0);

                            $portadaSrc   = obtenerPortadaValida($miLibro['portada'] ?? '', (int)$miLibro['id']);
                            $tienePortada = !empty($portadaSrc);
                            $enlaceLibro  = 'libro.php?id=' . urlencode($miLibro['libro_id'] ?? $miLibro['id']);
                        ?>
                            <article class="hm-read" data-card="<?= (int)$miLibro['id'] ?>">
                                <div class="hm-read-bg <?= $tienePortada ? '' : 'sin' ?>"
                                     <?= $tienePortada ? 'style="background-image:url(\'' . e($portadaSrc) . '\')"' : '' ?>></div>

                                <div class="hm-cover">
                                    <a href="<?= $enlaceLibro ?>" aria-label="Abrir <?= e($miLibro['titulo']) ?>">
                                        <?php if ($tienePortada): ?>
                                            <img src="<?= e($portadaSrc) ?>" alt=""
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <?php endif; ?>
                                        <div class="cubierta-generada-card" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                                            <span style="font-size:10px; font-weight:bold; line-height:1.2; max-height:50px; overflow:hidden;"><?= e($miLibro['titulo']) ?></span>
                                            
                                        </div>
                                    </a>
                                </div>

                                <div class="hm-read-info">
                                    <p class="hm-estado">En curso</p>
                                    <h3><?= e($miLibro['titulo']) ?></h3>
                                    <p class="hm-autor"><?= e($miLibro['autores'] ?? 'Autor desconocido') ?></p>

                                    <div class="hm-prog-linea">
                                        <span>
                                            <strong class="js-pct"><?= $miPct ?>%</strong>
                                        </span>
                                        <small class="js-pags"><?= ((int)$miLibro['paginas_totales'] > 0) ? (int)$miLibro['paginas_leidas'] . ' de ' . (int)$miLibro['paginas_totales'] . ' págs.' : '' ?></small>
                                    </div>
                                    <div class="barra-progreso-bg">
                                        <div class="barra-progreso-fill js-fill" style="width: <?= $miPct ?>%;"></div>
                                    </div>

                                    <!-- Abre el recuadro de progreso sin salir del inicio (si falla el JS, lleva a la ficha) -->
                                    <a href="<?= $enlaceLibro ?>"
                                       class="hm-btn js-editar-progreso"
                                       data-id="<?= (int)$miLibro['id'] ?>">Actualizar progreso</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="hm-read hm-vacio-lectura">
                        <div class="hm-read-bg sin"></div>
                        <div class="hm-read-info" style="text-align:center;">
                            <h3>No tienes ningún libro en curso</h3>
                            <p>Busca en el catálogo o revisa tus pendientes para empezar hoy.</p>
                            <a href="biblioteca.php" class="hm-btn">Explorar mi estantería</a>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Leídos este año -->
            <section class="hm-bloque" aria-labelledby="t-leidos">
                <div class="hm-sec-head">
                    <h2 id="t-leidos">Leídos en <?= $year ?></h2>
                    <a href="biblioteca.php">Ver todo</a>
                </div>

                <?php if (empty($librosPreview)): ?>
                    <p class="hm-vacio">Aún no has marcado libros como leídos este año.</p>
                <?php else: ?>
                    <div class="hm-estante">
                        <?php foreach ($librosPreview as $libro):
                            $descParam    = !empty($libro['descripcion']) ? '&desc=' . urlencode($libro['descripcion']) : '';
                            $portadaSrc   = obtenerPortadaValida($libro['portada'] ?? '', (int)$libro['id']);
                            $tienePortada = !empty($portadaSrc);
                            $portadaParam = $tienePortada ? '&portada=' . urlencode($portadaSrc) : '';
                        ?>
                            <a href="libro.php?id=<?= urlencode($libro['id_externo']) ?><?= $descParam ?><?= $portadaParam ?>"
                               class="hm-lomo" title="<?= e($libro['titulo']) ?>">
                                <?php if ($tienePortada): ?>
                                    <img src="<?= e($portadaSrc) ?>" alt="<?= e($libro['titulo']) ?>"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>
                                <div class="cubierta-generada-card" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                                    <span style="font-size:9px; font-weight:bold; line-height:1.1; max-height:40px; overflow:hidden;"><?= e($libro['titulo']) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Wrap up -->
            <section class="hm-bloque wu-panel" id="wrapup" aria-labelledby="t-wrap">
                <div class="wu-head">
                    <h2 id="t-wrap">Tu Wrap Up <?= $anioWrap ?></h2>

                    <?php if (count($aniosWrap) > 1): ?>
                        <form method="get" action="index.php#wrapup" class="wu-anio-form">
                            <select name="wrap" class="wu-select" aria-label="Año del Wrap Up" onchange="this.form.submit()">
                                <?php foreach ($aniosWrap as $a): ?>
                                    <option value="<?= $a ?>" <?= $a === $anioWrap ? 'selected' : '' ?>><?= $a ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($wuTotalLibros === 0): ?>
                    <div class="wu-vacio">
                                                <p>Todavía no has terminado ningún libro en <?= $anioWrap ?>.<br>Márcalos como <strong>leídos</strong> con su fecha de fin y aquí aparecerá tu resumen del año.</p>
                        <a href="biblioteca.php" class="hm-btn">Ir a mi estantería</a>
                    </div>
                <?php else: ?>
                    <div class="wu-bento">

                        <!-- Libro más largo -->
                        <a class="wu-tile wu-grande" href="<?= $wuLargo ? 'libro.php?id=' . urlencode($wuLargo['libro_id'] ?? $wuLargo['id']) : 'biblioteca.php' ?>">
                            <span class="wu-etq">Tu libro más largo</span>
                            <?php if ($wuLargo): ?>
                                <div class="wu-grande-cuerpo">
                                    <?php if (!empty($wuLargoPortada)): ?>
                                        <img class="wu-portada" src="<?= e($wuLargoPortada) ?>" alt="<?= e($wuLargo['titulo']) ?>" onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <div class="wu-grande-texto">
                                        <strong class="wu-titulo"><?= e($wuLargo['titulo']) ?></strong>
                                        <span class="wu-valor"><?= number_format($wuLargo['paginas'], 0, ',', '.') ?> páginas</span>
                                        <?php if ($wuCorto && $wuCorto['id'] !== $wuLargo['id']): ?>
                                            <small>El más cortito: <?= e($wuCorto['titulo']) ?> (<?= (int)$wuCorto['paginas'] ?> págs)</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="wu-aviso">Añade el número de páginas a tus libros para ver este dato.</p>
                            <?php endif; ?>
                        </a>

                        <!-- Autor del año -->
                        <?php if ($wuAutorTop): ?>
                            <a class="wu-tile wu-t-autor" href="buscar.php?q=<?= urlencode($wuAutorTop) ?>">
                        <?php else: ?>
                            <div class="wu-tile wu-t-autor">
                        <?php endif; ?>
                            <small>Autor del año</small>
                            <strong><?= $wuAutorTop ? e($wuAutorTop) : '—' ?></strong>
                            <?php if ($wuAutorTop): ?>
                                <em><?= $wuAutorTopNum > 1 ? $wuAutorTopNum . ' libros suyos' : 'Ninguno se repite' ?></em>
                            <?php endif; ?>
                        <?= $wuAutorTop ? '</a>' : '</div>' ?>

                        <!-- Mes estrella -->
                        <div class="wu-tile wu-t-mes">
                            <small>Mes estrella</small>
                            <strong><?= $wuMesTop ? ucfirst($mesesNombre[$wuMesTop]) : '—' ?></strong>
                            <?php if ($wuMesTop): ?><em><?= $wuMesTopNum ?> <?= $wuMesTopNum === 1 ? 'libro terminado' : 'libros terminados' ?></em><?php endif; ?>
                        </div>

                        <!-- Día favorito -->
                        <div class="wu-tile wu-t-dia">
                            <small>Terminas más</small>
                            <strong><?= $wuDiaTop ? 'Los ' . $diasPlural[$wuDiaTop] : '—' ?></strong>
                            <?php if ($wuDiaTop): ?><em><?= $wuDiaTopNum ?> <?= $wuDiaTopNum === 1 ? 'libro' : 'libros' ?></em><?php endif; ?>
                        </div>

                        <!-- Mejor racha -->
                        <div class="wu-tile wu-t-racha">
                            <small>Mejor racha</small>
                            <strong><?= $wuRachaMax ?> <?= $wuRachaMax === 1 ? 'mes seguido' : 'meses seguidos' ?></strong>
                            <?php if ($wuRachaMax > 0): ?>
                                <em><?= $wuRachaIni === $wuRachaFin ? ucfirst($mesesNombre[$wuRachaIni]) : 'de ' . $mesesNombre[$wuRachaIni] . ' a ' . $mesesNombre[$wuRachaFin] ?></em>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="wu-pie">
                        <button type="button" class="wu-copiar js-wu-copiar" data-texto="<?= e($wuTextoCompartir) ?>">Copiar mi resumen</button>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Recomendados -->
            <section class="hm-bloque" aria-labelledby="t-rec">
                <div class="hm-sec-head">
                    <h2 id="t-rec">Recomendados para ti</h2>
                </div>
                <p id="subtitulo-recomendados" style="font-size:.85rem; opacity:.7; margin:-6px 0 8px;">
                    Basado en tus últimas lecturas
                </p>
                <div id="carrusel-recomendados" class="horizontal-scroll">
                    <span style="opacity:.6; font-size:.85rem;">Cargando sugerencias...</span>
                </div>
            </section>
        </div>

        <!--  Columna lateral  -->
        <aside class="hm-side">

            <!-- Reto + resumen -->
            <section class="hm-bloque hm-reto" aria-labelledby="t-reto">
                <div class="hm-sec-head">
                    <h2 id="t-reto">Reto <?= $year ?></h2>
                </div>

                <div class="hm-anillo" role="img" aria-label="<?= $librosLeidos ?> de <?= $objetivoAnual ?> libros, <?= $porcentajeReto ?>%">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="fondo" cx="60" cy="60" r="52"></circle>
                        <circle class="valor" cx="60" cy="60" r="52"
                                stroke-dasharray="<?= round($anilloCirc, 2) ?>"
                                stroke-dashoffset="<?= round($anilloOffset, 2) ?>"></circle>
                    </svg>
                    <div class="centro">
                        <strong><?= $librosLeidos ?>/<?= $objetivoAnual ?></strong>
                        <small>libros · <?= $porcentajeReto ?>%</small>
                    </div>
                </div>

                <span class="hm-reto-estado"><?= $estadoReto ?></span>

                <ul class="hm-datos">
                    <li><span>Páginas leídas</span><strong><?= number_format($paginasLeidasNum, 0, ',', '.') ?></strong></li>
                    <li><span>Páginas por día</span><strong><?= $paginasPorDia ?></strong></li>
                    <li><span>Tiempo de lectura</span><strong>~<?= $horasLeidasEstimadas ?> h</strong></li>
                    <li><span>Media por libro</span><strong><?= $promedioPaginasPorLibro ?> págs</strong></li>
                    <li><span>Proyección a fin de año</span><strong>~<?= $proyeccionLibros ?> libros</strong></li>
                </ul>
            </section>

            <!-- Comunidad -->
            <section class="hm-bloque" aria-labelledby="t-com">
                <div class="hm-sec-head">
                    <h2 id="t-com">La comunidad está leyendo</h2>
                    <a href="comunidad.php">Ver</a>
                </div>

                <?php if (!empty($amigosLeyendo)): ?>
                    <div class="cm-scroll">
                        <?php foreach ($amigosLeyendo as $l) echo cmTarjetaLeyendo($l); ?>
                    </div>
                <?php elseif ($siguiendoN > 0): ?>
                    <p class="hm-vacio">Ninguna de las personas que sigues está leyendo ahora mismo.</p>
                <?php else: ?>
                    <p class="hm-vacio" style="margin-bottom:12px;">Sigue a otros lectores para ver aquí qué están leyendo.</p>
                    <a href="comunidad.php" class="hm-btn">Encontrar lectores</a>
                <?php endif; ?>
            </section>
        </aside>
    </div>

    <!-- Footer -->
    <footer class="site-footer">
        <span style="font-size: 0.85rem; opacity:.7;">Reads &copy; <?= date("Y") ?></span>
        <a href="logout.php" class="btn-logout-footer">
            Cerrar sesión
        </a>
    </footer>

</div>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float active"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="calendario.php" class="nav-card-float"><span class="nav-icon">📅</span><span>Calendario</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="comunidad.php" class="nav-card-float"><span class="nav-icon">👥</span><span>Comunidad</span></a>
        <a href="ajustes.php" class="nav-card-float"><span class="nav-icon">⚙️</span><span>Ajustes</span></a>
    </nav>
</div>

<!-- Recuadro para actualizar el progreso sin salir del inicio -->
<?php include __DIR__ . '/progreso_modal.php'; ?>

<script>
const input = document.querySelector('input[name="q"]');
const sugerencias = document.getElementById('sugerencias');

// Utilidad para escapar caracteres HTML y prevenir XSS
function escapeHtml(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function generarPortadaSVG(titulo) {
    const t = encodeURIComponent((titulo || 'Libro').substring(0, 30));
    return `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='160' viewBox='0 0 120 160'><defs><linearGradient id='g' x1='0%' y1='0%' x2='100%' y2='100%'><stop offset='0%' style='stop-color:%231e293b;stop-opacity:1'/><stop offset='100%' style='stop-color:%230f172a;stop-opacity:1'/></linearGradient></defs><rect width='100%' height='100%' fill='url(%23g)' rx='4'/><rect x='3' y='0' width='3' height='100%' fill='%23ffffff' opacity='0.25'/><text x='50%' y='45%' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='10' font-weight='bold' fill='%23ffffff'>${t}</text></svg>`;
}

// Búsqueda con sugerencias en tiempo real (con un pequeño retardo para no saturar la API)
if (input && sugerencias) {
    let temporizador = null;

    input.addEventListener('input', () => {
        clearTimeout(temporizador);
        const texto = input.value.trim();
        if (texto.length < 2) {
            sugerencias.style.display = "none";
            return;
        }

        temporizador = setTimeout(async () => {
            try {
                const res = await fetch(`https://openlibrary.org/search.json?q=${encodeURIComponent(texto)}&limit=5`);
                const data = await res.json();

                // Si el usuario siguió escribiendo mientras llegaba la respuesta, la descartamos
                if (input.value.trim() !== texto) return;

                sugerencias.innerHTML = "";
                sugerencias.style.display = "block";

                if (!data.docs || data.docs.length === 0) {
                    sugerencias.innerHTML = "<div style='padding:12px; opacity:.7;'>Sin resultados</div>";
                    return;
                }

                data.docs.forEach(item => {
                    const titulo = item.title || "Sin título";
                    const autor = item.author_name ? item.author_name[0] : "Autor desconocido";

                    const div = document.createElement("div");
                    div.innerHTML = `<strong>${escapeHtml(titulo)}</strong><br><small style="opacity:.7;">${escapeHtml(autor)}</small>`;
                    div.onclick = () => {
                        input.value = titulo;
                        sugerencias.style.display = "none";
                        input.form.submit();
                    };
                    sugerencias.appendChild(div);
                });
            } catch (err) {
                sugerencias.style.display = "none";
            }
        }, 250);
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !sugerencias.contains(e.target)) {
            sugerencias.style.display = "none";
        }
    });
}

// Cargar recomendaciones basadas en lecturas recientes
async function cargarRecomendaciones() {
    const contenedorRec = document.getElementById('carrusel-recomendados');
    const subtituloRec = document.getElementById('subtitulo-recomendados');
    if (!contenedorRec) return;

    try {
        const res = await fetch('api_recomendaciones.php');
        if (!res.ok) throw new Error("HTTP Error " + res.status);

        const data = await res.json();
        const base = data.basadoEn || [];
        const libros = data.libros || [];

        if (subtituloRec) {
            if (base.length === 0) {
                subtituloRec.textContent = 'Marca libros como leídos para recibir recomendaciones';
            } else {
                const nombres = base.slice(0, 2).map(t => `<strong>${escapeHtml(t)}</strong>`);
                const resto = base.length - 2;
                subtituloRec.innerHTML = 'Porque leíste ' + nombres.join(' y ') +
                    (resto > 0 ? ` y ${resto} más` : '');
            }
        }

        if (libros.length === 0) {
            contenedorRec.innerHTML = "<p style='opacity:.7; font-size:.85rem;'>No hay sugerencias disponibles en este momento.</p>";
            return;
        }

        contenedorRec.innerHTML = '';

        libros.forEach(libro => {
            const titulo = libro.titulo || 'Sin título';
            const autor = libro.autor || 'Autor desconocido';
            const portadaFinal = libro.portada ? libro.portada : generarPortadaSVG(titulo);

            const enlace = libro.url
                ? libro.url + '&rec=1'
                : `libro.php?id=${encodeURIComponent(libro.id)}&portada=${encodeURIComponent(libro.portada || '')}`;

            const html = `
                <a href="${escapeHtml(enlace)}" class="book-card-scroll" title="${escapeHtml(titulo)}">
                    <div style="width:110px; height:155px; position:relative; overflow:hidden; border-radius:8px; background:#1e293b;">
                        <img src="${escapeHtml(portadaFinal)}" alt="${escapeHtml(titulo)}" style="width:100%; height:100%; object-fit:cover;" onerror="this.closest('a').remove()">
                    </div>
                    <span class="title" style="display:block; margin-top:6px; font-size:0.85rem; line-height:1.2;">${escapeHtml(titulo)}</span>
                    <span class="subtitle" style="display:block; opacity:.65; font-size:0.75rem;">${escapeHtml(autor)}</span>
                </a>
            `;
            contenedorRec.insertAdjacentHTML('beforeend', html);
        });

    } catch (err) {
        console.error("Error al cargar sugerencias:", err);
        contenedorRec.innerHTML = "<p style='opacity:.7; font-size:.85rem;'>No se pudieron cargar las sugerencias.</p>";
    }
}

// Wrap Up: botón de copiar el resumen
function iniciarWrapUp() {
    document.querySelectorAll('.js-wu-copiar').forEach(btn => {
        btn.addEventListener('click', async () => {
            const original = btn.textContent;
            try {
                await navigator.clipboard.writeText(btn.dataset.texto);
                btn.textContent = '¡Copiado!';
            } catch (err) {
                btn.textContent = 'No se pudo copiar';
            }
            setTimeout(() => btn.textContent = original, 1800);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    cargarRecomendaciones();
    iniciarWrapUp();
});
</script>

<script src="/Reads/public/portadas_cola.js" defer></script>
</body>
</html>
<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$db = new Database();

/* ---------------------------------------------------------------
   Utilidades
---------------------------------------------------------------- */
function fechaValida(string $f): bool {
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

function redirigirAlMes(?string $fecha = null, ?int $mes = null, ?int $year = null): void {
    if ($fecha !== null && fechaValida($fecha)) {
        $mes  = (int)date('n', strtotime($fecha));
        $year = (int)date('Y', strtotime($fecha));
    }
    $mes  = $mes  ?: (int)date('n');
    $year = $year ?: (int)date('Y');
    header("Location: calendario.php?mes={$mes}&year={$year}");
    exit;
}

// La página "global" del libro pasa a ser la del último registro diario
function sincronizarPaginasLibro(PDO $pdo, int $uid, int $idLista): void {
    $st = $pdo->prepare("SELECT paginas_leidas FROM diario_lectura WHERE usuario_id = ? AND libro_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
    $st->execute([$uid, $idLista]);
    $ultima = $st->fetchColumn();
    if ($ultima === false) return;
    $pdo->prepare("UPDATE listas_lectura SET paginas_leidas = ? WHERE id = ? AND usuario_id = ?")
        ->execute([(int)$ultima, $idLista, $uid]);
}

/* ---------------------------------------------------------------
   Acciones (todas por POST)
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // Registrar la lectura de un día
    if ($accion === 'guardar_progreso_diario') {
        $idLista = (int)($_POST['id_lista'] ?? 0);
        $fecha   = $_POST['fecha_registro'] ?? '';
        $paginas = max(0, (int)($_POST['paginas_leidas'] ?? 0));

        if ($idLista > 0 && fechaValida($fecha)) {
            $stLibro = $db->pdo->prepare("SELECT paginas_totales FROM listas_lectura WHERE id = ? AND usuario_id = ?");
            $stLibro->execute([$idLista, $usuario['id']]);
            $libro = $stLibro->fetch(PDO::FETCH_ASSOC);

            if ($libro) {
                $totales = (int)$libro['paginas_totales'];
                if ($totales > 0) $paginas = min($paginas, $totales);

                $db->pdo->prepare("INSERT INTO diario_lectura (usuario_id, libro_id, fecha, paginas_leidas)
                                   VALUES (?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE paginas_leidas = VALUES(paginas_leidas)")
                        ->execute([$usuario['id'], $idLista, $fecha, $paginas]);

                sincronizarPaginasLibro($db->pdo, (int)$usuario['id'], $idLista);
            }
        }
        redirigirAlMes($fecha);
    }

    // Borrar un registro diario
    if ($accion === 'eliminar_diario') {
        $idDiario = (int)($_POST['id_diario'] ?? 0);

        $st = $db->pdo->prepare("SELECT libro_id FROM diario_lectura WHERE id = ? AND usuario_id = ?");
        $st->execute([$idDiario, $usuario['id']]);
        $idLista = $st->fetchColumn();

        $db->pdo->prepare("DELETE FROM diario_lectura WHERE id = ? AND usuario_id = ?")
                ->execute([$idDiario, $usuario['id']]);

        if ($idLista !== false) sincronizarPaginasLibro($db->pdo, (int)$usuario['id'], (int)$idLista);
        redirigirAlMes(null, (int)($_POST['mes'] ?? 0), (int)($_POST['year'] ?? 0));
    }

    // Nuevo lanzamiento futuro
    if ($accion === 'nuevo_lanzamiento') {
        $titulo  = trim($_POST['titulo'] ?? '');
        $autor   = trim($_POST['autor'] ?? '');
        $portada = trim($_POST['portada'] ?? '');
        $fecha   = $_POST['fecha_lanzamiento'] ?? '';

        if ($titulo !== '' && fechaValida($fecha)) {
            $db->pdo->prepare("INSERT INTO lanzamientos_deseados (usuario_id, titulo, autor, portada, fecha_lanzamiento) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$usuario['id'], $titulo, $autor, $portada, $fecha]);
        }
        redirigirAlMes($fecha);
    }

    // Borrar un lanzamiento futuro
    if ($accion === 'eliminar_lanzamiento') {
        $db->pdo->prepare("DELETE FROM lanzamientos_deseados WHERE id = ? AND usuario_id = ?")
                ->execute([(int)($_POST['id_lanzamiento'] ?? 0), $usuario['id']]);
        redirigirAlMes(null, (int)($_POST['mes'] ?? 0), (int)($_POST['year'] ?? 0));
    }

    redirigirAlMes();
}

/* ---------------------------------------------------------------
   Mes y año a mostrar
---------------------------------------------------------------- */
$mesActual  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$yearActual = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($mesActual < 1)  { $mesActual = 12; $yearActual--; }
if ($mesActual > 12) { $mesActual = 1;  $yearActual++; }

$primerDiaMes = sprintf("%04d-%02d-01", $yearActual, $mesActual);
$ultimoDiaMes = date("Y-m-t", strtotime($primerDiaMes));

$mesPrev  = $mesActual - 1; $yearPrev = $yearActual;
if ($mesPrev < 1)  { $mesPrev = 12; $yearPrev--; }
$mesNext  = $mesActual + 1; $yearNext = $yearActual;
if ($mesNext > 12) { $mesNext = 1;  $yearNext++; }

/* ---------------------------------------------------------------
   Datos
---------------------------------------------------------------- */
// Libros que estoy leyendo ahora
$stmtMisLibros = $db->pdo->prepare("SELECT id, titulo, paginas_totales, paginas_leidas
                                    FROM listas_lectura
                                    WHERE usuario_id = ? AND estado = 'leyendo'
                                    ORDER BY titulo");
$stmtMisLibros->execute([$usuario['id']]);
$misLibrosLeyendo = $stmtMisLibros->fetchAll(PDO::FETCH_ASSOC);

$eventosPorDia = [];

// Anotaciones diarias
$stmtDiario = $db->pdo->prepare("SELECT d.id AS diario_id, d.fecha, d.paginas_leidas, l.titulo, l.portada
                                 FROM diario_lectura d
                                 INNER JOIN listas_lectura l ON d.libro_id = l.id
                                 WHERE d.usuario_id = ? AND d.fecha BETWEEN ? AND ?
                                 ORDER BY d.fecha, d.id");
$stmtDiario->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtDiario->fetchAll(PDO::FETCH_ASSOC) as $reg) {
    $dia = (int)date('j', strtotime($reg['fecha']));
    $eventosPorDia[$dia][] = [
        'tipo' => 'diario', 'titulo' => $reg['titulo'], 'portada' => $reg['portada'],
        'paginas' => (int)$reg['paginas_leidas'], 'id' => (int)$reg['diario_id'],
    ];
}

// Inicio y fin de libros
$stmtHitos = $db->pdo->prepare("SELECT titulo, portada, fecha_inicio, fecha_fin
                                FROM listas_lectura
                                WHERE usuario_id = ?
                                  AND ((fecha_inicio BETWEEN ? AND ?) OR (fecha_fin BETWEEN ? AND ?))");
$stmtHitos->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes, $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtHitos->fetchAll(PDO::FETCH_ASSOC) as $h) {
    if (!empty($h['fecha_inicio']) && $h['fecha_inicio'] >= $primerDiaMes && $h['fecha_inicio'] <= $ultimoDiaMes) {
        $eventosPorDia[(int)date('j', strtotime($h['fecha_inicio']))][] =
            ['tipo' => 'hito_inicio', 'titulo' => $h['titulo'], 'portada' => $h['portada']];
    }
    if (!empty($h['fecha_fin']) && $h['fecha_fin'] >= $primerDiaMes && $h['fecha_fin'] <= $ultimoDiaMes) {
        $eventosPorDia[(int)date('j', strtotime($h['fecha_fin']))][] =
            ['tipo' => 'hito_fin', 'titulo' => $h['titulo'], 'portada' => $h['portada']];
    }
}

// Lanzamientos del mes
$stmtLanz = $db->pdo->prepare("SELECT id, titulo, portada, fecha_lanzamiento
                               FROM lanzamientos_deseados
                               WHERE usuario_id = ? AND fecha_lanzamiento BETWEEN ? AND ?");
$stmtLanz->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtLanz->fetchAll(PDO::FETCH_ASSOC) as $lz) {
    $eventosPorDia[(int)date('j', strtotime($lz['fecha_lanzamiento']))][] =
        ['tipo' => 'lanzamiento', 'titulo' => $lz['titulo'], 'portada' => $lz['portada'], 'id' => (int)$lz['id']];
}

// Próximos lanzamientos (de hoy en adelante, sin importar el mes que se vea)
$hoyStr = date('Y-m-d');
$stmtProx = $db->pdo->prepare("SELECT id, titulo, autor, portada, fecha_lanzamiento
                               FROM lanzamientos_deseados
                               WHERE usuario_id = ? AND fecha_lanzamiento >= ?
                               ORDER BY fecha_lanzamiento ASC LIMIT 5");
$stmtProx->execute([$usuario['id'], $hoyStr]);
$proximos = $stmtProx->fetchAll(PDO::FETCH_ASSOC);

// Racha de días seguidos con lectura anotada
$stmtRacha = $db->pdo->prepare("SELECT DISTINCT fecha FROM diario_lectura WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 400");
$stmtRacha->execute([$usuario['id'], $hoyStr]);
$fechasLectura = array_flip($stmtRacha->fetchAll(PDO::FETCH_COLUMN));
$racha = 0;
$cursor = new DateTime($hoyStr);
if (!isset($fechasLectura[$cursor->format('Y-m-d')])) $cursor->modify('-1 day');   // si hoy aún no has anotado, cuenta desde ayer
while (isset($fechasLectura[$cursor->format('Y-m-d')])) {
    $racha++;
    $cursor->modify('-1 day');
}

// Resumen del mes
$diasLeyendo = 0; $terminadosMes = 0; $empezadosMes = 0;
foreach ($eventosPorDia as $evs) {
    $hayDiario = false;
    foreach ($evs as $e) {
        if ($e['tipo'] === 'diario') $hayDiario = true;
        if ($e['tipo'] === 'hito_fin') $terminadosMes++;
        if ($e['tipo'] === 'hito_inicio') $empezadosMes++;
    }
    if ($hayDiario) $diasLeyendo++;
}

$diasEnMes = (int)date('t', strtotime($primerDiaMes));
$primerDiaSemana = (int)date('N', strtotime($primerDiaMes));
$mesesEs = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
$esMesActual = ($mesActual === (int)date('n') && $yearActual === (int)date('Y'));

$etiquetas = [
    'diario'      => ['📖', 'Lectura'],
    'hito_inicio' => ['🚀', 'Empieza'],
    'hito_fin'    => ['🏁', 'Terminado'],
    'lanzamiento' => ['📣', 'Salida'],
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

function textoCuentaAtras(string $fecha): string {
    $dias = (int)((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
    if ($dias <= 0) return '¡Hoy!';
    if ($dias === 1) return 'Mañana';
    return "En {$dias} días";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendario de Lectura</title>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
<script src="main.js"></script>
<style>
:root {
    --cal-accent: var(--primary-color, var(--color-primario, #d87d8a));
    --cal-border: var(--border-color, rgba(0,0,0,.09));
    --cal-surface: var(--bg-card, #ffffff);
}
body { padding-bottom: 110px; }
body.modal-open { overflow: hidden; }
.cal-wrap { max-width: 1060px; margin: 0 auto; padding: 20px 16px; }

.cal-header h1 { margin: 0 0 4px; font-size: 1.9rem; }
.cal-header p { margin: 0 0 18px; opacity: .7; }

/* Resumen */
.cal-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.cal-kpi { background: var(--cal-surface); border: 1px solid var(--cal-border); border-radius: 14px; padding: 14px 16px; position: relative; overflow: hidden; }
.cal-kpi::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--cal-accent); }
.cal-kpi .valor { display: block; font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
.cal-kpi .etiqueta { display: block; font-size: .78rem; opacity: .7; font-weight: 600; margin-top: 2px; }

/* Distribución */
.cal-layout { display: grid; grid-template-columns: 1fr 290px; gap: 18px; align-items: start; }
.cal-card { background: var(--cal-surface); border: 1px solid var(--cal-border); border-radius: 16px; padding: 20px; min-width: 0; }
.cal-card h2 { margin: 0 0 12px; font-size: 1.05rem; }
.cal-side { display: flex; flex-direction: column; gap: 18px; }

/* Navegación del mes */
.cal-nav { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.cal-nav h2 { margin: 0; font-size: 1.35rem; }
.cal-nav-btns { display: flex; gap: 6px; }
.cal-btn { padding: 7px 14px; border-radius: 999px; border: 1px solid var(--cal-border); background: rgba(0,0,0,.04); color: inherit; text-decoration: none; font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; }
.cal-btn:hover { border-color: var(--cal-accent); }
.cal-btn.hoy { background: var(--cal-accent); border-color: var(--cal-accent); color: #fff; }

/* Rejilla */
.calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.day-name { text-align: center; font-weight: 700; font-size: .8rem; padding: 6px 0; opacity: .6; }
.day-cell {
    background: rgba(255,255,255,.7); border: 1px solid var(--cal-border); border-radius: 10px;
    min-height: 104px; padding: 6px; cursor: pointer; transition: background .2s, transform .15s; overflow: hidden;
}
.day-cell:hover, .day-cell:focus-visible { background: rgba(255,255,255,.98); transform: translateY(-2px); outline: none; border-color: var(--cal-accent); }
.day-cell.empty { background: transparent; border: none; cursor: default; pointer-events: none; }
.day-cell.today { border: 2px solid var(--cal-accent); background: #fff; }
.day-number { font-size: .82rem; font-weight: 800; opacity: .7; }
.day-cell.today .day-number { color: var(--cal-accent); opacity: 1; }

.entry-card { display: flex; align-items: center; gap: 5px; background: #fff; padding: 3px 5px; border-radius: 6px; margin-top: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.12); overflow: hidden; }
.entry-card img { width: 20px; height: 29px; object-fit: cover; border-radius: 3px; flex-shrink: 0; background: #e2e8f0; }
.badge { font-size: .65rem; font-weight: 700; padding: 2px 5px; border-radius: 4px; white-space: nowrap; }
.badge-diario { background: #e8f0fe; color: #1a73e8; }
.badge-hito_inicio { background: #e6f4ea; color: #137333; }
.badge-hito_fin { background: #fce8e6; color: #c5221f; }
.badge-lanzamiento { background: #feefc3; color: #b06000; }
.mas-eventos { font-size: .7rem; font-weight: 700; opacity: .65; margin-top: 3px; display: block; }

.dots { display: none; gap: 3px; flex-wrap: wrap; margin-top: 6px; }
.dot { width: 8px; height: 8px; border-radius: 50%; }
.dot-diario { background: #1a73e8; } .dot-hito_inicio { background: #137333; }
.dot-hito_fin { background: #c5221f; } .dot-lanzamiento { background: #e0a100; }

.leyenda { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }

/* Barra lateral */
.prox-item { display: flex; gap: 10px; align-items: center; padding: 8px 0; border-top: 1px solid var(--cal-border); }
.prox-item:first-of-type { border-top: none; padding-top: 0; }
.prox-item img { width: 36px; height: 52px; object-fit: cover; border-radius: 4px; background: #e2e8f0; flex-shrink: 0; }
.prox-info { min-width: 0; flex: 1; }
.prox-info strong { display: block; font-size: .88rem; line-height: 1.2; }
.prox-info span { display: block; font-size: .75rem; opacity: .7; }
.prox-cuenta { font-size: .72rem; font-weight: 800; color: var(--cal-accent); white-space: nowrap; }
.vacio { margin: 0; opacity: .65; font-size: .88rem; }

details.form-lanzamiento summary { font-weight: 700; cursor: pointer; font-size: .95rem; }
.form-lanzamiento form { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.cal-input { padding: 9px 11px; border-radius: 8px; border: 1px solid var(--cal-border); font: inherit; font-size: .88rem; width: 100%; box-sizing: border-box; }
.cal-submit { padding: 10px 16px; border: none; border-radius: 10px; background: var(--cal-accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.cal-submit:hover { opacity: .92; }

/* Modal del día */
.modal-dia { width: min(460px, calc(100% - 24px)); max-height: calc(100vh - 40px); padding: 0; border: none; border-radius: 18px; overflow: hidden; background: var(--cal-surface); color: var(--text-color, #2d3748); box-shadow: 0 20px 60px rgba(0,0,0,.35); }
.modal-dia::backdrop { background: rgba(15,23,42,.55); backdrop-filter: blur(3px); }
.modal-dia[open] { display: flex; flex-direction: column; }
.modal-cerrar { position: absolute; top: 10px; right: 12px; width: 32px; height: 32px; border-radius: 50%; border: none; cursor: pointer; background: rgba(0,0,0,.08); color: inherit; font-size: 1rem; }
.modal-cuerpo { padding: 22px; overflow-y: auto; }
.modal-cuerpo h3 { margin: 0 0 14px; font-size: 1.15rem; text-transform: capitalize; padding-right: 30px; }
.modal-sep { border: none; border-top: 1px dashed var(--cal-border); margin: 16px 0; }
.ev-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--cal-border); }
.ev-row:first-child { border-top: none; }
.ev-row img { width: 34px; height: 49px; object-fit: cover; border-radius: 4px; background: #e2e8f0; flex-shrink: 0; }
.ev-texto { flex: 1; min-width: 0; font-size: .88rem; font-weight: 600; }
.ev-borrar { background: none; border: none; color: #d9534f; cursor: pointer; font-size: 1rem; padding: 4px 8px; border-radius: 6px; }
.ev-borrar:hover { background: #f8d7da; }
.modal-form label { display: block; font-size: .85rem; font-weight: 700; margin: 10px 0 4px; }
.modal-hint { font-size: .78rem; opacity: .7; margin: 4px 0 0; }
.modal-acciones { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }

/* Barra inferior */
.floating-nav-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: calc(100% - 40px); max-width: 600px; }
.quick-nav-floating { display: flex; align-items: center; justify-content: space-around; padding: 8px 12px; background: rgba(255,255,255,.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.6); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
.nav-card-float { display: flex; flex-direction: column; align-items: center; padding: 6px 12px; text-decoration: none; color: #2d3748; font-weight: 600; font-size: .8rem; border-radius: 12px; transition: color .2s, transform .2s; }
.nav-card-float:hover, .nav-card-float.active { color: var(--cal-accent); transform: translateY(-2px); }
.nav-card-float .nav-icon { font-size: 1.25rem; margin-bottom: 2px; }

@media (max-width: 860px) {
    .cal-layout { grid-template-columns: 1fr; }
    .cal-kpis { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .cal-wrap { padding: 14px 10px; }
    .cal-header h1 { font-size: 1.55rem; }
    .calendar-grid { gap: 4px; }
    .day-cell { min-height: 58px; padding: 4px; border-radius: 8px; }
    .entry-card, .mas-eventos { display: none; }
    .dots { display: flex; }
    .cal-card { padding: 14px; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

<div class="cal-wrap">

    <header class="cal-header">
        <h1>📅 Calendario de Lectura</h1>
        <p>Pulsa cualquier día para ver lo que pasó en él y anotar tus páginas.</p>
    </header>

    <!-- Resumen del mes -->
    <section class="cal-kpis">
        <div class="cal-kpi"><span class="valor">🔥 <?= $racha ?></span><span class="etiqueta">Racha actual (<?= $racha === 1 ? 'día' : 'días' ?>)</span></div>
        <div class="cal-kpi"><span class="valor"><?= $diasLeyendo ?></span><span class="etiqueta">Días leyendo en <?= strtolower($mesesEs[$mesActual]) ?></span></div>
        <div class="cal-kpi"><span class="valor"><?= $terminadosMes ?></span><span class="etiqueta"><?= $terminadosMes === 1 ? 'Libro terminado' : 'Libros terminados' ?></span></div>
        <div class="cal-kpi"><span class="valor"><?= $empezadosMes ?></span><span class="etiqueta"><?= $empezadosMes === 1 ? 'Libro empezado' : 'Libros empezados' ?></span></div>
    </section>

    <div class="cal-layout">

        <!-- Calendario -->
        <section class="cal-card">
            <div class="cal-nav">
                <h2><?= $mesesEs[$mesActual] ?> <?= $yearActual ?></h2>
                <div class="cal-nav-btns">
                    <a class="cal-btn" href="?mes=<?= $mesPrev ?>&year=<?= $yearPrev ?>" aria-label="Mes anterior">←</a>
                    <?php if (!$esMesActual): ?><a class="cal-btn hoy" href="calendario.php">Hoy</a><?php endif; ?>
                    <a class="cal-btn" href="?mes=<?= $mesNext ?>&year=<?= $yearNext ?>" aria-label="Mes siguiente">→</a>
                </div>
            </div>

            <div class="calendar-grid">
                <div class="day-name">Lun</div><div class="day-name">Mar</div><div class="day-name">Mié</div>
                <div class="day-name">Jue</div><div class="day-name">Vie</div><div class="day-name">Sáb</div><div class="day-name">Dom</div>

                <?php for ($i = 1; $i < $primerDiaSemana; $i++): ?>
                    <div class="day-cell empty"></div>
                <?php endfor; ?>

                <?php for ($dia = 1; $dia <= $diasEnMes; $dia++):
                    $fechaFormatted = sprintf("%04d-%02d-%02d", $yearActual, $mesActual, $dia);
                    $esHoy = ($fechaFormatted === $hoyStr) ? 'today' : '';
                    $evs = $eventosPorDia[$dia] ?? [];
                ?>
                    <div class="day-cell <?= $esHoy ?>" data-dia="<?= $dia ?>" tabindex="0" role="button" aria-label="Día <?= $dia ?> de <?= $mesesEs[$mesActual] ?>">
                        <span class="day-number"><?= $dia ?></span>

                        <?php foreach (array_slice($evs, 0, 2) as $ev):
                            $portada = !empty($ev['portada']) ? htmlspecialchars($ev['portada']) : '/Reads/img/default_cover.jpg';
                            $titulo  = htmlspecialchars($ev['titulo']);
                            $texto   = $ev['tipo'] === 'diario' ? 'Pág. ' . $ev['paginas'] : $etiquetas[$ev['tipo']][0] . ' ' . $etiquetas[$ev['tipo']][1];
                        ?>
                            <div class="entry-card" title="<?= $titulo ?>">
                                <img src="<?= $portada ?>" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                                <span class="badge badge-<?= $ev['tipo'] ?>"><?= $texto ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($evs) > 2): ?><span class="mas-eventos">+<?= count($evs) - 2 ?> más</span><?php endif; ?>

                        <?php if ($evs): ?>
                            <div class="dots"><?php foreach ($evs as $ev): ?><span class="dot dot-<?= $ev['tipo'] ?>"></span><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="leyenda">
                <?php foreach ($etiquetas as $tipo => [$icono, $nombre]): ?>
                    <span class="badge badge-<?= $tipo ?>"><?= $icono ?> <?= $nombre ?></span>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Barra lateral -->
        <aside class="cal-side">
            <section class="cal-card">
                <h2>📣 Próximos lanzamientos</h2>
                <?php if (empty($proximos)): ?>
                    <p class="vacio">Aún no tienes lanzamientos guardados.</p>
                <?php else: foreach ($proximos as $p): ?>
                    <div class="prox-item">
                        <img src="<?= htmlspecialchars(!empty($p['portada']) ? $p['portada'] : '/Reads/img/default_cover.jpg') ?>" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                        <div class="prox-info">
                            <strong><?= htmlspecialchars($p['titulo']) ?></strong>
                            <span><?= !empty($p['autor']) ? htmlspecialchars($p['autor']) . ' · ' : '' ?><?= date('d/m/Y', strtotime($p['fecha_lanzamiento'])) ?></span>
                        </div>
                        <span class="prox-cuenta"><?= textoCuentaAtras($p['fecha_lanzamiento']) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </section>

            <details class="cal-card form-lanzamiento">
                <summary>🚀 Recordar un lanzamiento</summary>
                <form method="POST">
                    <input type="hidden" name="accion" value="nuevo_lanzamiento">
                    <input class="cal-input" type="text" name="titulo" placeholder="Título del libro *" required>
                    <input class="cal-input" type="text" name="autor" placeholder="Autor / Autora">
                    <input class="cal-input" type="url" name="portada" placeholder="URL de portada (opcional)">
                    <input class="cal-input" type="date" name="fecha_lanzamiento" value="<?= date('Y-m-d') ?>" required>
                    <button type="submit" class="cal-submit">Guardar recordatorio</button>
                </form>
            </details>
        </aside>
    </div>
</div>

<!-- Modal del día -->
<dialog id="modalDia" class="modal-dia" aria-label="Detalle del día">
    <button type="button" class="modal-cerrar" id="modalCerrar" aria-label="Cerrar">✕</button>
    <div class="modal-cuerpo">
        <h3 id="diaTitulo"></h3>
        <div id="diaEventos"></div>

        <hr class="modal-sep">

        <?php if (empty($misLibrosLeyendo)): ?>
            <p class="vacio">No tienes ningún libro en estado <strong>«Leyendo»</strong>. Cambia el estado de un libro en tu perfil para anotar tus avances diarios.</p>
        <?php else: ?>
            <form method="POST" class="modal-form" id="formLectura">
                <input type="hidden" name="accion" value="guardar_progreso_diario">
                <input type="hidden" name="fecha_registro" id="modal_fecha">

                <strong>📖 Anotar lectura de este día</strong>

                <label for="selLibro">Libro</label>
                <select name="id_lista" id="selLibro" class="cal-input" required>
                    <?php foreach ($misLibrosLeyendo as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" data-actual="<?= (int)$l['paginas_leidas'] ?>" data-total="<?= (int)$l['paginas_totales'] ?>">
                            <?= htmlspecialchars($l['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="inpPaginas">Página alcanzada ese día</label>
                <input type="number" name="paginas_leidas" id="inpPaginas" class="cal-input" min="0" required>
                <p class="modal-hint" id="hintPaginas"></p>

                <div class="modal-acciones">
                    <button type="button" class="cal-btn" id="btnCancelar">Cancelar</button>
                    <button type="submit" class="cal-submit">Guardar</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</dialog>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi Perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<script>
const EVENTOS = <?= json_encode($eventosPorDia, $jsonFlags) ?>;
const ETIQUETAS = <?= json_encode($etiquetas, $jsonFlags) ?>;
const MES = <?= $mesActual ?>, ANIO = <?= $yearActual ?>;
const PORTADA_DEFECTO = '/Reads/img/default_cover.jpg';

const modal = document.getElementById('modalDia');

function crearFormBorrar(accion, campoId, id, aviso) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.addEventListener('submit', e => { if (!confirm(aviso)) e.preventDefault(); });
    [['accion', accion], [campoId, id], ['mes', MES], ['year', ANIO]].forEach(([n, v]) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = n; i.value = v;
        f.appendChild(i);
    });
    const b = document.createElement('button');
    b.type = 'submit'; b.className = 'ev-borrar'; b.title = 'Borrar'; b.textContent = '🗑️';
    f.appendChild(b);
    return f;
}

function pintarEventos(dia) {
    const cont = document.getElementById('diaEventos');
    cont.replaceChildren();
    const lista = EVENTOS[dia] || [];

    if (!lista.length) {
        const p = document.createElement('p');
        p.className = 'vacio';
        p.textContent = 'Nada anotado en este día todavía.';
        cont.appendChild(p);
        return;
    }

    lista.forEach(ev => {
        const fila = document.createElement('div');
        fila.className = 'ev-row';

        const img = document.createElement('img');
        img.src = ev.portada || PORTADA_DEFECTO; img.alt = ''; img.loading = 'lazy';
        img.onerror = () => { img.style.visibility = 'hidden'; };

        const texto = document.createElement('div');
        texto.className = 'ev-texto';
        const titulo = document.createElement('div');
        titulo.textContent = ev.titulo;
        const badge = document.createElement('span');
        badge.className = 'badge badge-' + ev.tipo;
        const et = ETIQUETAS[ev.tipo];
        badge.textContent = ev.tipo === 'diario' ? '📖 Página ' + ev.paginas : et[0] + ' ' + et[1];
        texto.append(titulo, badge);

        fila.append(img, texto);
        if (ev.tipo === 'diario') fila.appendChild(crearFormBorrar('eliminar_diario', 'id_diario', ev.id, '¿Borrar este registro de lectura?'));
        if (ev.tipo === 'lanzamiento') fila.appendChild(crearFormBorrar('eliminar_lanzamiento', 'id_lanzamiento', ev.id, '¿Borrar este lanzamiento?'));
        cont.appendChild(fila);
    });
}

function ajustarCampoPaginas() {
    const sel = document.getElementById('selLibro');
    if (!sel) return;
    const op = sel.options[sel.selectedIndex];
    const actual = parseInt(op.dataset.actual, 10) || 0;
    const total = parseInt(op.dataset.total, 10) || 0;
    const inp = document.getElementById('inpPaginas');
    inp.value = actual;
    if (total > 0) inp.max = total; else inp.removeAttribute('max');
    document.getElementById('hintPaginas').textContent =
        'Vas por la página ' + actual + (total > 0 ? ' de ' + total : '') + '.';
}

function abrirDia(dia) {
    const fecha = ANIO + '-' + String(MES).padStart(2, '0') + '-' + String(dia).padStart(2, '0');
    document.getElementById('diaTitulo').textContent =
        new Date(ANIO, MES - 1, dia).toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
    const campoFecha = document.getElementById('modal_fecha');
    if (campoFecha) campoFecha.value = fecha;
    pintarEventos(dia);
    ajustarCampoPaginas();
    modal.showModal();
    document.body.classList.add('modal-open');
}

document.querySelectorAll('.day-cell[data-dia]').forEach(celda => {
    const abrir = () => abrirDia(parseInt(celda.dataset.dia, 10));
    celda.addEventListener('click', abrir);
    celda.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrir(); }
    });
});

const selLibro = document.getElementById('selLibro');
if (selLibro) selLibro.addEventListener('change', ajustarCampoPaginas);

document.getElementById('modalCerrar').addEventListener('click', () => modal.close());
const btnCancelar = document.getElementById('btnCancelar');
if (btnCancelar) btnCancelar.addEventListener('click', () => modal.close());
modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
modal.addEventListener('close', () => document.body.classList.remove('modal-open'));
</script>

</body>
</html>
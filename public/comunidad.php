<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/Database.php";
require_once "../src/ComunidadService.php";
require_once "../src/ComunidadVistas.php";
require_once "../src/helpers.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}
$yo = (int)$usuario["id"];

// Tema visual
$userService  = new UserService();
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = strtolower(trim($datosUsuario["tema_visual"] ?? "pastel"));

$db   = new Database();
$com  = new ComunidadService($db->pdo);
$csrf = ComunidadService::token();

$q   = trim((string)($_GET['q'] ?? ''));
$ver = $_GET['ver'] ?? 'inicio';
if (!in_array($ver, ['inicio', 'actividad', 'siguiendo', 'seguidores'], true)) $ver = 'inicio';

$filtro = $_GET['filtro'] ?? 'todo';
$filtros = ['todo' => 'Todo', 'terminados' => ' Terminados', 'leyendo' => ' Leyendo', 'quieren' => ' Quieren leer'];
if (!isset($filtros[$filtro])) $filtro = 'todo';

$cont = $com->contadores($yo);

$resultados = ($q !== '') ? $com->buscarUsuarios($yo, $q) : [];
$leyendo = $grupos = $sugeridos = $lista = [];

if ($q === '') {
    if ($ver === 'inicio') {
        $leyendo   = $com->amigosLeyendo($yo, 12);
        $grupos    = $com->actividadAgrupada($yo, 5, 'todo', 80);   // resumen corto
        $sugeridos = $com->sugeridos($yo, 5);
    } elseif ($ver === 'actividad') {
        $grupos = $com->actividadAgrupada($yo, 40, $filtro, 300);   // vista completa
    } else {
        $lista = $com->listaRelacion($yo, $ver, $yo);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= cmH($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/comunidad.css?v=<?= @filemtime(__DIR__ . '/css/comunidad.css') ?>">
    <script src="main.js"></script>
    <title>Comunidad · Reads</title>
</head>

<body class="page-buscar">
<div class="bs-wrap">

    <!-- Cabecera y buscador de lectores -->
    <section class="bs-hero">
        <h1> Comunidad</h1>
        <p>Encuentra a otros lectores, síguelos y descubre qué están leyendo.</p>

        <form method="GET" action="comunidad.php" class="bs-form">
            <div class="bs-input-wrap">
                <span aria-hidden="true">🔎</span>
                <input type="text" name="q" class="bs-input" autocomplete="off"
                       placeholder="Busca lectores por nombre..." value="<?= cmH($q) ?>" aria-label="Buscar lectores">
            </div>
            <button type="submit" class="bs-btn">Buscar</button>
        </form>

        <div class="cm-pestanas">
            <a class="bs-chip" style="<?= ($ver === 'inicio' && $q === '') ? 'font-weight:700;' : '' ?>" href="comunidad.php"> Inicio</a>
            <a class="bs-chip" style="<?= $ver === 'actividad' && $q === '' ? 'font-weight:700;' : '' ?>" href="comunidad.php?ver=actividad"> Actividad</a>
            <a class="bs-chip" style="<?= $ver === 'siguiendo' ? 'font-weight:700;' : '' ?>" href="comunidad.php?ver=siguiendo">Siguiendo (<?= $cont['siguiendo'] ?>)</a>
            <a class="bs-chip" style="<?= $ver === 'seguidores' ? 'font-weight:700;' : '' ?>" href="comunidad.php?ver=seguidores">Seguidores (<?= $cont['seguidores'] ?>)</a>
        </div>
    </section>

    <?php if ($q !== ''): ?>

        <!-- Resultados de la búsqueda -->
        <section class="bs-card">
            <h2>Resultados para «<?= cmH($q) ?>»</h2>
            <?php if (mb_strlen($q) < 2): ?>
                <p class="cm-vacio">Escribe al menos 2 letras.</p>
            <?php elseif (empty($resultados)): ?>
                <p class="cm-vacio">No hemos encontrado lectores con ese nombre. Solo aparecen los perfiles públicos.</p>
            <?php else: ?>
                <ul class="cm-lista">
                    <?php foreach ($resultados as $u) echo cmFilaUsuario($u, $csrf); ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php elseif ($ver === 'actividad'): ?>

        <!-- Actividad completa, con filtros -->
        <section class="bs-card">
            <h2> Actividad de tus amigos</h2>
            <div class="cm-pestanas">
                <?php foreach ($filtros as $cod => $etq): ?>
                    <a class="bs-chip" style="<?= $filtro === $cod ? 'font-weight:700;' : '' ?>"
                       href="comunidad.php?ver=actividad&filtro=<?= $cod ?>"><?= cmH($etq) ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($grupos)): ?>
                <p class="cm-vacio">
                    <?= $cont['siguiendo'] > 0
                        ? 'No hay actividad de este tipo todavía.'
                        : 'Sigue a otros lectores para ver aquí su actividad.' ?>
                </p>
            <?php else: ?>
                <ul class="cm-lista">
                    <?php foreach ($grupos as $g) echo cmItemGrupo($g, 8); ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php elseif ($ver !== 'inicio'): ?>

        <!-- Siguiendo / Seguidores -->
        <section class="bs-card">
            <h2><?= $ver === 'siguiendo' ? 'Personas a las que sigues' : 'Personas que te siguen' ?></h2>
            <?php if (empty($lista)): ?>
                <p class="cm-vacio">
                    <?= $ver === 'siguiendo'
                        ? 'Todavía no sigues a nadie. Usa el buscador para encontrar lectores.'
                        : 'Aún nadie te sigue. ¡Comparte tu perfil con tus amigos!' ?>
                </p>
            <?php else: ?>
                <ul class="cm-lista">
                    <?php foreach ($lista as $u) echo cmFilaUsuario($u, $csrf); ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php else: ?>

        <!-- Leyendo ahora -->
        <section class="bs-card">
            <h2> Tus amigos están leyendo</h2>
            <?php if (!empty($leyendo)): ?>
                <div class="cm-scroll">
                    <?php foreach ($leyendo as $l) echo cmTarjetaLeyendo($l); ?>
                </div>
            <?php elseif ($cont['siguiendo'] > 0): ?>
                <p class="cm-vacio">Ahora mismo ninguna de las personas que sigues está leyendo un libro.</p>
            <?php else: ?>
                <p class="cm-vacio">Cuando sigas a otros lectores verás aquí qué libros tienen entre manos y cuánto llevan.</p>
            <?php endif; ?>
        </section>

        <!-- Actividad reciente (resumen) -->
        <section class="bs-card">
            <h2> Actividad reciente</h2>
            <?php if (empty($grupos)): ?>
                <p class="cm-vacio">Aquí aparecerá lo último que añadan o terminen las personas a las que sigues.</p>
            <?php else: ?>
                <ul class="cm-lista">
                    <?php foreach ($grupos as $g) echo cmItemGrupo($g, 6); ?>
                </ul>
                <a class="cm-enlace-ver" href="comunidad.php?ver=actividad">Ver toda la actividad →</a>
            <?php endif; ?>
        </section>

        <!-- Lectores sugeridos -->
        <section class="bs-card">
            <h2> Lectores que quizá te interesen</h2>
            <?php if (empty($sugeridos)): ?>
                <p class="cm-vacio">Aún no hay sugerencias. Cuando haya más lectores en la comunidad aparecerán aquí.</p>
            <?php else: ?>
                <ul class="cm-lista">
                    <?php foreach ($sugeridos as $u) echo cmFilaUsuario($u, $csrf); ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div><!-- /bs-wrap -->

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

        <a href="estadisticas.php" class="nav-card-float">
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

        <a href="comunidad.php" class="nav-card-float active">
            <span class="nav-icon">👥</span>
            <span>Comunidad</span>
        </a>

        <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<script src="comunidad.js"></script>
</body>
</html>
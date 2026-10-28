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
$id = (int)($_GET['id'] ?? 0);

if ($id === $yo) {            // tu propio perfil está en perfil.php
    header("Location: perfil.php");
    exit;
}

// Tema visual
$userService  = new UserService();
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = strtolower(trim($datosUsuario["tema_visual"] ?? "pastel"));

$db   = new Database();
$com  = new ComunidadService($db->pdo);
$csrf = ComunidadService::token();

$p = $id > 0 ? $com->perfil($id, $yo) : null;
if (!$p) http_response_code(404);

$nombre = $p['usuario']['nombre'] ?? '';

$mesesInicial = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
$mesesNombre  = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Rejilla de portadas (reutiliza tus clases shelf-grid / shelf-item) */
function pfEstanteria(array $libros): string
{
    $html = '<div class="shelf-grid">';
    foreach ($libros as $l) {
        $src = cmPortada($l);
        $tiene = $src !== '';
        $html .= '<a href="libro.php?id=' . urlencode((string)$l['libro_id']) . '" class="shelf-item" title="' . cmH($l['titulo']) . '">'
               . ($tiene ? '<img src="' . cmH($src) . '" alt="' . cmH($l['titulo']) . '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';">' : '')
               . '<div class="cubierta-generada-card" style="' . ($tiene ? 'display:none;' : 'display:flex;') . '">'
               . '<span style="font-size:9px; font-weight:bold; line-height:1.1; max-height:40px; overflow:hidden;">' . cmH($l['titulo']) . '</span>'
               . '<span style="font-size:12px; margin-top:4px;">📖</span></div></a>';
    }
    return $html . '</div>';
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
    <title><?= $p ? cmH($nombre) . ' · Lector' : 'Lector no encontrado' ?></title>
</head>

<body class="page-buscar">
<div class="bs-wrap">

<?php if (!$p): ?>

    <section class="bs-card bs-vacio">
        <div class="ico">🕵️</div>
        <h2>No hemos encontrado a este lector</h2>
        <p><a href="comunidad.php">Volver a la comunidad</a></p>
    </section>

<?php else: ?>

    <!-- Cabecera con banner de color propio -->
    <section class="bs-card pf-hero">
        <div class="pf-banner" style="background: <?= cmH(cmGradienteTema($p['tema'])) ?>;"></div>
        <div class="pf-cuerpo">
            <?= cmAvatar($nombre, 'xl', $p['usuario']['avatar'] ?? null) ?>

            <div class="pf-ident">
                <h1>
                    <?= cmH($nombre) ?>
                    <?php if ($p['te_sigue']): ?><span class="pf-etiqueta">Te sigue</span><?php endif; ?>
                </h1>
                <div class="pf-stats">
                    <div class="pf-stat"><strong data-seguidores-de="<?= $id ?>"><?= $p['seguidores'] ?></strong><span>seguidores</span></div>
                    <div class="pf-stat"><strong><?= $p['siguiendo'] ?></strong><span>siguiendo</span></div>
                    <?php if (!$p['privado']): ?>
                        <div class="pf-stat"><strong><?= $p['leidos_anio'] ?></strong><span>leídos en <?= $p['anio'] ?></span></div>
                        <div class="pf-stat"><strong><?= number_format($p['paginas_anio'], 0, ',', '.') ?></strong><span>páginas</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$p['privado'] || $p['sigo']): ?>
                <div class="pf-accion"><?= cmBotonSeguir($id, $p['sigo'], $csrf) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($p['privado']): ?>

        <section class="bs-card bs-vacio" style="margin-top:16px;">
            <div class="ico">🔒</div>
            <h2>Este perfil es privado</h2>
            <p>Esta persona ha decidido no mostrar sus lecturas.</p>
        </section>

    <?php else: ?>

    <?php
        $af = $p['afinidad'];
        $pctReto = $p['objetivo'] > 0 ? min(100, (int)round($p['leidos_anio'] / $p['objetivo'] * 100)) : 0;
        $maxMes = max(1, max($p['meses']));
        $topMes = max($p['meses']) > 0 ? array_search(max($p['meses']), $p['meses']) : null;
        $e = $p['por_estado'];
    ?>

    <div class="pf-grid">

        <!--  Columna principal  -->
        <div class="pf-main">

            <!-- Afinidad -->
            <section class="bs-card">
                <h2>💞 Afinidad lectora contigo</h2>
                <?php if ($af): ?>
                    <div class="pf-afinidad" style="margin-top:12px;">
                        <?= cmAnillo((int)$af['indice'], $af['indice'] . '%', 'afinidad') ?>
                        <div class="texto">
                            <p>📚 <strong><?= (int)$af['libros'] ?></strong> <?= $af['libros'] === 1 ? 'libro' : 'libros' ?> en común</p>
                            <p>✍️ <strong><?= (int)$af['autores'] ?></strong> <?= $af['autores'] === 1 ? 'autor' : 'autores' ?> en común</p>
                            <small style="opacity:.65;">
                                <?php
                                    echo $af['indice'] >= 60 ? 'Casi gemelos lectores ✨'
                                       : ($af['indice'] >= 30 ? 'Tenéis bastantes gustos en común'
                                       : ($af['indice'] > 0 ? 'Algunos puntos en común' : 'Gustos muy distintos: ¡buena fuente de descubrimientos!'));
                                ?>
                            </small>
                        </div>
                    </div>
                    <?php if (!empty($af['lista'])): ?>
                        <div class="pf-comunes">
                            <?php foreach ($af['lista'] as $c): $src = cmPortada($c); ?>
                                <a href="libro.php?id=<?= urlencode((string)$c['libro_id']) ?>" title="<?= cmH($c['titulo']) ?>">
                                    <?php if ($src !== ''): ?>
                                        <img src="<?= cmH($src) ?>" alt="" loading="lazy" onerror="this.remove()">
                                    <?php else: ?>
                                        <span class="cm-mini"></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="cm-vacio">Cuando los dos tengáis más libros guardados podremos calcular vuestra afinidad.</p>
                <?php endif; ?>
            </section>

            <!-- Leyendo ahora -->
            <section class="bs-card">
                <h2>📖 Leyendo ahora</h2>
                <?php if (empty($p['leyendo'])): ?>
                    <p class="cm-vacio"><?= cmH($nombre) ?> no está leyendo ningún libro ahora mismo.</p>
                <?php else: ?>
                    <div class="cm-scroll">
                        <?php foreach ($p['leyendo'] as $l) { $l['nombre'] = $nombre; $l['avatar'] = $p['usuario']['avatar'] ?? null; echo cmTarjetaLeyendo($l); } ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Últimos leídos -->
            <section class="bs-card">
                <h2>✅ Últimos libros leídos</h2>
                <?php if (empty($p['recientes'])): ?>
                    <p class="cm-vacio">Todavía no ha terminado ningún libro.</p>
                <?php else: ?>
                    <?= pfEstanteria($p['recientes']) ?>
                <?php endif; ?>
            </section>

            <!-- Quiere leer -->
            <?php if (!empty($p['quiere'])): ?>
            <section class="bs-card">
                <h2>🎯 Quiere leer</h2>
                <?= pfEstanteria($p['quiere']) ?>
            </section>
            <?php endif; ?>
        </div>

        <!-- ================= Columna lateral ================= -->
        <aside class="pf-side">

            <!-- Reto anual -->
            <section class="bs-card">
                <h2>🎯 Reto <?= $p['anio'] ?></h2>
                <div class="pf-reto" style="margin-top:12px;">
                    <?= cmAnillo($pctReto, $pctReto . '%') ?>
                    <p><strong><?= $p['leidos_anio'] ?></strong> de <?= $p['objetivo'] ?> libros</p>
                </div>
            </section>

            <!-- Estanterías -->
            <section class="bs-card">
                <h2>📚 Su estantería</h2>
                <ul class="pf-estantes">
                    <li><span>✅ Leídos</span><strong><?= $e['leido'] ?></strong></li>
                    <li><span>📖 Leyendo</span><strong><?= $e['leyendo'] ?></strong></li>
                    <li><span>🎯 TBR</span><strong><?= $e['tbr'] ?></strong></li>
                    <li><span>🎁 Wishlist</span><strong><?= $e['guardado'] ?></strong></li>
                    <?php if ($e['abandonado'] > 0): ?><li><span>❌ Abandonados</span><strong><?= $e['abandonado'] ?></strong></li><?php endif; ?>
                </ul>
            </section>

            <!-- Géneros -->
            <?php if (!empty($p['generos'])): ?>
            <section class="bs-card">
                <h2>🏷️ Géneros favoritos</h2>
                <div class="pf-chips">
                    <?php foreach ($p['generos'] as $g => $n): ?>
                        <span class="pf-chip">
                            <?= function_exists('estiloGenero') ? cmH(estiloGenero($g)[0]) . ' ' : '' ?><?= cmH($g) ?><small><?= (int)$n ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Autor favorito -->
            <?php if ($p['autor_top']): ?>
            <section class="bs-card">
                <h2>✍️ Autor más leído</h2>
                <div class="pf-autor">
                    <span class="ico">🏅</span>
                    <div>
                        <a href="autor.php?nombre=<?= urlencode($p['autor_top']) ?>"><?= cmH($p['autor_top']) ?></a>
                        <small><?= (int)$p['autor_top_n'] ?> <?= $p['autor_top_n'] === 1 ? 'libro' : 'libros' ?></small>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Ritmo mensual -->
            <section class="bs-card">
                <h2>📅 Su <?= $p['anio'] ?> mes a mes</h2>
                <div class="pf-meses">
                    <?php foreach ($p['meses'] as $m => $c): ?>
                        <div class="pf-mes <?= $m === $topMes ? 'top' : '' ?>" title="<?= ucfirst($mesesNombre[$m - 1]) ?>: <?= $c ?>">
                            <i style="height: <?= $c > 0 ? max(8, (int)round($c / $maxMes * 100)) : 4 ?>%;"></i>
                            <span><?= $mesesInicial[$m - 1] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($topMes): ?>
                    <p class="cm-vacio" style="margin-top:8px;">Su mejor mes: <strong><?= $mesesNombre[$topMes - 1] ?></strong> (<?= $p['meses'][$topMes] ?>)</p>
                <?php endif; ?>
            </section>

        </aside>
    </div>

    <?php endif; ?>

<?php endif; ?>

</div><!-- /bs-wrap -->

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="comunidad.php" class="nav-card-float active"><span class="nav-icon">👥</span><span>Comunidad</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="calendario.php" class="nav-card-float"><span class="nav-icon">📅</span><span>Calendario</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="ajustes.php" class="nav-card-float"><span class="nav-icon">⚙️</span><span>Ajustes</span></a>
    </nav>
</div>

<script src="comunidad.js"></script>
</body>
</html>
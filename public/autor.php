<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/Database.php";
require_once "../src/AutorService.php";
require_once "../src/helpers.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$nombre = trim((string)($_GET['nombre'] ?? ''));
if ($nombre === '') {
    header("Location: buscar.php");
    exit;
}

$orden = $_GET['orden'] ?? 'populares';
if (!in_array($orden, ['populares', 'recientes', 'antiguos'], true)) $orden = 'populares';

// Tema visual
$userService  = new UserService();
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = strtolower(trim($datosUsuario["tema_visual"] ?? "pastel"));

$db = new Database();
[$langGb, $langOl] = AutorService::idiomasUsuario($db->pdo, (int)$usuario["id"]);
$apiKey = function_exists('config') ? (string)config('google_books_key', '') : '';

$servicio = new AutorService($apiKey, $langGb, $langOl);
$ficha    = $servicio->fichaAutor($nombre);
$libros   = $servicio->librosDelAutor($nombre);

// Lo que el usuario ya tiene
$claveAutor = AutorService::claveAutor($nombre);
$stmt = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, portada, estado FROM listas_lectura WHERE usuario_id = ? ORDER BY id DESC");
$stmt->execute([$usuario["id"]]);
$tengo = [];   // título base 
$mios  = [];   // libros del usuario escritos por este autor
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tengo[AutorService::tituloBase((string)$r['titulo'])] = $r['estado'];
    $autoresFila = array_filter(array_map('trim', explode(',', (string)($r['autores'] ?? ''))));
    if (in_array($claveAutor, array_map([AutorService::class, 'claveAutor'], $autoresFila), true)) {
        $mios[] = $r;
    }
}

// Filtro por idioma: cada obra trae los idiomas en los que existen ediciones
$filtroIdioma    = $_GET['idioma'] ?? '';
$etiquetasIdioma = ['todos' => 'Todos', 'es' => 'Español', 'en' => 'Inglés', 'otros' => 'Otros idiomas'];
$cuentaIdioma    = ['todos' => count($libros), 'es' => 0, 'en' => 0, 'otros' => 0];
foreach ($libros as $l) {
    $ids = $l['idiomas'] ?? [];
    if (in_array('spa', $ids, true)) $cuentaIdioma['es']++;
    if (in_array('eng', $ids, true)) $cuentaIdioma['en']++;
    if (array_diff($ids, ['spa', 'eng'])) $cuentaIdioma['otros']++;
}
if (!isset($etiquetasIdioma[$filtroIdioma])) {
    // Sin elección: el idioma de Ajustes si el autor tiene obras en él; si no, todas
    $porDefecto   = $langGb === 'es' ? 'es' : ($langGb === 'en' ? 'en' : 'todos');
    $filtroIdioma = ($cuentaIdioma[$porDefecto] ?? 0) > 0 ? $porDefecto : 'todos';
}
$totalObras = count($libros);
if ($filtroIdioma !== 'todos') {
    $libros = array_values(array_filter($libros, function ($l) use ($filtroIdioma) {
        $ids = $l['idiomas'] ?? [];
        return match ($filtroIdioma) {
            'es'    => in_array('spa', $ids, true),
            'en'    => in_array('eng', $ids, true),
            default => (bool)array_diff($ids, ['spa', 'eng']),
        };
    }));
}
// En inglés u otros idiomas se muestra el título y la portada de la obra original
if ($filtroIdioma === 'en' || $filtroIdioma === 'otros') {
    foreach ($libros as &$l) {
        $l['titulo']  = $l['titulo_original']  ?? $l['titulo'];
        $l['portada'] = $l['portada_original'] ?? $l['portada'];
    }
    unset($l);
}

// Ordenar
if ($orden === 'recientes') {
    usort($libros, fn($a, $b) => ($b['anio'] ?? 0) <=> ($a['anio'] ?? 0));
} elseif ($orden === 'antiguos') {
    usort($libros, fn($a, $b) => ($a['anio'] ?? 9999) <=> ($b['anio'] ?? 9999));
}

$estadosEtiqueta = [
    'leido'      => '✅ Leído',
    'leyendo'    => '📖 Leyendo',
    'tbr'        => '🎯 TBR',
    'guardado'   => '🎁 Wishlist',
    'abandonado' => '❌ Abandonado',
];

if (!function_exists('h')) {
    function h($t) { return htmlspecialchars((string)($t ?? ''), ENT_QUOTES, 'UTF-8'); }
}
$urlOrden  = fn($o) => 'autor.php?nombre=' . urlencode($nombre) . '&orden=' . $o . '&idioma=' . $filtroIdioma;
$urlIdioma = fn($i) => 'autor.php?nombre=' . urlencode($nombre) . '&orden=' . $orden . '&idioma=' . $i;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= h($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="main.js"></script>
    <title><?= h($nombre) ?> · Autor</title>
</head>

<body class="page-buscar">
<div class="bs-wrap">

    <!-- Cabecera del autor -->
    <section class="bs-hero">
        <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <?php if ($ficha && $ficha['foto'] !== ''): ?>
                <img src="<?= h($ficha['foto']) ?>" alt="" onerror="this.remove()"
                     style="width:84px; height:84px; border-radius:50%; object-fit:cover; flex-shrink:0;">
            <?php endif; ?>
            <div>
                <h1>✍️ <?= h($ficha['nombre'] ?? $nombre) ?></h1>
                <p>
                    <?= count($libros) ?> <?= count($libros) === 1 ? 'obra' : 'obras' ?><?= $filtroIdioma !== 'todos' ? ' en ' . h(mb_strtolower($etiquetasIdioma[$filtroIdioma])) . ' (de ' . $totalObras . ' en total)' : '' ?>
                    <?php if ($ficha && $ficha['nacimiento'] !== ''): ?> · Nacimiento: <?= h($ficha['nacimiento']) ?><?php endif; ?>
                    <?php if (!empty($mios)): ?> · Tienes <?= count($mios) ?> en tu biblioteca<?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($totalObras > 1): ?>
        <div class="bs-bloque">
            <h2>🌐 Idioma</h2>
            <div class="bs-chips">
                <?php foreach ($etiquetasIdioma as $cod => $etq): ?>
                    <a class="bs-chip" style="<?= $filtroIdioma === $cod ? 'font-weight:700;' : '' ?>" href="<?= h($urlIdioma($cod)) ?>"><?= h($etq) ?> (<?= (int)$cuentaIdioma[$cod] ?>)</a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($libros) > 1): ?>
        <div class="bs-bloque">
            <h2>↕️ Ordenar</h2>
            <div class="bs-chips">
                <a class="bs-chip" style="<?= $orden === 'populares' ? 'font-weight:700;' : '' ?>" href="<?= h($urlOrden('populares')) ?>">Más populares</a>
                <a class="bs-chip" style="<?= $orden === 'recientes' ? 'font-weight:700;' : '' ?>" href="<?= h($urlOrden('recientes')) ?>">Más recientes</a>
                <a class="bs-chip" style="<?= $orden === 'antiguos' ? 'font-weight:700;' : '' ?>" href="<?= h($urlOrden('antiguos')) ?>">Más antiguos</a>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($mios)): ?>
    <!-- Tus libros de este autor -->
    <section class="bs-card">
        <h2>📚 Tus libros de <?= h($ficha['nombre'] ?? $nombre) ?></h2>
        <div class="shelf-grid">
            <?php foreach ($mios as $m):
                $portadaSrc   = obtenerPortadaValida($m['portada'] ?? '', (int)$m['id']);
                $tienePortada = !empty($portadaSrc);
            ?>
                <a href="libro.php?id=<?= urlencode((string)$m['libro_id']) ?>" class="shelf-item" title="<?= h($m['titulo']) ?>">
                    <?php if ($tienePortada): ?>
                        <img src="<?= h($portadaSrc) ?>" alt="<?= h($m['titulo']) ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <?php endif; ?>
                    <div class="cubierta-generada-card" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                        <span style="font-size:9px; font-weight:bold; line-height:1.1; max-height:40px; overflow:hidden;"><?= h($m['titulo']) ?></span>
                        <span style="font-size:12px; margin-top:4px;">📖</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (empty($libros)): ?>
        <section class="bs-card bs-vacio">
            <div class="ico">📭</div>
            <?php if ($totalObras > 0): ?>
                <h2>No hay obras de «<?= h($nombre) ?>» en <?= h(mb_strtolower($etiquetasIdioma[$filtroIdioma])) ?></h2>
                <p>Prueba con otro idioma o <a href="<?= h($urlIdioma('todos')) ?>">mira todas las obras</a>.</p>
            <?php else: ?>
                <h2>No hemos encontrado obras de «<?= h($nombre) ?>»</h2>
                <p>Puede ser un fallo temporal de las fuentes. Inténtalo de nuevo en unos minutos o
                   <a href="buscar.php?q=<?= urlencode($nombre) ?>">busca por el nombre del autor</a>.</p>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <div class="bs-resultados-head">
            <h2>Todas las obras</h2>
            <span><?= count($libros) ?> <?= count($libros) === 1 ? 'libro' : 'libros' ?></span>
        </div>

        <div class="bs-grid">
            <?php foreach ($libros as $libro):
                $enc     = urlencode((string)$libro['id']);
                $estadoL = $tengo[AutorService::tituloBase($libro['titulo'])] ?? null;
            ?>
            <article class="bs-libro">
                <a class="bs-cover" href="libro.php?id=<?= $enc ?>" tabindex="-1" aria-hidden="true">
                    <span class="sin"><?= h($libro['titulo']) ?></span>
                    <?php if ($libro['portada'] !== ''): ?>
                        <img src="<?= h($libro['portada']) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                    <?php endif; ?>
                </a>

                <div class="bs-info">
                    <h3><a href="libro.php?id=<?= $enc ?>"><?= h($libro['titulo']) ?></a></h3>
                    <div class="bs-meta">
                        <span><?= h($ficha['nombre'] ?? $nombre) ?></span>
                        <?php if (!empty($libro['anio'])): ?><span class="anio"><?= (int)$libro['anio'] ?></span><?php endif; ?>
                    </div>
                    <div class="bs-acciones">
                        <a class="bs-ver" href="libro.php?id=<?= $enc ?>">Ver más</a>
                        <?php if ($estadoL && isset($estadosEtiqueta[$estadoL])): ?>
                            <span class="bs-estado" title="Ya está en tu biblioteca"><?= $estadosEtiqueta[$estadoL] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /bs-wrap -->

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="comunidad.php" class="nav-card-float"><span class="nav-icon">👥</span><span>Comunidad</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="calendario.php" class="nav-card-float"><span class="nav-icon">📅</span><span>Calendario</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="ajustes.php" class="nav-card-float"><span class="nav-icon">⚙️</span><span>Ajustes</span></a>
    </nav>
</div>

</body>
</html>
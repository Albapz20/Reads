<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/BookService.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

// Tema visual
$userService = new UserService();
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datosUsuario["tema_visual"] ?? "pastel";

$service = new BookService();
$db = new Database();

$resultados = [];
$termino = "";
$buscado = isset($_GET['q']);

if ($buscado) {
    $termino = trim($_GET['q']);

    if ($termino !== "") {
        $resultados = $service->buscarLibros($termino);

        // Guardar en el historial de búsqueda
        $stmt = $db->pdo->prepare("INSERT INTO historial_busqueda (usuario_id, termino, fecha) VALUES (?, ?, NOW())");
        $stmt->execute([$usuario["id"], $termino]);
    }
}

// Libros que el usuario ya tiene (para marcarlos en los resultados)
$stmt = $db->pdo->prepare("SELECT libro_id, estado FROM listas_lectura WHERE usuario_id = ?");
$stmt->execute([$usuario["id"]]);
$misLibros = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $misLibros[(string)$r['libro_id']] = $r['estado'];
}

// Búsquedas recientes (sin repetir)
$stmt = $db->pdo->prepare("SELECT termino, MAX(fecha) AS f FROM historial_busqueda WHERE usuario_id = ? GROUP BY termino ORDER BY f DESC LIMIT 8");
$stmt->execute([$usuario["id"]]);
$recientes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$estadosEtiqueta = [
    'leido'      => '✅ Leído',
    'leyendo'    => '📖 Leyendo',
    'tbr'        => '🎯 TBR',
    'guardado'   => '🎁 Wishlist',
    'abandonado' => '❌ Abandonado',
];

$generos = ['Romance', 'Fantasía', 'Thriller', 'Ciencia ficción', 'Novela histórica', 'Young adult', 'Terror', 'Misterio', 'Clásicos', 'Ensayo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="main.js"></script>
    <title>Buscar libros</title>
    
</head>

<body class="page-buscar">
<div class="bs-wrap">

    <!-- Buscador -->
    <section class="bs-hero">
        <h1>🔍 Buscar libros</h1>
        <p>Encuentra tu próxima lectura por título, autor o tema.</p>

        <form method="GET" action="buscar.php" class="bs-form">
            <div class="bs-input-wrap">
                <span aria-hidden="true">🔎</span>
                <input type="text" name="q" class="bs-input" autocomplete="off" required
                       placeholder="Escribe un título, autor o palabra clave..."
                       value="<?= htmlspecialchars($termino) ?>" aria-label="Buscar libros">
            </div>
            <button type="submit" class="bs-btn">Buscar</button>
        </form>

        <?php if (!empty($recientes)): ?>
        <div class="bs-bloque">
            <h2>🕘 Búsquedas recientes</h2>
            <div class="bs-chips">
                <?php foreach ($recientes as $r): ?>
                    <a class="bs-chip" href="buscar.php?q=<?= urlencode($r) ?>"><?= htmlspecialchars($r) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bs-bloque">
            <h2>🏷️ Explorar por género</h2>
            <div class="bs-chips">
                <?php foreach ($generos as $g): ?>
                    <a class="bs-chip" href="buscar.php?q=<?= urlencode($g) ?>"><?= htmlspecialchars($g) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($buscado && $termino !== ''): ?>

        <?php if (empty($resultados)): ?>
            <section class="bs-card bs-vacio">
                <div class="ico">📭</div>
                <h2>No hemos encontrado nada para «<?= htmlspecialchars($termino) ?>»</h2>
                <p>Prueba con otro título, con solo el apellido del autor o con menos palabras.</p>
            </section>

        <?php else: ?>
            <div class="bs-resultados-head">
                <h2>Resultados para «<?= htmlspecialchars($termino) ?>»</h2>
                <span><?= count($resultados) ?> <?= count($resultados) === 1 ? 'libro' : 'libros' ?></span>
            </div>

            <div class="bs-grid">
                <?php foreach ($resultados as $libro):
                    $tituloL = (string)($libro["titulo"] ?? 'Sin título');
                    $autorL  = (string)($libro["autor"] ?? 'Autor desconocido');
                    $anioL   = (string)($libro["anio"] ?? '');
                    $descL   = trim(strip_tags((string)($libro["descripcion"] ?? '')));
                    $portadaL = (string)($libro["portada"] ?? '');
                    $enc = urlencode((string)$libro["id"]);
                    $estadoL = $misLibros[(string)$libro["id"]] ?? null;
                ?>
                <article class="bs-libro">
                    <a class="bs-cover" href="libro.php?id=<?= $enc ?>" tabindex="-1" aria-hidden="true">
                        <span class="sin"><?= htmlspecialchars($tituloL) ?></span>
                        <?php if ($portadaL !== ''): ?>
                            <img src="<?= htmlspecialchars($portadaL) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                        <?php endif; ?>
                    </a>

                    <div class="bs-info">
                        <h3><a href="libro.php?id=<?= $enc ?>"><?= htmlspecialchars($tituloL) ?></a></h3>
                        <div class="bs-meta">
                            <span><?= htmlspecialchars($autorL) ?></span>
                            <?php if ($anioL !== '' && $anioL !== 'N/A'): ?><span class="anio"><?= htmlspecialchars($anioL) ?></span><?php endif; ?>
                        </div>
                        <?php if ($descL !== ''): ?><p class="bs-desc"><?= htmlspecialchars($descL) ?></p><?php endif; ?>

                        <div class="bs-acciones">
                            <a class="bs-ver" href="libro.php?id=<?= $enc ?>&desc=<?= urlencode($libro["descripcion"] ?? '') ?>">Ver más</a>
                            <?php if ($estadoL && isset($estadosEtiqueta[$estadoL])): ?>
                                <span class="bs-estado" title="Ya está en tu biblioteca"><?= $estadosEtiqueta[$estadoL] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <!-- Recomendaciones -->
        <section class="bs-card" id="bloque-recomendados">
            <h2>✨ Recomendados para ti</h2>
            <p class="bs-sub" id="rec-subtitulo">Basado en tus últimas lecturas</p>
            <div class="rec-scroll" id="rec-carrusel">
                <span style="opacity:.6; font-size:.9rem;">Cargando sugerencias...</span>
            </div>
        </section>

    <?php endif; ?>

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
        
        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>   
        </a>
        
        <a href="buscar.php" class="nav-card-float active">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>

          <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<?php if (!($buscado && $termino !== '')): ?>
<script>
function escapeHtml(t) {
    return String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

(async function () {
    const cont = document.getElementById('rec-carrusel');
    const sub  = document.getElementById('rec-subtitulo');
    const bloque = document.getElementById('bloque-recomendados');

    try {
        const res = await fetch('api_recomendaciones.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        const base = data.basadoEn || [];
        const libros = data.libros || [];

        if (!libros.length) { bloque.style.display = 'none'; return; }   // sin lecturas: no mostrar el bloque

        if (base.length) {
            const nombres = base.slice(0, 2).map(t => '<strong>' + escapeHtml(t) + '</strong>');
            const resto = base.length - 2;
            sub.innerHTML = 'Porque leíste ' + nombres.join(' y ') + (resto > 0 ? ' y ' + resto + ' más' : '');
        }

        cont.innerHTML = libros.map(l => `
            <a class="rec-card" href="libro.php?id=${encodeURIComponent(l.id)}" title="${escapeHtml(l.titulo)}">
                <div class="portada">
                    <img src="${escapeHtml(l.portada || '')}" alt="" loading="lazy" onerror="this.closest('.rec-card').remove()">
                </div>
                <div class="t">${escapeHtml(l.titulo)}</div>
                <div class="a">${escapeHtml(l.autor || '')}</div>
            </a>`).join('');
    } catch (e) {
        bloque.style.display = 'none';
    }
})();
</script>
<?php endif; ?>

</body>
</html>
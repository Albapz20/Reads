<?php
require_once "../src/UserService.php";
require_once "../src/ListaService.php";
require_once "../src/Database.php";
require_once "../src/AvatarHelper.php";

$uid = (int)($_GET['usuario_id'] ?? 0);

$userService = new UserService();
$propietario = $uid > 0 ? $userService->obtenerUsuarioPorId($uid) : null;

$privado = $propietario && stripos((string)($propietario['privacidad'] ?? ''), 'priv') !== false;

$libros = [];
if ($propietario && !$privado) {
    $libros = (new ListaService())->obtenerLista($uid, 'guardado') ?? [];
}

$tema      = strtolower(trim($propietario['tema_visual'] ?? 'pastel'));
$nombre    = $propietario['nombre'] ?? '';
$degradado = AvatarHelper::gradienteTema($tema);

// Avatar del dueño
$avatar = null;
if ($propietario) {
    try {
        $db = new Database();
        $st = $db->pdo->prepare("SELECT avatar FROM usuarios WHERE id = ?");
        $st->execute([$uid]);
        $avatar = $st->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

// Cifras
$totalLibros  = count($libros);
$totalPaginas = array_sum(array_map(fn($l) => (int)($l['paginas_totales'] ?? 0), $libros));
$totalAutores = count(array_unique(array_filter(array_map(fn($l) => trim((string)($l['autores'] ?? '')), $libros))));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= @filemtime(__DIR__ . '/css/styles.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/comunidad.css?v=<?= @filemtime(__DIR__ . '/css/comunidad.css') ?>">
    <title>Wishlist<?= $nombre ? ' de ' . htmlspecialchars($nombre) : '' ?></title>
</head>
<body class="page-buscar">
<div class="bs-wrap">

<?php if (!$propietario): ?>
    <section class="bs-card bs-vacio">
        <div class="ico">🕵️</div>
        <h2>No hemos encontrado a este lector</h2>
    </section>

<?php elseif ($privado): ?>
    <section class="bs-card bs-vacio">
        <div class="ico">🔒</div>
        <h2>Esta wishlist es privada</h2>
    </section>

<?php else: ?>

    <!-- Cabecera -->
    <section class="bs-card wl-hero">
        <div class="pf-banner" style="background: <?= htmlspecialchars($degradado) ?>;"></div>
        <div class="wl-hero-cuerpo">
            <div class="avatar-circle wl-avatar">
                <?= AvatarHelper::contenidoCirculo($nombre ?: 'A', $avatar) ?>
            </div>
            <div class="wl-hero-texto">
                <h1>🎁 Wishlist de <?= htmlspecialchars($nombre) ?></h1>
                <p>Los libros que le haría ilusión recibir.</p>
            </div>
        </div>

        <div class="wl-cifras">
            <div class="wl-cifra"><strong><?= $totalLibros ?></strong><span><?= $totalLibros === 1 ? 'libro' : 'libros' ?></span></div>
            <div class="wl-cifra"><strong><?= $totalAutores ?></strong><span><?= $totalAutores === 1 ? 'autor' : 'autores' ?></span></div>
            <?php if ($totalPaginas > 0): ?>
                <div class="wl-cifra"><strong><?= number_format($totalPaginas, 0, ',', '.') ?></strong><span>páginas</span></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (empty($libros)): ?>
        <section class="bs-card bs-vacio">
            <div class="ico">📚</div>
            <h2>Todavía no hay libros aquí</h2>
            <p><?= htmlspecialchars($nombre) ?> aún no ha guardado nada en su wishlist.</p>
        </section>
    <?php else: ?>

    <!-- Herramientas -->
    <section class="bs-card wl-herramientas">
        <input type="search" id="wlBuscar" class="filtro-busqueda" placeholder="🔍 Buscar por título o autor…" aria-label="Buscar">
        <select id="wlOrden" class="filtro-orden" aria-label="Ordenar">
            <option value="orig">Más recientes</option>
            <option value="titulo">Título A-Z</option>
            <option value="autor">Autor A-Z</option>
            <option value="paginas">Más páginas</option>
        </select>
        <button type="button" class="wl-accion" id="wlAzar">🎲 Sorpréndeme</button>
        <button type="button" class="wl-accion" id="wlCopiar">🔗 Copiar enlace</button>
    </section>

    <p class="wl-contador" id="wlContador"></p>

    <!-- Libros -->
    <div class="wl-grid" id="wlGrid">
    <?php foreach ($libros as $i => $l):
        $titulo   = (string)($l['titulo'] ?? '');
        $autor    = trim((string)($l['autores'] ?? ''));
        $portada  = trim((string)($l['portada'] ?? ''));
        $paginas  = (int)($l['paginas_totales'] ?? 0);
        $anio     = substr((string)($l['publicado'] ?? ''), 0, 4);
        $anio     = ctype_digit($anio) ? $anio : '';
        $genero   = trim(explode(',', (string)($l['categorias'] ?? ''))[0]);
        $desc     = trim(strip_tags((string)($l['descripcion'] ?? '')));
        $q        = urlencode($titulo . ' ' . $autor);
        $buscar   = mb_strtolower($titulo . ' ' . $autor, 'UTF-8');
        $sinPortada = $portada === '' || stripos($portada, 'default') !== false || stripos($portada, 'placehold') !== false;
    ?>
        <article class="wl-libro" data-orden="<?= $i ?>"
                 data-titulo="<?= htmlspecialchars($titulo, ENT_QUOTES) ?>"
                 data-autor="<?= htmlspecialchars($autor, ENT_QUOTES) ?>"
                 data-paginas="<?= $paginas ?>"
                 data-buscar="<?= htmlspecialchars($buscar, ENT_QUOTES) ?>">

            <div class="wl-portada">
                <?php if (!$sinPortada): ?>
                    <img src="<?= htmlspecialchars($portada) ?>" alt="" loading="lazy"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <?php endif; ?>
                <div class="cubierta-generada-card" style="<?= $sinPortada ? 'display:flex;' : 'display:none;' ?>">
                    <span style="font-size:10px; font-weight:bold; line-height:1.15;"><?= htmlspecialchars($titulo) ?></span>
                    <span style="font-size:14px; margin-top:4px;">📖</span>
                </div>
            </div>

            <div class="wl-info">
                <h3><?= htmlspecialchars($titulo) ?></h3>
                <?php if ($autor !== ''): ?><p class="wl-autor"><?= htmlspecialchars($autor) ?></p><?php endif; ?>

                <?php if ($anio !== '' || $paginas > 0 || $genero !== ''): ?>
                <div class="wl-datos">
                    <?php if ($genero !== ''): ?><span><?= htmlspecialchars($genero) ?></span><?php endif; ?>
                    <?php if ($anio !== ''): ?><span><?= htmlspecialchars($anio) ?></span><?php endif; ?>
                    <?php if ($paginas > 0): ?><span><?= $paginas ?> págs.</span><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($desc !== ''): ?><p class="wl-desc"><?= htmlspecialchars($desc) ?></p><?php endif; ?>

                <div class="wl-tiendas">
                    <a class="wl-btn" target="_blank" rel="noopener noreferrer" href="https://www.casadellibro.com/busqueda-libros?busqueda=<?= $q ?>">📚 Casa del Libro</a>
                    <a class="wl-btn" target="_blank" rel="noopener noreferrer" href="https://www.amazon.es/s?k=<?= $q ?>&i=stripbooks">📦 Amazon</a>
                    <a class="wl-btn" target="_blank" rel="noopener noreferrer" href="https://www.fnac.es/ia1234/busqueda?Search=<?= $q ?>">🛍️ Fnac</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    </div>

    <p class="wl-sin-resultados" id="wlVacio" hidden>Ningún libro coincide con tu búsqueda.</p>

    <?php endif; ?>
<?php endif; ?>

</div>

<script>
(function () {
    const grid = document.getElementById('wlGrid');
    if (!grid) return;

    const normaliza = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const cards = Array.from(grid.children);
    cards.forEach(c => c._b = normaliza(c.dataset.buscar));

    const inpBuscar = document.getElementById('wlBuscar');
    const selOrden  = document.getElementById('wlOrden');
    const contador  = document.getElementById('wlContador');
    const vacio     = document.getElementById('wlVacio');

    const porOrig = (a, b) => a.dataset.orden - b.dataset.orden;
    const comparadores = {
        orig:    porOrig,
        titulo:  (a, b) => a.dataset.titulo.localeCompare(b.dataset.titulo, 'es', { sensitivity: 'base' }),
        autor:   (a, b) => (a.dataset.autor || '~').localeCompare(b.dataset.autor || '~', 'es', { sensitivity: 'base' }) || porOrig(a, b),
        paginas: (a, b) => (b.dataset.paginas - a.dataset.paginas) || porOrig(a, b)
    };

    function refrescar() {
        const q = normaliza(inpBuscar.value.trim());
        cards.sort(comparadores[selOrden.value] || porOrig).forEach(c => grid.appendChild(c));

        let n = 0;
        cards.forEach(c => {
            const ok = !q || c._b.includes(q);
            c.hidden = !ok;
            if (ok) n++;
        });
        contador.textContent = n + (n === 1 ? ' libro' : ' libros');
        vacio.hidden = n > 0;
    }
    inpBuscar.addEventListener('input', refrescar);
    selOrden.addEventListener('change', refrescar);
    refrescar();

    // Libro al azar
    document.getElementById('wlAzar').addEventListener('click', () => {
        const visibles = cards.filter(c => !c.hidden);
        if (!visibles.length) return;
        cards.forEach(c => c.classList.remove('destacado'));
        const elegido = visibles[Math.floor(Math.random() * visibles.length)];
        elegido.classList.add('destacado');
        elegido.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // Copiar enlace
    document.getElementById('wlCopiar').addEventListener('click', function () {
        const btn = this;
        const url = location.href;
        const listo = () => { btn.textContent = '✅ ¡Copiado!'; setTimeout(() => btn.textContent = '🔗 Copiar enlace', 1800); };
        if (navigator.clipboard) navigator.clipboard.writeText(url).then(listo).catch(() => prompt('Copia este enlace:', url));
        else prompt('Copia este enlace:', url);
    });
})();
    

    
</script>
        <!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="comunidad.php" class="nav-card-float active"><span class="nav-icon">👥</span><span>Comunidad</span></a>
    </nav>
</div>
</body>
</html>
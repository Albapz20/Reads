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
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <script src="main.js"></script>
    <title>Buscar libros</title>
    <style>
        :root {
            --bs-accent: var(--primary-color, var(--color-primario, #d87d8a));
            --bs-border: var(--border-color, rgba(0,0,0,.09));
            --bs-surface: var(--bg-card, #ffffff);
        }
        body { padding-bottom: 110px; }
        .bs-wrap { max-width: 980px; margin: 0 auto; padding: 20px 16px; }

        /*  Buscador  */
        .bs-hero {
            background: var(--bs-surface); border: 1px solid var(--bs-border); border-radius: 22px;
            padding: 28px; margin-bottom: 22px; position: relative; overflow: hidden;
        }
        .bs-hero::before {
            content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--bs-accent);
        }
        .bs-hero h1 { margin: 0 0 4px; font-size: 1.8rem; }
        .bs-hero p { margin: 0 0 18px; opacity: .7; }
        .bs-form { display: flex; gap: 10px; }
        .bs-input-wrap { position: relative; flex: 1; }
        .bs-input-wrap span { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); font-size: 1.1rem; opacity: .6; pointer-events: none; }
        .bs-input {
            width: 100%; box-sizing: border-box; padding: 14px 16px 14px 46px; font: inherit; font-size: 1.05rem;
            border: 2px solid var(--bs-border); border-radius: 14px; outline: none; background: transparent; color: inherit;
        }
        .bs-input:focus { border-color: var(--bs-accent); }
        .bs-btn {
            padding: 0 26px; border: none; border-radius: 14px; background: var(--bs-accent); color: #fff;
            font: inherit; font-weight: 700; font-size: 1rem; cursor: pointer;
        }
        .bs-btn:hover { opacity: .92; }

        /*  Chips  */
        .bs-bloque { margin-top: 18px; }
        .bs-bloque h2 { margin: 0 0 10px; font-size: .95rem; opacity: .75; font-weight: 700; }
        .bs-chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .bs-chip {
            padding: 7px 14px; border-radius: 999px; border: 1px solid var(--bs-border);
            text-decoration: none; color: inherit; font-size: .88rem; font-weight: 600;
            transition: background .15s, color .15s, border-color .15s;
        }
        .bs-chip:hover { background: var(--bs-accent); border-color: var(--bs-accent); color: #fff; }

        /*  Secciones  */
        .bs-card { background: var(--bs-surface); border: 1px solid var(--bs-border); border-radius: 18px; padding: 22px; margin-bottom: 20px; }
        .bs-card h2 { margin: 0 0 4px; font-size: 1.15rem; }
        .bs-sub { margin: 0 0 14px; font-size: .88rem; opacity: .7; }

        /* Recomendados (carrusel) */
        .rec-scroll { display: flex; gap: 14px; overflow-x: auto; padding: 6px 2px 12px; scrollbar-width: thin; }
        .rec-card { flex: 0 0 120px; text-decoration: none; color: inherit; }
        .rec-card .portada { width: 120px; height: 175px; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,.18); transition: transform .2s; background: #1e293b; }
        .rec-card:hover .portada { transform: translateY(-4px); }
        .rec-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .rec-card .t { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: .82rem; font-weight: 700; margin-top: 8px; line-height: 1.2; }
        .rec-card .a { font-size: .74rem; opacity: .65; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Resultados */
        .bs-resultados-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .bs-resultados-head h2 { margin: 0; font-size: 1.2rem; }
        .bs-resultados-head span { opacity: .65; font-size: .9rem; }
        .bs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 16px; }
        .bs-libro {
            display: grid; grid-template-columns: 100px 1fr; gap: 16px;
            background: var(--bs-surface); border: 1px solid var(--bs-border); border-radius: 16px; padding: 14px;
            transition: transform .15s, box-shadow .15s;
        }
        .bs-libro:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.08); }
        .bs-cover { position: relative; width: 100px; height: 150px; border-radius: 6px; overflow: hidden; background: linear-gradient(135deg, #2c3e50, #1a252f); box-shadow: 0 4px 10px rgba(0,0,0,.2); }
        .bs-cover img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .bs-cover .sin { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 8px; color: #fff; font: 700 11px/1.25 sans-serif; }
        .bs-info { min-width: 0; display: flex; flex-direction: column; }
        .bs-info h3 { margin: 0 0 4px; font-size: 1.05rem; line-height: 1.2; }
        .bs-info h3 a { color: inherit; text-decoration: none; }
        .bs-info h3 a:hover { color: var(--bs-accent); }
        .bs-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: .82rem; opacity: .8; margin-bottom: 8px; }
        .bs-meta .anio { padding: 1px 8px; border-radius: 999px; border: 1px solid var(--bs-border); }
        .bs-desc { margin: 0 0 10px; font-size: .88rem; line-height: 1.5; opacity: .85; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .bs-acciones { margin-top: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .bs-ver { padding: 7px 16px; border-radius: 999px; background: var(--bs-accent); color: #fff; text-decoration: none; font-size: .85rem; font-weight: 700; }
        .bs-ver:hover { opacity: .9; }
        .bs-estado { font-size: .8rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: rgba(128,128,128,.14); }

        .bs-vacio { text-align: center; padding: 30px 10px; }
        .bs-vacio .ico { font-size: 2.6rem; }
        .bs-vacio h2 { margin: 6px 0; }
        .bs-vacio p { opacity: .7; margin: 0 0 16px; }

        /* Barra inferior */
        .floating-nav-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: calc(100% - 40px); max-width: 600px; }
        .quick-nav-floating { display: flex; align-items: center; justify-content: space-around; padding: 8px 12px; background: rgba(255,255,255,.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.6); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
        .nav-card-float { display: flex; flex-direction: column; align-items: center; padding: 6px 12px; text-decoration: none; color: #2d3748; font-weight: 600; font-size: .8rem; border-radius: 12px; transition: color .2s, transform .2s; }
        .nav-card-float:hover, .nav-card-float.active { color: var(--bs-accent); transform: translateY(-2px); }
        .nav-card-float .nav-icon { font-size: 1.25rem; margin-bottom: 2px; }

        @media (max-width: 560px) {
            .bs-hero { padding: 20px 16px; }
            .bs-hero h1 { font-size: 1.5rem; }
            .bs-form { flex-direction: column; }
            .bs-btn { padding: 13px; }
            .bs-grid { grid-template-columns: 1fr; }
            .bs-libro { grid-template-columns: 84px 1fr; gap: 12px; }
            .bs-cover { width: 84px; height: 126px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>

<body>
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
            <span>Perfil</span>
            
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
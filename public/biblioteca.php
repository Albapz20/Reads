<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/PortadaHelper.php";
require_once __DIR__ . '/../src/helpers.php';

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$db = new Database();
$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

if (!function_exists('e')) {
    function e($texto) {
        return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/* ---------- Estanterías disponibles ---------- */
$estantes = [
    'leido'      => ['📚', 'Leídos'],
    'leyendo'    => ['📖', 'Leyendo'],
    'tbr'        => ['🎯', 'TBR'],
    'guardado'   => ['🎁', 'Wishlist'],
    'abandonado' => ['❌', 'Abandonados'],
];
$estado = $_GET['estado'] ?? 'leido';
if (!isset($estantes[$estado])) $estado = 'leido';

// Nº de libros de cada estantería (para las pestañas)
$stC = $db->pdo->prepare("SELECT estado, COUNT(*) FROM listas_lectura WHERE usuario_id = ? GROUP BY estado");
$stC->execute([$usuario["id"]]);
$conteos = $stC->fetchAll(PDO::FETCH_KEY_PAIR);

/* ---------- Año (solo para "Leídos") ---------- */
$yearActual = (int)date("Y");
$yearParam = $_GET['year'] ?? (string)$yearActual;
$verTodo = ($yearParam === 'todos');
$yearSeleccionado = $verTodo ? 0 : (int)$yearParam;

$colsBase = "l.id, l.libro_id, l.titulo, l.autores, l.portada, l.fecha_fin, l.paginas_totales, l.paginas_leidas";

$colNota = "COALESCE(
                (SELECT p.estrellas FROM puntuaciones p
                  WHERE p.usuario_id = l.usuario_id
                    AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                    AND p.estrellas > 0
                  ORDER BY (p.libro_id = l.libro_id) DESC
                  LIMIT 1),
                NULLIF(l.estrellas, 0), 0) AS estrellas";
$colNotaSimple = "COALESCE(NULLIF(l.estrellas, 0), 0) AS estrellas";

if ($estado === 'leido') {
    $where  = "l.usuario_id = ? AND l.estado = 'leido'";
    $orden  = "l.fecha_fin ASC, l.id ASC";
    $params = [$usuario["id"]];
    if (!$verTodo) {
        $where   .= " AND YEAR(l.fecha_fin) = ?";
        $params[] = $yearSeleccionado;
    }
} else {
    $where  = "l.usuario_id = ? AND l.estado = ?";
    $orden  = "l.fecha DESC, l.id DESC";
    $params = [$usuario["id"], $estado];
}

try {
    $stmt = $db->pdo->prepare("SELECT $colsBase, $colNota FROM listas_lectura l WHERE $where ORDER BY $orden");
    $stmt->execute($params);
} catch (Throwable $e) {

    $stmt = $db->pdo->prepare("SELECT $colsBase, $colNotaSimple FROM listas_lectura l WHERE $where ORDER BY $orden");
    $stmt->execute($params);
}
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Años disponibles para el selector
$stA = $db->pdo->prepare("SELECT DISTINCT YEAR(fecha_fin) FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido' AND fecha_fin IS NOT NULL ORDER BY 1 DESC");
$stA->execute([$usuario["id"]]);
$aniosDisponibles = $stA->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($yearActual, $aniosDisponibles)) array_unshift($aniosDisponibles, $yearActual);

// Resumen
$totalLibros = count($libros);
$totalPaginas = array_sum(array_map(fn($l) => max((int)$l['paginas_totales'], (int)$l['paginas_leidas']), $libros));

if ($estado === 'leido') {
    $titulo = '📚 Estantería ' . ($verTodo ? 'histórica' : $yearSeleccionado);
} else {
    $titulo = $estantes[$estado][0] . ' ' . $estantes[$estado][1];
}

/* ---------- Pintar la estantería ---------- */
function generarEstanteria(array $libros, string $estado) {
    if (empty($libros)): ?>
        <div class="biblioteca-vacia">
            <div class="biblioteca-vacia-icono">📚</div>
            <h3>Esta estantería está vacía</h3>
            <p><?= $estado === 'leido'
                ? 'Los libros que marques como leídos irán apareciendo aquí sobre tus repisas.'
                : 'Añade libros desde su ficha y aparecerán aquí.' ?></p>
            <a href="buscar.php" class="btn-vacio">🔍 Buscar libros</a>
        </div>
        <?php return;
    endif; ?>

    <div class="libros">
        <?php foreach ($libros as $libro):
            $titulo = e($libro["titulo"]);
            $autor = e(trim(explode(',', $libro["autores"] ?? '')[0]));
            $portadaSrc = obtenerPortadaValida($libro["portada"] ?? '', (int)$libro["id"]);
            $tienePortada = !empty($portadaSrc);
            $estrellas = (float)($libro["estrellas"] ?? 0);
            $idLink = ($libro["libro_id"] ?? '') !== '' ? $libro["libro_id"] : $libro["id"];
        ?>
            <a href="libro.php?id=<?= urlencode((string)$idLink) ?>" class="libro" title="<?= $titulo ?>">
                <div class="libro-cuerpo">
                    <?php if ($tienePortada): ?>
                        <img src="<?= e($portadaSrc) ?>" alt="<?= $titulo ?>" class="imagen-portada" loading="lazy"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <?php endif; ?>

                    <div class="cubierta-generada" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                        <span class="titulo-cubierta"><?= $titulo ?></span>
                        <span class="decoracion-cubierta">📖</span>
                    </div>

                    <?php if ($estrellas > 0): ?>
                        <span class="libro-nota">★ <?= rtrim(rtrim(number_format($estrellas, 1), '0'), '.') ?></span>
                    <?php endif; ?>

                    <span class="libro-etiqueta">
                        <strong><?= $titulo ?></strong>
                        <?php if ($autor !== ''): ?><small><?= $autor ?></small><?php endif; ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="main.js"></script>
<title>Mi Biblioteca</title>

<link rel="stylesheet" href="/Reads/temas/<?= e($tema) ?>.css">
<style>
body {
    background-color: var(--bg-biblioteca, #f4eade);
    color: var(--texto-biblioteca, #4a3b32);
    font-family: 'Georgia', serif;
    margin: 0;
    padding: 20px 20px 110px;
}
.biblioteca-wrapper { width: 100%; max-width: 980px; margin: 0 auto; }

/* ---- Cabecera ---- */
.biblioteca-header {
    display: flex; justify-content: space-between; align-items: flex-end;
    flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 0 4px;
}
.biblioteca-header h2 { font-size: 28px; margin: 0; color: var(--texto-biblioteca, #4a3b32); }
.biblioteca-resumen { margin: 4px 0 0; font-size: 14px; opacity: .75; font-family: system-ui, sans-serif; }
.selector-anios select {
    background: var(--selector-bg, #fff); color: var(--texto-biblioteca, #4a3b32);
    border: 1px solid var(--selector-borde, #d4a373);
    padding: 8px 15px; border-radius: 8px; font-size: 15px; cursor: pointer;
}

/* ---- Pestañas ---- */
.pestanas { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; font-family: system-ui, sans-serif; }
.pestana {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border-radius: 999px; text-decoration: none; font-size: 14px; font-weight: 600;
    color: var(--texto-biblioteca, #4a3b32);
    background: rgba(255,255,255,.55); border: 1px solid var(--selector-borde, #d4a373);
    transition: background .15s, color .15s;
}
.pestana:hover { background: rgba(255,255,255,.9); }
.pestana.activa { background: var(--borde-estanteria, #8d5b4c); border-color: var(--borde-estanteria, #8d5b4c); color: #fff; }
.pestana .n { font-size: 12px; opacity: .8; }

/* ---- Mueble ---- */
.estanteria {
    --w: 115px;          /* ancho del libro */
    --h: 175px;          /* alto del libro */
    --b: 16px;           /* grosor de la balda */
    --g: 38px;           /* aire entre balda y la fila siguiente */

    position: relative;
    padding: 35px 24px 20px;
    border-radius: 6px;
    background:
        linear-gradient(180deg, rgba(0,0,0,.4) 0%, rgba(0,0,0,.1) 40%, rgba(0,0,0,.4) 100%),
        url("/Reads/img/wood_texture_light.png") center center / cover no-repeat #e3caad;
    border: 16px solid var(--borde-estanteria, #8d5b4c);
    box-shadow:
        inset 0 0 0 2px rgba(255,255,255,.2),
        inset 6px 6px 18px rgba(0,0,0,.6),
        0 15px 30px rgba(0,0,0,.3);
}

.libros {
    display: grid;
    grid-template-columns: repeat(auto-fill, var(--w));
    justify-content: space-between;
    row-gap: 0;
}

/* Balda de madera */
.balda-madera {
    grid-column: 1 / -1;
    position: relative;
    height: var(--b);
    margin: 0 -6px var(--g);
    background: var(--madera-balda, #8d5b4c);
    border-radius: 2px;
    box-shadow: 0 6px 12px rgba(0,0,0,.5);
}
.balda-madera::before {
    content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: rgba(255,255,255,.2);
}

/* ---- Libro ---- */
.libro {
    position: relative; display: block;
    width: var(--w); height: var(--h);
    text-decoration: none; color: #fff;
    transition: transform .25s ease;
}
.libro:hover, .libro:focus-visible { transform: translateY(-8px) scale(1.04); z-index: 20; outline: none; }
.libro:focus-visible .libro-cuerpo { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--borde-estanteria, #8d5b4c); }

.libro-cuerpo {
    position: relative; width: 100%; height: 100%;
    border-radius: 3px 6px 6px 3px; overflow: hidden;
    box-shadow: 3px 4px 10px rgba(0,0,0,.4), inset -2px 0 4px rgba(0,0,0,.25);
}
.imagen-portada { width: 100%; height: 100%; object-fit: cover; display: block; }

.cubierta-generada {
    width: 100%; height: 100%;
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
    flex-direction: column; justify-content: space-between; align-items: center;
    padding: 12px 8px; box-sizing: border-box; text-align: center;
    border-left: 4px solid rgba(255,255,255,.2);
}
.titulo-cubierta { font: bold 11px/1.3 sans-serif; word-break: break-word; margin-top: 10px; }
.decoracion-cubierta { font-size: 20px; opacity: .8; margin-bottom: 10px; }

/* Nota y datos al pasar el ratón */
.libro-nota {
    position: absolute; top: 6px; right: 6px;
    background: rgba(0,0,0,.65); color: #ffd35c; font: 700 11px system-ui, sans-serif;
    padding: 2px 6px; border-radius: 999px;
}
.libro-etiqueta {
    position: absolute; left: 0; right: 0; bottom: 0;
    display: flex; flex-direction: column; gap: 2px;
    padding: 28px 8px 8px;
    background: linear-gradient(to top, rgba(0,0,0,.85), transparent);
    font-family: system-ui, sans-serif; text-align: left;
    opacity: 0; transition: opacity .2s;
}
.libro-etiqueta strong { font-size: 11px; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.libro-etiqueta small { font-size: 10px; opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.libro:hover .libro-etiqueta, .libro:focus-visible .libro-etiqueta { opacity: 1; }

/* ---- Vacía ---- */
.biblioteca-vacia { text-align: center; padding: 50px 20px; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.6); }
.biblioteca-vacia-icono { font-size: 48px; margin-bottom: 10px; }
.btn-vacio {
    display: inline-block; margin-top: 8px; padding: 9px 18px; border-radius: 999px;
    background: #fff; color: var(--borde-estanteria, #8d5b4c); font: 700 14px system-ui, sans-serif;
    text-decoration: none; text-shadow: none;
}

/* ---- Barra inferior ---- */
.floating-nav-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: calc(100% - 40px); max-width: 600px; }
.quick-nav-floating { display: flex; align-items: center; justify-content: space-around; padding: 8px 12px; background: rgba(255,255,255,.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.6); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.15); font-family: system-ui, sans-serif; }
.nav-card-float { display: flex; flex-direction: column; align-items: center; padding: 6px 12px; text-decoration: none; color: #2d3748; font-weight: 600; font-size: .8rem; border-radius: 12px; transition: color .2s, transform .2s; }
.nav-card-float:hover, .nav-card-float.active { color: var(--borde-estanteria, #8d5b4c); transform: translateY(-2px); }
.nav-card-float .nav-icon { font-size: 1.25rem; margin-bottom: 2px; }

/* ---- Móvil ---- */
@media (max-width: 560px) {
    body { padding: 14px 12px 110px; }
    .biblioteca-header h2 { font-size: 22px; }
    .estanteria { --w: 92px; --h: 140px; --g: 30px; padding: 22px 10px 12px; border-width: 10px; }
    .libro-etiqueta { opacity: 1; padding-top: 18px; }   /* sin ratón: título siempre visible */
    .libro-etiqueta small { display: none; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

<div class="biblioteca-wrapper">

    <div class="biblioteca-header">
        <div>
            <h2><?= e($titulo) ?></h2>
            <p class="biblioteca-resumen">
                <?= $totalLibros ?> <?= $totalLibros === 1 ? 'libro' : 'libros' ?>
                <?php if ($estado === 'leido' && $totalPaginas > 0): ?> · <?= number_format($totalPaginas, 0, '', '.') ?> páginas<?php endif; ?>
            </p>
        </div>

        <?php if ($estado === 'leido'): ?>
        <div class="selector-anios">
            <form method="GET" action="">
                <input type="hidden" name="estado" value="leido">
                <select name="year" onchange="this.form.submit()" aria-label="Año">
                    <option value="todos" <?= $verTodo ? 'selected' : '' ?>>Todas las lecturas</option>
                    <?php foreach ($aniosDisponibles as $anio): ?>
                        <option value="<?= (int)$anio ?>" <?= (!$verTodo && $yearSeleccionado == $anio) ? 'selected' : '' ?>>Año <?= (int)$anio ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <nav class="pestanas" aria-label="Estanterías">
        <?php foreach ($estantes as $clave => [$icono, $nombre]): ?>
            <a href="?estado=<?= $clave ?>" class="pestana <?= $estado === $clave ? 'activa' : '' ?>">
                <?= $icono ?> <?= $nombre ?> <span class="n"><?= (int)($conteos[$clave] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="estanteria">
        <?php generarEstanteria($libros, $estado); ?>
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
    
        <a href="estadisticas.php" class="nav-card-float">
            <span class="nav-icon">📊</span>
            <span>Estadísticas</span>
        
        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>   
        </a>
        
        <a href="buscar.php" class="nav-card-float">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>
         <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<script>
(function () {
    const cont = document.querySelector('.libros');
    if (!cont) return;

    // Agrupa los libros por fila y pone una balda tras el último de cada fila
    function colocarBaldas() {
        cont.querySelectorAll('.balda-madera').forEach(b => b.remove());
        const libros = Array.from(cont.querySelectorAll('.libro'));
        const filas = [];
        libros.forEach(l => {
            const ultima = filas[filas.length - 1];
            if (ultima && Math.abs(ultima.top - l.offsetTop) < 5) ultima.libros.push(l);
            else filas.push({ top: l.offsetTop, libros: [l] });
        });
        filas.forEach(f => {
            const balda = document.createElement('div');
            balda.className = 'balda-madera';
            balda.setAttribute('aria-hidden', 'true');
            f.libros[f.libros.length - 1].after(balda);
        });
    }

    colocarBaldas();

    let ancho = cont.clientWidth, t;
    window.addEventListener('resize', () => {
        clearTimeout(t);
        t = setTimeout(() => {
            if (cont.clientWidth !== ancho) { ancho = cont.clientWidth; colocarBaldas(); }
        }, 120);
    });
})();
</script>

</body>
</html>
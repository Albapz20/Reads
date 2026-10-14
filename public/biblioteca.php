<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/PortadaHelper.php";
require_once "../src/GenerosHelper.php";
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

/*  Estanterías disponibles */
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

/* Año (solo para "Leídos") */
$yearActual = (int)date("Y");
$yearParam = $_GET['year'] ?? (string)$yearActual;
$verTodo = ($yearParam === 'todos');
$yearSeleccionado = $verTodo ? 0 : (int)$yearParam;

/* Columna de géneros (se crea si no existe) */
$colGen = columnaGeneros($db->pdo);

$colsBase = "l.id, l.libro_id, l.titulo, l.autores, l.portada, l.fecha_fin, l.paginas_totales, l.paginas_leidas";
if ($colGen !== null) $colsBase .= ", l.`{$colGen}` AS generos_raw";

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

/* Géneros de cada libro y opciones del filtro */
$opcionesGenero = [];
$sinGeneroCuenta = 0;
foreach ($libros as &$lb) {
    $lb['generos'] = $colGen !== null ? normalizarGeneros(separarGeneros((string)($lb['generos_raw'] ?? ''))) : [];
    if (!$lb['generos']) $sinGeneroCuenta++;
    foreach ($lb['generos'] as $g) $opcionesGenero[$g] = ($opcionesGenero[$g] ?? 0) + 1;
}
unset($lb);
arsort($opcionesGenero);

$generoSel = (string)($_GET['genero'] ?? '');
if ($generoSel !== '__sin__' && !isset($opcionesGenero[$generoSel])) $generoSel = '';
if ($generoSel !== '') {
    $libros = array_values(array_filter($libros, function ($lb) use ($generoSel) {
        return $generoSel === '__sin__' ? empty($lb['generos']) : in_array($generoSel, $lb['generos'], true);
    }));
}
$hayFiltroGenero = ($generoSel !== '');

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

/* Enlace para quitar el filtro de género conservando el resto */
$quitarFiltroUrl = '?estado=' . urlencode($estado) . ($estado === 'leido' ? '&year=' . urlencode($yearParam) : '');

/* Libros de la TBR para la tómbola "¿Qué leo ahora?" */
$tbrLibros = [];
try {
    $selG = $colGen !== null ? ", l.`{$colGen}` AS generos_raw" : "";
    $stT = $db->pdo->prepare("SELECT l.id, l.libro_id, l.titulo, l.autores, l.portada, l.paginas_totales {$selG}
                              FROM listas_lectura l
                              WHERE l.usuario_id = ? AND l.estado = 'tbr'
                              ORDER BY l.id");
    $stT->execute([$usuario["id"]]);
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $idLinkT = ($t['libro_id'] ?? '') !== '' ? $t['libro_id'] : $t['id'];
        $tbrLibros[] = [
            'titulo'  => (string)$t['titulo'],
            'autor'   => trim(explode(',', (string)($t['autores'] ?? ''))[0]),
            'portada' => (string)(obtenerPortadaValida($t['portada'] ?? '', (int)$t['id']) ?: ''),
            'paginas' => (int)($t['paginas_totales'] ?? 0),
            'generos' => $colGen !== null ? normalizarGeneros(separarGeneros((string)($t['generos_raw'] ?? ''))) : [],
            'url'     => 'libro.php?id=' . urlencode((string)$idLinkT),
        ];
    }
} catch (Throwable $e) {
    $tbrLibros = [];
}

/*  Pintar la estantería */
function generarEstanteria(array $libros, string $estado, bool $hayFiltro = false, string $quitarUrl = '') {
    if (empty($libros)): ?>
        <div class="biblioteca-vacia">
            <div class="biblioteca-vacia-icono">📚</div>
            <?php if ($hayFiltro): ?>
                <h3>No hay libros con este filtro</h3>
                <p>Prueba con otro género o quita el filtro para ver toda la estantería.</p>
                <a href="<?= e($quitarUrl) ?>" class="btn-vacio">✖ Quitar filtro</a>
            <?php else: ?>
                <h3>Esta estantería está vacía</h3>
                <p><?= $estado === 'leido'
                    ? 'Los libros que marques como leídos irán apareciendo aquí sobre tus repisas.'
                    : 'Añade libros desde su ficha y aparecerán aquí.' ?></p>
                <a href="buscar.php" class="btn-vacio">🔍 Buscar libros</a>
            <?php endif; ?>
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
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
<script src="main.js"></script>
<title>Mi Biblioteca</title>


</head>
<body class="page-biblioteca">

<div class="biblioteca-wrapper">

    <div class="biblioteca-header">
        <div>
            <h2><?= e($titulo) ?></h2>
            <p class="biblioteca-resumen">
                <?= $totalLibros ?> <?= $totalLibros === 1 ? 'libro' : 'libros' ?>
                <?php if ($estado === 'leido' && $totalPaginas > 0): ?> · <?= number_format($totalPaginas, 0, '', '.') ?> páginas<?php endif; ?>
                <?php if ($hayFiltroGenero): ?> · <?= $generoSel === '__sin__' ? 'Sin género' : e($generoSel) ?> (<a href="<?= e($quitarFiltroUrl) ?>">quitar filtro</a>)<?php endif; ?>
                <span id="estadoGeneros" hidden></span>
            </p>
        </div>

        <div class="biblioteca-acciones">
            <form method="GET" action="" class="biblioteca-filtros">
                <input type="hidden" name="estado" value="<?= e($estado) ?>">

                <?php if ($estado === 'leido'): ?>
                <div class="selector-anios">
                    <select name="year" onchange="this.form.submit()" aria-label="Año">
                        <option value="todos" <?= $verTodo ? 'selected' : '' ?>>Todas las lecturas</option>
                        <?php foreach ($aniosDisponibles as $anio): ?>
                            <option value="<?= (int)$anio ?>" <?= (!$verTodo && $yearSeleccionado == $anio) ? 'selected' : '' ?>>Año <?= (int)$anio ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($colGen !== null && (!empty($opcionesGenero) || $generoSel === '__sin__')): ?>
                <div class="selector-anios">
                    <select name="genero" onchange="this.form.submit()" aria-label="Género">
                        <option value="">Todos los géneros</option>
                        <?php foreach ($opcionesGenero as $g => $n): ?>
                            <option value="<?= e($g) ?>" <?= $generoSel === $g ? 'selected' : '' ?>><?= e(estiloGenero($g)[0] . ' ' . $g) ?> (<?= (int)$n ?>)</option>
                        <?php endforeach; ?>
                        <?php if ($sinGeneroCuenta > 0 || $generoSel === '__sin__'): ?>
                            <option value="__sin__" <?= $generoSel === '__sin__' ? 'selected' : '' ?>>Sin género</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>

            <button type="button" class="btn-tombola" id="tomAbrir">
                🎰 ¿Qué leo ahora? <span class="n"><?= count($tbrLibros) ?></span>
            </button>
        </div>
    </div>

    <nav class="pestanas" aria-label="Estanterías">
        <?php foreach ($estantes as $clave => [$icono, $nombre]): ?>
            <a href="?estado=<?= $clave ?>" class="pestana <?= $estado === $clave ? 'activa' : '' ?>">
                <?= $icono ?> <?= $nombre ?> <span class="n"><?= (int)($conteos[$clave] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="estanteria">
        <?php generarEstanteria($libros, $estado, $hayFiltroGenero, $quitarFiltroUrl); ?>
    </div>

</div>

<!-- Tómbola: ¿Qué leo ahora? -->
<dialog class="tom-dialog" id="tombola" aria-labelledby="tomTitulo">
    <button type="button" class="modal-cerrar" id="tomCerrar" aria-label="Cerrar">✕</button>
    <div class="tom-cuerpo">
        <h2 id="tomTitulo">🎰 ¿Qué leo ahora?</h2>
        <p class="tom-sub">Gira la tómbola y deja que el azar elija uno de tu TBR.</p>

        <div id="tomVacio" hidden>
            <p class="tom-sub">Tu TBR está vacía. Añade libros pendientes para poder jugar.</p>
            <a href="buscar.php" class="tom-ver">🔍 Buscar libros</a>
        </div>

        <div id="tomJuego">
            <label class="tom-filtro" id="tomFiltro">Género
                <select id="tomGenero" aria-label="Género de la tómbola"></select>
            </label>

            <div class="tom-ruleta" id="tomRuleta">
                <div class="tom-marca" aria-hidden="true"></div>
                <div class="tom-tira" id="tomTira"></div>
            </div>

            <div class="tom-resultado" id="tomResultado" hidden aria-live="polite"></div>

            <button type="button" class="tom-boton" id="tomGirar">🎲 ¡Girar!</button>
        </div>
    </div>
</dialog>

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
        <a href="biblioteca.php" class="nav-card-float active">
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

// Tómbola "¿Qué leo ahora?"
(function () {
    const libros = <?= json_encode($tbrLibros, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const abrir = document.getElementById("tomAbrir");
    const dlg = document.getElementById("tombola");
    if (!abrir || !dlg) return;

    const tira = document.getElementById("tomTira");
    const ruleta = document.getElementById("tomRuleta");
    const resultado = document.getElementById("tomResultado");
    const boton = document.getElementById("tomGirar");
    const selGenero = document.getElementById("tomGenero");
    const filtro = document.getElementById("tomFiltro");
    const vacio = document.getElementById("tomVacio");
    const juego = document.getElementById("tomJuego");
    const reducir = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let girando = false, ultimo = null;

    const azar = lista => lista[Math.floor(Math.random() * lista.length)];

    function poblarGeneros() {
        const cuenta = {};
        libros.forEach(l => l.generos.forEach(g => { cuenta[g] = (cuenta[g] || 0) + 1; }));
        const nombres = Object.keys(cuenta).sort((a, b) => cuenta[b] - cuenta[a]);
        selGenero.textContent = "";
        selGenero.add(new Option("Cualquier género (" + libros.length + ")", ""));
        nombres.forEach(g => selGenero.add(new Option(g + " (" + cuenta[g] + ")", g)));
        filtro.hidden = nombres.length === 0;
    }

    function pool() {
        const g = selGenero.value;
        return g ? libros.filter(l => l.generos.indexOf(g) !== -1) : libros;
    }

    function crearItem(l) {
        const d = document.createElement("div");
        d.className = "tom-item";
        const t = document.createElement("span");
        t.textContent = l.titulo;
        d.appendChild(t);
        if (l.portada) {
            const im = document.createElement("img");
            im.src = l.portada; im.alt = "";
            im.onerror = function () { im.remove(); };
            d.appendChild(im);
        }
        return d;
    }

    // Tira de portadas quieta 
    function vistaPrevia() {
        tira.style.transition = "none";
        tira.style.transform = "translateX(0)";
        tira.textContent = "";
        resultado.hidden = true;
        const lista = pool().slice().sort(() => Math.random() - 0.5);
        const n = Math.max(6, Math.min(10, lista.length));
        for (let i = 0; i < n; i++) tira.appendChild(crearItem(lista[i % lista.length]));
    }

    function mostrar(l) {
        resultado.textContent = "";
        if (l.portada) {
            const im = document.createElement("img");
            im.className = "tom-res-portada"; im.src = l.portada; im.alt = "Portada de " + l.titulo;
            im.onerror = function () { im.remove(); };
            resultado.appendChild(im);
        }
        const info = document.createElement("div");
        info.className = "tom-res-info";

        const kicker = document.createElement("span");
        kicker.className = "tom-res-kicker"; kicker.textContent = "¡Te toca leer!";
        const tit = document.createElement("strong");
        tit.className = "tom-res-titulo"; tit.textContent = l.titulo;
        info.appendChild(kicker); info.appendChild(tit);

        if (l.autor) {
            const a = document.createElement("span");
            a.className = "tom-res-autor"; a.textContent = l.autor;
            info.appendChild(a);
        }
        const meta = [];
        if (l.paginas > 0) meta.push(l.paginas.toLocaleString("es-ES") + " páginas");
        if (l.generos.length) meta.push(l.generos.join(" · "));
        if (meta.length) {
            const m = document.createElement("span");
            m.className = "tom-res-meta"; m.textContent = meta.join(" · ");
            info.appendChild(m);
        }
        const ver = document.createElement("a");
        ver.className = "tom-ver"; ver.href = l.url; ver.textContent = "📖 Ver libro";
        info.appendChild(ver);

        resultado.appendChild(info);
        resultado.hidden = false;
    }

    function confeti() {
        const cuerpo = dlg.querySelector(".tom-cuerpo");
        const emojis = ["🎉", "✨", "📚", "⭐", "💜"];
        for (let i = 0; i < 36; i++) {
            const s = document.createElement("span");
            s.className = "tom-confeti";
            s.textContent = emojis[i % emojis.length];
            s.style.setProperty("--dx", ((Math.random() - 0.5) * 460) + "px");
            s.style.setProperty("--dy", ((Math.random() - 0.8) * 320) + "px");
            s.style.setProperty("--rot", ((Math.random() - 0.5) * 540) + "deg");
            s.style.animationDelay = (Math.random() * 0.15) + "s";
            cuerpo.appendChild(s);
            setTimeout(function () { s.remove(); }, 1800);
        }
    }

    function girar() {
        if (girando) return;
        const lista = pool();
        if (!lista.length) return;

        let ganador = azar(lista);
        if (lista.length > 1) {
            for (let k = 0; k < 12 && ganador === ultimo; k++) ganador = azar(lista);
        }
        ultimo = ganador;
        resultado.hidden = true;

        const N = 38, IDX = 32;
        tira.style.transition = "none";
        tira.style.transform = "translateX(0)";
        tira.textContent = "";
        let prev = null;
        for (let i = 0; i < N; i++) {
            let l;
            if (i === IDX) {
                l = ganador;
            } else {
                l = azar(lista);
                if (lista.length > 1) {
                    for (let k = 0; k < 12 && (l === prev || ((i === IDX - 1 || i === IDX + 1) && l === ganador)); k++) l = azar(lista);
                }
            }
            prev = l;
            tira.appendChild(crearItem(l));
        }

        void tira.offsetWidth;   // fuerza el cálculo antes de animar
        const item = tira.children[IDX];
        const w = item.offsetWidth;
        const destino = item.offsetLeft + w / 2 - ruleta.clientWidth / 2 + (Math.random() - 0.5) * w * 0.4;
        const dur = reducir ? 0.01 : 4.2;

        girando = true;
        boton.disabled = true;
        boton.textContent = "🎰 Girando…";
        tira.style.transition = "transform " + dur + "s cubic-bezier(.12,.7,.1,1)";
        tira.style.transform = "translateX(" + (-destino) + "px)";

        let hecho = false;
        const alTerminar = function (e) { if (e.target === tira) fin(); };
        function fin() {
            if (hecho) return;
            hecho = true;
            tira.removeEventListener("transitionend", alTerminar);
            girando = false;
            item.classList.add("gana");
            mostrar(ganador);
            boton.disabled = false;
            boton.textContent = "🔄 Girar otra vez";
            if (!reducir) confeti();
        }
        tira.addEventListener("transitionend", alTerminar);
        setTimeout(fin, dur * 1000 + 400);
    }

    abrir.addEventListener("click", function () {
        poblarGeneros();
        const hay = libros.length > 0;
        vacio.hidden = hay;
        juego.hidden = !hay;
        boton.textContent = "🎲 ¡Girar!";
        boton.disabled = false;
        dlg.showModal();
        if (hay) vistaPrevia();
    });
    document.getElementById("tomCerrar").addEventListener("click", function () { dlg.close(); });
    dlg.addEventListener("click", function (e) { if (e.target === dlg) dlg.close(); });
    selGenero.addEventListener("change", function () { if (!girando) vistaPrevia(); });
    boton.addEventListener("click", girar);
})();

<?php if ($colGen !== null && $sinGeneroCuenta > 0): ?>
// Detección automática de géneros en segundo plano (libros que aún no tienen género)
(async function () {
    const estado = document.getElementById("estadoGeneros");
    let procesados = 0;
    try {
        for (let i = 0; i < 40; i++) {
            const fd = new FormData();
            fd.append("accion", "procesar");
            fd.append("ajax", "1");
            const r = await fetch("rellenar_generos.php", { method: "POST", body: fd });
            if (!r.ok) break;
            const j = await r.json();
            procesados += j.procesados;
            if (estado && j.procesados > 0) {
                estado.hidden = false;
                estado.textContent = " · 🔎 Detectando géneros… quedan " + j.pendientes;
            }
            if (!j.pendientes || !j.procesados) break;
        }
    } catch (e) {}
    const dlg = document.getElementById("tombola");
    if (procesados > 0 && !(dlg && dlg.open)) location.reload();
})();
<?php endif; ?>
</script>

</body>
</html>
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

/*  Pintar la estantería */
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
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=3">
<script src="main.js"></script>
<title>Mi Biblioteca</title>
<link rel="stylesheet" href="/Reads/temas/<?= e($tema) ?>.css">

</head>
<body class="page-biblioteca">

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
        <a href="biblioteca.php" class="nav-card-float active">
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
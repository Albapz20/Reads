<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/AjustesService.php";
require_once "../src/UserService.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$db = new Database();
$ajustesService = new AjustesService();
$userService    = new UserService();

// Procesar formulario de actualización del objetivo anual
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["nuevo_objetivo"])) {
    $nuevoObjetivo = max(1, (int)$_POST["nuevo_objetivo"]);

    $stmtCheck = $db->pdo->prepare("SELECT id FROM ajustes_usuario WHERE usuario_id = ?");
    $stmtCheck->execute([$usuario["id"]]);
    $existe = $stmtCheck->fetchColumn();

    if ($existe) {
        $stmtOpt = $db->pdo->prepare("UPDATE ajustes_usuario SET objetivo_anual = ? WHERE usuario_id = ?");
        $stmtOpt->execute([$nuevoObjetivo, $usuario["id"]]);
    } else {
        $stmtOpt = $db->pdo->prepare("INSERT INTO ajustes_usuario (usuario_id, objetivo_anual) VALUES (?, ?)");
        $stmtOpt->execute([$usuario["id"], $nuevoObjetivo]);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$stmtAjustes = $db->pdo->prepare("SELECT objetivo_anual FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
$stmtAjustes->execute([$usuario["id"]]);
$ajustes = $stmtAjustes->fetch(PDO::FETCH_ASSOC) ?: [];
$year = date("Y");
$yearPrev = (int)$year - 1;
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

// Catálogo manual para resolver títulos difíciles o con etiquetas de Goodreads
function obtenerPaginasPorCatalogo($tituloOriginal) {
    $catalogo = [
        "ever and after"                 => 544,
        "the wolf king"                  => 496,
        "una maldición tallada en hueso" => 448,
        "el príncipe de la noche"        => 440,
        "instrucción de novicias"        => 288,
        "caraval"                        => 416,
        "delito"                         => 480,
        "icebreaker"                     => 432,
        "los secretos de heap house"     => 352,
        "novia"                          => 416,
        "quicksilver"                    => 512,
        "nightshade"                     => 432,
        "calabobos"                      => 160,
        "metal slinger"                  => 418,
        "light wielder"                  => 400,
        "smoke and scar"                 => 420,
        "la asistenta"                   => 336,
        "rey de la soberbia"             => 448,
        "el libro de azrael"             => 576,
        "la espada de la asesina"        => 432
    ];

    $tituloLower = mb_strtolower(trim($tituloOriginal), 'UTF-8');
    foreach ($catalogo as $clave => $pags) {
        if (mb_strpos($tituloLower, $clave, 0, 'UTF-8') !== false) {
            return $pags;
        }
    }
    return 0;
}

// Descarga y decodifica un JSON con timeouts cortos
if (!function_exists('curlJson')) {
    function curlJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (contacto@tudominio.com)',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? (json_decode($resp, true) ?: []) : [];
    }
}

// Busca el número de páginas en Google Books y Open Library
function buscarPaginasOnline(string $titulo, string $autores, string $libroId): int {
    $tituloLimpio = trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo)));
    $autor = trim(explode(',', $autores)[0] ?? '');
    $tl = mb_strtolower($tituloLimpio, 'UTF-8');

    // 1. Google Books por ISBN 
    if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', $libroId)) {
        $json = curlJson('https://www.googleapis.com/books/v1/volumes?q=' . urlencode('isbn:' . $libroId));
        $p = (int)($json['items'][0]['volumeInfo']['pageCount'] ?? 0);
        if ($p > 30) return $p;
    }

    // 2. Google Books por título + autor 
    $q = 'intitle:"' . $tituloLimpio . '"' . ($autor !== '' ? ' inauthor:"' . $autor . '"' : '');
    $json = curlJson('https://www.googleapis.com/books/v1/volumes?maxResults=5&q=' . urlencode($q));
    foreach ($json['items'] ?? [] as $item) {
        $p = (int)($item['volumeInfo']['pageCount'] ?? 0);
        $t = mb_strtolower($item['volumeInfo']['title'] ?? '', 'UTF-8');
        if ($t === '') continue;
        similar_text($tl, $t, $pct);
        if ($p > 30 && ($pct >= 60 || str_contains($t, $tl) || str_contains($tl, $t))) return $p;
    }

    // 3. Open Library 
    $params = ['title' => $tituloLimpio, 'limit' => 5, 'fields' => 'title,author_name,number_of_pages_median'];
    if ($autor !== '') $params['author'] = $autor;
    $json = curlJson('https://openlibrary.org/search.json?' . http_build_query($params));
    foreach ($json['docs'] ?? [] as $doc) {
        $p = (int)($doc['number_of_pages_median'] ?? 0);
        $t = mb_strtolower($doc['title'] ?? '', 'UTF-8');
        if ($t === '') continue;
        similar_text($tl, $t, $pct);
        if ($p > 30 && ($pct >= 60 || str_contains($t, $tl) || str_contains($tl, $t))) return $p;
    }

    return 0;
}

// Filtro Top 5
$periodoTop = $_GET['periodo'] ?? 'anio';
if (!in_array($periodoTop, ['anio', 'todos'])) $periodoTop = 'anio';

if ($periodoTop === 'todos') {
    $whereTop = "";
    $paramsTop = [$usuario["id"]];
} else {
    $whereTop = " AND YEAR(fecha_fin) = ?";
    $paramsTop = [$usuario["id"], $year];
}

// Obtener libros leídos
$sqlPaginas = "SELECT id, libro_id, titulo, autores,
                      paginas_totales, paginas_leidas,
                      GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0)) AS paginas_reales,
                      portada, estado
               FROM listas_lectura
               WHERE usuario_id = ? AND estado = 'leido' {$whereTop}";
$stmt = $db->pdo->prepare($sqlPaginas);
$stmt->execute($paramsTop);
$todosLosLibrosPeriodo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-resolver libros con 0 o 300 páginas (catálogo manual y después búsqueda online)
$maxConsultasOnline = 4;   // límite por carga para no ralentizar la página
if (!isset($_SESSION['paginas_fallidas'])) $_SESSION['paginas_fallidas'] = [];

foreach ($todosLosLibrosPeriodo as &$l) {
    $pags = (int)$l['paginas_reales'];
    if ($pags !== 0 && $pags !== 300) continue;

    $pagsAuto = obtenerPaginasPorCatalogo($l['titulo']);

    if ($pagsAuto === 0 && $maxConsultasOnline > 0 && empty($_SESSION['paginas_fallidas'][$l['id']])) {
        $maxConsultasOnline--;
        $pagsAuto = buscarPaginasOnline($l['titulo'], (string)($l['autores'] ?? ''), (string)($l['libro_id'] ?? ''));
        if ($pagsAuto === 0) $_SESSION['paginas_fallidas'][$l['id']] = true;
    }

    if ($pagsAuto > 0 && $pagsAuto !== $pags) {
        $l['paginas_totales'] = $pagsAuto;
        $l['paginas_leidas']  = $pagsAuto;
        $l['paginas_reales']  = $pagsAuto;

        $db->pdo->prepare("UPDATE listas_lectura SET paginas_totales = ?, paginas_leidas = ? WHERE id = ?")
                ->execute([$pagsAuto, $pagsAuto, $l['id']]);
    }
}
unset($l);

// Ordenar por mayor número de páginas y extraer el Top 5
usort($todosLosLibrosPeriodo, fn($a, $b) => (int)$b['paginas_reales'] <=> (int)$a['paginas_reales']);
$topPaginas = array_slice($todosLosLibrosPeriodo, 0, 5);
$topValores = array_map(fn($item) => (int)$item['paginas_reales'], $topPaginas);

// Libros leídos por mes
$stmt = $db->pdo->prepare("SELECT MONTH(fecha_fin) AS mes, COUNT(*) AS total
                           FROM listas_lectura
                           WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                           GROUP BY MONTH(fecha_fin)");
$stmt->execute([$usuario["id"], $year]);
$mesesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mesesNombres = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
$mesesCompletos = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
$mesesLabels = [];
$mesesValores = [];
for ($i = 1; $i <= 12; $i++) {
    $mesesLabels[] = $mesesNombres[$i - 1];
    $mesesValores[] = isset($mesesData[$i]) ? (int)$mesesData[$i] : 0;
}

// Total de libros leídos en el año
$stmt = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmt->execute([$usuario["id"], $year]);
$leidosEsteAño = (int)$stmt->fetchColumn();

// Objetivo, porcentaje y mensaje de ritmo
$objetivo = (int)($ajustes["objetivo_anual"] ?? 0);
$porcentajeObjetivo = $objetivo > 0 ? min(100, round(($leidosEsteAño / $objetivo) * 100)) : 0;
$faltan = max(0, $objetivo - $leidosEsteAño);

$diaDelAno = (int)date("z") + 1;
$diasTotalesAno = (date("L") == 1) ? 366 : 365;
$esperados = $objetivo > 0 ? ($objetivo / $diasTotalesAno) * $diaDelAno : 0;
if ($objetivo <= 0) {
    $mensajeReto = "Fija un reto para seguir tu progreso.";
} elseif ($leidosEsteAño >= $objetivo) {
    $mensajeReto = "🎉 ¡Reto completado!";
} elseif ($leidosEsteAño >= floor($esperados)) {
    $mensajeReto = "Vas bien, te faltan $faltan " . ($faltan === 1 ? "libro" : "libros") . ".";
} else {
    $mensajeReto = "Te faltan $faltan " . ($faltan === 1 ? "libro" : "libros") . ". ¡Aún puedes lograrlo!";
}

// Tiempo de lectura
$stmtTiempo = $db->pdo->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0))), 0) FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmtTiempo->execute([$usuario["id"], $year]);
$totalPaginasAño = (int)$stmtTiempo->fetchColumn();

$minutosPorPagina = 1.5;
$totalMinutosLectura = (int)round($totalPaginasAño * $minutosPorPagina);
$diasLectura = floor($totalMinutosLectura / 1440);
$horasLectura = floor(($totalMinutosLectura % 1440) / 60);

// Comparativa con el año anterior
$stmtPrev = $db->pdo->prepare("SELECT COUNT(*) AS libros,
                                      COALESCE(SUM(GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0))), 0) AS paginas
                               FROM listas_lectura
                               WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
$stmtPrev->execute([$usuario["id"], $yearPrev]);
$prev = $stmtPrev->fetch(PDO::FETCH_ASSOC) ?: ['libros' => 0, 'paginas' => 0];
$leidosAnioPrev  = (int)$prev['libros'];
$paginasAnioPrev = (int)$prev['paginas'];

// Pinta la diferencia con el año anterior
function badgeComparativa(int $actual, int $anterior, int $anioPrev): string {
    if ($anterior <= 0) {
        return '<span class="st-delta neutro">Sin datos de ' . $anioPrev . '</span>';
    }
    $dif = $actual - $anterior;
    if ($dif > 0) {
        return '<span class="st-delta sube">▲ +' . number_format($dif, 0, '', '.') . ' vs ' . $anioPrev . '</span>';
    }
    if ($dif < 0) {
        return '<span class="st-delta baja">▼ ' . number_format($dif, 0, '', '.') . ' vs ' . $anioPrev . '</span>';
    }
    return '<span class="st-delta neutro">= igual que en ' . $anioPrev . '</span>';
}

// Libros del año (para autores y datos curiosos)
$librosAnio = [];
try {
    $stmt = $db->pdo->prepare("SELECT titulo, autores, fecha_inicio, fecha_fin,
                                      GREATEST(COALESCE(paginas_totales, 0), COALESCE(paginas_leidas, 0)) AS paginas_reales
                               FROM listas_lectura
                               WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?");
    $stmt->execute([$usuario["id"], $year]);
    $librosAnio = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $librosAnio = [];
}

// Autores más leídos
$conteoAutores = [];
$nombreAutor   = [];
foreach ($librosAnio as $libro) {
    $autoresLibro = array_filter(array_map('trim', explode(',', (string)($libro['autores'] ?? ''))));
    foreach (array_unique($autoresLibro) as $aut) {
        $clave = mb_strtolower($aut, 'UTF-8');
        $conteoAutores[$clave] = ($conteoAutores[$clave] ?? 0) + 1;
        $nombreAutor[$clave] = $nombreAutor[$clave] ?? $aut;
    }
}
arsort($conteoAutores);
$topAutores = [];
foreach (array_slice($conteoAutores, 0, 5, true) as $clave => $n) {
    $topAutores[] = ['nombre' => $nombreAutor[$clave], 'libros' => $n];
}
$maxLibrosAutor = !empty($topAutores) ? max(array_column($topAutores, 'libros')) : 1;

// Datos curiosos
$conPaginas = array_values(array_filter($librosAnio, fn($b) => (int)$b['paginas_reales'] > 0));
$libroLargo = null;
$libroCorto = null;
foreach ($conPaginas as $b) {
    if ($libroLargo === null || (int)$b['paginas_reales'] > (int)$libroLargo['paginas_reales']) $libroLargo = $b;
    if ($libroCorto === null || (int)$b['paginas_reales'] < (int)$libroCorto['paginas_reales']) $libroCorto = $b;
}

$mediaPaginasLibro = $leidosEsteAño > 0 ? (int)round($totalPaginasAño / $leidosEsteAño) : 0;
$paginasPorDia = $diaDelAno > 0 ? round($totalPaginasAño / $diaDelAno, 1) : 0;

$maxMes = max($mesesValores);
$mejorMes = $maxMes > 0 ? ucfirst($mesesCompletos[array_search($maxMes, $mesesValores)]) : null;

// Días medios para terminar un libro (usa fecha_inicio y fecha_fin)
$duraciones = [];
foreach ($librosAnio as $b) {
    $ini = strtotime((string)($b['fecha_inicio'] ?? ''));
    $fin = strtotime((string)($b['fecha_fin'] ?? ''));
    if ($ini && $fin && $fin >= $ini) {
        $duraciones[] = max(1, (int)round(($fin - $ini) / 86400) + 1);
    }
}
$diasMediosLibro = !empty($duraciones) ? (int)round(array_sum($duraciones) / count($duraciones)) : 0;

// Sincronizar y obtener distribución por estrellas
try {
    $stmtSync = $db->pdo->prepare("UPDATE listas_lectura l
                JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                SET l.estrellas = p.estrellas
                WHERE l.usuario_id = ? AND (l.estrellas IS NULL OR l.estrellas = 0) AND p.estrellas > 0");
    $stmtSync->execute([$usuario["id"]]);
} catch (Exception $e) {}

try {
    $stmt = $db->pdo->prepare("SELECT COALESCE(NULLIF(p.estrellas, 0), NULLIF(l.estrellas, 0), 0) AS estrellas, YEAR(l.fecha_fin) AS anio
                     FROM listas_lectura l
                     LEFT JOIN puntuaciones p ON p.usuario_id = l.usuario_id AND (p.libro_id = l.libro_id OR p.libro_id = l.id)
                     WHERE l.usuario_id = ? AND l.estado = 'leido'");
    $stmt->execute([$usuario["id"]]);
    $filasNotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stmt = $db->pdo->prepare("SELECT estrellas, YEAR(fecha_fin) AS anio FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido'");
    $stmt->execute([$usuario["id"]]);
    $filasNotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Resume una lista de notas: promedio, nota más común y distribución
function resumenEstrellas(array $valores): array {
    $dist = ["5 ★" => 0, "4 ★" => 0, "3 ★" => 0, "2 ★" => 0, "1 ★" => 0];
    if (empty($valores)) return ['promedio' => 0, 'comun' => 0, 'dist' => $dist, 'total' => 0];

    $enteras = array_map(fn($v) => (int)round($v), $valores);
    $frecuencias = array_count_values($enteras);
    foreach ($enteras as $v) {
        if ($v >= 1 && $v <= 5) $dist["{$v} ★"]++;
    }
    return [
        'promedio' => round(array_sum($valores) / count($valores), 2),
        'comun'    => array_keys($frecuencias, max($frecuencias))[0],
        'dist'     => $dist,
        'total'    => count($valores),
    ];
}

$notasTodasLista = [];
$notasAnioLista  = [];
foreach ($filasNotas as $fila) {
    $v = (float)$fila['estrellas'];
    if ($v <= 0) continue;
    $notasTodasLista[] = $v;
    if ((string)$fila['anio'] === (string)$year) $notasAnioLista[] = $v;
}
$notasTodos = resumenEstrellas($notasTodasLista);
$notasAnio  = resumenEstrellas($notasAnioLista);

// Pestaña elegida para la sección de estrellas (independiente del Top 5)
$periodoNotas = $_GET['notas'] ?? 'anio';
if (!in_array($periodoNotas, ['anio', 'todos'])) $periodoNotas = 'anio';
$notasMostradas = ($periodoNotas === 'todos') ? $notasTodos : $notasAnio;

$promedioEstrellas     = $notasMostradas['promedio'];
$estrellaComun         = $notasMostradas['comun'];
$distribucionEstrellas = $notasMostradas['dist'];
$totalDistribucion     = array_sum($distribucionEstrellas);
$porcentajeRelleno     = round(($promedioEstrellas / 5) * 100, 1);

// Enlaces de las pestañas: cada una cambia solo su parámetro y conserva el otro
function urlEstadisticas(array $cambios, string $periodoTop, string $periodoNotas): string {
    return 'estadisticas.php?' . http_build_query(array_merge(['periodo' => $periodoTop, 'notas' => $periodoNotas], $cambios));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="main.js"></script>
<title>Estadísticas de lectura</title>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --st-accent: var(--primary-color, var(--color-primario, #d87d8a));
    --st-border: var(--border-color, rgba(0,0,0,.09));
    --st-surface: var(--bg-card, #ffffff);
}
body { padding-bottom: 110px; }
.st-wrap { max-width: 980px; margin: 0 auto; padding: 20px 16px; }

/* ---- Cabecera ---- */
.st-header { margin-bottom: 18px; }
.st-header h1 { margin: 0 0 4px; font-size: 1.9rem; }
.st-header p { margin: 0; opacity: .7; }

/* ---- Tarjetas resumen ---- */
.st-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
.st-kpi {
    background: var(--st-surface); border: 1px solid var(--st-border); border-radius: 16px;
    padding: 16px 18px; position: relative; overflow: hidden;
}
.st-kpi::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--st-accent); }
.st-kpi .icono { font-size: 1.3rem; }
.st-kpi .valor { display: block; font-size: 1.9rem; font-weight: 800; line-height: 1.1; margin-top: 6px; }
.st-kpi .etiqueta { display: block; font-size: .8rem; opacity: .7; margin-top: 2px; font-weight: 600; }
.st-delta { display: block; font-size: .75rem; font-weight: 700; margin-top: 6px; }
.st-delta.sube { color: #2e9e5b; }
.st-delta.baja { color: #d9534f; }
.st-delta.neutro { opacity: .55; font-weight: 600; }

/* ---- Paneles ---- */
.st-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }
.st-card {
    background: var(--st-surface); border: 1px solid var(--st-border); border-radius: 16px;
    padding: 22px; min-width: 0;
}
.st-card.ancho { grid-column: 1 / -1; }
.st-card-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.st-card-head h2 { margin: 0; font-size: 1.1rem; }

.filtro-pestañas { display: flex; gap: 4px; background: rgba(0,0,0,.05); padding: 4px; border-radius: 999px; }
.filtro-btn { padding: 5px 12px; border-radius: 999px; font-size: .8rem; font-weight: 700; text-decoration: none; transition: background .2s; }
.filtro-btn.activo { background: var(--st-accent); color: #fff !important; }
.filtro-btn.inactivo { color: inherit; opacity: .7; }

/* Objetivo */
.objetivo-card-wrapper { display: flex; flex-direction: column; align-items: center; }
.grafico-dona-contenedor { position: relative; width: 100%; max-width: 260px; height: 150px; }
.objetivo-centro-texto { position: absolute; top: 68%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none; }
.objetivo-centro-texto .porcentaje { display: block; font-size: 2rem; font-weight: 800; line-height: 1; }
.objetivo-centro-texto .progreso-sub { display: block; font-size: .85rem; opacity: .7; margin-top: 4px; font-weight: 600; }
.objetivo-mensaje { margin: 14px 0 0; font-size: .92rem; text-align: center; font-weight: 600; }
.btn-reto { margin-top: 14px; background: rgba(0,0,0,.05); color: inherit; border: 1px solid var(--st-border); padding: 8px 18px; border-radius: 999px; font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; }
.btn-reto:hover { border-color: var(--st-accent); }
.form-reto-wrapper { display: none; margin-top: 12px; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--st-border); width: 100%; max-width: 300px; box-sizing: border-box; }
.form-reto-wrapper form { display: flex; gap: 8px; }
.form-reto-wrapper input[type="number"] { width: 100%; padding: 7px 10px; border: 1px solid var(--st-border); border-radius: 8px; font: inherit; }
.btn-guardar-reto { border: none; color: #fff; padding: 7px 14px; border-radius: 8px; cursor: pointer; font: inherit; font-size: .85rem; font-weight: 700; background: var(--st-accent); }

.grafico-contenedor { width: 100%; height: 230px; position: relative; }

/* Top 5 */
.ranking-lista { display: flex; flex-direction: column; gap: 10px; }
.ranking-card { display: flex; align-items: center; gap: 14px; border: 1px solid var(--st-border); border-radius: 12px; padding: 10px 12px; }
.ranking-portada-box { position: relative; flex-shrink: 0; width: 46px; height: 66px; border-radius: 4px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #2c3e50, #1a252f); }
.ranking-portada { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,.2); }
.ranking-badge { position: absolute; top: -7px; left: -7px; z-index: 2; background: #2c3e50; color: #fff; font-size: .7rem; font-weight: 800; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--st-surface); }
.pos-1 .ranking-badge { background: #ffd700; color: #5a4300; }
.pos-2 .ranking-badge { background: #c0c0c0; color: #333; }
.pos-3 .ranking-badge { background: #cd7f32; color: #fff; }
.ranking-detalles { flex: 1; min-width: 0; }
.ranking-top-info { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 6px; }
.ranking-titulo { font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ranking-paginas-tag { font-size: .85rem; font-weight: 700; white-space: nowrap; color: var(--st-accent); }
.ranking-barra-wrapper { background: rgba(0,0,0,.08); height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 6px; }
.ranking-barra-fill { height: 100%; border-radius: 3px; background: var(--st-accent); }
.ranking-link { font-size: .78rem; opacity: .7; text-decoration: none; color: inherit; }
.ranking-link:hover { opacity: 1; text-decoration: underline; }

/* Estrellas (nuevo diseño) */
.estrellas-layout { display: flex; gap: 22px; align-items: center; }
.estrellas-media { flex-shrink: 0; text-align: center; min-width: 110px; }
.estrellas-media .nota { display: block; font-size: 2.6rem; font-weight: 800; line-height: 1; color: var(--st-accent); }
.estrellas-vis {
    display: inline-block; font-size: 1.15rem; letter-spacing: 2px; margin-top: 6px;
    background: linear-gradient(90deg, var(--st-accent) var(--p, 0%), rgba(128,128,128,.28) var(--p, 0%));
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent;
}
.estrellas-media .sub { display: block; font-size: .78rem; opacity: .7; margin-top: 4px; font-weight: 600; }
.estrellas-filas { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.estrella-fila {
    display: grid; grid-template-columns: 28px 1fr 64px; align-items: center; gap: 10px;
    text-decoration: none; color: inherit; padding: 4px 6px; border-radius: 8px; transition: background .2s;
}
.estrella-fila:hover { background: rgba(0,0,0,.05); }
.estrella-fila .num { font-size: .85rem; font-weight: 700; }
.estrella-fila .barra { background: rgba(0,0,0,.08); height: 8px; border-radius: 4px; overflow: hidden; }
.estrella-fila .relleno { height: 100%; border-radius: 4px; background: var(--st-accent); }
.estrella-fila .cuenta { font-size: .78rem; opacity: .75; text-align: right; font-weight: 600; white-space: nowrap; }
.estrella-fila.vacia { opacity: .5; }
.estrellas-pie { margin: 12px 0 0; font-size: .8rem; opacity: .65; }

/* Autores */
.autores-lista { display: flex; flex-direction: column; gap: 12px; }
.autor-fila { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; align-items: center; }
.autor-nombre { font-size: .9rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.autor-num { font-size: .82rem; font-weight: 700; color: var(--st-accent); white-space: nowrap; }
.autor-barra { grid-column: 1 / -1; background: rgba(0,0,0,.08); height: 6px; border-radius: 3px; overflow: hidden; }
.autor-barra div { height: 100%; border-radius: 3px; background: var(--st-accent); }

/* Datos curiosos */
.curiosos-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.curioso { border: 1px solid var(--st-border); border-radius: 12px; padding: 14px 16px; min-width: 0; }
.curioso .c-icono { font-size: 1.2rem; }
.curioso .c-etiqueta { display: block; font-size: .75rem; opacity: .65; font-weight: 700; margin-top: 4px; text-transform: uppercase; letter-spacing: .03em; }
.curioso .c-valor { display: block; font-size: 1.15rem; font-weight: 800; margin-top: 4px; color: var(--st-accent); }
.curioso .c-detalle { display: block; font-size: .8rem; opacity: .75; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.vacio { margin: 0; opacity: .65; font-size: .9rem; }

/* Barra inferior */
.floating-nav-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: calc(100% - 40px); max-width: 600px; }
.quick-nav-floating { display: flex; align-items: center; justify-content: space-around; padding: 8px 12px; background: rgba(255,255,255,.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.6); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
.nav-card-float { display: flex; flex-direction: column; align-items: center; padding: 6px 12px; text-decoration: none; color: #2d3748; font-weight: 600; font-size: .8rem; border-radius: 12px; transition: color .2s, transform .2s; }
.nav-card-float:hover, .nav-card-float.active { color: var(--st-accent); transform: translateY(-2px); }
.nav-card-float .nav-icon { font-size: 1.25rem; margin-bottom: 2px; }

@media (max-width: 820px) {
    .st-kpis { grid-template-columns: repeat(2, 1fr); }
    .st-grid { grid-template-columns: 1fr; }
    .st-header h1 { font-size: 1.6rem; }
    .curiosos-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .estrellas-layout { flex-direction: column; align-items: stretch; }
    .curiosos-grid { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

<div class="st-wrap">

    <header class="st-header">
        <h1>📊 Estadísticas de lectura</h1>
        <p>Tu año lector <?= htmlspecialchars($year) ?> de un vistazo</p>
    </header>

    <!-- Resumen -->
    <section class="st-kpis">
        <div class="st-kpi">
            <span class="icono">📚</span>
            <span class="valor"><?= number_format($leidosEsteAño, 0, '', '.') ?></span>
            <span class="etiqueta">Libros en <?= htmlspecialchars($year) ?></span>
            <?= badgeComparativa($leidosEsteAño, $leidosAnioPrev, $yearPrev) ?>
        </div>
        <div class="st-kpi">
            <span class="icono">📖</span>
            <span class="valor"><?= number_format($totalPaginasAño, 0, '', '.') ?></span>
            <span class="etiqueta">Páginas leídas</span>
            <?= badgeComparativa($totalPaginasAño, $paginasAnioPrev, $yearPrev) ?>
        </div>
        <div class="st-kpi">
            <span class="icono">⏱️</span>
            <span class="valor"><?= $diasLectura ?>d <?= $horasLectura ?>h</span>
            <span class="etiqueta"><?= number_format($totalMinutosLectura, 0, '', '.') ?> minutos leyendo</span>
        </div>
        <div class="st-kpi">
            <span class="icono">⭐</span>
            <span class="valor"><?= $notasAnio['promedio'] > 0 ? $notasAnio['promedio'] : '—' ?></span>
            <span class="etiqueta">Nota media <?= htmlspecialchars($year) ?> · total <?= $notasTodos['promedio'] > 0 ? $notasTodos['promedio'] : '—' ?></span>
        </div>
    </section>

    <div class="st-grid">

        <!-- Objetivo anual -->
        <section class="st-card">
            <div class="st-card-head"><h2>📘 Objetivo anual</h2></div>
            <div class="objetivo-card-wrapper">
                <div class="grafico-dona-contenedor">
                    <canvas id="objetivoChart"></canvas>
                    <div class="objetivo-centro-texto">
                        <span class="porcentaje"><?= $porcentajeObjetivo ?>%</span>
                        <span class="progreso-sub"><?= $leidosEsteAño ?> / <?= $objetivo ?> libros</span>
                    </div>
                </div>
                <p class="objetivo-mensaje"><?= htmlspecialchars($mensajeReto) ?></p>
                <button class="btn-reto" onclick="toggleFormReto()">🎯 Establecer reto de lectura</button>
                <div class="form-reto-wrapper" id="formRetoWrapper">
                    <form action="" method="POST">
                        <input type="number" name="nuevo_objetivo" min="1" value="<?= $objetivo ?: 1 ?>" required placeholder="Nº de libros">
                        <button type="submit" class="btn-guardar-reto">Guardar</button>
                    </form>
                </div>
            </div>
        </section>

        <!-- Libros por mes -->
        <section class="st-card">
            <div class="st-card-head"><h2>📅 Libros leídos por mes</h2></div>
            <div class="grafico-contenedor"><canvas id="mesesChart"></canvas></div>
        </section>

        <!-- Top 5 por páginas -->
        <section class="st-card ancho">
            <div class="st-card-head">
                <h2>🏆 Top 5 libros por páginas</h2>
                <div class="filtro-pestañas">
                    <a href="<?= htmlspecialchars(urlEstadisticas(['periodo' => 'anio'], $periodoTop, $periodoNotas)) ?>" class="filtro-btn <?= $periodoTop === 'anio' ? 'activo' : 'inactivo' ?>">📅 <?= htmlspecialchars($year) ?></a>
                    <a href="<?= htmlspecialchars(urlEstadisticas(['periodo' => 'todos'], $periodoTop, $periodoNotas)) ?>" class="filtro-btn <?= $periodoTop === 'todos' ? 'activo' : 'inactivo' ?>">🌍 Todos</a>
                </div>
            </div>

            <div class="ranking-lista">
                <?php if (empty($topPaginas)): ?>
                    <p class="vacio">No hay libros leídos registrados en este período.</p>
                <?php else:
                    $maxPaginas = (!empty($topValores) && max($topValores) > 0) ? max($topValores) : 1;
                    foreach ($topPaginas as $index => $t):
                        $paginasLibro = (int)$t['paginas_reales'];
                        $porcentaje = $paginasLibro > 0 ? round(($paginasLibro / $maxPaginas) * 100) : 0;
                        $posicion = $index + 1;
                        $idLink = ($t['libro_id'] ?? '') !== '' ? $t['libro_id'] : $t['id'];
                ?>
                    <div class="ranking-card pos-<?= $posicion ?>">
                        <div class="ranking-portada-box">
                            <span aria-hidden="true">📖</span>
                            <img src="<?= htmlspecialchars($t['portada'] ?: 'img/sin_portada.png') ?>" alt="Portada de <?= htmlspecialchars($t['titulo']) ?>"
                                 class="ranking-portada" loading="lazy" onerror="this.style.display='none'">
                            <span class="ranking-badge">#<?= $posicion ?></span>
                        </div>
                        <div class="ranking-detalles">
                            <div class="ranking-top-info">
                                <strong class="ranking-titulo"><?= htmlspecialchars($t['titulo']) ?></strong>
                                <span class="ranking-paginas-tag"><?= $paginasLibro > 0 ? number_format($paginasLibro, 0, '', '.') . ' pág.' : 'Sin especificar' ?></span>
                            </div>
                            <div class="ranking-barra-wrapper"><div class="ranking-barra-fill" style="width: <?= $porcentaje ?>%;"></div></div>
                            <a href="libro.php?id=<?= urlencode((string)$idLink) ?>" class="ranking-link">Ver detalles →</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <!-- Estrellas -->
        <section class="st-card">
            <div class="st-card-head">
                <h2>⭐ Distribución por estrellas</h2>
                <div class="filtro-pestañas">
                    <a href="<?= htmlspecialchars(urlEstadisticas(['notas' => 'anio'], $periodoTop, $periodoNotas)) ?>" class="filtro-btn <?= $periodoNotas === 'anio' ? 'activo' : 'inactivo' ?>">📅 <?= htmlspecialchars($year) ?></a>
                    <a href="<?= htmlspecialchars(urlEstadisticas(['notas' => 'todos'], $periodoTop, $periodoNotas)) ?>" class="filtro-btn <?= $periodoNotas === 'todos' ? 'activo' : 'inactivo' ?>">🌍 Todos</a>
                </div>
            </div>

            <?php if ($totalDistribucion === 0): ?>
                <p class="vacio">Todavía no has puntuado ningún libro en este período.</p>
            <?php else: ?>
                <div class="estrellas-layout">
                    <div class="estrellas-media">
                        <span class="nota"><?= $promedioEstrellas ?></span>
                        <span class="estrellas-vis" style="--p: <?= $porcentajeRelleno ?>%;" aria-hidden="true">★★★★★</span>
                        <span class="sub"><?= (int)$notasMostradas['total'] ?> <?= (int)$notasMostradas['total'] === 1 ? 'libro puntuado' : 'libros puntuados' ?></span>
                    </div>
                    <div class="estrellas-filas">
                        <?php for ($n = 5; $n >= 1; $n--):
                            $cantidad = (int)$distribucionEstrellas["{$n} ★"];
                            $pctFila = round(($cantidad / $totalDistribucion) * 100);
                        ?>
                            <a class="estrella-fila <?= $cantidad === 0 ? 'vacia' : '' ?>" href="estadisticas_estrellas.php?valor=<?= $n ?>" title="Ver libros de <?= $n ?> estrellas">
                                <span class="num"><?= $n ?>★</span>
                                <span class="barra"><span class="relleno" style="display:block; width: <?= $pctFila ?>%;"></span></span>
                                <span class="cuenta"><?= $cantidad ?> · <?= $pctFila ?>%</span>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
                <p class="estrellas-pie">Nota más común: <strong><?= $estrellaComun ? $estrellaComun . ' ★' : '—' ?></strong> · Pulsa una fila para ver esos libros.</p>
            <?php endif; ?>
        </section>

        <!-- Autores más leídos -->
        <section class="st-card">
            <div class="st-card-head"><h2>✍️ Autores más leídos <?= htmlspecialchars($year) ?></h2></div>
            <?php if (empty($topAutores)): ?>
                <p class="vacio">Aún no hay autores registrados este año.</p>
            <?php else: ?>
                <div class="autores-lista">
                    <?php foreach ($topAutores as $a):
                        $pctAutor = round(($a['libros'] / $maxLibrosAutor) * 100);
                    ?>
                        <div class="autor-fila">
                            <span class="autor-nombre"><?= htmlspecialchars($a['nombre']) ?></span>
                            <span class="autor-num"><?= (int)$a['libros'] ?> <?= $a['libros'] === 1 ? 'libro' : 'libros' ?></span>
                            <div class="autor-barra"><div style="width: <?= $pctAutor ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Datos curiosos -->
        <section class="st-card ancho">
            <div class="st-card-head"><h2>💡 Datos curiosos de <?= htmlspecialchars($year) ?></h2></div>
            <?php if ($leidosEsteAño === 0): ?>
                <p class="vacio">Termina tu primer libro del año para ver tus datos curiosos.</p>
            <?php else: ?>
                <div class="curiosos-grid">
                    <div class="curioso">
                        <span class="c-icono">🐘</span>
                        <span class="c-etiqueta">Libro más largo</span>
                        <span class="c-valor"><?= $libroLargo ? number_format((int)$libroLargo['paginas_reales'], 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle"><?= $libroLargo ? htmlspecialchars($libroLargo['titulo']) : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-icono">🐜</span>
                        <span class="c-etiqueta">Libro más corto</span>
                        <span class="c-valor"><?= $libroCorto ? number_format((int)$libroCorto['paginas_reales'], 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle"><?= $libroCorto ? htmlspecialchars($libroCorto['titulo']) : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-icono">📏</span>
                        <span class="c-etiqueta">Media por libro</span>
                        <span class="c-valor"><?= $mediaPaginasLibro > 0 ? number_format($mediaPaginasLibro, 0, '', '.') . ' pág.' : '—' ?></span>
                        <span class="c-detalle">Páginas por libro leído</span>
                    </div>
                    <div class="curioso">
                        <span class="c-icono">🏅</span>
                        <span class="c-etiqueta">Mejor mes</span>
                        <span class="c-valor"><?= $mejorMes ? htmlspecialchars($mejorMes) : '—' ?></span>
                        <span class="c-detalle"><?= $maxMes > 0 ? $maxMes . ($maxMes === 1 ? ' libro terminado' : ' libros terminados') : 'Sin datos' ?></span>
                    </div>
                    <div class="curioso">
                        <span class="c-icono">⏳</span>
                        <span class="c-etiqueta">Tiempo por libro</span>
                        <span class="c-valor"><?= $diasMediosLibro > 0 ? $diasMediosLibro . ($diasMediosLibro === 1 ? ' día' : ' días') : '—' ?></span>
                        <span class="c-detalle">De media, de inicio a fin</span>
                    </div>
                    <div class="curioso">
                        <span class="c-icono">🔥</span>
                        <span class="c-etiqueta">Ritmo diario</span>
                        <span class="c-valor"><?= $paginasPorDia > 0 ? str_replace('.', ',', (string)$paginasPorDia) . ' pág.' : '—' ?></span>
                        <span class="c-detalle">Por día en lo que va de año</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

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
         <a href="biblioteca.php" class="nav-card-float">
            <span class="nav-icon">📚</span>
            <span>Mi estantería</span>
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
function toggleFormReto() {
    const el = document.getElementById("formRetoWrapper");
    el.style.display = (el.style.display === "block") ? "none" : "block";
}

function obtenerColorTema() {
    const estilos = getComputedStyle(document.documentElement);
    const variables = ['--primary-color', '--color-principal', '--accent-color', '--primary', '--color-primario', '--main-color'];
    for (let i = 0; i < variables.length; i++) {
        let val = estilos.getPropertyValue(variables[i]).trim();
        if (val && val !== 'black' && val !== '#000000') return val;
    }
    return '#d87d8a';
}

function colorToRgba(color, alpha) {
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.fillStyle = color;
    const hex = ctx.fillStyle;
    if (hex.startsWith('#')) {
        const r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }
    return color;
}

const colorTema = obtenerColorTema();
let colorTexto = getComputedStyle(document.documentElement).getPropertyValue('--text-color').trim() || '#666666';
const colorRejilla = 'rgba(128,128,128,0.15)';
const colorResto = 'rgba(128,128,128,0.18)';

new Chart(document.getElementById("objetivoChart"), {
    type: "doughnut",
    data: {
        labels: ["Completado", "Restante"],
        datasets: [{
            data: [<?= $porcentajeObjetivo ?>, <?= max(0, 100 - $porcentajeObjetivo) ?>],
            backgroundColor: [colorTema, colorResto],
            borderWidth: 0,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        rotation: -90, circumference: 180, cutout: "78%",
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});

const ctxMeses = document.getElementById("mesesChart").getContext("2d");
const gradient = ctxMeses.createLinearGradient(0, 0, 0, 220);
gradient.addColorStop(0, colorToRgba(colorTema, 0.35));
gradient.addColorStop(1, colorToRgba(colorTema, 0.0));

new Chart(ctxMeses, {
    type: "line",
    data: {
        labels: <?= json_encode($mesesLabels) ?>,
        datasets: [{
            label: "Libros",
            data: <?= json_encode($mesesValores) ?>,
            borderColor: colorTema, backgroundColor: gradient, fill: true, tension: 0.4,
            pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: "#ffffff",
            pointBorderColor: colorTema, pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: colorTexto, font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: colorTexto, font: { size: 11 } }, grid: { color: colorRejilla } }
        }
    }
});
</script>

</body>
</html>
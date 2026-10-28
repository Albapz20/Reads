<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/PaginasService.php";

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');   
@set_time_limit(60);

function salir(array $d, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// Páginas que ya se conocen para el mismo libro.
function paginasConocidas(PDO $pdo, array $l): int {
    $cond = [];
    $par = [$l['id']];
    $ref = trim((string)($l['libro_id'] ?? ''));
    if ($ref !== '') { $cond[] = 'libro_id = ?'; $par[] = $ref; }
    $tit = trim((string)($l['titulo'] ?? ''));
    $aut = trim((string)($l['autores'] ?? ''));
    if ($tit !== '' && $aut !== '') { $cond[] = '(titulo = ? AND autores = ?)'; $par[] = $tit; $par[] = $aut; }
    if (!$cond) return 0;

    $st = $pdo->prepare("SELECT paginas_totales FROM listas_lectura
                         WHERE id <> ? AND paginas_totales > 0 AND paginas_totales <> 300
                           AND (" . implode(' OR ', $cond) . ")
                         GROUP BY paginas_totales
                         ORDER BY COUNT(*) DESC, paginas_totales ASC LIMIT 1");
    $st->execute($par);
    return (int)$st->fetchColumn();
}

$usuario = Auth::usuario();
if (!$usuario) salir(['ok' => false, 'error' => 'Sesión caducada'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false, 'error' => 'Método no permitido'], 405);

if (session_status() === PHP_SESSION_NONE) @session_start();
$csrf = (string)($_SESSION['csrf_progreso'] ?? '');
$comprobados = is_array($_SESSION['paginas_revisadas'] ?? null) ? $_SESSION['paginas_revisadas'] : [];
@session_write_close();   // libera la sesión durante las búsquedas lentas

if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) salir(['ok' => false, 'error' => 'Petición no válida'], 403);

$db = new Database();
$uid = (int)$usuario['id'];
$incluir300 = !empty($_POST['incluir300']);

$cond = "(paginas_totales IS NULL OR paginas_totales = 0" . ($incluir300 ? " OR paginas_totales = 300" : "") . ")";
$st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, paginas_totales
                         FROM listas_lectura
                         WHERE usuario_id = ? AND estado = 'leido' AND {$cond}
                         ORDER BY (paginas_totales = 300) ASC, id DESC");
$st->execute([$uid]);
$candidatos = $st->fetchAll(PDO::FETCH_ASSOC);

$pendientesLista = array_values(array_filter($candidatos, function ($l) use ($comprobados) {
    return !isset($comprobados[$l['id']]) || (time() - $comprobados[$l['id']]) >= 6 * 3600;
}));

$lote = array_slice($pendientesLista, 0, 2);
$actualizados = 0;
$marcas = [];
$aviso = '';

foreach ($lote as $l) {
    $log = [];
    $conocidas = paginasConocidas($db->pdo, $l);
    if ($conocidas > 0) {
        $res = ['paginas' => $conocidas, 'fiabilidad' => 'cache'];
    } else {
        $res = PaginasService::buscar($l, $log);
    }
    $nuevo = (int)$res['paginas'];
    $actual = (int)($l['paginas_totales'] ?? 0);

    // Fallo de red/servidor o cuota de Google agotada: NO se da el libro por comprobado (se reintenta en 10 min)
    $temporal = !empty($log['fallo_temporal']) || str_contains((string)($log['aviso_google'] ?? ''), '429');
    if (!empty($log['aviso_google']) && str_contains((string)$log['aviso_google'], '429')) {
        $aviso = 'Google ha agotado la cuota de la clave por hoy.';
    }
    if ($nuevo > 0 || !$temporal) {
        $marcas[$l['id']] = time();
    } else {
        $marcas[$l['id']] = time() - 6 * 3600 + 600;
    }

    if ($nuevo <= 0 || $nuevo === $actual) continue;

    if ($actual === 300 && !in_array($res['fiabilidad'], ['exacta', 'catalogo', 'cache'], true)) continue;

    $db->pdo->prepare("UPDATE listas_lectura SET paginas_totales = ?, paginas_leidas = ?, progreso = 100 WHERE id = ? AND usuario_id = ?")
            ->execute([$nuevo, $nuevo, $l['id'], $uid]);
    $actualizados++;
}

// Se apuntan los libros ya comprobados
if ($marcas) {
    @session_start();
    if (!isset($_SESSION['paginas_revisadas']) || !is_array($_SESSION['paginas_revisadas'])) $_SESSION['paginas_revisadas'] = [];
    foreach ($marcas as $idL => $t) $_SESSION['paginas_revisadas'][$idL] = $t;
    @session_write_close();
}

salir([
    'ok'           => true,
    'procesados'   => count($lote),
    'actualizados' => $actualizados,
    'pendientes'   => max(0, count($pendientesLista) - count($lote)),
    'aviso'        => $aviso,
]);
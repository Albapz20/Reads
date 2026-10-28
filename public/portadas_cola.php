<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/PortadasVerificadas.php";
require_once "../src/PortadasService.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$usuario = Auth::usuario();
if (!$usuario) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();

@set_time_limit(90);
$db  = new Database();
$uid = (int)$usuario['id'];

$esAdmin = in_array($uid, array_map('intval', (array)config('admin_ids', [])), true)
        || (!empty($usuario['email']) && in_array(strtolower($usuario['email']), array_map('strtolower', (array)config('admin_emails', [])), true));

// Memoria de la sesión 
$caducar = function (array $a): array { foreach ($a as $k => $t) { if (time() - $t > 6 * 3600) unset($a[$k]); } return $a; };
$fallidas = $caducar($_SESSION['cola_fallidas'] ?? []);   // buscados por título y sin éxito
$directo  = $caducar($_SESSION['cola_directo'] ?? []);    // ya probados por descarga directa

$st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores FROM listas_lectura
                         WHERE usuario_id = ?
                           AND (portada IS NULL OR portada = '' OR portada LIKE '%default%' OR portada LIKE '%placehold%')
                         ORDER BY id LIMIT 500");
$st->execute([$uid]);
$pendientes = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($l) => !isset($fallidas[(int)$l['id']])));

$resp = ['nuevas' => [], 'pendientes' => count($pendientes), 'pausa' => false, 'rapida' => false, 'admin' => $esAdmin];

$guardar = function (int $id, string $bytes) use ($db, $uid): ?string {
    $nombre = 'portada_' . $id . '_' . time() . '.jpg';   
    if (!PortadasService::guardarBytes($bytes, __DIR__ . '/uploads/portadas/' . $nombre)) return null;
    $ruta = 'uploads/portadas/' . $nombre;
    $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([$ruta, $id, $uid]);
    return $ruta;
};

// fase rapida
$paraDirecto = array_values(array_filter($pendientes, fn($l) =>
    !isset($directo[(int)$l['id']]) && PortadasVerificadas::urlsDirectas((string)($l['libro_id'] ?? ''))));

if ($paraDirecto) {
    $lote = array_slice($paraDirecto, 0, 15);
    $halladas = PortadasVerificadas::resolverDirectos($lote);

    foreach ($lote as $l) {
        $id = (int)$l['id'];
        $directo[$id] = time();
        if (isset($halladas[$id]) && ($ruta = $guardar($id, $halladas[$id]['bytes']))) {
            $resp['nuevas'][] = ['id' => $id, 'portada' => $ruta];
            $resp['pendientes']--;
        }
    }
    $resp['rapida'] = count($paraDirecto) > count($lote);   // ¿quedan más por la vía rápida?
    $_SESSION['cola_directo'] = $directo;
    $resp['pendientes'] = max(0, $resp['pendientes']);
    echo json_encode($resp);
    exit;
}

// busqueda por titulo y autor
if (!empty($_SESSION['cola_pausa_hasta']) && time() < $_SESSION['cola_pausa_hasta']) {
    $resp['pausa'] = true;
    echo json_encode($resp);
    exit;
}

foreach (array_slice($pendientes, 0, 2) as $l) {
    PortadasVerificadas::$limitado = 0;
    $id   = (int)$l['id'];
    $isbn = PortadasVerificadas::esIsbn((string)($l['libro_id'] ?? '')) ? (string)$l['libro_id'] : '';
    $ruta = PortadasService::obtenerPortada($l['titulo'], $l['autores'] ?? '', $id, $isbn);

    if ($ruta) {
        $nuevo = 'uploads/portadas/portada_' . $id . '_' . time() . '.jpg';
        if (@rename(__DIR__ . '/' . $ruta, __DIR__ . '/' . $nuevo)) $ruta = $nuevo;
        $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([$ruta, $id, $uid]);
        $resp['nuevas'][] = ['id' => $id, 'portada' => $ruta];
        $resp['pendientes']--;
    } elseif (PortadasVerificadas::$limitado === 0) {
        $fallidas[$id] = time();   
        $resp['pendientes']--;
    }

    if (PortadasVerificadas::$limitado > 0) { 
        $_SESSION['cola_pausa_hasta'] = time() + 300;
        $resp['pausa'] = true;
        break;
    }
}

$_SESSION['cola_fallidas'] = $fallidas;
$resp['pendientes'] = max(0, $resp['pendientes']);
echo json_encode($resp);
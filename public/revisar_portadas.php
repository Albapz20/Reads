<?php

require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/PortadasVerificadas.php";
require_once "../src/PortadasService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();

@set_time_limit(120);
const LOTE = 3;                                        
const PORTADA_DEFECTO = '/Reads/img/default_cover.jpg';
const DIF_MISMA = 12;                                  
const DIF_MUY_DISTINTA = 26;                           

$db  = new Database();
$uid = (int)$usuario['id'];
$datos = (new UserService())->obtenerUsuarioPorId($uid);
$tema  = strtolower(trim($datos['tema_visual'] ?? 'pastel'));

$textoMotivo = [
    'apagado'         => 'Desmarcaste la revisión de imágenes guardadas en tu servidor.',
    'ruta'            => 'La ruta de la imagen no empieza por «uploads/», así que no sé dónde está el archivo.',
    'sin_gd'          => 'A PHP le falta la extensión GD, necesaria para comparar imágenes (en XAMPP: quita el «;» de la línea extension=gd en php.ini y reinicia Apache).',
    'sin_candidata'   => 'No encontré ninguna portada fiable de ese libro (título + autor) en Open Library, Apple ni Google. Suele ser un libro poco conocido.',
    'error_red'       => 'Fallaron las consultas (límite de Google, sin conexión…). Repite la revisión más tarde.',
    'sin_descarga'    => 'No pude descargar la portada de referencia.',
    'imagen_ilegible' => 'No pude leer una de las dos imágenes.',
];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function esGenerica(string $p): bool {
    $p = trim($p);
    return $p === '' || strtolower($p) === 'sin portada' || stripos($p, 'default') !== false || stripos($p, 'placehold') !== false;
}

function propuesta(array $l, string $estado, string $detalle, ?array $cand, string $def): array {
    return [
        'id' => (int)$l['id'], 'titulo' => $l['titulo'], 'autores' => $l['autores'] ?? '',
        'actual' => $l['portada'], 'estado' => $estado, 'detalle' => $detalle, 'def' => $def,
        'nueva' => $cand['url'] ?? '', 'fuente' => $cand['fuente'] ?? '',
        'enc_titulo' => $cand['titulo_enc'] ?? '', 'enc_autores' => $cand['autores_enc'] ?? '',
        'autor_ok' => $cand['autor_ok'] ?? null,
    ];
}

/** Descarga la portada elegida a tu servidor (con nombre nuevo para saltarse la caché del navegador) */
function guardarPortadaNueva(int $id, string $url): string {
    $nombre = 'portada_' . $id . '_' . time() . '.jpg';
    if (PortadasService::descargarImagenValidada($url, __DIR__ . '/uploads/portadas/' . $nombre)) {
        return 'uploads/portadas/' . $nombre;
    }
    return $url;   // si no se pudo descargar, se guarda el enlace directo
}

function borrarArchivoLocal(string $ruta): void {
    if (strpos($ruta, 'uploads/portadas/') === 0) {
        $f = __DIR__ . '/' . $ruta;
        if (is_file($f)) @unlink($f);
    }
}

/** Guarda una imagen subida desde el formulario; devuelve la ruta relativa o null */
function guardarSubida(int $id, array $f): ?string {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 5 * 1024 * 1024) return null;
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[0] < 80 || $info[1] < 120) return null;
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2]] ?? null;
    if (!$ext) return null;

    $dir = __DIR__ . '/uploads/portadas/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $nombre = 'portada_' . $id . '_' . time() . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], $dir . $nombre) ? 'uploads/portadas/' . $nombre : null;
}


function clasificar(array $l, bool $locales): array {
    $titulo  = (string)$l['titulo'];
    $autores = (string)($l['autores'] ?? '');
    $portada = trim((string)$l['portada']);
    $isbn    = PortadasVerificadas::esIsbn((string)($l['libro_id'] ?? '')) ? (string)$l['libro_id'] : '';
    $esWeb   = (bool)preg_match('~^https?://~i', $portada);

    //  Sin portada 
    if (esGenerica($portada)) {
        $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
        return ['propuesta' => propuesta($l, 'sin_portada', 'No tiene portada', $cand, $cand ? 'usar' : 'dejar')];
    }

    //  Enlace a Google / Open Library: se mira de qué libro es 
    if ($esWeb) {
        $act = PortadasVerificadas::verificarActual($portada, $titulo, $autores);
        if ($act['estado'] === 'ok') return ['ok' => true];

        $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
        if ($cand && $cand['url'] === $portada) return ['ok' => true];

        $def = 'dejar';
        if ($act['estado'] === 'incorrecta') $def = $cand ? 'usar' : 'quitar';
        return ['propuesta' => propuesta($l, $act['estado'], $act['detalle'], $cand, $def)];
    }

    //  Imagen guardada en tu servidor 
    if (!$locales) return ['motivo' => 'apagado'];
    if (strpos($portada, 'uploads/') !== 0) return ['motivo' => 'ruta'];

    $ruta = __DIR__ . '/' . $portada;
    if (!is_file($ruta)) {
        $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
        return ['propuesta' => propuesta($l, 'sin_portada', 'El archivo de la portada ya no existe', $cand, $cand ? 'usar' : 'dejar')];
    }
    if (!function_exists('imagecreatefromstring')) return ['sinref' => 'sin_gd'];   // sin GD no se pueden comparar, pero sí revisar a ojo

    $erroresAntes = PortadasVerificadas::$errores;
    $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
    if (!$cand) return ['sinref' => (PortadasVerificadas::$errores > $erroresAntes) ? 'error_red' : 'sin_candidata'];

    $bytes = PortadasVerificadas::descargar($cand['url']);
    if (!$bytes) return ['sinref' => 'sin_descarga'];

    $hCand = PortadasVerificadas::huella($bytes);
    $hLoc  = PortadasVerificadas::huella((string)file_get_contents($ruta));
    if ($hCand === null || $hLoc === null) return ['sinref' => 'imagen_ilegible'];

    $dif = PortadasVerificadas::distancia($hLoc, $hCand);
    if ($dif <= DIF_MISMA) return ['ok' => true];

    $segura = ($cand['autor_ok'] === true && $cand['titulo'] === 100 && $dif >= DIF_MUY_DISTINTA);
    return ['propuesta' => propuesta(
        $l, 'distinta',
        'No se parece a la portada de «' . $cand['titulo_enc'] . '» (diferencia ' . $dif . '/64). Puede ser otra edición.',
        $cand, $segura ? 'usar' : 'dejar'
    )];
}

function sumarMotivo(string $m): void {
    $_SESSION['rev_stats']['motivos'][$m] = ($_SESSION['rev_stats']['motivos'][$m] ?? 0) + 1;
}

/*  Aplicar cambios  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'aplicar') {
    $propuestas = $_SESSION['rev_portadas'] ?? [];
    $sinref     = $_SESSION['rev_sinref'] ?? [];
    $cambiadas = 0;

    foreach (($_POST['accion_libro'] ?? []) as $id => $acc) {
        $id = (int)$id;
        if (!isset($propuestas[$id])) continue;

        $nueva = null;
        if ($acc === 'usar' && !empty($propuestas[$id]['nueva'])) $nueva = guardarPortadaNueva($id, $propuestas[$id]['nueva']);
        if ($acc === 'quitar') $nueva = PORTADA_DEFECTO;
        if ($nueva === null) continue;

        $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([$nueva, $id, $uid]);
        if ($vieja = (string)$propuestas[$id]['actual']) { if ($vieja !== $nueva) borrarArchivoLocal($vieja); }

        unset($_SESSION['rev_portadas'][$id]);
        $cambiadas++;
    }

    foreach (array_keys($_POST['quitar_sinref'] ?? []) as $id) {
        $id = (int)$id;
        if (!isset($sinref[$id])) continue;

        $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([PORTADA_DEFECTO, $id, $uid]);
        borrarArchivoLocal((string)$sinref[$id]['actual']);

        unset($_SESSION['rev_sinref'][$id]);
        $cambiadas++;
    }

    $_SESSION['rev_mensaje'] = "Se han actualizado $cambiadas portadas.";
    header("Location: revisar_portadas.php?paso=resultados");
    exit;
}

/*  Poner una portada elegida a mano  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'poner_portada') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->pdo->prepare("SELECT id, portada FROM listas_lectura WHERE id = ? AND usuario_id = ?");
    $st->execute([$id, $uid]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);

    $nueva = null;
    if ($fila) {
        $via = $_POST['via'] ?? '';
        if ($via === 'candidata') {
            $c = $_SESSION['rev_cands'][$id][(int)($_POST['indice'] ?? -1)] ?? null;
            if ($c) $nueva = guardarPortadaNueva($id, $c['url']);
        } elseif ($via === 'url') {
            $url = trim((string)($_POST['url'] ?? ''));
            if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) $nueva = guardarPortadaNueva($id, $url);
        } elseif ($via === 'subida' && isset($_FILES['imagen'])) {
            $nueva = guardarSubida($id, $_FILES['imagen']);
        }
    }

    if ($nueva) {
        $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([$nueva, $id, $uid]);
        if ((string)$fila['portada'] !== $nueva) borrarArchivoLocal((string)$fila['portada']);
        $_SESSION['rev_mensaje'] = 'Portada guardada.';
        header("Location: revisar_portadas.php?paso=pendientes");
    } else {
        $_SESSION['rev_mensaje'] = 'No he podido guardar esa imagen (comprueba que sea un enlace directo a una imagen, o que el archivo sea JPG/PNG/WEBP de menos de 5 MB).';
        header("Location: revisar_portadas.php?paso=elegir&id=" . $id);
    }
    exit;
}

$paso = $_GET['paso'] ?? '';
$refresco = '';       
$diag = null;

/*  Analizar por tandas  */
if ($paso === 'analizar') {
    $desde = max(0, (int)($_GET['desde'] ?? 0));
    if ($desde === 0) {
        $_SESSION['rev_portadas'] = [];
        $_SESSION['rev_sinref']   = [];
        $_SESSION['rev_stats']    = ['ok' => 0, 'limite' => 0, 'motivos' => []];
        $_SESSION['rev_mensaje']  = '';
        $_SESSION['rev_locales']  = !empty($_GET['locales']);
    }
    $_SESSION['rev_pausa'] = null;
    $locales = $_SESSION['rev_locales'] ?? true;

    $st = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?");
    $st->execute([$uid]);
    $total = (int)$st->fetchColumn();
    $_SESSION['rev_total'] = $total;

    $st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, portada FROM listas_lectura
                             WHERE usuario_id = ? ORDER BY id LIMIT " . LOTE . " OFFSET " . $desde);
    $st->execute([$uid]);

    $pausado = false;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $l) {
        $limiteAntes = PortadasVerificadas::$limitado;
        $res = clasificar($l, $locales);

        // Si un servidor nos ha cortado y el libro se ha quedado sin comprobar, paramos y se retoma después
        if (isset($res['sinref']) && PortadasVerificadas::$limitado > $limiteAntes) {
            $_SESSION['rev_pausa'] = $desde + $i;
            $pausado = true;
            break;
        }

        $id = (int)$l['id'];
        if (!empty($res['ok'])) {
            $_SESSION['rev_stats']['ok']++;
        } elseif (isset($res['propuesta'])) {
            $_SESSION['rev_portadas'][$id] = $res['propuesta'];
        } elseif (isset($res['sinref'])) {
            sumarMotivo($res['sinref']);
            $_SESSION['rev_sinref'][$id] = [
                'id' => $id, 'titulo' => $l['titulo'], 'autores' => $l['autores'] ?? '',
                'actual' => $l['portada'], 'motivo' => $res['sinref'],
            ];
        } elseif (isset($res['motivo'])) {
            sumarMotivo($res['motivo']);
        }
        usleep(300000);
    }
    $_SESSION['rev_stats']['limite'] += PortadasVerificadas::$limitado;

    $siguiente = $desde + LOTE;
    if ($pausado || $siguiente >= $total) { header("Location: revisar_portadas.php?paso=resultados"); exit; }
    $refresco = "revisar_portadas.php?paso=analizar&desde=" . $siguiente;
}

/*  Rellenar las que están sin portada (búsqueda estricta)  */
if ($paso === 'rellenar') {
    $ultimo = max(0, (int)($_GET['ultimo'] ?? 0));
    if ($ultimo === 0) $_SESSION['rev_relleno'] = ['hechas' => 0, 'fallidas' => 0];

    $st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores FROM listas_lectura
                             WHERE usuario_id = ? AND id > ?
                               AND (portada IS NULL OR portada = '' OR portada LIKE '%default%' OR portada LIKE '%placehold%')
                             ORDER BY id LIMIT 2");
    $st->execute([$uid, $ultimo]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filas as $l) {
        $isbn = PortadasVerificadas::esIsbn((string)($l['libro_id'] ?? '')) ? (string)$l['libro_id'] : '';
        $ruta = PortadasService::obtenerPortada($l['titulo'], $l['autores'] ?? '', (int)$l['id'], $isbn);

        if ($ruta) {
            $nuevo = 'uploads/portadas/portada_' . (int)$l['id'] . '_' . time() . '.jpg';   // nombre nuevo: sin caché
            if (@rename(__DIR__ . '/' . $ruta, __DIR__ . '/' . $nuevo)) $ruta = $nuevo;
            $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")->execute([$ruta, (int)$l['id'], $uid]);
            $_SESSION['rev_relleno']['hechas']++;
        } else {
            $_SESSION['rev_relleno']['fallidas']++;
        }
        $ultimo = (int)$l['id'];
    }

    if (count($filas) < 2) {
        $r = $_SESSION['rev_relleno'];
        $_SESSION['rev_mensaje'] = "Rellenadas {$r['hechas']} portadas; en {$r['fallidas']} no encontré ninguna fiable: pulsa «Elegir a mano» para esos.";
        header("Location: revisar_portadas.php?paso=resultados");
        exit;
    }
    $refresco = "revisar_portadas.php?paso=rellenar&ultimo=" . $ultimo;
}

/*  Diagnóstico de un libro  */
if ($paso === 'probar') {
    $idp = (int)($_GET['id'] ?? 0);
    $st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, portada FROM listas_lectura WHERE id = ? AND usuario_id = ?");
    $st->execute([$idp, $uid]);
    $l = $st->fetch(PDO::FETCH_ASSOC);

    if (!$l) {
        $diag = ['error' => 'No encuentro un libro tuyo con ese id (es el número de la columna id de listas_lectura).'];
    } else {
        $p = trim((string)$l['portada']);
        $diag = [
            'libro' => $l['titulo'] . ' — ' . ($l['autores'] ?? ''),
            'portada_guardada' => $p,
            'tipo' => esGenerica($p) ? 'sin portada' : (preg_match('~^https?://~i', $p) ? 'enlace web' : 'archivo en tu servidor'),
            'archivo_existe' => (!preg_match('~^https?://~i', $p) && $p !== '') ? (is_file(__DIR__ . '/' . $p) ? 'sí (' . filesize(__DIR__ . '/' . $p) . ' bytes)' : 'NO') : '—',
            'extension_GD' => function_exists('imagecreatefromstring') ? 'sí' : 'NO',
            'clave_google' => (function_exists('config') && config('google_books_key', '') !== '') ? 'sí' : 'NO (Google limitará enseguida)',
        ];
        $isbn = PortadasVerificadas::esIsbn((string)($l['libro_id'] ?? '')) ? (string)$l['libro_id'] : '';
        $diag['isbn_usado'] = $isbn ?: '—';
        $diag['candidata'] = PortadasVerificadas::buscar((string)$l['titulo'], (string)($l['autores'] ?? ''), $isbn) ?? 'ninguna fiable';
        $diag['consultas_fallidas'] = PortadasVerificadas::$errores;
        $diag['consultas_con_limite_429'] = PortadasVerificadas::$limitado;
        $diag['resultado'] = clasificar($l, true);
    }
}

/*  Libros sin portada y elegir a mano  */
$msg = '';
$pendientes = [];
$libroElegir = null;
$cands = [];
if ($paso === 'pendientes' || $paso === 'elegir') {
    $msg = (string)($_SESSION['rev_mensaje'] ?? '');
    $_SESSION['rev_mensaje'] = '';
}
if ($paso === 'pendientes') {
    $st = $db->pdo->prepare("SELECT id, titulo, autores FROM listas_lectura
                             WHERE usuario_id = ? AND (portada IS NULL OR portada = '' OR portada LIKE '%default%' OR portada LIKE '%placehold%')
                             ORDER BY id");
    $st->execute([$uid]);
    $pendientes = $st->fetchAll(PDO::FETCH_ASSOC);
}
if ($paso === 'elegir') {
    $idE = (int)($_GET['id'] ?? 0);
    $st = $db->pdo->prepare("SELECT id, titulo, autores FROM listas_lectura WHERE id = ? AND usuario_id = ?");
    $st->execute([$idE, $uid]);
    $libroElegir = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($libroElegir) {
        $cands = PortadasVerificadas::candidatos((string)$libroElegir['titulo'], (string)($libroElegir['autores'] ?? ''));
        $_SESSION['rev_cands'][$idE] = $cands;
    }
}

/* Datos para las vistas  */
$propuestas = $_SESSION['rev_portadas'] ?? [];
$sinref     = $_SESSION['rev_sinref'] ?? [];
$stats      = $_SESSION['rev_stats'] ?? ['ok' => 0, 'limite' => 0, 'motivos' => []];
$pausa      = $_SESSION['rev_pausa'] ?? null;

if ($paso === 'resultados') {
    $orden = ['incorrecta' => 0, 'distinta' => 1, 'sin_portada' => 2, 'desconocida' => 3];
    uasort($propuestas, fn($a, $b) => ($orden[$a['estado']] ?? 9) <=> ($orden[$b['estado']] ?? 9));

    $st = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?
                             AND (portada IS NULL OR portada = '' OR portada LIKE '%default%' OR portada LIKE '%placehold%')");
    $st->execute([$uid]);
    $sinPortadaAhora = (int)$st->fetchColumn();
}

$etiquetas = [
    'incorrecta'  => ['❌', 'Portada de otro libro'],
    'distinta'    => ['🔀', 'Parece otra portada'],
    'sin_portada' => ['🕳️', 'Sin portada'],
    'desconocida' => ['❔', 'No verificable'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Revisar portadas</title>
<link rel="stylesheet" href="/Reads/temas/<?= h($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= @filemtime(__DIR__ . '/css/styles.css') ?>">
<?php if ($refresco !== ''): ?><meta http-equiv="refresh" content="1;url=<?= h($refresco) ?>"><?php endif; ?>
<style>
    .rp-wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 90px; }
    .rp-card { background: var(--bg-card, #fff); border: 1px solid var(--border-color, #ddd); border-radius: 16px; padding: 22px; margin-bottom: 16px; }
    .rp-btn { display: inline-block; padding: 11px 22px; border-radius: 12px; background: var(--primary-color, #d87d8a); color: var(--primary-contrast, #fff); text-decoration: none; font-weight: 700; border: none; cursor: pointer; font-size: 1rem; }
    .rp-btn.sec { background: var(--bg-chip, #eee); color: var(--text-color, #333); border: 1px solid var(--border-color, #ccc); }
    .rp-bar { height: 12px; border-radius: 12px; background: var(--tint, rgba(0,0,0,.08)); overflow: hidden; margin: 14px 0; }
    .rp-bar > div { height: 100%; background: var(--primary-color, #d87d8a); }
    .rp-aviso { padding: 12px 14px; border-radius: 12px; background: var(--primary-soft, rgba(128,128,128,.15)); margin-bottom: 14px; }
    .rp-fila { display: grid; grid-template-columns: 110px 110px 1fr; gap: 16px; align-items: start; }
    .rp-img { text-align: center; font-size: .72rem; opacity: .85; }
    .rp-img img { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 8px; background: var(--bg-chip, #eee); box-shadow: 0 3px 8px rgba(0,0,0,.2); display: block; margin-bottom: 4px; }
    .rp-vacia { width: 100%; aspect-ratio: 2/3; border-radius: 8px; border: 2px dashed var(--border-color, #ccc); display: flex; align-items: center; justify-content: center; padding: 6px; box-sizing: border-box; margin-bottom: 4px; }
    .rp-info h3 { margin: 0 0 2px; font-size: 1.05rem; }
    .rp-info p { margin: 0 0 6px; font-size: .85rem; opacity: .85; }
    .rp-tag { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: .78rem; font-weight: 700; background: var(--tint, rgba(0,0,0,.08)); margin-bottom: 8px; }
    .rp-info select { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border-color, #ccc); font: inherit; font-size: .9rem; max-width: 100%; }
    .rp-motivos { margin: 10px 0 0; padding-left: 20px; font-size: .88rem; }
    .rp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 14px; margin-top: 14px; }
    .rp-tile { display: block; cursor: pointer; font-size: .75rem; }
    .rp-tile img { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 8px; background: var(--bg-chip, #eee); box-shadow: 0 3px 8px rgba(0,0,0,.2); display: block; margin-bottom: 6px; }
    .rp-tile .t { display: block; font-weight: 700; line-height: 1.2; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .rp-tile .a { display: block; opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rp-tile .c { display: block; margin-top: 4px; font-weight: 700; color: var(--danger, #c0392b); }
    .rp-sticky { position: sticky; bottom: 14px; text-align: center; padding: 10px; }
    .rp-pre { white-space: pre-wrap; word-break: break-word; font-size: .8rem; background: var(--bg-chip, #eee); padding: 12px; border-radius: 10px; }
    @media (max-width: 620px) { .rp-fila { grid-template-columns: 90px 90px 1fr; gap: 10px; } }
</style>
</head>
<body class="page-revisar">
<div class="rp-wrap">
    <h1>🖼️ Revisar portadas</h1>

<?php if ($paso === 'analizar'): ?>
    <?php $hecho = min($siguiente, $total); $pct = $total > 0 ? round($hecho / $total * 100) : 100; ?>
    <div class="rp-card">
        <h2>Comprobando tus libros…</h2>
        <div class="rp-bar"><div style="width: <?= $pct ?>%"></div></div>
        <p><strong><?= $hecho ?></strong> de <strong><?= $total ?></strong> revisados · <?= count($propuestas) ?> con problemas · <?= count($sinref) ?> sin poder comprobar</p>
        <p style="opacity:.7">No cierres esta pestaña: avanza sola de <?= LOTE ?> en <?= LOTE ?> libros.</p>
    </div>

<?php elseif ($paso === 'rellenar'): ?>
    <div class="rp-card">
        <h2>Buscando portadas fiables para los libros sin portada…</h2>
        <p>Vistos hasta ahora: <strong><?= (int)($_SESSION['rev_relleno']['hechas'] ?? 0) ?></strong> rellenados · <?= (int)($_SESSION['rev_relleno']['fallidas'] ?? 0) ?> sin encontrar.</p>
        <p style="opacity:.7">No cierres esta pestaña.</p>
    </div>

<?php elseif ($paso === 'pendientes'): ?>
    <?php if ($msg !== ''): ?><div class="rp-aviso">✅ <?= h($msg) ?></div><?php endif; ?>
    <div class="rp-card">
        <h2>Libros sin portada (<?= count($pendientes) ?>)</h2>
        <?php if (empty($pendientes)): ?>
            <p>🎉 Todos tus libros tienen portada.</p>
        <?php else: ?>
            <p>Para cada uno te enseño las portadas que existen de ese autor y eliges la correcta, o pegas el enlace de una imagen, o subes la tuya.</p>
            <?php foreach ($pendientes as $b): ?>
                <p><strong><?= h($b['titulo']) ?></strong> — <?= h($b['autores']) ?>
                    <a class="rp-btn sec" style="padding:6px 14px; font-size:.85rem; margin-left:8px" href="revisar_portadas.php?paso=elegir&id=<?= (int)$b['id'] ?>">Elegir portada</a></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <p><a href="revisar_portadas.php?paso=resultados">← Volver a los resultados</a> · <a href="perfil.php">Mi perfil</a></p>
    </div>

<?php elseif ($paso === 'elegir'): ?>
    <?php if ($msg !== ''): ?><div class="rp-aviso">⚠️ <?= h($msg) ?></div><?php endif; ?>
    <?php if (!$libroElegir): ?>
        <div class="rp-card"><p>No encuentro ese libro. <a href="revisar_portadas.php?paso=pendientes">Volver</a></p></div>
    <?php else:
        $busq = urlencode($libroElegir['titulo'] . ' ' . ($libroElegir['autores'] ?? ''));
    ?>
        <div class="rp-card">
            <h2><?= h($libroElegir['titulo']) ?></h2>
            <p><?= h($libroElegir['autores']) ?></p>
            <p>Buscar la portada por tu cuenta:
                <a href="https://www.google.com/search?tbm=isch&q=<?= $busq ?>+portada" target="_blank" rel="noopener noreferrer">Google Imágenes</a> ·
                <a href="https://www.casadellibro.com/busqueda-libros?busqueda=<?= $busq ?>" target="_blank" rel="noopener noreferrer">Casa del Libro</a> ·
                <a href="https://www.amazon.es/s?k=<?= $busq ?>&i=stripbooks" target="_blank" rel="noopener noreferrer">Amazon</a>
            </p>
        </div>

        <div class="rp-card">
            <h3>Portadas que he encontrado (<?= count($cands) ?>)</h3>
            <?php if (empty($cands)): ?>
                <p>No he encontrado ninguna portada de ese autor en Open Library, Apple ni Google. Usa una de las dos opciones de abajo.</p>
            <?php else: ?>
                <p>Están ordenadas por parecido con el título. Pulsa «Usar esta» en la correcta.</p>
                <div class="rp-grid">
                    <?php foreach ($cands as $i => $c): ?>
                        <form method="POST" class="rp-tile">
                            <input type="hidden" name="accion" value="poner_portada">
                            <input type="hidden" name="via" value="candidata">
                            <input type="hidden" name="id" value="<?= (int)$libroElegir['id'] ?>">
                            <input type="hidden" name="indice" value="<?= (int)$i ?>">
                            <img src="<?= h($c['url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                            <span class="t">«<?= h($c['titulo_enc']) ?>»</span>
                            <span class="a"><?= h($c['fuente']) ?> · <?= h($c['autores_enc']) ?></span>
                            <button type="submit" style="margin-top:6px; width:100%">Usar esta</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="rp-card">
            <h3>Pegar el enlace de una imagen</h3>
            <p style="opacity:.75">En Google Imágenes: clic derecho sobre la portada → «Copiar dirección de la imagen».</p>
            <form method="POST">
                <input type="hidden" name="accion" value="poner_portada">
                <input type="hidden" name="via" value="url">
                <input type="hidden" name="id" value="<?= (int)$libroElegir['id'] ?>">
                <input type="url" name="url" required placeholder="https://…" style="width:100%; padding:10px; box-sizing:border-box; margin-bottom:8px">
                <button type="submit" class="rp-btn">Usar esta imagen</button>
            </form>
        </div>

        <div class="rp-card">
            <h3>Subir una imagen desde tu ordenador</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="poner_portada">
                <input type="hidden" name="via" value="subida">
                <input type="hidden" name="id" value="<?= (int)$libroElegir['id'] ?>">
                <input type="file" name="imagen" accept="image/*" required style="margin-bottom:8px">
                <button type="submit" class="rp-btn">Subir</button>
            </form>
        </div>
        <p><a href="revisar_portadas.php?paso=pendientes">← Volver a la lista</a></p>
    <?php endif; ?>

<?php elseif ($paso === 'probar'): ?>
    <div class="rp-card">
        <h2>Diagnóstico de un libro</h2>
        <div class="rp-pre"><?= h(print_r($diag, true)) ?></div>
        <p><a href="revisar_portadas.php?paso=resultados">← Volver a los resultados</a></p>
    </div>

<?php elseif ($paso === 'resultados'): ?>
    <?php if (!empty($_SESSION['rev_mensaje'])): ?><div class="rp-aviso">✅ <?= h($_SESSION['rev_mensaje']) ?></div><?php endif; ?>

    <?php if ($pausa !== null): ?>
        <div class="rp-aviso">⏸️ <strong>Revisión pausada en el libro <?= (int)$pausa + 1 ?> de <?= (int)($_SESSION['rev_total'] ?? 0) ?></strong>: un servidor ha limitado las consultas.
            Espera 2-3 minutos y <a href="revisar_portadas.php?paso=analizar&desde=<?= (int)$pausa ?>"><strong>continúa desde ahí</strong></a>.
            Para que no vuelva a pasar, pon tu clave gratuita de Google en <code>config/config.local.php</code> (<code>google_books_key</code>).</div>
    <?php elseif (!empty($stats['limite'])): ?>
        <div class="rp-aviso">⚠️ Algún servidor limitó las consultas durante la revisión. Si hay libros «sin poder comprobar», repite la revisión pasados unos minutos.</div>
    <?php endif; ?>

    <div class="rp-card">
        <p><strong><?= (int)$stats['ok'] ?></strong> comprobadas y correctas ·
           <strong><?= count($propuestas) ?></strong> con propuesta ·
           <strong><?= count($sinref) ?></strong> sin poder comprobar</p>

        <?php if (!empty($stats['motivos'])): ?>
            <p style="margin-bottom:0"><strong>Por qué no se han podido comprobar algunos libros:</strong></p>
            <ul class="rp-motivos">
                <?php foreach ($stats['motivos'] as $mot => $n): ?>
                    <li><strong><?= (int)$n ?></strong> — <?= h($textoMotivo[$mot] ?? $mot) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p style="margin-top:16px">
            <a class="rp-btn" href="revisar_portadas.php">🔄 Volver a revisar</a>
            <?php if ($sinPortadaAhora > 0): ?>
                <a class="rp-btn sec" href="revisar_portadas.php?paso=rellenar&ultimo=0">🔎 Rellenar los <?= $sinPortadaAhora ?> sin portada</a>
                <a class="rp-btn" href="revisar_portadas.php?paso=pendientes">✋ Elegir a mano</a>
            <?php endif; ?>
            <a href="perfil.php" style="margin-left:10px">← Mi perfil</a>
        </p>
        <form method="GET" style="margin-top:8px">
            <input type="hidden" name="paso" value="probar">
            <small>Diagnóstico de un libro (id de <code>listas_lectura</code>):</small>
            <input type="number" name="id" min="1" required style="width:90px">
            <button type="submit">Probar</button>
        </form>
    </div>

<?php if (empty($propuestas) && empty($sinref)): ?>
    <div class="rp-card">
        <?php if ((int)$stats['ok'] > 0): ?>
            <p>🎉 No hay portadas pendientes de revisar entre las que se han podido comprobar.</p>
        <?php else: ?>
            <p>⚠️ <strong>No he podido comprobar ninguna portada.</strong> Mira arriba los motivos: eso es lo que hay que arreglar antes de fiarse de este resultado.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="accion" value="aplicar">

        <?php foreach ($propuestas as $p):
            [$ico, $txt] = $etiquetas[$p['estado']] ?? ['❔', 'Revisar'];
        ?>
        <div class="rp-card rp-fila">
            <div class="rp-img">
                <?php if (esGenerica((string)$p['actual'])): ?>
                    <div class="rp-vacia">Sin portada</div>
                <?php else: ?>
                    <img src="<?= h($p['actual']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                <?php endif; ?>
                Actual
            </div>
            <div class="rp-img">
                <?php if ($p['nueva'] !== ''): ?>
                    <img src="<?= h($p['nueva']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                    Propuesta · <?= h($p['fuente']) ?>
                <?php else: ?>
                    <div class="rp-vacia">No hay ninguna fiable</div>
                    Propuesta
                <?php endif; ?>
            </div>
            <div class="rp-info">
                <span class="rp-tag"><?= $ico ?> <?= h($txt) ?></span>
                <h3><?= h($p['titulo']) ?></h3>
                <p><?= h($p['autores']) ?></p>
                <p><?= h($p['detalle']) ?></p>
                <?php if ($p['nueva'] !== ''): ?>
                    <p>Propuesta: «<?= h($p['enc_titulo']) ?>» de <?= h($p['enc_autores'] ?: 'autor sin indicar') ?><?= $p['autor_ok'] === null ? ' · ⚠️ sin autor para confirmar' : '' ?></p>
                <?php endif; ?>
                <select name="accion_libro[<?= (int)$p['id'] ?>]">
                    <?php if ($p['nueva'] !== ''): ?><option value="usar" <?= $p['def'] === 'usar' ? 'selected' : '' ?>>✅ Usar la propuesta</option><?php endif; ?>
                    <option value="quitar" <?= $p['def'] === 'quitar' ? 'selected' : '' ?>>🗑️ Quitar (dejar la genérica)</option>
                    <option value="dejar"  <?= $p['def'] === 'dejar'  ? 'selected' : '' ?>>⏸️ Dejar como está</option>
                </select>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($sinref)): ?>
        <div class="rp-card">
            <h2>Sin poder comprobar (<?= count($sinref) ?>)</h2>
            <p>No he podido saber de qué libro es su portada. <strong>Míralas tú y marca las que NO correspondan</strong>: se quitarán, y luego puedes pulsar «Rellenar» para que busque otra con comprobación de título y autor.</p>
            <div class="rp-grid">
                <?php foreach ($sinref as $s): ?>
                    <label class="rp-tile" title="<?= h($textoMotivo[$s['motivo']] ?? '') ?>">
                        <img src="<?= h($s['actual']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                        <span class="t"><?= h($s['titulo']) ?></span>
                        <span class="a"><?= h($s['autores']) ?></span>
                        <span class="c"><input type="checkbox" name="quitar_sinref[<?= (int)$s['id'] ?>]" value="1"> Quitar</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="rp-sticky"><button type="submit" class="rp-btn">Aplicar los cambios elegidos</button></div>
    </form>
<?php endif; ?>

<?php else: ?>
    <?php
        $st = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?");
        $st->execute([$uid]);
        $totalLibros = (int)$st->fetchColumn();
        $tieneGd = function_exists('imagecreatefromstring');
        $tieneClave = function_exists('config') && config('google_books_key', '') !== '';
    ?>
    <div class="rp-card">
        <p>Voy a revisar las portadas de tus <strong><?= $totalLibros ?></strong> libros, de <?= LOTE ?> en <?= LOTE ?>. Para cada una compruebo de qué libro es de verdad (título y autor) y, si no coincide, busco la correcta en Open Library, Apple Books y Google Books.</p>
        <p><strong>No cambia nada solo:</strong> al final verás la portada actual junto a la propuesta y tú eliges. Si algún libro no se puede comprobar, te digo por qué.</p>

        <?php if (!$tieneGd): ?><div class="rp-aviso">⚠️ A PHP le falta la extensión <strong>GD</strong>: no podré comparar las imágenes guardadas en tu servidor. En XAMPP abre <code>php.ini</code>, quita el «;» de <code>extension=gd</code> y reinicia Apache.</div><?php endif; ?>
        <?php if (!$tieneClave): ?><div class="rp-aviso">ℹ️ No hay clave de Google en <code>config/config.local.php</code>. Funcionará, pero Google cortará antes. Es gratis: consola de Google Cloud → habilitar «Books API» → Credenciales → Crear clave de API.</div><?php endif; ?>

        <form method="GET">
            <input type="hidden" name="paso" value="analizar">
            <input type="hidden" name="desde" value="0">
            <p><label><input type="checkbox" name="locales" value="1" checked>
                Revisar también las imágenes guardadas en mi servidor (<code>uploads/portadas</code>) comparándolas con la portada fiable del libro. Es más lento, pero ahí suelen estar las equivocadas.</label></p>
            <button type="submit" class="rp-btn">▶ Empezar la revisión</button>
            <a href="perfil.php" style="margin-left:14px">← Volver</a>
        </form>
    </div>
<?php endif; ?>
</div>
</body>
</html>
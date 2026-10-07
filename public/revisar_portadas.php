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
const LOTE = 5;                                        // libros que se revisan por tanda
const PORTADA_DEFECTO = '/Reads/img/default_cover.jpg';
const DIF_MISMA = 12;                                  // diferencia de imagen (0-64) hasta la que se considera la misma portada
const DIF_MUY_DISTINTA = 26;                           // a partir de aquí se propone cambiarla por defecto

$db  = new Database();
$uid = (int)$usuario['id'];
$datos = (new UserService())->obtenerUsuarioPorId($uid);
$tema  = strtolower(trim($datos['tema_visual'] ?? 'pastel'));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

/*  Aplicar cambios  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'aplicar') {
    $propuestas = $_SESSION['rev_portadas'] ?? [];
    $cambiadas = 0;

    foreach (($_POST['accion_libro'] ?? []) as $id => $acc) {
        $id = (int)$id;
        if (!isset($propuestas[$id])) continue;

        $nueva = null;
        if ($acc === 'usar' && !empty($propuestas[$id]['nueva'])) $nueva = guardarPortadaNueva($id, $propuestas[$id]['nueva']);
        if ($acc === 'quitar') $nueva = PORTADA_DEFECTO;
        if ($nueva === null) continue;

        $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ? AND usuario_id = ?")
                ->execute([$nueva, $id, $uid]);

        // Borrar el archivo antiguo si estaba en tu servidor
        $vieja = (string)$propuestas[$id]['actual'];
        if (strpos($vieja, 'uploads/portadas/') === 0 && $vieja !== $nueva) {
            $f = __DIR__ . '/' . $vieja;
            if (is_file($f)) @unlink($f);
        }

        unset($_SESSION['rev_portadas'][$id]);
        $cambiadas++;
    }
    $_SESSION['rev_mensaje'] = "Se han actualizado $cambiadas portadas.";
    header("Location: revisar_portadas.php?paso=resultados");
    exit;
}

$paso = $_GET['paso'] ?? '';

/*  Analizar por tandas  */
if ($paso === 'analizar') {
    $desde = max(0, (int)($_GET['desde'] ?? 0));
    if ($desde === 0) {
        $_SESSION['rev_portadas'] = [];
        $_SESSION['rev_stats'] = ['ok' => 0, 'sin_ref' => 0, 'limite' => 0];
        $_SESSION['rev_mensaje'] = '';
        $_SESSION['rev_locales'] = !empty($_GET['locales']);
    }
    $locales = $_SESSION['rev_locales'] ?? true;

    $st = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?");
    $st->execute([$uid]);
    $total = (int)$st->fetchColumn();

    $st = $db->pdo->prepare("SELECT id, libro_id, titulo, autores, portada FROM listas_lectura
                             WHERE usuario_id = ? ORDER BY id LIMIT " . LOTE . " OFFSET " . $desde);
    $st->execute([$uid]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $titulo  = (string)$l['titulo'];
        $autores = (string)($l['autores'] ?? '');
        $portada = trim((string)$l['portada']);
        $isbn    = PortadasVerificadas::esIsbn((string)($l['libro_id'] ?? '')) ? (string)$l['libro_id'] : '';

        $esWeb = (bool)preg_match('~^https?://~i', $portada);
        $esGenerica = $portada === '' || strtolower($portada) === 'sin portada'
                   || stripos($portada, 'default') !== false || stripos($portada, 'placehold') !== false;

        //  Sin portada 
        if ($esGenerica) {
            $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
            $_SESSION['rev_portadas'][(int)$l['id']] = propuesta($l, 'sin_portada', 'No tiene portada', $cand, $cand ? 'usar' : 'dejar');
            continue;
        }

        //  Enlace a Google / Open Library: se mira de qué libro es 
        if ($esWeb) {
            $act = PortadasVerificadas::verificarActual($portada, $titulo, $autores);
            if ($act['estado'] === 'ok') { $_SESSION['rev_stats']['ok']++; continue; }

            $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
            if ($cand && $cand['url'] === $portada) { $_SESSION['rev_stats']['ok']++; continue; }

            $def = 'dejar';
            if ($act['estado'] === 'incorrecta') $def = $cand ? 'usar' : 'quitar';
            $_SESSION['rev_portadas'][(int)$l['id']] = propuesta($l, $act['estado'], $act['detalle'], $cand, $def);
            continue;
        }

        // -- Imagen guardada en tu servidor (uploads/portadas/…) 
        if (!$locales || strpos($portada, 'uploads/') !== 0) { $_SESSION['rev_stats']['sin_ref']++; continue; }

        $ruta = __DIR__ . '/' . $portada;
        if (!is_file($ruta)) {
            $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
            $_SESSION['rev_portadas'][(int)$l['id']] = propuesta($l, 'sin_portada', 'El archivo de la portada ya no existe', $cand, $cand ? 'usar' : 'dejar');
            continue;
        }

        $cand = PortadasVerificadas::buscar($titulo, $autores, $isbn);
        $bytes = $cand ? PortadasVerificadas::descargar($cand['url']) : null;
        $hCand = $bytes ? PortadasVerificadas::huella($bytes) : null;
        $hLoc  = PortadasVerificadas::huella((string)file_get_contents($ruta));

        if ($hCand === null || $hLoc === null) { $_SESSION['rev_stats']['sin_ref']++; continue; }   // nada fiable con qué comparar

        $dif = PortadasVerificadas::distancia($hLoc, $hCand);
        if ($dif <= DIF_MISMA) { $_SESSION['rev_stats']['ok']++; continue; }

        $segura = ($cand['autor_ok'] === true && $cand['titulo'] === 100 && $dif >= DIF_MUY_DISTINTA);
        $_SESSION['rev_portadas'][(int)$l['id']] = propuesta(
            $l, 'distinta',
            'No se parece a la portada de «' . $cand['titulo_enc'] . '» (diferencia ' . $dif . '/64). Puede ser otra edición.',
            $cand, $segura ? 'usar' : 'dejar'
        );
    }
    $_SESSION['rev_stats']['limite'] += PortadasVerificadas::$limitado;

    $siguiente = $desde + LOTE;
    if ($siguiente >= $total) { header("Location: revisar_portadas.php?paso=resultados"); exit; }
}

/*  Datos para las vistas  */
$propuestas = $_SESSION['rev_portadas'] ?? [];
$stats      = $_SESSION['rev_stats'] ?? ['ok' => 0, 'sin_ref' => 0, 'limite' => 0];

if ($paso === 'resultados') {
    $orden = ['incorrecta' => 0, 'distinta' => 1, 'sin_portada' => 2, 'desconocida' => 3];
    uasort($propuestas, fn($a, $b) => ($orden[$a['estado']] ?? 9) <=> ($orden[$b['estado']] ?? 9));
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
<?php if ($paso === 'analizar' && $siguiente < $total): ?>
<meta http-equiv="refresh" content="1;url=revisar_portadas.php?paso=analizar&desde=<?= $siguiente ?>">
<?php endif; ?>
<style>
    .rp-wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }
    .rp-card { background: var(--bg-card, #fff); border: 1px solid var(--border-color, #ddd); border-radius: 16px; padding: 22px; margin-bottom: 16px; }
    .rp-btn { display: inline-block; padding: 11px 22px; border-radius: 12px; background: var(--primary-color, #d87d8a); color: var(--primary-contrast, #fff); text-decoration: none; font-weight: 700; border: none; cursor: pointer; font-size: 1rem; }
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
        <p><strong><?= $hecho ?></strong> de <strong><?= $total ?></strong> revisados · <?= count($propuestas) ?> con posibles problemas</p>
        <p style="opacity:.7">No cierres esta pestaña: avanza sola de <?= LOTE ?> en <?= LOTE ?> libros.</p>
    </div>

<?php elseif ($paso === 'resultados'): ?>
    <?php if (!empty($_SESSION['rev_mensaje'])): ?><div class="rp-aviso">✅ <?= h($_SESSION['rev_mensaje']) ?></div><?php endif; ?>
    <?php if (!empty($stats['limite'])): ?>
        <div class="rp-aviso">⚠️ Google ha limitado las consultas (demasiadas seguidas), así que algunos libros pueden haber quedado sin revisar. Espera unos minutos y repite la revisión, o pon tu clave en <code>config.local.php</code>.</div>
    <?php endif; ?>

    <div class="rp-card">
        <p><strong><?= (int)$stats['ok'] ?></strong> portadas correctas · <strong><?= (int)$stats['sin_ref'] ?></strong> sin nada fiable con lo que compararlas · <strong><?= count($propuestas) ?></strong> para revisar</p>
        <a class="rp-btn" href="revisar_portadas.php">🔄 Volver a revisar</a>
        <a href="perfil.php" style="margin-left:14px">← Volver a mi perfil</a>
    </div>

<?php if (empty($propuestas)): ?>
    <div class="rp-card"><p>🎉 No hay portadas pendientes de revisar.</p></div>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="accion" value="aplicar">
        <?php foreach ($propuestas as $p):
            [$ico, $txt] = $etiquetas[$p['estado']] ?? ['❔', 'Revisar'];
        ?>
        <div class="rp-card rp-fila">
            <div class="rp-img">
                <?php if (stripos((string)$p['actual'], 'default') !== false || trim((string)$p['actual']) === ''): ?>
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
        <button type="submit" class="rp-btn">Aplicar los cambios elegidos</button>
    </form>
<?php endif; ?>

<?php else: ?>
    <?php
        $st = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?");
        $st->execute([$uid]);
        $totalLibros = (int)$st->fetchColumn();
    ?>
    <div class="rp-card">
        <p>Voy a revisar las portadas de tus <strong><?= $totalLibros ?></strong> libros, de <?= LOTE ?> en <?= LOTE ?>. Para cada una compruebo de qué libro es de verdad (título y autor) y, si no coincide, busco la correcta.</p>
        <p><strong>No cambia nada solo:</strong> al final verás la portada actual junto a la propuesta y tú eliges.</p>
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
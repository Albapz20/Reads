<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/ListaService.php";
require_once "../src/AjustesService.php";
require_once "../src/Database.php";
require_once "../src/ReviewService.php";
require_once "../src/AvatarHelper.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}
$userService    = new UserService();
$listaService   = new ListaService();
$ajustesService = new AjustesService();

$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$tema = strtolower(trim($tema));
$ajustes = $ajustesService->obtenerAjustes($usuario["id"]);

$db = new Database();

// Imagen de perfil: subir una foto, elegir un icono o volver a la inicial
$avatarMsg = $_SESSION['avatar_msg'] ?? null;
unset($_SESSION['avatar_msg']);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["avatar_accion"])) {
    $msg = ['tipo' => 'error', 'texto' => 'No se pudo actualizar la imagen.'];

    if (!AvatarHelper::verificarToken((string)($_POST['csrf'] ?? ''))) {
        $msg['texto'] = 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.';
    } else {
        $stAv = $db->pdo->prepare("SELECT avatar FROM usuarios WHERE id = ?");
        $stAv->execute([$usuario["id"]]);
        $anterior = $stAv->fetchColumn() ?: null;

        $accion = (string)$_POST["avatar_accion"];
        $nuevo = false;   // false = sin cambios

        if ($accion === 'subir') {
            $r = AvatarHelper::guardarImagen($_FILES['foto'] ?? [], (int)$usuario["id"]);
            if ($r['ok']) $nuevo = $r['valor'];
            else $msg['texto'] = $r['error'];
        } elseif ($accion === 'emoji') {
            $e = (string)($_POST['emoji'] ?? '');
            if (AvatarHelper::esEmojiValido($e)) $nuevo = 'emoji:' . $e;
            else $msg['texto'] = 'Icono no válido.';
        } elseif ($accion === 'quitar') {
            $nuevo = null;
        }

        if ($nuevo !== false) {
            $db->pdo->prepare("UPDATE usuarios SET avatar = ? WHERE id = ?")->execute([$nuevo, $usuario["id"]]);
            if ($nuevo !== $anterior) AvatarHelper::borrarImagen($anterior);   // libera la foto anterior
            $msg = ['tipo' => 'ok', 'texto' => $accion === 'quitar'
                ? 'Hemos vuelto a usar tu inicial como imagen.'
                : 'Imagen de perfil actualizada.'];
        }
    }

    $_SESSION['avatar_msg'] = $msg;
    header("Location: perfil.php");
    exit;
}

$stAv = $db->pdo->prepare("SELECT avatar FROM usuarios WHERE id = ?");
$stAv->execute([$usuario["id"]]);
$avatarActual = $stAv->fetchColumn() ?: null;
$avatarInfo   = AvatarHelper::interpretar($avatarActual);
$csrfAvatar   = AvatarHelper::token();
$gradienteBanda = AvatarHelper::gradienteTema($tema);

// Guardar valoraciones desde el perfil: se escribe en listas_lectura Y en puntuaciones
// (libro.php y la biblioteca leen de puntuaciones, así que ambas deben quedar iguales)
// Nota: la columna "tension" ahora representa "Terror"
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_valoraciones"])) {
    $idLista = (int)($_POST["libro_id"] ?? 0);

    $stFila = $db->pdo->prepare("SELECT id, libro_id FROM listas_lectura WHERE id = ? AND usuario_id = ? LIMIT 1");
    $stFila->execute([$idLista, $usuario["id"]]);
    $fila = $stFila->fetch(PDO::FETCH_ASSOC);

    if ($fila) {
        $limitar = fn($v) => max(0, min(5, round((float)$v * 2) / 2));
        $est  = $limitar($_POST["estrellas"]    ?? 0);
        $rom  = $limitar($_POST["romance"]      ?? 0);
        $spi  = $limitar($_POST["spicy"]        ?? 0);
        $lag  = $limitar($_POST["lagrimas"]     ?? 0);
        $plot = $limitar($_POST["plot_twist"]   ?? 0);
        $hum  = $limitar($_POST["humor"]        ?? 0);
        $ten  = $limitar($_POST["tension"]      ?? 0);   // ahora es "terror"
        $ori  = $limitar($_POST["originalidad"] ?? 0);

        // 1) listas_lectura (amor = romance)
        try {
            $db->pdo->prepare("UPDATE listas_lectura SET estrellas = ?, spicy = ?, amor = ?, plot_twist = ?, lagrimas = ?
                               WHERE id = ? AND usuario_id = ?")
                    ->execute([$est, $spi, $rom, $plot, $lag, $fila["id"], $usuario["id"]]);
        } catch (Throwable $e) {}

        // 2) puntuaciones (la usa libro.php); la clave es el libro_id externo, o el id de la lista si no hay
        try {
            $claveExterna = ($fila["libro_id"] ?? '') !== '' ? (string)$fila["libro_id"] : (string)$fila["id"];
            $stExiste = $db->pdo->prepare("SELECT libro_id FROM puntuaciones WHERE usuario_id = ? AND libro_id IN (?, ?) LIMIT 1");
            $stExiste->execute([$usuario["id"], $claveExterna, (string)$fila["id"]]);
            $claveExistente = $stExiste->fetchColumn();

            if ($claveExistente !== false) {
                $db->pdo->prepare("UPDATE puntuaciones SET estrellas = ?, romance = ?, spicy = ?, lagrimas = ?, plot_twist = ?, humor = ?, tension = ?, originalidad = ?
                                   WHERE usuario_id = ? AND libro_id = ?")
                        ->execute([$est, $rom, $spi, $lag, $plot, $hum, $ten, $ori, $usuario["id"], $claveExistente]);
            } else {
                $db->pdo->prepare("INSERT INTO puntuaciones (usuario_id, libro_id, estrellas, romance, spicy, lagrimas, plot_twist, humor, tension, originalidad)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$usuario["id"], $claveExterna, $est, $rom, $spi, $lag, $plot, $hum, $ten, $ori]);
            }
        } catch (Throwable $e) {}
    }

    header("Location: perfil.php");
    exit;
}

// Listas de lectura
$guardados   = $listaService->obtenerLista($usuario["id"], "guardado") ?? [];
$tbr         = $listaService->obtenerLista($usuario["id"], "tbr") ?? [];
$leyendo     = $listaService->obtenerLista($usuario["id"], "leyendo") ?? [];
$leidos      = $listaService->obtenerLista($usuario["id"], "leido") ?? [];
$abandonados = $listaService->obtenerLista($usuario["id"], "abandonado") ?? [];

// Puntuaciones del usuario (tabla que usa libro.php), indexadas por libro_id
$mapaPuntuaciones = [];
try {
    $stPunt = $db->pdo->prepare("SELECT libro_id, estrellas, romance, spicy, lagrimas, plot_twist, humor, tension, originalidad FROM puntuaciones WHERE usuario_id = ?");
    $stPunt->execute([$usuario["id"]]);
    foreach ($stPunt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $mapaPuntuaciones[(string)$p["libro_id"]] = $p;
    }
} catch (Throwable $e) {}

// Completa cada libro con sus puntuaciones (si la tabla puntuaciones tiene valor, manda sobre listas_lectura)
function conPuntuaciones(array $lista, array $mapa): array {
    foreach ($lista as &$libro) {
        $p = $mapa[(string)($libro["libro_id"] ?? '')] ?? $mapa[(string)($libro["id"] ?? '')] ?? null;
        if (!$p) continue;
        if ((float)($p["estrellas"]     ?? 0) > 0) $libro["estrellas"]     = $p["estrellas"];
        if ((float)($p["romance"]       ?? 0) > 0) $libro["amor"]          = $p["romance"];
        if ((float)($p["spicy"]         ?? 0) > 0) $libro["spicy"]         = $p["spicy"];
        if ((float)($p["lagrimas"]      ?? 0) > 0) $libro["lagrimas"]      = $p["lagrimas"];
        if ((float)($p["plot_twist"]    ?? 0) > 0) $libro["plot_twist"]    = $p["plot_twist"];
        if ((float)($p["humor"]         ?? 0) > 0) $libro["humor"]         = $p["humor"];
        if ((float)($p["tension"]       ?? 0) > 0) $libro["tension"]       = $p["tension"];
        if ((float)($p["originalidad"]  ?? 0) > 0) $libro["originalidad"]  = $p["originalidad"];
    }
    unset($libro);
    return $lista;
}
$guardados   = conPuntuaciones($guardados,   $mapaPuntuaciones);
$tbr         = conPuntuaciones($tbr,         $mapaPuntuaciones);
$leyendo     = conPuntuaciones($leyendo,     $mapaPuntuaciones);
$leidos      = conPuntuaciones($leidos,      $mapaPuntuaciones);
$abandonados = conPuntuaciones($abandonados, $mapaPuntuaciones);

// Reseñas del usuario (tabla reseñas, la misma que usa libro.php)
$mapaReseñas = [];
try {
    $mapaReseñas = (new ReviewService())->obtenerReseñasUsuario($usuario["id"]);
} catch (Throwable $e) {}

function conReseñas(array $lista, array $mapa): array {
    foreach ($lista as &$libro) {
        $clave = (string)(($libro["libro_id"] ?? '') !== '' ? $libro["libro_id"] : $libro["id"]);
        if (isset($mapa[$clave])) $libro["reseña_personal"] = $mapa[$clave];
    }
    unset($libro);
    return $lista;
}
$guardados   = conReseñas($guardados,   $mapaReseñas);
$tbr         = conReseñas($tbr,         $mapaReseñas);
$leyendo     = conReseñas($leyendo,     $mapaReseñas);
$leidos      = conReseñas($leidos,      $mapaReseñas);
$abandonados = conReseñas($abandonados, $mapaReseñas);

// ---------- Calendario (igual que en el inicio) ----------
$hoyStr = date('Y-m-d');
$mesesNombre = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
                7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
$diasSemana = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

$anotadoHoy = false;
$rachaCal = 0;
$proxLanz = [];
try {
    $stmtHoy = $db->pdo->prepare("SELECT COALESCE(SUM(paginas_leidas), 0) FROM diario_lectura WHERE usuario_id = ? AND fecha = ?");
    $stmtHoy->execute([$usuario["id"], $hoyStr]);
    $anotadoHoy = ((int)$stmtHoy->fetchColumn() > 0);

    $stmtRachaCal = $db->pdo->prepare("SELECT DISTINCT fecha FROM diario_lectura WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 400");
    $stmtRachaCal->execute([$usuario["id"], $hoyStr]);
    $fechasLectura = array_flip($stmtRachaCal->fetchAll(PDO::FETCH_COLUMN));
    $cursorCal = new DateTime($hoyStr);
    if (!isset($fechasLectura[$cursorCal->format('Y-m-d')])) $cursorCal->modify('-1 day'); // si hoy aún no has anotado, cuenta desde ayer
    while (isset($fechasLectura[$cursorCal->format('Y-m-d')])) {
        $rachaCal++;
        $cursorCal->modify('-1 day');
    }

    $stmtProx = $db->pdo->prepare("SELECT titulo, fecha_lanzamiento
                                   FROM lanzamientos_deseados
                                   WHERE usuario_id = ? AND fecha_lanzamiento >= ?
                                   ORDER BY fecha_lanzamiento ASC LIMIT 3");
    $stmtProx->execute([$usuario["id"], $hoyStr]);
    $proxLanz = $stmtProx->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!function_exists('textoCuentaAtras')) {
    function textoCuentaAtras(string $fecha): string {
        $dias = (int)((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
        if ($dias <= 0) return '¡Hoy!';
        if ($dias === 1) return 'Mañana';
        return "En {$dias} días";
    }
}
if (!function_exists('e')) {
    function e($texto) { return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Datos para compartir la lista de deseos / Wishlist
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$urlDeseosPublica = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/Reads/public/lista_deseos.php?usuario_id=" . $usuario["id"];
$urlQR = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($urlDeseosPublica);
$textoWhatsApp = urlencode("¡Hola! Te comparto mi wishlist de libros para que veas cuáles me gustaría leer: " . $urlDeseosPublica);

// ¿El libro todavía no tiene una portada real?
function esGenericaPortada(string $p): bool {
    $p = trim($p);
    return $p === '' || stripos($p, 'default') !== false || stripos($p, 'placehold') !== false;
}

// Texto de estrellas (admite medias)
function estrellasTexto(float $v): string {
    if ($v <= 0) return '';
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' ★';
}

// Selector de iconos con medias notas (igual que en libro.php)
function selectorIconos(string $name, string $etiqueta, string $icono, float $actual): string {
    $html  = '<div class="fila-valoracion">';
    $html .= '<label>' . $etiqueta . ' <span class="val-num">' . $actual . '</span>/5</label>';
    $html .= '<div class="icon-selector" data-campo="' . $name . '">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span data-val="' . $i . '">' . $icono . '</span>';
    }
    $html .= '</div>';
    $html .= '<input type="hidden" name="' . $name . '" value="' . $actual . '">';
    return $html . '</div>';
}

// Tarjeta compacta de libro + panel de gestión (dentro de un <template>, se abre en un modal)
function renderizarTarjeta(array $libro, bool $esListaDeseos, int $orden): void {
    $paginasTotales = (int)($libro["paginas_totales"] ?? 0);
    $paginasLeidas  = (int)($libro["paginas_leidas"] ?? 0);

    $progreso = $paginasTotales > 0
        ? round(($paginasLeidas / $paginasTotales) * 100)
        : (int)($libro["progreso"] ?? 0);
    $progreso = max(0, min(100, $progreso));

    $estado             = $libro["estado"] ?? "";
    $estrellasActuales  = (float)($libro["estrellas"] ?? 0);
    $spicyActual        = (float)($libro["spicy"] ?? 0);
    $amorActual         = (float)($libro["amor"] ?? 0);
    $plotTwistActual    = (float)($libro["plot_twist"] ?? 0);
    $lagrimasActuales   = (float)($libro["lagrimas"] ?? 0);
    $humorActual        = (float)($libro["humor"] ?? 0);
    $tensionActual      = (float)($libro["tension"] ?? 0);        // "Terror"
    $originalidadActual = (float)($libro["originalidad"] ?? 0);
    $tituloEscapado     = htmlspecialchars($libro["titulo"], ENT_QUOTES);
    $autorEscapado      = htmlspecialchars($libro["autores"] ?? "", ENT_QUOTES);
    $portada            = htmlspecialchars($libro["portada"] ?? '/Reads/img/default_cover.jpg', ENT_QUOTES);
    $sinPortada         = esGenericaPortada((string)($libro["portada"] ?? ''));
    $buscar             = htmlspecialchars(mb_strtolower(($libro["titulo"] ?? '') . ' ' . ($libro["autores"] ?? ''), 'UTF-8'), ENT_QUOTES);
    $placeholder        = 'https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada';

    // Etiqueta sobre la portada
    $chip = '';
    if ($estado === 'leyendo') $chip = $progreso . '%';
    elseif ($estado === 'leido' && $estrellasActuales > 0) $chip = estrellasTexto($estrellasActuales);
    elseif ($estado === 'abandonado') $chip = '❌';

    // URLs de tiendas
    $queryBusqueda = urlencode($libro["titulo"] . " " . ($libro["autores"] ?? ""));
    $urlAmazon     = "https://www.amazon.es/s?k=" . $queryBusqueda . "&i=stripbooks";
    $urlCasaLibro  = "https://www.casadellibro.com/busqueda-libros?busqueda=" . $queryBusqueda;
    $urlFnac       = "https://www.fnac.es/ia1234/busqueda?Search=" . $queryBusqueda;
    ?>
    <article class="libro-card" tabindex="0" role="button" aria-label="Gestionar «<?= $tituloEscapado ?>»"
             data-orden="<?= $orden ?>" data-buscar="<?= $buscar ?>"
             data-titulo="<?= $tituloEscapado ?>" data-autor="<?= $autorEscapado ?>"
             data-estrellas="<?= $estrellasActuales ?>" data-progreso="<?= $progreso ?>">
        <div class="libro-portada" data-portada-id="<?= (int)$libro['id'] ?>">
            <?php if ($sinPortada): ?>
                <div class="portada-generada"><strong><?= $tituloEscapado ?></strong><small><?= $autorEscapado ?></small></div>
            <?php else: ?>
                <img src="<?= $portada ?>" alt="" loading="lazy" onerror="portadaRota(this)">
            <?php endif; ?>
            <?php if ($chip !== ''): ?><span class="libro-chip"><?= htmlspecialchars($chip) ?></span><?php endif; ?>
        </div>
        <div class="libro-meta">
            <h4 class="libro-titulo"><?= $tituloEscapado ?></h4>
            <?php if ($autorEscapado !== ''): ?><p class="libro-autor"><?= $autorEscapado ?></p><?php endif; ?>
            <?php if ($estado === 'leyendo'): ?>
                <div class="barra-progreso-bg mini"><div class="barra-progreso-fill" style="width:<?= $progreso ?>%;"></div></div>
            <?php endif; ?>
        </div>

        <template class="libro-detalle">
            <div class="detalle-cabecera">
                <img src="<?= $portada ?>" alt="" class="detalle-portada" onerror="this.onerror=null;this.src='<?= $placeholder ?>';">
                <div style="flex: 1; min-width: 0;">
                    <h3 class="detalle-titulo"><?= $tituloEscapado ?></h3>
                    <?php if ($autorEscapado !== ''): ?><p class="detalle-autor"><?= $autorEscapado ?></p><?php endif; ?>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <form method="POST" action="cambiar_estado.php" style="display: inline-flex; gap: 5px;">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <select name="estado" class="input-select">
                                <option value="guardado"   <?= $estado === "guardado"   ? "selected" : "" ?>>Wishlist</option>
                                <option value="tbr"        <?= $estado === "tbr"        ? "selected" : "" ?>>TBR (Pendiente)</option>
                                <option value="leyendo"    <?= $estado === "leyendo"    ? "selected" : "" ?>>Leyendo</option>
                                <option value="leido"      <?= $estado === "leido"      ? "selected" : "" ?>>Leído</option>
                                <option value="abandonado" <?= $estado === "abandonado" ? "selected" : "" ?>>Abandonado</option>
                            </select>
                            <button type="submit" class="btn-accion">Cambiar</button>
                        </form>

                        <form method="POST" action="eliminar_libro.php" style="display: inline;"
                              data-titulo="<?= $tituloEscapado ?>"
                              onsubmit="return confirm('¿Estás seguro de que quieres eliminar «' + this.dataset.titulo + '» de tu lista?');">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <button type="submit" class="btn-accion btn-eliminar">Eliminar</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($esListaDeseos): ?>
                <div class="seccion-secundaria-item">
                    <p style="margin: 0 0 8px 0; font-size: 0.85rem; font-weight: 700;">🛒 Opciones para comprar o regalar:</p>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="<?= $urlCasaLibro ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">📚 La Casa del Libro</a>
                        <a href="<?= $urlAmazon ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">📦 Amazon</a>
                        <a href="<?= $urlFnac ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">🛍️ Fnac</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="seccion-secundaria-item">
                    <p style="margin: 0 0 5px 0;"><strong>Progreso:</strong> <?= $progreso ?>%</p>

                    <form method="POST" action="actualizar_paginas.php" data-todo style="margin: 8px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                        <label style="font-size: 0.9rem;">Páginas leídas:</label>
                        <input type="number" name="paginas_leidas" min="0" <?= $paginasTotales > 0 ? 'max="'.$paginasTotales.'"' : '' ?> value="<?= $paginasLeidas ?>" class="input-number">
                        <button type="submit" class="btn-accion">Actualizar</button>
                    </form>

                    <p style="margin: 5px 0 8px 0; font-size: 0.9rem; opacity: 0.8;">Páginas totales: <?= $paginasTotales ?></p>

                    <div class="barra-progreso-bg">
                        <div class="barra-progreso-fill" style="width:<?= $progreso ?>%;"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($estado === "leyendo" || $estado === "leido"): ?>
                <div class="seccion-secundaria-item">
                    <form action="actualizar_fechas.php" method="POST" data-todo style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                        <input type="hidden" name="libro_id" value="<?= $libro['id'] ?>">

                        <label style="font-size: 0.88rem; font-weight: 600;">
                            📅 Inicio:
                            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($libro['fecha_inicio'] ?? '') ?>" class="input-select">
                        </label>

                        <label style="font-size: 0.88rem; font-weight: 600;">
                            🏁 Fin:
                            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($libro['fecha_fin'] ?? '') ?>" class="input-select">
                        </label>

                        <button type="submit" class="btn-accion">Actualizar fechas</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($estado === "leido" || $estado === "abandonado"): ?>
                <div class="seccion-secundaria-item">
                    <form method="POST" action="perfil.php" class="form-valoraciones" data-todo>
                        <input type="hidden" name="guardar_valoraciones" value="1">
                        <input type="hidden" name="libro_id" value="<?= (int)$libro["id"] ?>">

                        <?= selectorIconos('estrellas',    '⭐ Estrellas:',      '★',  $estrellasActuales) ?>
                        <?= selectorIconos('romance',      '💖 Romance:',        '💖', $amorActual) ?>
                        <?= selectorIconos('spicy',        '🌶️ Spicy:',          '🌶️', $spicyActual) ?>
                        <?= selectorIconos('lagrimas',     '💧 Lágrimas:',       '💧', $lagrimasActuales) ?>
                        <?= selectorIconos('plot_twist',   '⚡ Plot Twist:',     '⚡', $plotTwistActual) ?>
                        <?= selectorIconos('humor',        '😂 Humor:',          '😂', $humorActual) ?>
                        <?= selectorIconos('tension',      '👻 Terror:',         '👻', $tensionActual) ?>
                        <?= selectorIconos('originalidad', '💡 Originalidad:',   '💡', $originalidadActual) ?>

                        <div><button type="submit" class="btn-accion">Guardar valoraciones</button></div>
                    </form>

                    <form method="POST" action="guardar_reseña.php" data-todo style="margin-bottom: 5px;">
                        <input type="hidden" name="libro_id" value="<?= htmlspecialchars((string)(($libro["libro_id"] ?? '') !== '' ? $libro["libro_id"] : $libro["id"]), ENT_QUOTES) ?>">
                        <input type="hidden" name="volver" value="perfil">
                        <label style="font-weight: 600;">Reseña:</label><br>
                        <textarea name="contenido" rows="3" class="input-textarea"><?= htmlspecialchars($libro["reseña_personal"] ?? "") ?></textarea>
                        <button type="submit" class="btn-accion">Guardar reseña</button>
                    </form>

                    <?php if ($estado === "abandonado"): ?>
                        <form method="POST" action="actualizar_progreso.php" data-todo style="margin-top: 8px;">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <label style="font-weight: 600;">Motivo de abandono:</label><br>
                            <textarea name="motivo_abandono" rows="2" class="input-textarea"><?= htmlspecialchars($libro["motivo_abandono"] ?? "") ?></textarea>
                            <button type="submit" class="btn-accion">Guardar motivo</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (in_array($estado, ['leyendo', 'leido', 'abandonado'], true)): ?>
                <div class="guardar-todo-wrap">
                    <button type="button" class="btn-accion js-guardar-todo">Guardar todos los cambios</button>
                    <span class="guardar-todo-msg" role="status"></span>
                </div>
            <?php endif; ?>
        </template>
    </article>
    <?php
}

// Panel completo de una lista: buscador, orden, cuadrícula y "mostrar más"
function renderizarPanel(string $id, array $lista, bool $esListaDeseos, bool $activo = false): void {
    ?>
    <div id="lista-<?= $id ?>" class="tab-content <?= $activo ? 'active' : '' ?>">
        <?php if ($esListaDeseos): ?>
            <?php global $urlQR, $textoWhatsApp, $urlDeseosPublica; ?>
            <div class="share-card-box">
                <img src="<?= $urlQR ?>" alt="Código QR Wishlist" class="qr-img">
                <div style="flex: 1; min-width: 200px;">
                    <h4 style="margin: 0 0 4px 0; font-size: 1.05rem;">🎁 Compartir mi Wishlist</h4>
                    <p style="margin: 0 0 10px 0; font-size: 0.85rem; opacity: 0.8;">Muestra este código QR o comparte tu enlace directo para que sepan qué libros regalarte.</p>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="https://api.whatsapp.com/send?text=<?= $textoWhatsApp ?>" target="_blank" rel="noopener noreferrer" class="btn-ws-icon" title="Compartir por WhatsApp">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.729.729 0 0 0-.529.247c-.182.198-.691.677-.691 1.654 0 .977.71 1.916.81 2.049.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.17-.478 1.338-.94.166-.464.166-.86.116-.94-.048-.08-.182-.133-.379-.232z"/>
                            </svg>
                        </a>
                        <button type="button" onclick="copiarEnlace('<?= htmlspecialchars($urlDeseosPublica, ENT_QUOTES) ?>')" class="btn-accion">🔗 Copiar Enlace</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($lista)): ?>
            <p class="lista-vacia">No hay libros en esta categoría.</p>
        <?php else: ?>
            <div class="lista-toolbar">
                <input type="search" class="filtro-busqueda" placeholder="🔍 Buscar por título o autor…" aria-label="Buscar en esta lista">
                <select class="filtro-orden" aria-label="Ordenar">
                    <option value="original">Más recientes</option>
                    <option value="titulo">Título A-Z</option>
                    <option value="autor">Autor A-Z</option>
                    <?php if (!$esListaDeseos): ?>
                        <option value="nota">Mejor valorados</option>
                        <option value="progreso">Mayor progreso</option>
                    <?php endif; ?>
                </select>
                <span class="lista-contador"></span>
            </div>

            <div class="libros-grid">
                <?php foreach (array_values($lista) as $i => $libro) renderizarTarjeta($libro, $esListaDeseos, $i); ?>
            </div>

            <p class="lista-vacia sin-resultados" hidden>Ningún libro coincide con tu búsqueda.</p>
            <div class="mostrar-mas-wrap"><button type="button" class="btn-mostrar-mas">Mostrar más</button></div>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="/Reads/public/main.js"></script>
    <title>Mi perfil</title>
    <script>
    // Si una imagen de portada no carga, se muestra una portada generada con el título y el autor
    function portadaRota(img) {
        const card = img.closest('.libro-card');
        if (!card) return;
        const d = document.createElement('div');
        d.className = 'portada-generada';
        const t = document.createElement('strong');
        t.textContent = card.dataset.titulo || '';
        const a = document.createElement('small');
        a.textContent = card.dataset.autor || '';
        d.append(t, a);
        img.replaceWith(d);
    }
    </script>
</head>
<body class="page-perfil">

<div class="container">

    <?php if ($avatarMsg): ?>
        <div class="perfil-card avatar-aviso <?= $avatarMsg['tipo'] === 'error' ? 'error' : '' ?>">
            <?= $avatarMsg['tipo'] === 'ok' ? '✅' : '⚠️' ?> <?= htmlspecialchars($avatarMsg['texto']) ?>
        </div>
    <?php endif; ?>

    <!-- Banner de perfil -->
    <div class="perfil-card perfil-banner-container con-banda">
        <div class="pf-banner" style="background: <?= htmlspecialchars($gradienteBanda) ?>;"></div>

        <div class="perfil-header-main">
            <div class="avatar-circle avatar-editable">
                <?= AvatarHelper::contenidoCirculo($datos["nombre"] ?? 'A', $avatarActual) ?>
                <button type="button" class="avatar-editar" id="abrirAvatar" aria-label="Cambiar imagen de perfil" title="Cambiar imagen de perfil">📷</button>
            </div>

            <div class="perfil-info-main">
                <h1 class="perfil-usuario-nombre"><?= htmlspecialchars($datos["nombre"] ?? 'Usuario') ?></h1>
                <p class="perfil-usuario-email"><?= htmlspecialchars($datos["email"] ?? '') ?></p>

                <div class="perfil-badges-group">
                    <span class="badge-chip">🔒 <?= htmlspecialchars($datos["privacidad"] ?? 'público') ?></span>
                    <span class="badge-chip">🎨 <?= htmlspecialchars($datos["tema_visual"] ?? 'pastel') ?></span>
                </div>
            </div>

            <div class="perfil-acciones-destacadas">
                <a href="ajustes.php" class="btn-banner">⚙️ Ajustes</a>
                <a href="logout.php" class="btn-banner btn-danger" title="Cerrar sesión">🚪</a>
            </div>
        </div>
    </div>

    <!-- Mis listas de lectura -->
    <div class="perfil-card perfil-listas-card">

        <div class="perfil-card-header">
            <h2 class="perfil-card-titulo"> Mis lecturas</h2>

            <button type="button" class="wishlist-btn-destacado" data-tab="guardados">
                 Wishlist <span class="wishlist-count"><?= count($guardados) ?></span>
            </button>
        </div>

        <div class="tabs-listas">
            <button type="button" class="tab-btn active" data-tab="tbr">
                TBR <span class="tab-count"><?= count($tbr) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="leyendo">
                 Leyendo <span class="tab-count"><?= count($leyendo) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="leidos">
                 Leídos <span class="tab-count"><?= count($leidos) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="abandonados">
                 Abandonados <span class="tab-count"><?= count($abandonados) ?></span>
            </button>
        </div>

        <?php
        renderizarPanel('tbr', $tbr, false, true);
        renderizarPanel('leyendo', $leyendo, false);
        renderizarPanel('leidos', $leidos, false);
        renderizarPanel('abandonados', $abandonados, false);
        renderizarPanel('guardados', $guardados, true);
        ?>

    </div>

    <!-- Calendario (debajo de las lecturas) -->
    <div class="perfil-card">
        <div class="perfil-card-header">
            <h2 class="perfil-card-titulo">📅 Calendario</h2>
        </div>

        <div class="cal-hoy">
            <div class="cal-fecha">
                <strong><?= (int)date('j') ?></strong>
                <small><?= e(mb_substr($mesesNombre[(int)date('n')], 0, 3)) ?></small>
            </div>
            <div class="cal-texto">
                <span><?= e(ucfirst($diasSemana[(int)date('N')])) ?></span>
                <small>
                    <?= $anotadoHoy ? 'Hoy ya has anotado lectura ✔' : 'Aún no has anotado lectura hoy' ?>
                    <?php if ($rachaCal > 0): ?>
                        · Racha: <?= $rachaCal ?> <?= $rachaCal === 1 ? 'día' : 'días' ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <?php if (!empty($proxLanz)): ?>
            <ul class="cal-lista">
                <?php foreach ($proxLanz as $lz): ?>
                    <li>
                        <span class="cal-dia"><?= e(textoCuentaAtras($lz['fecha_lanzamiento'])) ?></span>
                        <span class="cal-titulo"><?= e($lz['titulo']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="cal-botones">
            <a href="calendario.php?hoy=1" class="hm-btn">Anotar lectura de hoy</a>
            <a href="calendario.php" class="cal-enlace">Ver calendario</a>
        </div>
    </div>

</div>

<!-- Modal de gestión del libro -->
<dialog id="modalLibro" class="modal-libro" aria-label="Gestionar libro">
    <button type="button" class="modal-cerrar" id="modalCerrar" aria-label="Cerrar">✕</button>
    <div class="modal-cuerpo" id="modalCuerpo"></div>
</dialog>
<!-- Modal: cambiar imagen de perfil -->
<dialog id="modalAvatar" class="modal-libro" aria-label="Cambiar imagen de perfil">
    <button type="button" class="modal-cerrar" id="cerrarAvatar" aria-label="Cerrar">✕</button>
    <div class="modal-cuerpo">
        <div class="av-modal">
            <h3>📷 Imagen de perfil</h3>
            <p class="av-sub">Sube una foto o elige un icono. Se verá en tu perfil y en la comunidad.</p>

            <div class="av-seccion">
                <h4>Subir una foto</h4>
                <form method="POST" enctype="multipart/form-data" id="formFoto">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfAvatar) ?>">
                    <input type="hidden" name="avatar_accion" value="subir">
                    <div class="av-subir">
                        <img id="avPreview" class="av-preview" alt="Vista previa">
                        <input type="file" name="foto" id="inputFoto" accept="image/*">
                        <button type="submit" class="btn-accion" id="btnSubirFoto" disabled>Usar esta foto</button>
                    </div>
                    <p class="av-error" id="avError"></p>
                    <p class="av-sub" style="margin:6px 0 0;">Cualquier imagen · se ajusta y se recorta en cuadrado automáticamente</p>
                </form>
            </div>

            <div class="av-seccion">
                <h4>O elige un icono</h4>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfAvatar) ?>">
                    <input type="hidden" name="avatar_accion" value="emoji">
                    <div class="av-emojis">
                        <?php foreach (AvatarHelper::EMOJIS as $em): ?>
                            <button type="submit" name="emoji" value="<?= htmlspecialchars($em) ?>"
                                    class="av-emoji <?= ($avatarInfo['tipo'] === 'emoji' && $avatarInfo['valor'] === $em) ? 'actual' : '' ?>"
                                    aria-label="Usar el icono <?= htmlspecialchars($em) ?>"><?= htmlspecialchars($em) ?></button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <?php if ($avatarInfo['tipo'] !== 'letra'): ?>
            <div class="av-seccion">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfAvatar) ?>">
                    <input type="hidden" name="avatar_accion" value="quitar">
                    <button type="submit" class="btn-accion btn-eliminar">Quitar y usar mi inicial</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</dialog>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float active"><span class="nav-icon">👤</span><span>Perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Estantería</span></a>
        <a href="comunidad.php" class="nav-card-float"><span class="nav-icon">👥</span><span>Comunidad</span></a>
    </nav>
</div>

<script>
const LIBROS_POR_PAGINA = 9;
const PESTANAS = ['tbr', 'leyendo', 'leidos', 'abandonados', 'guardados'];

const normaliza = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/*  Pestañas  */
function abrirPestana(nombre) {
    if (!PESTANAS.includes(nombre)) nombre = 'tbr';

    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === nombre));

    const panel = document.getElementById('lista-' + nombre);
    if (panel) panel.classList.add('active');

    try { localStorage.setItem('perfilTab', nombre); } catch (e) {}
}

document.querySelectorAll('[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => abrirPestana(btn.dataset.tab));
});

/*  Búsqueda, orden y "mostrar más"  */
function refrescarPanel(panel, reiniciar) {
    const grid = panel.querySelector('.libros-grid');
    if (!grid) return;

    if (reiniciar) panel.dataset.limite = LIBROS_POR_PAGINA;
    const limite = parseInt(panel.dataset.limite || LIBROS_POR_PAGINA, 10);
    const q = normaliza(panel.querySelector('.filtro-busqueda').value.trim());
    const orden = panel.querySelector('.filtro-orden').value;

    const cards = Array.from(grid.children);
    const porOriginal = (a, b) => a.dataset.orden - b.dataset.orden;
    const comparadores = {
        original: porOriginal,
        titulo:   (a, b) => a.dataset.titulo.localeCompare(b.dataset.titulo, 'es', { sensitivity: 'base' }),
        autor:    (a, b) => (a.dataset.autor || '~').localeCompare(b.dataset.autor || '~', 'es', { sensitivity: 'base' }) || porOriginal(a, b),
        nota:     (a, b) => (b.dataset.estrellas - a.dataset.estrellas) || porOriginal(a, b),
        progreso: (a, b) => (b.dataset.progreso - a.dataset.progreso) || porOriginal(a, b)
    };
    cards.sort(comparadores[orden] || porOriginal).forEach(c => grid.appendChild(c));

    let coincidencias = 0;
    cards.forEach(c => {
        const ok = !q || c._buscar.includes(q);
        if (ok) coincidencias++;
        c.hidden = !ok || coincidencias > limite;
    });

    const mostrados = Math.min(coincidencias, limite);
    panel.querySelector('.lista-contador').textContent =
        'Mostrando ' + mostrados + ' de ' + coincidencias + (q ? ' resultados' : ' libros');
    panel.querySelector('.sin-resultados').hidden = coincidencias > 0;
    panel.querySelector('.mostrar-mas-wrap').hidden = coincidencias <= limite;
}

document.querySelectorAll('.tab-content').forEach(panel => {
    const grid = panel.querySelector('.libros-grid');
    if (!grid) return;

    Array.from(grid.children).forEach(c => { c._buscar = normaliza(c.dataset.buscar); });
    panel.dataset.limite = LIBROS_POR_PAGINA;

    panel.querySelector('.filtro-busqueda').addEventListener('input', () => refrescarPanel(panel, true));
    panel.querySelector('.filtro-orden').addEventListener('change', () => refrescarPanel(panel, true));
    panel.querySelector('.btn-mostrar-mas').addEventListener('click', () => {
        panel.dataset.limite = parseInt(panel.dataset.limite, 10) + LIBROS_POR_PAGINA;
        refrescarPanel(panel, false);
    });

    refrescarPanel(panel, true);
});

/*  Modal de gestión  */
const modal = document.getElementById('modalLibro');
const modalCuerpo = document.getElementById('modalCuerpo');

// "Foto" de los valores de un formulario, para saber si el usuario ha cambiado algo
function fotoFormulario(f) {
    return JSON.stringify(Array.from(new FormData(f).entries()).map(([k, v]) => [k, String(v)]));
}

/*  Selector de iconos con medias notas  */
function pintarSelector(cont, val) {
    const esEstrellas = cont.dataset.campo === 'estrellas';
    cont.querySelectorAll('span').forEach(icon => {
        const n = parseInt(icon.dataset.val, 10);
        if (n <= val) {
            icon.style.opacity = '1';
            icon.style.filter = 'grayscale(0%)';
            icon.style.color = esEstrellas ? '#f5b301' : '';
        } else if (n - 0.5 === val) {
            icon.style.opacity = '0.6';
            icon.style.filter = 'grayscale(30%)';
            icon.style.color = esEstrellas ? '#f5b301' : '';
        } else {
            icon.style.opacity = '0.25';
            icon.style.filter = 'grayscale(100%)';
            icon.style.color = '';
        }
    });
}

function iniciarSelectores(raiz) {
    raiz.querySelectorAll('.icon-selector').forEach(cont => {
        const input = cont.closest('.fila-valoracion').querySelector('input[type=hidden]');
        pintarSelector(cont, parseFloat(input.value) || 0);
    });
}

function abrirModal(card) {
    const plantilla = card.querySelector('template.libro-detalle');
    if (!plantilla) return;
    modalCuerpo.replaceChildren(plantilla.content.cloneNode(true));

    iniciarSelectores(modalCuerpo);

    // Valores iniciales de cada formulario (para "Guardar todos los cambios")
    modalCuerpo.querySelectorAll('form[data-todo]').forEach(f => {
        f.dataset.inicial = fotoFormulario(f);
    });

    modal.showModal();
    modal.scrollTop = 0;
    modalCuerpo.scrollTop = 0;
    document.body.classList.add('modal-open');
}

document.querySelectorAll('.libros-grid').forEach(grid => {
    grid.addEventListener('click', e => {
        const card = e.target.closest('.libro-card');
        if (card) abrirModal(card);
    });
    grid.addEventListener('keydown', e => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const card = e.target.closest('.libro-card');
        if (card) { e.preventDefault(); abrirModal(card); }
    });
});

// Guardar de una vez todo lo que se haya modificado en el modal
modalCuerpo.addEventListener('click', async e => {
    const btn = e.target.closest('.js-guardar-todo');
    if (!btn) return;

    const msg = modalCuerpo.querySelector('.guardar-todo-msg');
    const cambiados = Array.from(modalCuerpo.querySelectorAll('form[data-todo]'))
        .filter(f => fotoFormulario(f) !== f.dataset.inicial);

    if (cambiados.length === 0) {
        msg.textContent = 'No hay cambios que guardar.';
        return;
    }
    if (!cambiados.every(f => f.reportValidity())) return;

    btn.disabled = true;
    msg.textContent = 'Guardando…';
    try {
        for (const f of cambiados) {
            const res = await fetch(f.getAttribute('action'), {
                method: 'POST',
                body: new FormData(f),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
        }
        location.reload();   // vuelve a la misma pestaña gracias a localStorage
    } catch (err) {
        btn.disabled = false;
        msg.textContent = 'No se pudo guardar todo. Inténtalo de nuevo.';
    }
});

// Clic en un icono: izquierda = media nota, derecha = nota entera
modalCuerpo.addEventListener('click', e => {
    const icon = e.target.closest('.icon-selector > span');
    if (!icon) return;

    const cont  = icon.parentElement;
    const fila  = cont.closest('.fila-valoracion');
    const input = fila.querySelector('input[type=hidden]');
    const num   = fila.querySelector('.val-num');

    const rect  = icon.getBoundingClientRect();
    const base  = parseInt(icon.dataset.val, 10);
    let valor   = ((e.clientX - rect.left) < rect.width / 2) ? base - 0.5 : base;

    // Pulsar otra vez el mismo valor lo pone a 0 (así se puede quitar la nota)
    if (valor === (parseFloat(input.value) || 0)) valor = 0;

    input.value = valor;
    num.textContent = valor;
    pintarSelector(cont, valor);
});

document.getElementById('modalCerrar').addEventListener('click', () => modal.close());
modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
modal.addEventListener('close', () => {
    modalCuerpo.replaceChildren();
    document.body.classList.remove('modal-open');
});

/* ---------- Imagen de perfil ---------- */
const modalAvatar = document.getElementById('modalAvatar');
document.getElementById('abrirAvatar').addEventListener('click', () => {
    modalAvatar.showModal();
    document.body.classList.add('modal-open');
});
document.getElementById('cerrarAvatar').addEventListener('click', () => modalAvatar.close());
modalAvatar.addEventListener('click', e => { if (e.target === modalAvatar) modalAvatar.close(); });
modalAvatar.addEventListener('close', () => document.body.classList.remove('modal-open'));

// Vista previa y reducción automática de la imagen antes de enviar
const inputFoto = document.getElementById('inputFoto');
const avPreview = document.getElementById('avPreview');
const avError   = document.getElementById('avError');
const btnFoto   = document.getElementById('btnSubirFoto');
const AV_LADO   = 800;   // tamaño final: 800x800 px

function reducirImagen(archivo) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(archivo);
        const img = new Image();
        img.onload = () => {
            // Recorte cuadrado centrado
            const lado = Math.min(img.naturalWidth, img.naturalHeight);
            const sx = (img.naturalWidth - lado) / 2;
            const sy = (img.naturalHeight - lado) / 2;
            const salida = Math.min(AV_LADO, lado);

            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = salida;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';                 // fondo blanco para PNG transparentes
            ctx.fillRect(0, 0, salida, salida);
            ctx.drawImage(img, sx, sy, lado, lado, 0, 0, salida, salida);
            URL.revokeObjectURL(url);

            canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('blob')), 'image/jpeg', 0.9);
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('img')); };
        img.src = url;
    });
}

inputFoto.addEventListener('change', async () => {
    const f = inputFoto.files[0];
    avError.textContent = '';
    btnFoto.disabled = true;
    avPreview.classList.remove('visible');
    if (!f) return;

    if (!f.type.startsWith('image/')) {
        avError.textContent = 'El archivo no es una imagen.';
        return;
    }

    try {
        avError.textContent = 'Preparando imagen…';
        const blob = await reducirImagen(f);

        // Sustituye el archivo elegido por la versión reducida
        const nombre = f.name.replace(/\.[^.]+$/, '') + '.jpg';
        const reducido = new File([blob], nombre, { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(reducido);
        inputFoto.files = dt.files;

        avPreview.src = URL.createObjectURL(reducido);
        avPreview.classList.add('visible');
        avError.textContent = '';
        btnFoto.disabled = false;
    } catch (e) {
        avError.textContent = 'No se pudo leer esa imagen. Prueba con otra (JPG, PNG o WebP).';
    }
});

/*  Utilidades  */
function copiarEnlace(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert("¡Enlace copiado al portapapeles!");
    }).catch(() => {
        prompt("Copia este enlace:", url);
    });
}

/* Recuerda la última pestaña (útil al volver tras guardar un cambio) */
let pestanaGuardada = 'tbr';
try { pestanaGuardada = localStorage.getItem('perfilTab') || 'tbr'; } catch (e) {}
abrirPestana(pestanaGuardada);
</script>
</body>
</html>
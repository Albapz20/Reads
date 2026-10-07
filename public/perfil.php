<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/ListaService.php";
require_once "../src/AjustesService.php";
require_once "../src/Database.php";
require_once "../src/ReviewService.php";

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

// Guardar valoraciones desde el perfil: se escribe en listas_lectura Y en puntuaciones
// (libro.php y la biblioteca leen de puntuaciones, así que ambas deben quedar iguales)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_valoraciones"])) {
    $idLista = (int)($_POST["libro_id"] ?? 0);

    $stFila = $db->pdo->prepare("SELECT id, libro_id FROM listas_lectura WHERE id = ? AND usuario_id = ? LIMIT 1");
    $stFila->execute([$idLista, $usuario["id"]]);
    $fila = $stFila->fetch(PDO::FETCH_ASSOC);

    if ($fila) {
        $limitar = fn($v) => max(0, min(5, round((float)$v * 2) / 2));
        $est  = $limitar($_POST["estrellas"]  ?? 0);
        $rom  = $limitar($_POST["romance"]    ?? 0);
        $spi  = $limitar($_POST["spicy"]      ?? 0);
        $lag  = $limitar($_POST["lagrimas"]   ?? 0);
        $plot = $limitar($_POST["plot_twist"] ?? 0);

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
                $db->pdo->prepare("UPDATE puntuaciones SET estrellas = ?, romance = ?, spicy = ?, lagrimas = ?, plot_twist = ?
                                   WHERE usuario_id = ? AND libro_id = ?")
                        ->execute([$est, $rom, $spi, $lag, $plot, $usuario["id"], $claveExistente]);
            } else {
                $db->pdo->prepare("INSERT INTO puntuaciones (usuario_id, libro_id, estrellas, romance, spicy, lagrimas, plot_twist)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$usuario["id"], $claveExterna, $est, $rom, $spi, $lag, $plot]);
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
    $stPunt = $db->pdo->prepare("SELECT libro_id, estrellas, romance, spicy, lagrimas, plot_twist FROM puntuaciones WHERE usuario_id = ?");
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
        if ((float)($p["estrellas"]   ?? 0) > 0) $libro["estrellas"]  = $p["estrellas"];
        if ((float)($p["romance"]     ?? 0) > 0) $libro["amor"]       = $p["romance"];
        if ((float)($p["spicy"]       ?? 0) > 0) $libro["spicy"]      = $p["spicy"];
        if ((float)($p["lagrimas"]    ?? 0) > 0) $libro["lagrimas"]   = $p["lagrimas"];
        if ((float)($p["plot_twist"]  ?? 0) > 0) $libro["plot_twist"] = $p["plot_twist"];
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

// Datos para compartir la lista de deseos / Wishlist
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$urlDeseosPublica = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/Reads/vistas/lista_deseos.php?usuario_id=" . $usuario["id"];
$urlQR = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($urlDeseosPublica);
$textoWhatsApp = urlencode("¡Hola! Te comparto mi wishlist de libros para que veas cuáles me gustaría leer: " . $urlDeseosPublica);

// Texto de estrellas (admite medias)
function estrellasTexto(float $v): string {
    if ($v <= 0) return '';
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' ★';
}

// Select de estrellas con medias
function selectEstrellas(float $actual): string {
    $html = '<select name="estrellas" class="input-select">';
    for ($i = 0; $i <= 10; $i++) {
        $v = $i / 2;
        $completas = (int)floor($v);
        $media = ($v - $completas) >= 0.5;
        $texto = $i === 0 ? 'Sin valorar' : str_repeat('★', $completas) . ($media ? '⯨' : '');
        $sel = abs($actual - $v) < 0.01 ? 'selected' : '';
        $html .= '<option value="' . $v . '" ' . $sel . '>' . $texto . '</option>';
    }
    return $html . '</select>';
}

// Select de nivel 0-5 con emoji (spicy, amor, plot twist, lágrimas)
function selectNivel(string $name, string $emoji, float $actual, string $vacio = 'Ninguno'): string {
    $html = '<select name="' . $name . '" class="input-select">';
    for ($i = 0; $i <= 10; $i++) {
        $v = $i / 2;
        $completas = (int)floor($v);
        $media = ($v - $completas) >= 0.5;
        $texto = $i === 0 ? $vacio : str_repeat($emoji, $completas) . ($media ? '½' : '');
        $sel = abs($actual - $v) < 0.01 ? 'selected' : '';
        $html .= '<option value="' . $v . '" ' . $sel . '>' . $texto . '</option>';
    }
    return $html . '</select>';
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
    $tituloEscapado     = htmlspecialchars($libro["titulo"], ENT_QUOTES);
    $autorEscapado      = htmlspecialchars($libro["autores"] ?? "", ENT_QUOTES);
    $portada            = htmlspecialchars($libro["portada"] ?? '/Reads/img/default_cover.jpg', ENT_QUOTES);
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
        <div class="libro-portada">
            <img src="<?= $portada ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= $placeholder ?>';">
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
                        <a href="<?= $urlCasaLibro ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">📗 La Casa del Libro</a>
                        <a href="<?= $urlAmazon ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">📙 Amazon</a>
                        <a href="<?= $urlFnac ?>" target="_blank" rel="noopener noreferrer" class="btn-comprar">📘 Fnac</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="seccion-secundaria-item">
                    <p style="margin: 0 0 5px 0;"><strong>Progreso:</strong> <?= $progreso ?>%</p>

                    <form method="POST" action="actualizar_paginas.php" style="margin: 8px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
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
                    <form action="actualizar_fechas.php" method="POST" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
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
                    <form method="POST" action="perfil.php" class="form-valoraciones">
                        <input type="hidden" name="guardar_valoraciones" value="1">
                        <input type="hidden" name="libro_id" value="<?= (int)$libro["id"] ?>">

                        <div class="fila-valoracion"><label>⭐ Estrellas:</label><?= selectEstrellas($estrellasActuales) ?></div>
                        <div class="fila-valoracion"><label>🌶️ Spicy:</label><?= selectNivel('spicy', '🌶️', $spicyActual) ?></div>
                        <div class="fila-valoracion"><label>💖 Romance:</label><?= selectNivel('romance', '💖', $amorActual) ?></div>
                        <div class="fila-valoracion"><label>🌀 Plot Twist:</label><?= selectNivel('plot_twist', '🌀', $plotTwistActual) ?></div>
                        <div class="fila-valoracion"><label>💧 Lágrimas:</label><?= selectNivel('lagrimas', '💧', $lagrimasActuales, 'Ninguna') ?></div>

                        <div><button type="submit" class="btn-accion">Guardar valoraciones</button></div>
                    </form>

                    <form method="POST" action="guardar_reseña.php" style="margin-bottom: 5px;">
                        <input type="hidden" name="libro_id" value="<?= htmlspecialchars((string)(($libro["libro_id"] ?? '') !== '' ? $libro["libro_id"] : $libro["id"]), ENT_QUOTES) ?>">
                        <input type="hidden" name="volver" value="perfil">
                        <label style="font-weight: 600;">Reseña:</label><br>
                        <textarea name="contenido" rows="3" class="input-textarea"><?= htmlspecialchars($libro["reseña_personal"] ?? "") ?></textarea>
                        <button type="submit" class="btn-accion">Guardar reseña</button>
                    </form>

                    <?php if ($estado === "abandonado"): ?>
                        <form method="POST" action="actualizar_progreso.php" style="margin-top: 8px;">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <label style="font-weight: 600;">Motivo de abandono:</label><br>
                            <textarea name="motivo_abandono" rows="2" class="input-textarea"><?= htmlspecialchars($libro["motivo_abandono"] ?? "") ?></textarea>
                            <button type="submit" class="btn-accion">Guardar motivo</button>
                        </form>
                    <?php endif; ?>
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
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <script src="/Reads/public/main.js"></script>
    <title>Mi perfil</title>

</head>
 <body class="page-perfil">

<div class="container">

    <!-- Banner de perfil -->
    <div class="perfil-card perfil-banner-container">
        <div class="perfil-header-main">
            <div class="avatar-circle">
                <?= strtoupper(substr($datos["nombre"] ?? 'A', 0, 1)) ?>
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
            <h2 class="perfil-card-titulo">📚 Mis lecturas</h2>

            <button type="button" class="wishlist-btn-destacado" data-tab="guardados">
                🎁 Wishlist <span class="wishlist-count"><?= count($guardados) ?></span>
            </button>
        </div>

        <div class="tabs-listas">
            <button type="button" class="tab-btn active" data-tab="tbr">
                🎯 TBR <span class="tab-count"><?= count($tbr) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="leyendo">
                📖 Leyendo <span class="tab-count"><?= count($leyendo) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="leidos">
                ✅ Leídos <span class="tab-count"><?= count($leidos) ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="abandonados">
                ❌ Abandonados <span class="tab-count"><?= count($abandonados) ?></span>
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

</div>

<!-- Modal de gestión del libro -->
<dialog id="modalLibro" class="modal-libro" aria-label="Gestionar libro">
    <button type="button" class="modal-cerrar" id="modalCerrar" aria-label="Cerrar">✕</button>
    <div class="modal-cuerpo" id="modalCuerpo"></div>
</dialog>

<!-- Navegación flotante -->
<div class="floating-nav-container">
   <nav class="quick-nav-floating">

        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>

        <a href="perfil.php" class="nav-card-float active">
            <span class="nav-icon">👤</span>
            <span>Mi perfil</span>
        </a>
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
const LIBROS_POR_PAGINA = 24;
const PESTANAS = ['tbr', 'leyendo', 'leidos', 'abandonados', 'guardados'];

const normaliza = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/* ---------- Pestañas ---------- */
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

/* ---------- Búsqueda, orden y "mostrar más" ---------- */
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

/* ---------- Modal de gestión ---------- */
const modal = document.getElementById('modalLibro');
const modalCuerpo = document.getElementById('modalCuerpo');

function abrirModal(card) {
    const plantilla = card.querySelector('template.libro-detalle');
    if (!plantilla) return;
    modalCuerpo.replaceChildren(plantilla.content.cloneNode(true));
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

document.getElementById('modalCerrar').addEventListener('click', () => modal.close());
modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
modal.addEventListener('close', () => {
    modalCuerpo.replaceChildren();
    document.body.classList.remove('modal-open');
});

/* ---------- Utilidades ---------- */
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
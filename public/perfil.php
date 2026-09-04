<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/ListaService.php";
require_once "../src/AjustesService.php";

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

// Listas de lectura
$guardados   = $listaService->obtenerLista($usuario["id"], "guardado") ?? [];
$tbr         = method_exists($listaService, 'obtenerLista') ? ($listaService->obtenerLista($usuario["id"], "tbr") ?? []) : [];
$leyendo     = $listaService->obtenerLista($usuario["id"], "leyendo") ?? [];
$leidos      = $listaService->obtenerLista($usuario["id"], "leido") ?? [];
$abandonados = $listaService->obtenerLista($usuario["id"], "abandonado") ?? [];

// Datos para compartir la lista de deseos / Wishlist
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$urlDeseosPublica = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/Reads/vistas/lista_deseos.php?usuario_id=" . $usuario["id"];
$urlQR = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($urlDeseosPublica);
$textoWhatsApp = urlencode("¡Hola! Te comparto mi wishlist de libros para que veas cuáles me gustaría leer: " . $urlDeseosPublica);

// Función para renderizar los libros dentro de cada pestaña
function renderizarContenidoLista($lista, $esListaDeseos = false) {
    global $listaService, $usuario;

    if (empty($lista)) {
        echo "<p style='color: var(--color-subtexto, #64748b); padding: 30px 0; text-align: center; font-style: italic;'>No hay libros en esta categoría.</p>";
        return;
    }

    foreach ($lista as $libro) {
        $paginasTotales = (int)($libro["paginas_totales"] ?? 0);
        $paginasLeidas  = (int)($libro["paginas_leidas"] ?? 0);

        if ($paginasTotales === 0) {
            $query = urlencode($libro["titulo"] . " " . ($libro["autores"] ?? ""));
            $url = "https://openlibrary.org/search.json?q=" . $query;
            $opts = ["http" => ["method" => "GET", "header" => "User-Agent: ReadingApp/1.0\r\n", "timeout" => 2]];
            $json = @file_get_contents($url, false, stream_context_create($opts));

            if ($json) {
                $data = json_decode($json, true);
                if (!empty($data["docs"][0])) {
                    $doc = $data["docs"][0];
                    $paginasTotales = (int)($doc["number_of_pages_median"] ?? $doc["number_of_pages"] ?? 0);
                }
            }

            if ($paginasTotales === 0) {
                $paginasTotales = 300;
            }

            $listaService->actualizarPaginas($usuario["id"], $libro["id"], $paginasTotales, $paginasLeidas);
        }

        $progreso = $paginasTotales > 0
            ? round(($paginasLeidas / $paginasTotales) * 100)
            : (int)($libro["progreso"] ?? 0);
        
        $estrellasActuales  = (float)($libro["estrellas"] ?? 0);
        $spicyActual        = (int)($libro["spicy"] ?? 0);
        $amorActual         = (int)($libro["amor"] ?? 0);
        $plotTwistActual    = (int)($libro["plot_twist"] ?? 0);
        $lagrimasActuales   = (int)($libro["lagrimas"] ?? 0);
        $tituloEscapado     = htmlspecialchars($libro["titulo"], ENT_QUOTES);
        $autorEscapado      = htmlspecialchars($libro["autores"] ?? "", ENT_QUOTES);

        // URLs de tiendas
        $queryBusqueda = urlencode($libro["titulo"] . " " . ($libro["autores"] ?? ""));
        $urlAmazon     = "https://www.amazon.es/s?k=" . $queryBusqueda . "&i=stripbooks";
        $urlCasaLibro  = "https://www.casadellibro.com/busqueda-libros?busqueda=" . $queryBusqueda;
        $urlFnac       = "https://www.fnac.es/ia1234/busqueda?Search=" . $queryBusqueda;
        ?>
        <div class="panel-libro-item">
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <img src="<?= htmlspecialchars($libro["portada"] ?? '/Reads/img/default_cover.jpg') ?>" style="width: 70px; height: 105px; object-fit: cover; border-radius: 6px;">

                <div style="flex: 1;">
                    <h4 style="margin: 0 0 4px 0; font-size: 1.1rem; color: inherit;"><?= $tituloEscapado ?></h4>
                    <?php if (!empty($autorEscapado)): ?>
                        <p style="margin: 0 0 10px 0; font-size: 0.85rem; opacity: 0.8;"><?= $autorEscapado ?></p>
                    <?php endif; ?>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <form method="POST" action="cambiar_estado.php" style="display: inline-flex; gap: 5px;">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <select name="estado" class="input-select">
                                <option value="guardado"   <?= $libro["estado"] === "guardado"   ? "selected" : "" ?>>Wishlist</option>
                                <option value="tbr"        <?= $libro["estado"] === "tbr"        ? "selected" : "" ?>>TBR (Pendiente)</option>
                                <option value="leyendo"    <?= $libro["estado"] === "leyendo"    ? "selected" : "" ?>>Leyendo</option>
                                <option value="leido"      <?= $libro["estado"] === "leido"      ? "selected" : "" ?>>Leído</option>
                                <option value="abandonado" <?= $libro["estado"] === "abandonado" ? "selected" : "" ?>>Abandonado</option>
                            </select>
                            <button type="submit" class="btn-accion">Cambiar</button>
                        </form>

                        <form method="POST" action="eliminar_libro.php" style="display: inline;" onsubmit="return confirm('¿Estás seguro de que quieres eliminar «<?= $tituloEscapado ?>» de tu lista?');">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <button type="submit" class="btn-accion btn-eliminar">Eliminar</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- TIENDAS DE COMPRA (WISHLIST) -->
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
                <!-- PROGRESO DE LECTURA -->
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

            <!-- FECHAS DE LECTURA -->
            <?php if ($libro["estado"] === "leyendo" || $libro["estado"] === "leido"): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-color, rgba(128,128,128,0.3));">
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

            <!-- VALORACIÓN COMPLETA -->
            <?php if ($libro["estado"] === "leido" || $libro["estado"] === "abandonado"): ?>
                <div class="seccion-secundaria-item">
                    <form method="POST" action="actualizar_estrellas.php" style="margin-bottom: 12px; display: flex; flex-direction: column; gap: 8px;">
                        <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                        
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <label style="font-weight: 600; min-width: 105px;">⭐ Estrellas:</label>
                            <select name="estrellas" class="input-select">
                                <option value="0"   <?= $estrellasActuales == 0 ? "selected" : "" ?>>Sin valorar</option>
                                <option value="0.5" <?= $estrellasActuales == 0.5 ? "selected" : "" ?>>⯨</option>
                                <option value="1"   <?= $estrellasActuales == 1 ? "selected" : "" ?>>★</option>
                                <option value="1.5" <?= $estrellasActuales == 1.5 ? "selected" : "" ?>>★⯨</option>
                                <option value="2"   <?= $estrellasActuales == 2 ? "selected" : "" ?>>★★</option>
                                <option value="2.5" <?= $estrellasActuales == 2.5 ? "selected" : "" ?>>★★⯨</option>
                                <option value="3"   <?= $estrellasActuales == 3 ? "selected" : "" ?>>★★★</option>
                                <option value="3.5" <?= $estrellasActuales == 3.5 ? "selected" : "" ?>>★★★⯨</option>
                                <option value="4"   <?= $estrellasActuales == 4 ? "selected" : "" ?>>★★★★</option>
                                <option value="4.5" <?= $estrellasActuales == 4.5 ? "selected" : "" ?>>★★★★⯨</option>
                                <option value="5"   <?= $estrellasActuales == 5 ? "selected" : "" ?>>★★★★★</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <label style="font-weight: 600; min-width: 105px;">🌶️ Spicy:</label>
                            <select name="spicy" class="input-select">
                                <option value="0" <?= $spicyActual == 0 ? "selected" : "" ?>>Ninguno</option>
                                <option value="1" <?= $spicyActual == 1 ? "selected" : "" ?>>🌶️</option>
                                <option value="2" <?= $spicyActual == 2 ? "selected" : "" ?>>🌶️🌶️</option>
                                <option value="3" <?= $spicyActual == 3 ? "selected" : "" ?>>🌶️🌶️🌶️</option>
                                <option value="4" <?= $spicyActual == 4 ? "selected" : "" ?>>🌶️🌶️🌶️🌶️</option>
                                <option value="5" <?= $spicyActual == 5 ? "selected" : "" ?>>🌶️🌶️🌶️🌶️🌶️</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <label style="font-weight: 600; min-width: 105px;">❤️ Amor:</label>
                            <select name="amor" class="input-select">
                                <option value="0" <?= $amorActual == 0 ? "selected" : "" ?>>Ninguno</option>
                                <option value="1" <?= $amorActual == 1 ? "selected" : "" ?>>❤️</option>
                                <option value="2" <?= $amorActual == 2 ? "selected" : "" ?>>❤️❤️</option>
                                <option value="3" <?= $amorActual == 3 ? "selected" : "" ?>>❤️❤️❤️</option>
                                <option value="4" <?= $amorActual == 4 ? "selected" : "" ?>>❤️❤️❤️❤️</option>
                                <option value="5" <?= $amorActual == 5 ? "selected" : "" ?>>❤️❤️❤️❤️❤️</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <label style="font-weight: 600; min-width: 105px;">🌀 Plot Twist:</label>
                            <select name="plot_twist" class="input-select">
                                <option value="0" <?= $plotTwistActual == 0 ? "selected" : "" ?>>Ninguno</option>
                                <option value="1" <?= $plotTwistActual == 1 ? "selected" : "" ?>>🌀</option>
                                <option value="2" <?= $plotTwistActual == 2 ? "selected" : "" ?>>🌀🌀</option>
                                <option value="3" <?= $plotTwistActual == 3 ? "selected" : "" ?>>🌀🌀🌀</option>
                                <option value="4" <?= $plotTwistActual == 4 ? "selected" : "" ?>>🌀🌀🌀🌀</option>
                                <option value="5" <?= $plotTwistActual == 5 ? "selected" : "" ?>>🌀🌀🌀🌀🌀</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <label style="font-weight: 600; min-width: 105px;">💧 Lágrimas:</label>
                            <select name="lagrimas" class="input-select">
                                <option value="0" <?= $lagrimasActuales == 0 ? "selected" : "" ?>>Ninguna</option>
                                <option value="1" <?= $lagrimasActuales == 1 ? "selected" : "" ?>>💧</option>
                                <option value="2" <?= $lagrimasActuales == 2 ? "selected" : "" ?>>💧💧</option>
                                <option value="3" <?= $lagrimasActuales == 3 ? "selected" : "" ?>>💧💧💧</option>
                                <option value="4" <?= $lagrimasActuales == 4 ? "selected" : "" ?>>💧💧💧💧</option>
                                <option value="5" <?= $lagrimasActuales == 5 ? "selected" : "" ?>>💧💧💧💧💧</option>
                            </select>
                        </div>

                        <div style="margin-top: 5px;">
                            <button type="submit" class="btn-accion">Guardar valoraciones</button>
                        </div>
                    </form>

                    <form method="POST" action="actualizar_reseña.php" style="margin-bottom: 5px;">
                        <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                        <label style="font-weight: 600;">Reseña:</label><br>
                        <textarea name="reseñas" rows="2" class="input-textarea"><?= htmlspecialchars($libro["reseña_personal"] ?? "") ?></textarea>
                        <button type="submit" class="btn-accion">Guardar reseña</button>
                    </form>

                    <?php if ($libro["estado"] === "abandonado"): ?>
                        <form method="POST" action="actualizar_progreso.php" style="margin-top: 8px;">
                            <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                            <label style="font-weight: 600;">Motivo de abandono:</label><br>
                            <textarea name="motivo_abandono" rows="2" class="input-textarea"><?= htmlspecialchars($libro["motivo_abandono"] ?? "") ?></textarea>
                            <button type="submit" class="btn-accion">Guardar motivo</button>
                        </form>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <title>Mi perfil</title>
<style>
    body {
        font-family: system-ui, -apple-system, sans-serif;
        margin: 0;
        padding: 20px;
        padding-bottom: 100px;
    }

    .container {
        max-width: 950px;
        margin: 0 auto;
    }

    /* CARD DE BANNER Y PERFIL */
    .perfil-card {
        border-radius: 16px;
        margin-bottom: 25px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(0, 0, 0, 0.06);
    }

    .perfil-banner-container {
        padding: 0;
        overflow: hidden;
    }

    .perfil-header-main {
        padding: 28px;
        display: flex;
        align-items: center;
        gap: 25px;
        flex-wrap: wrap;
    }

    .avatar-circle {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        font-size: 2.2rem;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    .perfil-info-main {
        flex: 1;
        min-width: 200px;
    }

    .perfil-usuario-nombre {
        margin: 0 0 4px 0;
        font-size: 1.8rem;
        font-weight: 800;
    }

    .perfil-usuario-email {
        margin: 0 0 12px 0;
        font-size: 0.95rem;
        opacity: 0.75;
    }

    .perfil-badges-group {
        display: flex;
        gap: 8px;
    }

    .badge-chip {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
        background: rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(0, 0, 0, 0.08);
    }

    .perfil-acciones-destacadas {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .btn-banner {
        padding: 9px 15px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 0.88rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        transition: all 0.2s ease;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .btn-banner:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    .btn-danger {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* CARD DE MIS LISTAS CON BOTÓN DESTACADO */
    .perfil-listas-card {
        padding: 28px;
    }

    .perfil-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        gap: 15px;
        flex-wrap: wrap;
    }

    .perfil-card-titulo {
        font-size: 1.35rem;
        margin: 0;
        font-weight: 800;
        letter-spacing: -0.3px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* BOTÓN WISHLIST DESTACADO LATERAL */
    .wishlist-btn-destacado {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(45, 90, 39, 0.08);
        color: var(--color-primario, #2d5a27);
        border: 1.5px solid var(--color-primario, #2d5a27);
        padding: 8px 16px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.88rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .wishlist-btn-destacado:hover,
    .wishlist-btn-destacado.active {
        background: var(--color-primario, #2d5a27);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(45, 90, 39, 0.25);
    }

    .wishlist-count {
        background: var(--color-primario, #2d5a27);
        color: #ffffff;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 0.75rem;
        transition: all 0.2s ease;
    }

    .wishlist-btn-destacado:hover .wishlist-count,
    .wishlist-btn-destacado.active .wishlist-count {
        background: rgba(255, 255, 255, 0.25);
    }

    /* PESTAÑAS PRINCIPALES (4 CATEGORÍAS) */
    .tabs-listas {
        display: flex;
        background: rgba(0, 0, 0, 0.04);
        padding: 5px;
        border-radius: 14px;
        gap: 4px;
        margin-bottom: 22px;
    }

    .tab-btn {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 9px 12px;
        border-radius: 10px;
        border: none;
        background: transparent;
        font-weight: 600;
        font-size: 0.88rem;
        cursor: pointer;
        color: inherit;
        opacity: 0.7;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .tab-btn:hover {
        opacity: 1;
        background: rgba(255, 255, 255, 0.4);
    }

    .tab-btn.active {
        opacity: 1;
        background: var(--color-primario, #2d5a27);
        color: #ffffff;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
    }

    .tab-count {
        background: rgba(0, 0, 0, 0.08);
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 0.75rem;
    }

    .tab-btn.active .tab-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }

    .tab-content { display: none !important; }
    .tab-content.active { display: block !important; }

    /* TARJETAS DE LIBROS Y COMPARTIR */
    .panel-libro-item {
        border-radius: 14px;
        padding: 18px;
        margin-bottom: 16px;
        border: 1px solid rgba(0, 0, 0, 0.06);
        background: rgba(255, 255, 255, 0.5);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    }

    .seccion-secundaria-item, .share-card-box {
        padding: 14px 16px;
        border-radius: 12px;
        margin-top: 12px;
        background: rgba(0, 0, 0, 0.02);
        border: 1px dashed rgba(0, 0, 0, 0.12);
    }

    .share-card-box {
        margin-bottom: 22px;
        display: flex;
        align-items: center;
        gap: 18px;
        flex-wrap: wrap;
    }

    .qr-img {
        width: 85px;
        height: 85px;
        border-radius: 8px;
        background: #ffffff;
        padding: 4px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .btn-comprar {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid rgba(0, 0, 0, 0.1);
    }

    .input-select, .input-number, .input-textarea {
        padding: 7px 12px;
        border-radius: 6px;
        font-size: 0.88rem;
        border: 1px solid rgba(0, 0, 0, 0.15);
    }

    /* NAVEGACIÓN FLOTANTE */
    .floating-nav-container {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1000;
        width: calc(100% - 40px);
        max-width: 600px;
    }

    .quick-nav-floating {
        display: flex;
        align-items: center;
        justify-content: space-around;
        padding: 8px 12px;
        background: rgba(255, 255, 255, 0.88);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.6);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
    }

    .nav-card-float {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 6px 12px;
        text-decoration: none;
        color: inherit;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 12px;
        transition: all 0.2s ease;
    }

    .nav-card-float .nav-icon {
        font-size: 1.25rem;
        margin-bottom: 2px;
    }

    /* RESPONSIVE */
    @media (max-width: 650px) {
        .perfil-header-main { flex-direction: column; text-align: center; }
        .perfil-badges-group, .perfil-acciones-destacadas { justify-content: center; }
        .tabs-listas { flex-wrap: wrap; }
        .tab-btn { flex: 1 1 45%; }
    }
</style>
</head>
<body>

<div class="container">

    <!-- BANNER DE PERFIL -->
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
                <a href="editar_perfil.php" class="btn-banner">🎨 Personalizar</a>
                <a href="logout.php" class="btn-banner btn-danger" title="Cerrar sesión">🚪</a>
            </div>
        </div>
    </div>

    <!-- MIS LISTAS DE LECTURA CON WISHLIST DESTACADA -->
    <div class="perfil-card perfil-listas-card">
        
        <div class="perfil-card-header">
            <h2 class="perfil-card-titulo">📚 Mis lecturas</h2>

            <!-- BOTÓN WISHLIST DESTACADO -->
            <button class="wishlist-btn-destacado" onclick="openTab(event, 'guardados')">
                🎁 Wishlist <span class="wishlist-count"><?= count($guardados) ?></span>
            </button>
        </div>

        <!-- 4 PESTAÑAS DE SEGUIMIENTO -->
        <div class="tabs-listas">
            <button class="tab-btn active" onclick="openTab(event, 'tbr')">
                🎯 TBR <span class="tab-count"><?= count($tbr) ?></span>
            </button>
            <button class="tab-btn" onclick="openTab(event, 'leyendo')">
                📖 Leyendo <span class="tab-count"><?= count($leyendo) ?></span>
            </button>
            <button class="tab-btn" onclick="openTab(event, 'leidos')">
                ✅ Leídos <span class="tab-count"><?= count($leidos) ?></span>
            </button>
            <button class="tab-btn" onclick="openTab(event, 'abandonados')">
                ❌ Abandonados <span class="tab-count"><?= count($abandonados) ?></span>
            </button>
        </div>

        <!-- PESTAÑA TBR (ACTIVA POR DEFECTO) -->
        <div id="lista-tbr" class="tab-content active">
            <?php renderizarContenidoLista($tbr, false); ?>
        </div>

        <!-- PESTAÑA LEYENDO -->
        <div id="lista-leyendo" class="tab-content">
            <?php renderizarContenidoLista($leyendo, false); ?>
        </div>

        <!-- PESTAÑA LEÍDOS -->
        <div id="lista-leidos" class="tab-content">
            <?php renderizarContenidoLista($leidos, false); ?>
        </div>

        <!-- PESTAÑA ABANDONADOS -->
        <div id="lista-abandonados" class="tab-content">
            <?php renderizarContenidoLista($abandonados, false); ?>
        </div>

        <!-- CONTENIDO WISHLIST -->
        <div id="lista-guardados" class="tab-content">
            <div class="share-card-box">
                <img src="<?= $urlQR ?>" alt="Código QR Wishlist" class="qr-img">
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 4px 0; font-size: 1.05rem;">🎁 Compartir mi Wishlist</h4>
                    <p style="margin: 0 0 10px 0; font-size: 0.85rem; opacity: 0.8;">Muestra este código QR o comparte tu enlace directo para que sepan qué libros regalarte.</p>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="https://api.whatsapp.com/send?text=<?= $textoWhatsApp ?>" target="_blank" class="btn-ws-icon" title="Compartir por WhatsApp">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.729.729 0 0 0-.529.247c-.182.198-.691.677-.691 1.654 0 .977.71 1.916.81 2.049.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.17-.478 1.338-.94.166-.464.166-.86.116-.94-.048-.08-.182-.133-.379-.232z"/>
                            </svg>
                        </a>
                        <button onclick="copiarEnlace('<?= $urlDeseosPublica ?>')" class="btn-accion">🔗 Copiar Enlace</button>
                    </div>
                </div>
            </div>

            <?php renderizarContenidoLista($guardados, true); ?>
        </div>

    </div>

</div>

<!-- NAVEGACIÓN FLOTANTE -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>
        <a href="biblioteca.php" class="nav-card-float">
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
            <span>Buscar libros</span>
        </a>
    </nav>
</div>

<script>
function openTab(evt, tabName) {
    // Ocultar todos los contenidos
    const contents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < contents.length; i++) {
        contents[i].classList.remove("active");
    }

    // Limpiar estado activo de botones estándar
    const tabButtons = document.getElementsByClassName("tab-btn");
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].classList.remove("active");
    }

    // Limpiar estado activo de Wishlist destacado
    const wishlistBtn = document.querySelector(".wishlist-btn-destacado");
    if (wishlistBtn) {
        wishlistBtn.classList.remove("active");
    }

    // Activar el contenedor deseado y el botón presionado
    document.getElementById("lista-" + tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

function copiarEnlace(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert("¡Enlace copiado al portapapeles!");
    }).catch(() => {
        prompt("Copia este enlace:", url);
    });
}
</script>
</body>
</html>
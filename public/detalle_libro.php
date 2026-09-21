<?php
// public/detalle_libro.php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/ListaService.php";
require_once __DIR__ . '/../src/helpers.php';

// Validar Sesión
$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$userService  = new UserService();
$listaService = new ListaService();

// Cargar Tema del usuario
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datosUsuario["tema_visual"] ?? "pastel";

// Obtener ID del libro desde el parámetro GET
$id_param = $_GET['id'] ?? null;
if (!$id_param) {
    header("Location: biblioteca.php");
    exit;
}

// Buscar la información completa del libro en las listas del usuario
$todasLasListas = array_merge(
    $listaService->obtenerLista($usuario["id"], "guardado") ?? [],
    $listaService->obtenerLista($usuario["id"], "leyendo") ?? [],
    $listaService->obtenerLista($usuario["id"], "leido") ?? [],
    $listaService->obtenerLista($usuario["id"], "abandonado") ?? []
);

$libro = null;
foreach ($todasLasListas as $l) {
    // Compara como texto o id para evitar fallos si es id numérico o id de Google Books
    if ((string)$l["id"] === (string)$id_param || (string)($l["libro_id"] ?? '') === (string)$id_param) {
        $libro = $l;
        break;
    }
}

// Si no se encuentra en las listas del usuario, redirigir
if (!$libro) {
    header("Location: biblioteca.php");
    exit;
}

// Cálculos de Progreso y Auto-recuperación de Páginas
$paginasTotales = (int)($libro["paginas_totales"] ?? 0);
$paginasLeidas  = (int)($libro["paginas_leidas"] ?? 0);

// Si en la base de datos las páginas totales son 0 y tenemos el ID de Google Books, las traemos automáticamente
if ($paginasTotales === 0 && !empty($libro["libro_id"])) {
    $apiUrl = "https://www.googleapis.com/books/v1/volumes/" . $libro["libro_id"];
    $json = @file_get_contents($apiUrl);
    if ($json) {
        $data = json_decode($json, true);
        $paginasApi = (int)($data["volumeInfo"]["pageCount"] ?? 0);
        if ($paginasApi > 0) {
            $paginasTotales = $paginasApi;
            // Si el libro está leído, las páginas leídas deben ser el total
            if ($libro["estado"] === "leido") {
                $paginasLeidas = $paginasTotales;
            }
            // Guardamos el dato corregido en la base de datos
            $listaService->actualizarPaginas($usuario["id"], $libro["id"], $paginasTotales, $paginasLeidas);
        }
    }
}

// Si está leído y tenía páginas totales pero no leídas, igualamos leídas = totales
if ($libro["estado"] === "leido" && $paginasLeidas === 0 && $paginasTotales > 0) {
    $paginasLeidas = $paginasTotales;
    $listaService->actualizarPaginasLeidas($usuario["id"], $libro["id"], $paginasLeidas);
}

$progreso = $paginasTotales > 0
    ? round(($paginasLeidas / $paginasTotales) * 100)
    : (int)($libro["progreso"] ?? 0);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="main.js"></script>
    <title><?= htmlspecialchars($libro["titulo"]) ?> - Reads</title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            padding: 30px 15px;
            display: flex;
            justify-content: center;
        }

        .contenedor-ficha {
            max-width: 700px;
            width: 100%;
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .libro-header {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }

        .libro-portada {
            width: 160px;
            height: auto;
            border-radius: 6px;
            object-fit: cover;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .libro-info {
            flex: 1;
        }

        .libro-titulo {
            margin-top: 0;
            font-size: 1.8em;
            color: #222;
        }

        .panel-seccion {
            background: #fdfdfd;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .barra-progreso {
            background-color: #e0e0e0;
            border-radius: 10px;
            height: 12px;
            width: 100%;
            overflow: hidden;
            margin-top: 10px;
        }

        .barra-progreso-inner {
            background-color: #4caf50;
            height: 100%;
            transition: width 0.3s ease;
        }

        .campo-grupo {
            margin-bottom: 15px;
        }

        .campo-grupo label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .campo-grupo input, 
        .campo-grupo select, 
        .campo-grupo textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        .inline-form {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
        }

        button {
            background-color: #2c3e50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            opacity: 0.9;
        }

        .btn-eliminar {
            background-color: #e74c3c;
        }

        .enlace-volver {
            display: inline-block;
            margin-top: 25px;
            color: #555;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="contenedor-ficha">

    <!-- Cabecera del libro -->
    <div class="libro-header">
        <img src="<?= htmlspecialchars($libro["portada"] ?? '/Reads/img/default_cover.jpg') ?>" class="libro-portada" alt="Portada">

        <div class="libro-info">
            <h2 class="libro-titulo"><?= htmlspecialchars($libro["titulo"]) ?></h2>
            <p><strong>Autor:</strong> <?= htmlspecialchars($libro["autores"] ?? 'Desconocido') ?></p>
            
            <!-- Acciones de perfil: cambiar estado y eliminar libro -->
            <div class="acciones-perfil">
                <form method="POST" action="cambiar_estado.php" class="inline-form">
                    <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                    <select name="estado">
                        <option value="guardado"   <?= $libro["estado"] === "guardado"   ? "selected" : "" ?>>Guardado</option>
                        <option value="leyendo"    <?= $libro["estado"] === "leyendo"    ? "selected" : "" ?>>Leyendo</option>
                        <option value="leido"      <?= $libro["estado"] === "leido"      ? "selected" : "" ?>>Leído</option>
                        <option value="abandonado" <?= $libro["estado"] === "abandonado" ? "selected" : "" ?>>Abandonado</option>
                    </select>
                    <button type="submit">Cambiar estado</button>
                </form>

                <form method="POST" action="eliminar_libro.php" class="inline-form" style="margin-top: 10px;">
                    <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                    <button type="submit" class="btn-eliminar">Eliminar libro</button>
                </form>
            </div>
        </div>
    </div>

<!-- Tarjeta de progreso -->
<div class="panel-seccion">
    <h3>Progreso de lectura</h3>
    <p><strong>Progreso actual:</strong> <?= $progreso ?>%</p>

    <!-- Formulario de actualización de páginas leídas -->
    <form method="POST" action="actualizar_paginas.php" class="campo-grupo">
        <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">

        <label for="paginas_leidas">Páginas leídas:</label>
        <div class="inline-form">
            <!-- Si paginasTotales es 0 temporalmente, quitamos el limite max para que no bloquee el HTML -->
            <input type="number" id="paginas_leidas" name="paginas_leidas" min="0" <?= $paginasTotales > 0 ? 'max="' . $paginasTotales . '"' : '' ?> value="<?= $paginasLeidas ?>">
            <button type="submit">Actualizar</button>
        </div>
    </form>

    <p style="margin-top: 15px;"><strong>Páginas totales:</strong> <?= $paginasTotales > 0 ? $paginasTotales : 'Cargando/No disponible' ?></p>

    <div class="barra-progreso" style="margin-top: 10px;">
        <div class="barra-progreso-inner" style="width:<?= $progreso ?>%;"></div>
    </div>
</div>

    <!-- Valoración y opinión -->
    <?php if ($libro["estado"] === "leido" || $libro["estado"] === "abandonado"): ?>
        <div class="panel-seccion">
            <h3>Tu valoración y opinión</h3>

            <!-- Valoración en estrellas -->
            <form method="POST" action="actualizar_estrellas.php" class="campo-grupo">
                <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                <label for="estrellas">Valoración:</label>
                <div class="inline-form">
                    <select name="estrellas" id="estrellas">
                        <option value="1" <?= ($libro["estrellas"] ?? 0) == 1 ? "selected" : "" ?>>⭐</option>
                        <option value="2" <?= ($libro["estrellas"] ?? 0) == 2 ? "selected" : "" ?>>⭐⭐</option>
                        <option value="3" <?= ($libro["estrellas"] ?? 0) == 3 ? "selected" : "" ?>>⭐⭐⭐</option>
                        <option value="4" <?= ($libro["estrellas"] ?? 0) == 4 ? "selected" : "" ?>>⭐⭐⭐⭐</option>
                        <option value="5" <?= ($libro["estrellas"] ?? 0) == 5 ? "selected" : "" ?>>⭐⭐⭐⭐⭐</option>
                    </select>
                    <button type="submit">Guardar estrellas</button>
                </div>
            </form>

            <!-- Reseña personal -->
            <form method="POST" action="actualizar_reseña.php" class="campo-grupo">
                <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                <label for="reseñas">Reseña:</label>
                <textarea name="reseñas" id="reseñas" rows="4"><?= htmlspecialchars($libro["reseña_personal"] ?? "") ?></textarea>
                <button type="submit" style="margin-top: 8px;">Guardar reseña</button>
            </form>

            <!-- Fecha de finalización -->
            <?php if ($libro["estado"] === "leido"): ?>
                <p><strong>Fecha de finalización:</strong> <?= htmlspecialchars($libro["fecha_fin"] ?? 'No registrada') ?></p>
            <?php endif; ?>

            <!-- Motivo de abandono -->
            <?php if ($libro["estado"] === "abandonado"): ?>
                <form method="POST" action="actualizar_progreso.php" class="campo-grupo">
                    <input type="hidden" name="libro_id" value="<?= $libro["id"] ?>">
                    <label for="motivo_abandono">Motivo de abandono:</label>
                    <textarea name="motivo_abandono" id="motivo_abandono" rows="3"><?= htmlspecialchars($libro["motivo_abandono"] ?? "") ?></textarea>
                    <button type="submit" style="margin-top: 8px;">Guardar motivo</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a href="perfil.php" class="enlace-volver">&larr; Volver a mi perfil</a>
</div>

</body>
</html>
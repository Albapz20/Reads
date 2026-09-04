<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();

if (!$usuario) {
    header("Location: login.php");
    exit;
}

$db = new Database();
$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

// Manejo del año seleccionado (Por defecto el año actual)
$yearActual = (int)date("Y");
$yearSeleccionado = isset($_GET['year']) ? (int)$_GET['year'] : $yearActual;
$verTodo = isset($_GET['year']) && $_GET['year'] === 'todos';

/* =========================================================
   CONSULTA DE LIBROS
   ========================================================= */

if ($verTodo) {
    $sql = "
        SELECT id, titulo, portada, fecha_fin
        FROM listas_lectura
        WHERE usuario_id = ? AND estado = 'leido'
        ORDER BY fecha_fin ASC, id ASC
    ";
    $params = [$usuario["id"]];
} else {
    $sql = "
        SELECT id, titulo, portada, fecha_fin
        FROM listas_lectura
        WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
        ORDER BY fecha_fin ASC, id ASC
    ";
    $params = [$usuario["id"], $yearSeleccionado];
}

$stmt = $db->pdo->prepare($sql);
$stmt->execute($params);
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener la lista de años disponibles para el selector
$sqlAños = "
    SELECT DISTINCT YEAR(fecha_fin) as anio 
    FROM listas_lectura 
    WHERE usuario_id = ? AND estado = 'leido' AND fecha_fin IS NOT NULL
    ORDER BY anio DESC
";
$stmtAños = $db->pdo->prepare($sqlAños);
$stmtAños->execute([$usuario["id"]]);
$aniosDisponibles = $stmtAños->fetchAll(PDO::FETCH_COLUMN);

if (!in_array($yearActual, $aniosDisponibles)) {
    array_unshift($aniosDisponibles, $yearActual);
}

function e($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   GENERAR BALDA (LIMPIA Y SIN DECORACIONES)
   ========================================================= */
function generarBalda($librosGrupo) {
    ?>
    <div class="balda-seccion">
        <div class="hueco-balda">
            <div class="libros">
                <?php foreach ($librosGrupo as $libro): 
                    $titulo = e($libro["titulo"]);
                    $tienePortada = !empty($libro["portada"]) && filter_var($libro["portada"], FILTER_VALIDATE_URL);
                ?>
                    <a href="detalle_libro.php?id=<?= (int)$libro["id"] ?>" 
                       class="libro libro-portada" 
                       title="<?= $titulo ?>">
                        
                        <div class="libro-cuerpo">
                            <?php if ($tienePortada): ?>
                                <img src="<?= e($libro["portada"]) ?>" 
                                     alt="<?= $titulo ?>" 
                                     class="imagen-portada" 
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                     loading="lazy">
                                <div class="portada-fallback" style="display: none;">
                                    <span><?= $titulo ?></span>
                                </div>
                            <?php else: ?>
                                <div class="portada-fallback">
                                    <span><?= $titulo ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="sombra-libro"></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Estructura de la balda de madera -->
        <div class="balda-madera"></div>
    </div>
    <?php
}

function generarEstanteria($libros) {
    if (empty($libros)): ?>
        <div class="biblioteca-vacia">
            <div class="biblioteca-vacia-icono">📚</div>
            <h3>Tu estantería está vacía para este periodo</h3>
            <p>Los libros que leas irán apareciendo aquí automáticamente sobre tus repisas.</p>
        </div>
        <?php return;
    endif;

    // Libros mostrados por cada balda
    $librosPorBalda = 5;
    $grupos = array_chunk($libros, $librosPorBalda);

    foreach ($grupos as $grupo) {
        generarBalda($grupo);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi Biblioteca</title>

<link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
<style>
/* Base */
body {
    background-color: var(--bg-biblioteca, #f4eade);
    color: var(--texto-biblioteca, #4a3b32);
    font-family: 'Georgia', serif;
    margin: 0;
    padding: 20px;
}

.biblioteca-wrapper {
    width: 100%;
    max-width: 950px;
    margin: 0 auto;
}

/* Cabecera */
.biblioteca-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0 10px;
}

.biblioteca-header h2 {
    font-size: 28px;
    color: var(--texto-biblioteca, #4a3b32);
    margin: 0;
}

.selector-anios select {
    background: var(--selector-bg, #ffffff);
    color: var(--texto-biblioteca, #4a3b32);
    border: 1px solid var(--selector-borde, #d4a373);
    padding: 8px 15px;
    border-radius: 8px;
    font-size: 15px;
    cursor: pointer;
}

/* Mueble / Marco Estantería */
.estanteria {
    position: relative;
    padding: 35px 20px 25px;
    border-radius: 6px;
    background: 
        linear-gradient(180deg, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.1) 40%, rgba(0, 0, 0, 0.4) 100%),
        url("/Reads/img/wood_texture_light.png") center center / cover no-repeat #e3caad;
        
    border-style: solid;
    border-width: 16px;
    border-color: var(--borde-estanteria, #8d5b4c);
    
    box-shadow: 
        inset 0 0 0 2px rgba(255,255,255,0.2),
        inset 6px 6px 18px rgba(0, 0, 0, 0.6),
        0 15px 30px rgba(0, 0, 0, 0.3);
}

/* Secciones y Baldas */
.balda-seccion {
    position: relative;
    margin-bottom: 35px;
}

.hueco-balda {
    position: relative;
    min-height: 180px;
}

/* Alineación de Libros */
.libros {
    position: relative;
    display: flex !important;
    flex-direction: row !important;
    align-items: flex-end !important;
    gap: 18px;
    padding: 0 20px;
    z-index: 5;
}

/* Libro Portada Frontal */
.libro-portada {
    position: relative;
    display: inline-block;
    height: 175px;
    width: 115px;
    text-decoration: none;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    flex-shrink: 0;
}

.libro-portada .libro-cuerpo {
    height: 100%;
    width: 100%;
    border-radius: 3px 6px 6px 3px;
    overflow: hidden;
    box-shadow: 
        3px 4px 10px rgba(0, 0, 0, 0.4),
        inset -2px 0 4px rgba(0, 0, 0, 0.25);
}

.imagen-portada {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Cubierta alternativa elegante para libros sin portada o imagen rota */
.portada-fallback {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #2c3e50, #4ca1af);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
    box-sizing: border-box;
    text-align: center;
    font-family: 'Georgia', serif;
    font-size: 13px;
    font-weight: bold;
    line-height: 1.3;
    border: 1px solid rgba(255,255,255,0.2);
    box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
}

.libro-portada:hover {
    transform: translateY(-8px) scale(1.04);
    z-index: 20;
}

.sombra-libro {
    position: absolute;
    bottom: -3px;
    left: 4px;
    right: 4px;
    height: 6px;
    background: rgba(0,0,0,0.6);
    border-radius: 50%;
    filter: blur(3px);
    z-index: -1;
}

/* Balda de Madera */
.balda-madera {
    position: relative;
    height: 18px;
    background: var(--madera-balda, #8d5b4c);
    border-radius: 2px;
    box-shadow: 0 6px 12px rgba(0,0,0,0.5);
    width: 100%;
    margin-top: 0;
}

.balda-madera::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: rgba(255,255,255,0.2);
}

/* Mensaje biblioteca vacía */
.biblioteca-vacia {
    text-align: center;
    padding: 60px 20px;
    color: #6b5246;
}

.biblioteca-vacia-icono {
    font-size: 48px;
    margin-bottom: 10px;
}
</style>
</head>
<body>

<div class="biblioteca-wrapper">

    <!-- CABECERA CON FILTRO DE AÑO -->
    <div class="biblioteca-header">
        <h2>📚 Estantería <?= $verTodo ? 'Histórica' : $yearSeleccionado ?></h2>

        <div class="selector-anios">
            <form method="GET" action="">
                <select name="year" onchange="this.form.submit()">
                    <option value="todos" <?= $verTodo ? 'selected' : '' ?>>Todas las lecturas</option>
                    <?php foreach ($aniosDisponibles as $anio): ?>
                        <option value="<?= $anio ?>" <?= (!$verTodo && $yearSeleccionado == $anio) ? 'selected' : '' ?>>
                            Año <?= $anio ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- ESTANTERÍA -->
    <div class="estanteria">
        <?php generarEstanteria($libros); ?>
    </div>

    <div style="margin-top: 25px;">
        <a href="index.php" style="color: #8d5b4c; text-decoration: none; font-weight: bold;">← Volver al inicio</a>
        <a href="perfil.php" style="color: #8d5b4c; text-decoration: none; font-weight: bold; margin-left: 20px;">← Volver a mi perfil</a>
    </div>

</div>

</body>
</html>
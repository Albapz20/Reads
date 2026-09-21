<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$db = new Database();

// Procesar el registro de lectura diaria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_progreso_diario') {
    $idLista = (int)$_POST['id_lista'];
    $fecha = $_POST['fecha_registro'];
    $paginas = (int)$_POST['paginas_leidas'];

    if ($idLista > 0 && !empty($fecha) && $paginas >= 0) {
        // Guardar o actualizar registro diario en la base de datos
        $sql = "INSERT INTO diario_lectura (usuario_id, libro_id, fecha, paginas_leidas) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE paginas_leidas = VALUES(paginas_leidas)";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute([$usuario['id'], $idLista, $fecha, $paginas]);

        // Actualizar el número de páginas globales leídas en tu lista de lectura
        $sqlPaginas = "UPDATE listas_lectura SET paginas_leidas = ? WHERE id = ? AND usuario_id = ?";
        $stmtPag = $db->pdo->prepare($sqlPaginas);
        $stmtPag->execute([$paginas, $idLista, $usuario['id']]);
    }

    $mesRed = date('m', strtotime($fecha));
    $yearRed = date('Y', strtotime($fecha));
    header("Location: calendario.php?mes={$mesRed}&year={$yearRed}");
    exit;
}

// Eliminar un registro diario
if (isset($_GET['eliminar_diario'])) {
    $idDiario = (int)$_GET['eliminar_diario'];
    
    // Eliminar solo si pertenece al usuario actual
    $sqlDelete = "DELETE FROM diario_lectura WHERE id = ? AND usuario_id = ?";
    $stmtDelete = $db->pdo->prepare($sqlDelete);
    $stmtDelete->execute([$idDiario, $usuario['id']]);

    $mesRed = $_GET['mes'] ?? date('m');
    $yearRed = $_GET['year'] ?? date('Y');
    header("Location: calendario.php?mes={$mesRed}&year={$yearRed}");
    exit;
}

// Procesar nuevo lanzamiento futuro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'nuevo_lanzamiento') {
    $titulo = trim($_POST['titulo'] ?? '');
    $autor = trim($_POST['autor'] ?? '');
    $portada = trim($_POST['portada'] ?? '');
    $fecha = $_POST['fecha_lanzamiento'] ?? '';

    if (!empty($titulo) && !empty($fecha)) {
        $sqlInsert = "INSERT INTO lanzamientos_deseados (usuario_id, titulo, autor, portada, fecha_lanzamiento) VALUES (?, ?, ?, ?, ?)";
        $stmtInsert = $db->pdo->prepare($sqlInsert);
        $stmtInsert->execute([$usuario['id'], $titulo, $autor, $portada, $fecha]);
    }
    
    $mesRedirect = date('m', strtotime($fecha));
    $yearRedirect = date('Y', strtotime($fecha));
    header("Location: calendario.php?mes={$mesRedirect}&year={$yearRedirect}");
    exit;
}

// Eliminar un lanzamiento futuro
if (isset($_GET['eliminar_lanzamiento'])) {
    $idEliminar = (int)$_GET['eliminar_lanzamiento'];
    $sqlDelete = "DELETE FROM lanzamientos_deseados WHERE id = ? AND usuario_id = ?";
    $stmtDelete = $db->pdo->prepare($sqlDelete);
    $stmtDelete->execute([$idEliminar, $usuario['id']]);
    header("Location: calendario.php?mes=" . ($_GET['mes'] ?? date('m')) . "&year=" . ($_GET['year'] ?? date('Y')));
    exit;
}

// Control de mes y año para mostrar en el calendario
$mesActual = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$yearActual = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($mesActual < 1) { $mesActual = 12; $yearActual--; }
if ($mesActual > 12) { $mesActual = 1; $yearActual++; }

$primerDiaMes = "$yearActual-" . str_pad($mesActual, 2, '0', STR_PAD_LEFT) . "-01";
$ultimoDiaMes = date("Y-m-t", strtotime($primerDiaMes));

// Obtener la lista de libros que el usuario está leyendo actualmente
$sqlMisLibros = "SELECT id, titulo, paginas_totales, paginas_leidas 
                 FROM listas_lectura 
                 WHERE usuario_id = ? AND estado = 'leyendo'";
$stmtMisLibros = $db->pdo->prepare($sqlMisLibros);
$stmtMisLibros->execute([$usuario['id']]);
$misLibrosLeyendo = $stmtMisLibros->fetchAll(PDO::FETCH_ASSOC);

$eventosPorDia = [];

// Avances diarios (Anotaciones manuales de lectura)
$sqlDiario = "SELECT d.id as diario_id, d.fecha, d.paginas_leidas, l.titulo, l.portada, l.id as lista_id
              FROM diario_lectura d
              INNER JOIN listas_lectura l ON d.libro_id = l.id
              WHERE d.usuario_id = ? AND d.fecha BETWEEN ? AND ?";
$stmtDiario = $db->pdo->prepare($sqlDiario);
$stmtDiario->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtDiario->fetchAll(PDO::FETCH_ASSOC) as $reg) {
    $dia = (int)date('j', strtotime($reg['fecha']));
    $reg['tipo'] = 'diario';
    $eventosPorDia[$dia][] = $reg;
}

// Fechas automáticas de inicio y fin de libro
$sqlHitos = "SELECT id, titulo, portada, fecha_inicio, fecha_fin 
             FROM listas_lectura 
             WHERE usuario_id = ? 
               AND ((fecha_inicio BETWEEN ? AND ?) OR (fecha_fin BETWEEN ? AND ?))";
$stmtHitos = $db->pdo->prepare($sqlHitos);
$stmtHitos->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes, $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtHitos->fetchAll(PDO::FETCH_ASSOC) as $hito) {
    if (!empty($hito['fecha_inicio']) && $hito['fecha_inicio'] >= $primerDiaMes && $hito['fecha_inicio'] <= $ultimoDiaMes) {
        $dia = (int)date('j', strtotime($hito['fecha_inicio']));
        $eventosPorDia[$dia][] = [
            'tipo' => 'hito_inicio',
            'titulo' => $hito['titulo'],
            'portada' => $hito['portada']
        ];
    }
    if (!empty($hito['fecha_fin']) && $hito['fecha_fin'] >= $primerDiaMes && $hito['fecha_fin'] <= $ultimoDiaMes) {
        $dia = (int)date('j', strtotime($hito['fecha_fin']));
        $eventosPorDia[$dia][] = [
            'tipo' => 'hito_fin',
            'titulo' => $hito['titulo'],
            'portada' => $hito['portada']
        ];
    }
}

// Lanzamientos futuros
$sqlLanz = "SELECT id, titulo, portada, fecha_lanzamiento 
            FROM lanzamientos_deseados 
            WHERE usuario_id = ? AND fecha_lanzamiento BETWEEN ? AND ?";
$stmtLanz = $db->pdo->prepare($sqlLanz);
$stmtLanz->execute([$usuario['id'], $primerDiaMes, $ultimoDiaMes]);
foreach ($stmtLanz->fetchAll(PDO::FETCH_ASSOC) as $lz) {
    $dia = (int)date('j', strtotime($lz['fecha_lanzamiento']));
    $lz['tipo'] = 'lanzamiento';
    $eventosPorDia[$dia][] = $lz;
}

$diasEnMes = date('t', strtotime($primerDiaMes));
$primerDiaSemana = date('N', strtotime($primerDiaMes));
$mesesEs = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Calendario de Lectura</title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <script src="main.js"></script>
    <style>
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .day-name { text-align: center; font-weight: bold; padding: 8px 0; opacity: 0.7; }
        .day-cell {
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            min-height: 110px;
            padding: 6px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .day-cell:hover { background: rgba(255, 255, 255, 0.95); transform: translateY(-2px); }
        .day-cell.empty { background: transparent; border: none; cursor: default; }
        .day-number { font-size: 0.85rem; font-weight: bold; opacity: 0.7; }
        .day-cell.today { border: 2px solid var(--primary-color, #333); background: #fff; }

        .entry-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            background: #fff;
            padding: 3px 5px;
            border-radius: 6px;
            margin-top: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            position: relative;
        }
        .entry-card-content { display: flex; align-items: center; gap: 5px; overflow: hidden; }
        .entry-card img { width: 22px; height: 32px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
        .badge { font-size: 0.65rem; font-weight: bold; padding: 2px 4px; border-radius: 4px; }
        .badge-diario { background: #e8f0fe; color: #1a73e8; }
        .badge-inicio { background: #e6f4ea; color: #137333; }
        .badge-fin { background: #fce8e6; color: #c5221f; }
        .badge-lanzamiento { background: #feefc3; color: #b06000; }

        .btn-delete-entry {
            color: #d9534f;
            text-decoration: none;
            font-weight: bold;
            font-size: 11px;
            padding: 0 3px;
            border-radius: 3px;
            line-height: 1;
            transition: background 0.2s;
        }
        .btn-delete-entry:hover { background: #f8d7da; }

        .form-lanzamiento {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 20px;
        }

        .modal {
            display: none; position: fixed; z-index: 100;
            left: 0; top: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.4); justify-content: center; align-items: center;
        }
        .modal-content { background: #fff; padding: 20px; border-radius: 12px; width: 320px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

<div class="container">
    <h1>📅 Calendario de Lectura</h1>
    <p>Haz clic en cualquier casilla del día para anotar las páginas leídas de tus libros activos.</p>

    <!-- Lanzamientos futuros -->
    <details class="form-lanzamiento">
        <summary style="font-weight: bold; cursor: pointer;">
            🚀 Recordar la fecha de lanzamiento de un libro futuro
        </summary>
        <form method="POST" style="margin-top: 12px;">
            <input type="hidden" name="accion" value="nuevo_lanzamiento">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
                <input type="text" name="titulo" placeholder="Título del libro *" required style="padding: 8px;">
                <input type="text" name="autor" placeholder="Autor / Autora" style="padding: 8px;">
                <input type="url" name="portada" placeholder="URL Portada (opcional)" style="padding: 8px;">
                <input type="date" name="fecha_lanzamiento" value="<?= date('Y-m-d') ?>" required style="padding: 8px;">
            </div>
            <div style="margin-top: 10px; text-align: right;">
                <button type="submit" style="padding: 8px 16px; background: #2c3e50; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">Guardar Recordatorio</button>
            </div>
        </form>
    </details>

    <!-- Navegación -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <a href="?mes=<?= $mesActual-1 ?>&year=<?= $yearActual ?>" class="btn">← Anterior</a>
        <h2><?= $mesesEs[$mesActual] ?> <?= $yearActual ?></h2>
        <a href="?mes=<?= $mesActual+1 ?>&year=<?= $yearActual ?>" class="btn">Siguiente →</a>
    </div>

    <!-- Rejilla del calendario -->
    <div class="calendar-grid">
        <div class="day-name">Lun</div><div class="day-name">Mar</div><div class="day-name">Mié</div>
        <div class="day-name">Jue</div><div class="day-name">Vie</div><div class="day-name">Sáb</div><div class="day-name">Dom</div>

        <?php
        for ($i = 1; $i < $primerDiaSemana; $i++) {
            echo '<div class="day-cell empty"></div>';
        }

        $hoyStr = date('Y-m-d');
        for ($dia = 1; $dia <= $diasEnMes; $dia++) {
            $fechaFormatted = sprintf("%04d-%02d-%02d", $yearActual, $mesActual, $dia);
            $esHoy = ($fechaFormatted === $hoyStr) ? 'today' : '';

            echo "<div class='day-cell {$esHoy}' onclick='abrirModal(\"{$fechaFormatted}\")'>";
            echo "<span class='day-number'>{$dia}</span>";

            if (isset($eventosPorDia[$dia])) {
                foreach ($eventosPorDia[$dia] as $ev) {
                    $portada = !empty($ev['portada']) ? htmlspecialchars($ev['portada']) : '/Reads/img/default_cover.jpg';
                    $titulo = htmlspecialchars($ev['titulo']);

                    echo "<div class='entry-card' title='{$titulo}'>";
                    echo "<div class='entry-card-content'>";
                    echo "<img src='{$portada}'>";
                    echo "<div style='display:flex; flex-direction:column; overflow:hidden;'>";

                    if ($ev['tipo'] === 'diario') {
                        echo "<span class='badge badge-diario'>Pág. {$ev['paginas_leidas']}</span>";
                    } elseif ($ev['tipo'] === 'hito_inicio') {
                        echo "<span class='badge badge-inicio'>🚀 Empieza</span>";
                    } elseif ($ev['tipo'] === 'hito_fin') {
                        echo "<span class='badge badge-fin'>🏁 Terminado</span>";
                    } elseif ($ev['tipo'] === 'lanzamiento') {
                        echo "<span class='badge badge-lanzamiento'>📣 Salida</span>";
                    }

                    echo "</div>";
                    echo "</div>";

                    // Botón de eliminar solo para anotaciones diarias manuales
                    if ($ev['tipo'] === 'diario') {
                        $urlBorrar = "?eliminar_diario={$ev['diario_id']}&mes={$mesActual}&year={$yearActual}";
                        echo "<a href='{$urlBorrar}' class='btn-delete-entry' onclick='event.stopPropagation(); return confirm(\"¿Borrar este registro de lectura?\");' title='Borrar anotación'>✕</a>";
                    }

                    echo "</div>";
                }
            }
            echo "</div>";
        }
        ?>
    </div>
</div>

<!-- Formulario para anotar lectura diaria -->
<div id="modalLectura" class="modal" onclick="cerrarModal();">
    <div class="modal-content" onclick="event.stopPropagation();">
        <h3 style="margin-top:0;">Anotar Lectura</h3>
        <?php if (empty($misLibrosLeyendo)): ?>
            <p style="font-size:0.9rem; color:#666;">No tienes ningún libro actualmente en estado <strong>"Leyendo"</strong>. Cambia el estado de un libro en tu perfil para registrar tus avances diarios.</p>
            <button type="button" onclick="cerrarModal()" style="padding:8px 12px; border:none; background:#ccc; border-radius:6px; cursor:pointer;">Cerrar</button>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_progreso_diario">
                <input type="hidden" name="fecha_registro" id="modal_fecha">

                <label style="font-size:0.85rem;">Selecciona el libro:</label>
                <select name="id_lista" required style="width:100%; padding:8px; margin: 5px 0 12px 0;">
                    <?php foreach ($misLibrosLeyendo as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['titulo']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="font-size:0.85rem;">Página alcanzada en este día:</label>
                <input type="number" name="paginas_leidas" min="0" required style="width:93%; padding:8px; margin: 5px 0 15px 0;">

                <div style="display:flex; justify-content:space-between;">
                    <button type="button" onclick="cerrarModal()" style="padding:8px 12px; border:none; background:#ccc; border-radius:6px; cursor:pointer;">Cancelar</button>
                    <button type="submit" style="padding:8px 12px; border:none; background:#2c3e50; color:#fff; border-radius:6px; cursor:pointer;">Guardar</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function abrirModal(fecha) {
    document.getElementById('modal_fecha').value = fecha;
    document.getElementById('modalLectura').style.display = 'flex';
}
function cerrarModal() {
    document.getElementById('modalLectura').style.display = 'none';
}
</script>
 <div style="margin-top: 20px;">
        <a href="index.php" style="color: #d4a373; text-decoration: none;">← Volver al inicio</a>
        <a href="perfil.php" style="color: #d4a373; text-decoration: none; margin-left: 20px;">← Volver a mi perfil</a>
    </div>

</body>
</html>
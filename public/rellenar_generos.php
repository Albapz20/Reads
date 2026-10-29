<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/GenerosHelper.php";

if (session_status() === PHP_SESSION_NONE) session_start();
@set_time_limit(120);

$usuario = Auth::usuario();
$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }
session_write_close();   // libera la sesión: el resto de páginas no se quedan esperando a esta tanda

$db  = new Database();
$pdo = $db->pdo;
$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

// La pantalla manual es solo para el administrador; la detección en segundo plano (ajax) es para todos
$esAdmin = (strtolower(trim($datos['email'] ?? $usuario['email'] ?? '')) === 'admin@admin.com');
$esAjax  = (($_POST['ajax'] ?? '') === '1');
if (!$esAjax && !$esAdmin) { header("Location: estadisticas.php"); exit; }
ignore_user_abort(true);   // si el usuario cambia de página, la tanda termina igualmente

$col = columnaGeneros($pdo);
if ($col === null) { die("No se pudo acceder a la columna de géneros de la tabla listas_lectura."); }

$condPend = condicionGeneroPendiente($col);   
$cols    = columnasListasLectura($pdo);
$colCat  = $cols['categorias'] ?? ($cols['categories'] ?? null);
$colDesc = $cols['descripcion'] ?? ($cols['description'] ?? null);

$LOTE = 8;                      // libros por tanda
$resultados = [];               // filas para mostrar lo que ha pasado
$aviso = '';
$hizoProceso = false;
$accion = $_POST['accion'] ?? '';

/*  Procesar una tanda automática  */
$pausa = false;
$guardados = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'procesar') {
    $hizoProceso = true;
    $selCat  = $colCat  ? ", `{$colCat}` AS cat"    : ", NULL AS cat";
    $selDesc = $colDesc ? ", `{$colDesc}` AS descr" : ", NULL AS descr";

    $stmt = $pdo->prepare("SELECT id, libro_id, titulo, autores {$selCat} {$selDesc}
                           FROM listas_lectura
                           WHERE usuario_id = ? AND {$condPend}
                           ORDER BY id LIMIT {$LOTE}");
    $stmt->execute([$usuario["id"]]);
    $lote = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lote as $b) {
        $norm = [];
        $fuente = '';
        $errorRed = '';

        if (!empty($b['cat'])) {
            $norm = normalizarGeneros(separarGeneros(strip_tags((string)$b['cat'])));
            if ($norm) $fuente = 'Columna categorias';
        }

        // Google Books / Open Library (categorías y, si no hay, su sinopsis)
        if (!$norm) {
            $online = buscarDatosOnline((string)$b['titulo'], (string)($b['autores'] ?? ''), (string)($b['libro_id'] ?? ''));
            $errorRed = $online['error'];
            $norm = normalizarGeneros($online['categorias']);
            if ($norm) {
                $fuente = 'Google Books / Open Library';
            } elseif ($online['descripcion'] !== '') {
                $norm = inferirGenerosDeTexto($b['titulo'] . ' ' . $online['descripcion']);
                if ($norm) $fuente = 'Sinopsis online';
            }
        }

        // Sinopsis guardada en la base de datos
        if (!$norm && !empty($b['descr'])) {
            $norm = inferirGenerosDeTexto($b['titulo'] . ' ' . $b['descr']);
            if ($norm) $fuente = 'Sinopsis';
        }

        // Fallo de conexión o límite de Google: NO se marca como "sin clasificar"; se reintenta más tarde
        if (!$norm && $errorRed !== '') {
            $pausa = true;
            $resultados[] = ['titulo' => $b['titulo'], 'valor' => 'Pendiente', 'detalle' => 'Error de conexión: ' . $errorRed, 'ok' => false];
            break;
        }

        $valor = $norm ? implode(', ', $norm) : '-';
        $pdo->prepare("UPDATE listas_lectura SET `{$col}` = ? WHERE id = ? AND usuario_id = ?")
            ->execute([$valor, $b['id'], $usuario["id"]]);
        $guardados++;

        $resultados[] = [
            'titulo'  => $b['titulo'],
            'valor'   => $norm ? $valor : 'Sin clasificar',
            'detalle' => $norm ? $fuente : 'No se encontraron datos',
            'ok'      => (bool)$norm,
        ];
    }
    if (!$lote) $aviso = 'No quedan libros pendientes.';

    // Modo automático (en segundo plano): responde en JSON
    if ($esAjax) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ? AND {$condPend}");
        $c->execute([$usuario["id"]]);
        header('Content-Type: application/json');
        echo json_encode(['procesados' => $guardados, 'pendientes' => (int)$c->fetchColumn(), 'pausa' => $pausa]);
        exit;
    }
}

/*  Guardar géneros a mano  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'manual') {
    $guardados = 0;
    foreach (($_POST['gen'] ?? []) as $id => $texto) {
        $texto = trim((string)$texto);
        if ($texto === '') continue;
        $norm = normalizarGeneros(separarGeneros($texto));
        if (!$norm) continue;   // género no reconocido: se deja como estaba
        $pdo->prepare("UPDATE listas_lectura SET `{$col}` = ? WHERE id = ? AND usuario_id = ?")
            ->execute([valorGeneroManual($norm), (int)$id, $usuario["id"]]);
        $guardados++;
    }
    $aviso = $guardados . ($guardados === 1 ? ' libro actualizado.' : ' libros actualizados.');
}

/*  Reclasificar todos los libros (menos los corregidos a mano)  */
$autoInicio = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'reclasificar') {
    $pdo->prepare("UPDATE listas_lectura SET `{$col}` = NULL WHERE usuario_id = ? AND (`{$col}` IS NULL OR `{$col}` NOT LIKE '%[manual]%')")
        ->execute([$usuario["id"]]);
    $autoInicio = true;
    $aviso = 'Reclasificando todos los libros con la detección mejorada…';
}

/*  Reintentar los "sin clasificar"  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'reintentar') {
    $pdo->prepare("UPDATE listas_lectura SET `{$col}` = NULL WHERE usuario_id = ? AND `{$col}` = '-'")
        ->execute([$usuario["id"]]);
    $aviso = 'Los libros sin clasificar vuelven a estar pendientes.';
}

/*  Estado actual  */
$stmt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN {$condPend} THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN `{$col}` = '-' THEN 1 ELSE 0 END) AS sin_clasificar
    FROM listas_lectura WHERE usuario_id = ?");
$stmt->execute([$usuario["id"]]);
$est = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total         = (int)($est['total'] ?? 0);
$pendientes    = (int)($est['pendientes'] ?? 0);
$sinClasificar = (int)($est['sin_clasificar'] ?? 0);
$conGenero     = $total - $pendientes - $sinClasificar;

$stmt = $pdo->prepare("SELECT id, titulo, autores FROM listas_lectura
                       WHERE usuario_id = ? AND `{$col}` = '-' ORDER BY titulo LIMIT 60");
$stmt->execute([$usuario["id"]]);
$listaManual = $stmt->fetchAll(PDO::FETCH_ASSOC);

$continuar = !$pausa && (($hizoProceso && $pendientes > 0 && !empty($resultados)) || ($autoInicio && $pendientes > 0));

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Completar géneros</title>
<script src="main.js"></script>
<link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
<link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
</head>
<body class="page-estadisticas">

<div class="st-wrap">

    <a href="estadisticas.php?generos=todos" class="ne-volver">← Estadísticas</a>

    <header class="st-header">
        <h1>🏷️ Completar géneros</h1>
        <p>Rellena el género de los libros de tu biblioteca para el mapa y los filtros.</p>
    </header>

    <?php if ($aviso !== ''): ?>
        <div class="st-card"><p class="objetivo-mensaje"><?= htmlspecialchars($aviso) ?></p></div>
    <?php endif; ?>

    <section class="st-kpis">
        <div class="st-kpi"><span class="valor"><?= $total ?></span><span class="etiqueta">Libros</span></div>
        <div class="st-kpi"><span class="valor"><?= $conGenero ?></span><span class="etiqueta">Con género</span></div>
        <div class="st-kpi"><span class="valor"><?= $pendientes ?></span><span class="etiqueta">Pendientes</span></div>
        <div class="st-kpi"><span class="valor"><?= $sinClasificar ?></span><span class="etiqueta">Sin clasificar</span></div>
    </section>

    <section class="st-card">
        <div class="st-card-head"><h2> Detección automática</h2></div>
        <p class="vacio">Busca el género de cada libro (columna de categorías, Google Books, Open Library y, si no hay nada, la sinopsis). Si no está seguro, deja el libro sin clasificar para que lo asignes tú. Se procesan <?= $LOTE ?> libros por tanda y se encadenan solas hasta terminar.</p>
        <form method="POST" id="formProcesar">
            <input type="hidden" name="accion" value="procesar">
            <button type="submit" class="btn-reto" <?= $pendientes === 0 ? 'disabled' : '' ?>>
                <?= $pendientes === 0 ? '✅ No quedan libros pendientes' : '▶️ Detectar géneros (' . $pendientes . ' pendientes)' ?>
            </button>
        </form>

        <form method="POST" onsubmit="return confirm('Se borrarán los géneros detectados automáticamente y se volverán a detectar. Los que hayas corregido a mano se mantienen. ¿Continuar?');">
            <input type="hidden" name="accion" value="reclasificar">
            <button type="submit" class="btn-reto"> Reclasificar todos los libros</button>
        </form>
        <p class="vacio">Los géneros que corrijas a mano (desde la ficha de cada libro o desde aquí) nunca se tocan.</p>
    </section>

    <?php if (!empty($resultados)): ?>
    <section class="st-card">
        <div class="st-card-head"><h2> Última tanda</h2></div>
        <table class="tabla-libros">
            <thead><tr><th>Libro</th><th>Resultado</th><th>Detalle</th></tr></thead>
            <tbody>
            <?php foreach ($resultados as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['titulo']) ?></td>
                    <td><span class="badge-status <?= $r['ok'] ? 'badge-ok' : 'badge-pending' ?>"><?= htmlspecialchars($r['valor']) ?></span></td>
                    <td><?= htmlspecialchars($r['detalle']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($continuar): ?>
            <p class="vacio">Procesando la siguiente tanda… <a href="rellenar_generos.php">Detener</a></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($sinClasificar > 0): ?>
    <section class="st-card">
        <div class="st-card-head"><h2> Asignar a mano</h2></div>
        <p class="vacio">Estos libros no se han podido clasificar. Escribe el género (puedes poner varios separados por coma). Géneros reconocidos: <?= htmlspecialchars(implode(', ', array_keys(reglasGeneros()))) ?>.</p>

        <form method="POST">
            <input type="hidden" name="accion" value="manual">
            <table class="tabla-libros">
                <thead><tr><th>Libro</th><th>Género</th></tr></thead>
                <tbody>
                <?php foreach ($listaManual as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['titulo']) ?><br><small><?= htmlspecialchars(trim(explode(',', (string)($m['autores'] ?? ''))[0])) ?></small></td>
                        <td><input type="text" class="cal-input" list="listaGeneros" name="gen[<?= (int)$m['id'] ?>]" placeholder="Ej: Fantasía, Romance"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <datalist id="listaGeneros">
                <?php foreach (array_keys(reglasGeneros()) as $g): ?><option value="<?= htmlspecialchars($g) ?>"><?php endforeach; ?>
            </datalist>
            <div class="modal-acciones">
                <button type="submit" class="btn-guardar-reto">Guardar géneros</button>
            </div>
        </form>

        <form method="POST">
            <input type="hidden" name="accion" value="reintentar">
            <button type="submit" class="btn-reto">🔄 Volver a intentar la detección automática</button>
        </form>
    </section>
    <?php endif; ?>

</div>

<?php if ($continuar): ?>
<script>setTimeout(function () { document.getElementById("formProcesar").submit(); }, 1500);</script>
<?php endif; ?>

</body>
</html>
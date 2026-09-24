<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$db = new Database();

// Guardar los datos enviados por el formulario
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_paginas"])) {
    if (!empty($_POST["paginas"]) && is_array($_POST["paginas"])) {
        $actualizados = 0;
        foreach ($_POST["paginas"] as $libroId =>$pags) {
            $pagsNum = (int)$pags;
            if ($pagsNum > 0) {
                // Verificar si está leído para actualizar también paginas_leidas
                $sqlCheck = "SELECT estado FROM listas_lectura WHERE id = ? AND usuario_id = ?";
                $stmtCheck =$db->pdo->prepare($sqlCheck);$stmtCheck->execute([$libroId,$usuario["id"]]);
                $estado =$stmtCheck->fetchColumn();

                if ($estado === 'leido') {$sqlUp = "UPDATE listas_lectura SET paginas_totales = ?, paginas_leidas = ? WHERE id = ? AND usuario_id = ?";
                    $stmtUp =$db->pdo->prepare($sqlUp);$stmtUp->execute([$pagsNum,$pagsNum, $libroId,$usuario["id"]]);
                } else {
                    $sqlUp = "UPDATE listas_lectura SET paginas_totales = ? WHERE id = ? AND usuario_id = ?";
                    $stmtUp = $db->pdo->prepare($sqlUp);
                    $stmtUp->execute([$pagsNum, $libroId,$usuario["id"]]);
                }
                $actualizados++;
            }
        }
        header("Location: estadisticas.php?msj=corregidos_" . $actualizados);
        exit;
    }
}

// Obtener libros sin páginas o con 300 páginas
$sql = "SELECT id, titulo, autores, paginas_totales 
        FROM listas_lectura 
        WHERE usuario_id = ? AND (paginas_totales = 0 OR paginas_totales = 300)
        ORDER BY id DESC";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"]]);
$libros =$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Corregir Páginas de Libros</title>
<style>
    body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; padding: 20px; max-width: 900px; margin: 0 auto; }
    .header-box { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px; }
    .actions-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
    .btn { padding: 10px 18px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background: #0078ff; color: #fff; }
    .btn-primary:hover { background: #0056b3; }
    .btn-success { background: #16a34a; color: #fff; }
    .btn-success:hover { background: #15803d; }
    .btn-secondary { background: #e2e8f0; color: #334155; }
    .tabla-libros { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .tabla-libros th, .tabla-libros td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
    .tabla-libros th { background: #f1f5f9; font-weight: 700; color: #475569; }
    .input-pags { width: 90px; padding: 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-weight: bold; font-size: 0.95rem; text-align: center; }
    .input-pags:focus { border-color: #0078ff; outline: none; }
    .btn-auto { padding: 6px 10px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; }
    .btn-auto:hover { background: #e2e8f0; }
    .badge-status { font-size: 0.75rem; padding: 3px 8px; border-radius: 12px; font-weight: bold; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-ok { background: #dcfce7; color: #166534; }
    .progress-container { margin-top: 15px; display: none; }
    .progress-bg { width: 100%; height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; margin-top: 6px; }
    .progress-fill { height: 100%; background: #0078ff; width: 0%; transition: width 0.3s; }
</style>
</head>
<body>

<div class="header-box">
    <h2>🛠️ Corrector de Páginas de la Biblioteca</h2>
    <p style="color: #64748b; margin: 5px 0 0 0;">
        Se han detectado <strong><?= count($libros) ?></strong> libros con 0 o 300 páginas.
    </p>

    <div class="actions-bar">
        <button type="button" class="btn btn-primary" onclick="iniciarBusquedaAuto()">
            ⚡ Auto-Buscar Páginas (con pausa anti-bloqueo)
        </button>
        <a href="estadisticas.php" class="btn btn-secondary">← Volver a Estadísticas</a>
    </div>

    <div id="progressContainer" class="progress-container">
        <div id="progressStatus" style="font-size: 0.85rem; font-weight: bold; color: #0078ff;">
            Buscando páginas... (0 / <?= count($libros) ?>)
        </div>
        <div class="progress-bg">
            <div id="progressFill" class="progress-fill"></div>
        </div>
    </div>
</div>

<?php if (empty($libros)): ?>
    <div class="header-box" style="text-align: center; color: #16a34a;">
        <h3>🎉 ¡Excelente! No tienes ningún libro pendiente de corregir.</h3>
        <a href="estadisticas.php" class="btn btn-primary" style="margin-top: 10px;">Volver a Estadísticas</a>
    </div>
<?php else: ?>
    <form action="" method="POST" id="formPaginas">
        <input type="hidden" name="guardar_paginas" value="1">

        <table class="tabla-libros">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Autor</th>
                    <th>Estado</th>
                    <th style="width: 130px; text-align: center;">Páginas</th>
                    <th style="width: 100px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($libros as$l): ?>
                    <tr id="row-<?= $l['id'] ?>">
                        <td>
                            <strong><?= htmlspecialchars($l['titulo']) ?></strong>
                        </td>
                        <td style="color: #64748b;">
                            <?= htmlspecialchars($l['autores'] ?: 'Desconocido') ?>
                        </td>
                        <td>
                            <span class="badge-status badge-pending" id="badge-<?= $l['id'] ?>">Pendiente</span>
                        </td>
                        <td style="text-align: center;">
                            <input type="number" 
                                   name="paginas[<?= $l['id'] ?>]" 
                                   id="input-<?= $l['id'] ?>" 
                                   class="input-pags" 
                                   value="" 
                                   placeholder="0" 
                                   min="1">
                        </td>
                        <td>
                            <button type="button" class="btn-auto" onclick="buscarUnLibro(<?= $l['id'] ?>, '<?= addslashes($l['titulo']) ?>', '<?= addslashes($l['autores'] ?: '') ?>')">
                                🔍 Buscar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="position: sticky; bottom: 20px; background: rgba(255,255,255,0.95); backdrop-filter: blur(8px); padding: 15px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 0.9rem; color: #475569;">Modifica o rellena las casillas que desees y guarda cuando termines.</span>
            <button type="submit" class="btn btn-success">
                💾 Guardar Todos los Cambios
            </button>
        </div>
    </form>
<?php endif; ?>

<script>
const librosData = <?= json_encode($libros, JSON_UNESCAPED_UNICODE) ?>;

function limpiarTitulo(titulo) {
    if (!titulo) return '';
    let t = titulo.replace(/[\u200B-\u200D\uFEFF]/g, '');
    t = t.replace(/\s*[\(\[\{].*?[\)\]\}]/g, '');
    return t.split(/[:–\-]/)[0].trim();
}

async function consultarApi(titulo, autor) {
    const tituloLimpio = limpiarTitulo(titulo);
    const busquedas = [...new Set([`${tituloLimpio} ${autor || ''}`.trim(), tituloLimpio])];

    for (const q of busquedas) {
        try {
            const url = `https://www.googleapis.com/books/v1/volumes?q=${encodeURIComponent(q)}&maxResults=3`;
            const res = await fetch(url);
            if (res.ok) {
                const data = await res.json();
                if (data.items) {
                    for (const item of data.items) {
                        if (item.volumeInfo && item.volumeInfo.pageCount > 0) {
                            return item.volumeInfo.pageCount;
                        }
                    }
                }
            }
        } catch (e) {}

        try {
            const urlOL = `https://openlibrary.org/search.json?q=${encodeURIComponent(q)}&limit=3`;
            const resOL = await fetch(urlOL);
            if (resOL.ok) {
                const dataOL = await resOL.json();
                if (dataOL.docs) {
                    for (const doc of dataOL.docs) {
                        if (doc.number_of_pages_median > 0) return doc.number_of_pages_median;
                        if (doc.number_of_pages > 0) return doc.number_of_pages;
                    }
                }
            }
        } catch (e) {}
    }
    return 0;
}

async function buscarUnLibro(id, titulo, autor) {
    const input = document.getElementById(`input-${id}`);
    const badge = document.getElementById(`badge-${id}`);
    
    badge.innerText = 'Buscando...';
    badge.className = 'badge-status badge-pending';

    const pags = await consultarApi(titulo, autor);

    if (pags > 0) {
        input.value = pags;
        badge.innerText = '✅ Encontrado (' + pags + ' pág)';
        badge.className = 'badge-status badge-ok';
    } else {
        badge.innerText = '⚠️ No encontrado';
    }
}

async function iniciarBusquedaAuto() {
    const container = document.getElementById('progressContainer');
    const fill = document.getElementById('progressFill');
    const status = document.getElementById('progressStatus');
    
    container.style.display = 'block';
    
    let procesados = 0;
    let encontrados = 0;
    const total = librosData.length;

    for (const l of librosData) {
        const input = document.getElementById(`input-${l.id}`);
        
        if (!input.value || input.value == '0') {
            await buscarUnLibro(l.id, l.titulo, l.autores);
            if (input.value && input.value > 0) {
                encontrados++;
            }
            // PAUSA ANTI-BLOQUEO DE 1.2 SEGUNDOS ENTRE PETICIONES
            await new Promise(r => setTimeout(r, 1200));
        }

        procesados++;
        const pct = Math.round((procesados / total) * 100);
        fill.style.width = pct + '%';
        status.innerText = `Buscando páginas... (${procesados} de ${total}) — Auto-completados: ${encontrados}`;
    }

    status.innerText = `🎉 Búsqueda finalizada. ${encontrados} libros completados. Pulsa 'Guardar Todos los Cambios' abajo.`;
}
</script>

</body>
</html>
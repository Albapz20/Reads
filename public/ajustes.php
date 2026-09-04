<?php
require_once "../src/Auth.php";
require_once "../src/AjustesService.php";
require_once "../src/UserService.php";
require_once "../src/Database.php";
require_once "../src/ListaService.php";
require_once "../src/CorreoService.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$ajustesService = new AjustesService();
$userService    = new UserService();
$listaService   = new ListaService();
$db             = new Database();

$ajustesService->crearAjustesSiNoExisten($usuario["id"]);
$ajustes = $ajustesService->obtenerAjustes($usuario["id"]);

$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$idiomaActual = $datos["idioma"] ?? "es";

$mensaje = "";

/* ==========================================
   EXPORTAR BIBLIOTECA A FORMATO GOODREADS
   ========================================== */
if (isset($_GET["exportar"]) && $_GET["exportar"] === "goodreads") {
    $sql = "SELECT * FROM listas_lectura WHERE usuario_id = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$usuario["id"]]);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "reads_export_goodreads_" . date("Y-m-d") . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    fputcsv($output, ['Book Id', 'Title', 'Author', 'ISBN', 'My Rating', 'Average Rating', 'Publisher', 'Binding', 'Year Published', 'Original Publication Year', 'Date Read', 'Date Added', 'Exclusive Shelf', 'My Review']);

    foreach ($libros as $libro) {
        $estanteGR = 'to-read';
        if ($libro['estado'] === 'leido') $estanteGR = 'read';
        if ($libro['estado'] === 'leyendo') $estanteGR = 'currently-reading';
        if ($libro['estado'] === 'tbr') $estanteGR = 'to-read';
        if ($libro['estado'] === 'guardado') $estanteGR = 'to-read';

        fputcsv($output, [
            $libro['libro_id'] ?? '',
            $libro['titulo'],
            '',
            '',
            0,
            '',
            '',
            '',
            '',
            '',
            '',
            $libro['creado_en'] ?? date('Y-m-d'),
            $estanteGR,
            ''
        ]);
    }

    fclose($output);
    exit;
}

/* ==========================================
   IMPORTAR CSV DE GOODREADS
   ========================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["importar_goodreads"])) {
    if (isset($_FILES["csv_file"]) && $_FILES["csv_file"]["error"] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES["csv_file"]["tmp_name"];
        
        $handle = fopen($fileTmpPath, "r");
        if ($handle !== false) {
            $header = fgetcsv($handle, 1000, ",");
            
            if ($header) {
                $colMap = array_flip($header);
                
                $colTitulo  = $colMap['Title'] ?? null;
                $colAutor   = $colMap['Author'] ?? null;
                $colIsbn    = $colMap['ISBN13'] ?? ($colMap['ISBN'] ?? null);
                $colEstante = $colMap['Exclusive Shelf'] ?? null;

                $importados = 0;

                while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                    if ($colTitulo === null || !isset($data[$colTitulo])) continue;

                    $titulo = trim($data[$colTitulo]);
                    if (empty($titulo)) continue;

                    $estanteGR = ($colEstante !== null && isset($data[$colEstante])) ? trim($data[$colEstante]) : 'to-read';
                    
                    $estado = 'tbr';
                    if ($estanteGR === 'read') {
                        $estado = 'leido';
                    } elseif ($estanteGR === 'currently-reading') {
                        $estado = 'leyendo';
                    } elseif ($estanteGR === 'to-read') {
                        $estado = 'tbr';
                    }

                    $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=" . urlencode($titulo);
                    $libroId = ($colIsbn !== null && !empty($data[$colIsbn])) ? preg_replace('/[^0-9X]/i', '', $data[$colIsbn]) : uniqid('gr_');

                    $listaService->agregarLibro($usuario["id"], $libroId, $titulo, $portada, $estado);
                    
                    $sqlObtenerId = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) ORDER BY id DESC LIMIT 1";
                    $stmtId = $db->pdo->prepare($sqlObtenerId);
                    $stmtId->execute([$usuario["id"], $libroId, $titulo]);
                    $registro = $stmtId->fetch(PDO::FETCH_ASSOC);

                    if ($registro) {
                        $listaService->cambiarEstado($usuario["id"], $registro["id"], $estado);
                    }

                    $importados++;
                }

                fclose($handle);
                $mensaje = "¡Se han importado exitosamente $importados libros desde Goodreads!";
            } else {
                $mensaje = "Error: El archivo CSV está vacío o es inválido.";
            }
        } else {
            $mensaje = "Error al abrir el archivo enviado.";
        }
    } else {
        $mensaje = "Por favor, selecciona un archivo CSV válido.";
    }
}

/* GUARDAR AJUSTES GENERALES */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_ajustes"])) {

    $mostrar_email = isset($_POST["mostrar_email"]) ? 1 : 0;
    $mostrar_listas = isset($_POST["mostrar_listas"]) ? 1 : 0;
    $objetivo_anual = isset($_POST["objetivo_anual"]) ? (int)$_POST["objetivo_anual"] : 50;

    if ($ajustesService->actualizarAjustes(
        $usuario["id"],
        $mostrar_email,
        $mostrar_listas,
        $objetivo_anual
    )) {
        $mensaje = "Ajustes guardados correctamente.";
    } else {
        $mensaje = "Error al guardar los ajustes.";
    }

    $ajustes = $ajustesService->obtenerAjustes($usuario["id"]);
}

/* GUARDAR TEMA VISUAL */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_tema"])) {

    $tema_visual = $_POST["tema_visual"];

    $sql = "UPDATE usuarios SET tema_visual = ? WHERE id = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$tema_visual, $usuario["id"]]);

    $usuario["tema_visual"] = $tema_visual;
    $tema = $tema_visual;

    $mensaje = "Tema visual actualizado.";
}

/* GUARDAR IDIOMA */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_idioma"])) {
    $nuevoIdioma = $_POST["idioma"];

    $sql = "UPDATE usuarios SET idioma = ? WHERE id = ?";
    $stmt = $db->pdo->prepare($sql);
    
    if ($stmt->execute([$nuevoIdioma, $usuario["id"]])) {
        $_SESSION["usuario"]["idioma"] = $nuevoIdioma;
        $idiomaActual = $nuevoIdioma;
        $mensaje = "Idioma de la aplicación actualizado.";
    } else {
        $mensaje = "Error al actualizar el idioma.";
    }
}

/* ENVIAR MENSAJE DE CONTACTO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enviar_contacto"])) {
    $asunto  = trim($_POST["asunto"]);
    $mensajeContacto = trim($_POST["mensaje_contacto"]);

    if (!empty($asunto) && !empty($mensajeContacto)) {
        $correoService = new CorreoService();
        
        $enviado = $correoService->enviarContacto(
            $usuario["email"],
            $usuario["nombre"] ?? "Usuario Reads",
            $asunto,
            $mensajeContacto
        );

        if ($enviado) {
            $mensaje = "¡Gracias por contactarnos! Tu mensaje ha sido enviado correctamente a nuestro equipo.";
        } else {
            $mensaje = "Hubo un problema al enviar el mensaje. Inténtalo más tarde o verifica la configuración de correo.";
        }
    } else {
        $mensaje = "Por favor, completa todos los campos del formulario de contacto.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <title>Ajustes</title>
</head>

<body>

<div class="container">

    <div class="panel">
        <div class="panel-header">
            <h1>Ajustes de la aplicación</h1>
        </div>

        <?php if ($mensaje): ?>
            <div class="review-card">
                <strong><?= htmlspecialchars($mensaje) ?></strong>
            </div>
        <?php endif; ?>
        <!-- FORMULARIO DE AJUSTES GENERALES -->
        <div class="panel">
            <div class="panel-header">
                <h2>Ajustes generales</h2>
            </div>

            <form method="POST">

                <label>
                    <input type="checkbox" name="mostrar_email" <?= $ajustes["mostrar_email"] ? "checked" : "" ?>>
                    Mostrar mi email públicamente
                </label><br><br>

                <label>
                    <input type="checkbox" name="mostrar_listas" <?= $ajustes["mostrar_listas"] ? "checked" : "" ?>>
                    Mostrar mis listas de lectura en el perfil
                </label><br><br>

                <label><strong>Objetivo anual de lectura (libros):</strong></label><br>
                <input type="number" name="objetivo_anual" min="1" max="500"
                       value="<?= htmlspecialchars($ajustes["objetivo_anual"]) ?>"><br><br>

                <button type="submit" name="guardar_ajustes">Guardar ajustes</button>
            </form>
        </div>

        <!-- SECCIÓN IMPORTAR / EXPORTAR GOODREADS -->
        <div class="panel">
            <div class="panel-header">
                <h2>📚 Importar / Exportar Goodreads</h2>
            </div>

            <div style="margin-bottom: 20px;">
                <p><strong>📥 Importar tus libros:</strong></p>
                <p style="font-size: 0.9rem; color: #666;">
                    Sube el archivo <code>.csv</code> exportado desde Goodreads (<em>My Books &gt; Import/Export</em>).
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="csv_file" accept=".csv" required><br><br>
                    <button type="submit" name="importar_goodreads">Importar desde Goodreads</button>
                </form>
            </div>

            <hr style="border: 0; border-top: 1px solid rgba(0,0,0,0.1); margin: 20px 0;">

            <div>
                <p><strong>📤 Exportar tus libros:</strong></p>
                <p style="font-size: 0.9rem; color: #666;">
                    Descarga tu estantería en un archivo CSV compatible con Goodreads.
                </p>
                <a href="ajustes.php?exportar=goodreads">
                    <button type="button">Descargar mi biblioteca (.CSV)</button>
                </a>
            </div>
        </div>

        <!-- SECCIÓN CONTACTA CON NOSOTROS -->
        <div class="panel">
            <div class="panel-header">
                <h2>📩 Contacta con nosotros</h2>
            </div>

            <p style="font-size: 0.9rem; color: #666;">
                ¿Tienes dudas, alguna sugerencia o has encontrado un problema? Envíanos un mensaje:
            </p>

            <form method="POST">
                <label><strong>Asunto:</strong></label><br>
                <input type="text" name="asunto" placeholder="Ej: Sugerencia de mejora / Error al cargar un libro" required style="width: 100%; box-sizing: border-box; padding: 8px; margin-top: 5px;"><br><br>

                <label><strong>Mensaje:</strong></label><br>
                <textarea name="mensaje_contacto" rows="4" placeholder="Escribe aquí tu consulta detallada..." required style="width: 100%; box-sizing: border-box; padding: 8px; margin-top: 5px;"></textarea><br><br>

                <button type="submit" name="enviar_contacto">Enviar mensaje</button>
            </form>
        </div>

        <div class="acciones-perfil">
            <a href="perfil.php">← Volver al perfil</a>
        </div>

    </div>

</div>

</body>
</html>
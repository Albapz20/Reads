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

/* Exportar biblioteca a formato csv */
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
        if (in_array($libro['estado'], ['tbr', 'pendiente', 'guardado'])) $estanteGR = 'to-read';

        fputcsv($output, [
            $libro['libro_id'] ?? '',
            $libro['titulo'],
            $libro['autores'] ?? '',
            '',
            0,
            '',
            '',
            '',
            '',
            '',
            $libro['fecha_fin'] ?? '',
            $libro['fecha'] ?? date('Y-m-d'),
            $estanteGR,
            ''
        ]);
    }

    fclose($output);
    exit;
}

/* Importar CSV de Goodreads */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["importar_goodreads"])) {
    @set_time_limit(180);

    if (isset($_FILES["csv_file"]) && $_FILES["csv_file"]["error"] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES["csv_file"]["tmp_name"];
        
        $content = file_get_contents($fileTmpPath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        $tempStream = fopen('php://memory', 'r+');
        fwrite($tempStream, $content);
        rewind($tempStream);

        $header = fgetcsv($tempStream, 5000, ",");
        
        if ($header) {
            $headerClean = array_map(function($h) {
                return strtolower(trim(preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $h)));
            }, $header);
            
            $colMap = array_flip($headerClean);
            
            $colTitulo    = $colMap['title'] ?? null;
            $colAutor     = $colMap['author'] ?? null;
            $colIsbn13    = $colMap['isbn13'] ?? null;
            $colIsbn      = $colMap['isbn'] ?? null;
            $colEstante   = $colMap['exclusive shelf'] ?? null;
            $colDateRead  = $colMap['date read'] ?? null;
            $colDateAdded = $colMap['date added'] ?? null;

            $dirUploads = __DIR__ . '/uploads/portadas/';
            if (!is_dir($dirUploads)) {
                @mkdir($dirUploads, 0777, true);
            }

            $filas = [];

            while (($data = fgetcsv($tempStream, 5000, ",")) !== false) {
                if ($colTitulo === null || !isset($data[$colTitulo])) continue;

                $titulo = trim($data[$colTitulo]);
                if (empty($titulo)) continue;

                $autor = ($colAutor !== null && isset($data[$colAutor])) ? trim($data[$colAutor]) : 'Autor desconocido';

                $estanteGR = ($colEstante !== null && isset($data[$colEstante])) ? strtolower(trim($data[$colEstante])) : 'to-read';
                
                if ($estanteGR === 'read') {
                    $estado = 'leido';
                } elseif ($estanteGR === 'currently-reading') {
                    $estado = 'leyendo';
                } else {
                    $estado = 'tbr';
                }

                $isbnRaw = '';
                if ($colIsbn13 !== null && !empty($data[$colIsbn13])) {
                    $isbnRaw = $data[$colIsbn13];
                } elseif ($colIsbn !== null && !empty($data[$colIsbn])) {
                    $isbnRaw = $data[$colIsbn];
                }
                $isbnLimpio = preg_replace('/[^0-9X]/i', '', $isbnRaw);

                $dateReadRaw  = ($colDateRead !== null && isset($data[$colDateRead])) ? trim($data[$colDateRead]) : '';
                $dateAddedRaw = ($colDateAdded !== null && isset($data[$colDateAdded])) ? trim($data[$colDateAdded]) : '';

                $fechaAgregado = date('Y-m-d');
                if (!empty($dateAddedRaw)) {
                    $timeAdded = strtotime(str_replace('/', '-', $dateAddedRaw));
                    if ($timeAdded && $timeAdded > 0) {
                        $fechaAgregado = date('Y-m-d', $timeAdded);
                    }
                }

                $fechaFin = null;
                if ($estado === 'leido') {
                    if (!empty($dateReadRaw)) {
                        $timeRead = strtotime(str_replace('/', '-', $dateReadRaw));
                        if ($timeRead && $timeRead > 0) {
                            $fechaFin = date('Y-m-d', $timeRead);
                        }
                    }
                    if (empty($fechaFin)) {
                        $fechaFin = $fechaAgregado;
                    }
                }

                $idRef = !empty($isbnLimpio) ? $isbnLimpio : uniqid('gr_');

                $filas[] = [
                    'id_ref'        => $idRef,
                    'isbn'          => $isbnLimpio,
                    'titulo'        => $titulo,
                    'autor'         => $autor,
                    'estado'        => $estado,
                    'fecha_fin'     => $fechaFin,
                    'fecha'         => $fechaAgregado,
                    'img_remote'    => null,
                    'portada_local' => 'uploads/portadas/default.jpg'
                ];
            }
            fclose($tempStream);

            // FASE 1: Consultar API de Google Books (con Bypassing de SSL para XAMPP)
            if (!empty($filas) && function_exists('curl_multi_init')) {
                $mh1 = curl_multi_init();
                $handlesApi = [];

                foreach ($filas as $idx => $f) {
                    $nombreArchivo = (!empty($f['isbn']) ? $f['isbn'] : 'gr_' . substr(md5($f['titulo']), 0, 10)) . '.jpg';
                    if (file_exists($dirUploads . $nombreArchivo) && filesize($dirUploads . $nombreArchivo) > 1000) {
                        $filas[$idx]['portada_local'] = 'uploads/portadas/' . $nombreArchivo;
                        continue;
                    }

                    if (!empty($f['isbn'])) {
                        $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($f['isbn']);
                    } else {
                        $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=intitle:" . urlencode($f['titulo']);
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $apiUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Omite bloqueo SSL en XAMPP
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                    curl_multi_add_handle($mh1, $ch);
                    $handlesApi[$idx] = $ch;
                }

                if (!empty($handlesApi)) {
                    $active = null;
                    do {
                        $status = curl_multi_exec($mh1, $active);
                        if ($active) {
                            curl_multi_select($mh1);
                        }
                    } while ($active && $status == CURLM_OK);

                    foreach ($handlesApi as $idx => $ch) {
                        $json = curl_multi_getcontent($ch);
                        if ($json) {
                            $res = json_decode($json, true);
                            if (!empty($res['items'][0]['volumeInfo']['imageLinks']['thumbnail'])) {
                                $filas[$idx]['img_remote'] = str_replace('http://', 'https://', $res['items'][0]['volumeInfo']['imageLinks']['thumbnail']);
                            } elseif (!empty($res['items'][0]['volumeInfo']['imageLinks']['smallThumbnail'])) {
                                $filas[$idx]['img_remote'] = str_replace('http://', 'https://', $res['items'][0]['volumeInfo']['imageLinks']['smallThumbnail']);
                            }
                        }
                        curl_multi_remove_handle($mh1, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh1);
                }

                // FASE 2: Descargar las imágenes a la carpeta local
                $mh2 = curl_multi_init();
                $handlesImg = [];

                foreach ($filas as $idx => $f) {
                    if (!empty($f['img_remote'])) {
                        $nombreArchivo = (!empty($f['isbn']) ? $f['isbn'] : 'gr_' . substr(md5($f['titulo']), 0, 10)) . '.jpg';
                        $rutaAbsoluta = $dirUploads . $nombreArchivo;

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $f['img_remote']);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Omite bloqueo SSL en XAMPP
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                        curl_multi_add_handle($mh2, $ch);
                        $handlesImg[$idx] = ['ch' => $ch, 'file' => $rutaAbsoluta, 'rel' => 'uploads/portadas/' . $nombreArchivo];
                    }
                }

                if (!empty($handlesImg)) {
                    $active = null;
                    do {
                        $status = curl_multi_exec($mh2, $active);
                        if ($active) {
                            curl_multi_select($mh2);
                        }
                    } while ($active && $status == CURLM_OK);

                    foreach ($handlesImg as $idx => $item) {
                        $ch = $item['ch'];
                        $file = $item['file'];
                        $data = curl_multi_getcontent($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                        if ($httpCode === 200 && $data !== false && strlen($data) > 1000) {
                            file_put_contents($file, $data);
                            $filas[$idx]['portada_local'] = $item['rel'];
                        }
                        curl_multi_remove_handle($mh2, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh2);
                }
            }

            // FASE 3: Guardar en Base de Datos
            $importados = 0;
            foreach ($filas as $libro) {
                $listaService->agregarLibro($usuario["id"], $libro['id_ref'], $libro['titulo'], $libro['portada_local'], $libro['estado']);

                $sqlObtenerId = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) ORDER BY id DESC LIMIT 1";
                $stmtId = $db->pdo->prepare($sqlObtenerId);
                $stmtId->execute([$usuario["id"], $libro['id_ref'], $libro['titulo']]);
                $registro = $stmtId->fetch(PDO::FETCH_ASSOC);

                if ($registro) {
                    $sqlUpdate = "UPDATE listas_lectura SET autores = ?, portada = ?, estado = ?, fecha_fin = ?, fecha = ? WHERE id = ?";
                    $stmtUpdate = $db->pdo->prepare($sqlUpdate);
                    $stmtUpdate->execute([$libro['autor'], $libro['portada_local'], $libro['estado'], $libro['fecha_fin'], $libro['fecha'], $registro["id"]]);
                }

                $importados++;
            }

            $mensaje = "¡Se han importado exitosamente $importados libros guardando sus portadas localmente!";
        } else {
            $mensaje = "Error: El archivo CSV está vacío o es inválido.";
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
<?php
require_once "../src/Auth.php";
require_once "../src/AjustesService.php";
require_once "../src/UserService.php";
require_once "../src/Database.php";
require_once "../src/ListaService.php";
require_once "../src/GenerosHelper.php";
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

// Temas disponibles
$temasDisponibles = [
    'pastel'      => ['nombre' => 'Pastel',      'icono' => '🌸', 'bg' => '#fdf3f8', 'border' => '#e7d4fb', 'text' => '#4b3b55'],
    'naturalista' => ['nombre' => 'Naturalista', 'icono' => '🌿', 'bg' => '#f1f6ee', 'border' => '#cfe0c6', 'text' => '#26382b'],
    'azul'        => ['nombre' => 'Azul',        'icono' => '💙', 'bg' => '#edf5fc', 'border' => '#c9ddf1', 'text' => '#1f3a5c'],
    'sunset'      => ['nombre' => 'Sunset',      'icono' => '🌅', 'bg' => '#fff5ec', 'border' => '#fbd0a8', 'text' => '#4a1d0e'],
    'coffee'      => ['nombre' => 'Café',        'icono' => '☕', 'bg' => '#231a16', 'border' => '#5a443a', 'text' => '#f4e9d8'],
    'dracula'     => ['nombre' => 'Dracula',     'icono' => '🐉', 'bg' => '#1e1f2e', 'border' => '#44476a', 'text' => '#f8f8f2'],
    'lavanda'     => ['nombre' => 'Lavanda',     'icono' => '🪻', 'bg' => '#f5f0ff', 'border' => '#d8c8f0', 'text' => '#4b3f72'],
];

// Obtener usuario e información del tema
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
// Solo el administrador ve el progreso de la búsqueda de portadas
$emailActual = strtolower(trim($datos['email'] ?? $usuario['email'] ?? ''));
$esAdmin     = ($emailActual === 'admin@admin.com');
$tema = strtolower(trim($datos["tema_visual"] ?? "pastel"));
if (!isset($temasDisponibles[$tema])) $tema = 'pastel';   

$mensaje = "";
$iniciarCola = false;   

// Si el archivo supera el límite de PHP, el servidor descarta todo el formulario y no llega ni $_POST ni $_FILES:
// sin esto no se mostraría ningún mensaje.
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $mensaje = "El archivo es demasiado grande para el servidor (límite: " . ini_get('post_max_size') . "). "
             . "Prueba con un CSV más pequeño o sube el valor de post_max_size y upload_max_filesize en php.ini.";
}

/* Guardar tema visual */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_tema"])) {
    $tema_visual = strtolower(trim($_POST["tema_visual"] ?? ''));
    if (!isset($temasDisponibles[$tema_visual])) $tema_visual = 'pastel';   // solo temas que existen

    $sql = "UPDATE usuarios SET tema_visual = ? WHERE id = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$tema_visual, $usuario["id"]]);

    if (isset($_SESSION["usuario"])) {
        $_SESSION["usuario"]["tema_visual"] = $tema_visual;
    }

    $usuario["tema_visual"] = $tema_visual;
    $tema = $tema_visual;

    $mensaje = "Tema visual actualizado a " . $temasDisponibles[$tema]['nombre'] . ".";
}
/* Eliminar cuenta */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_eliminar'])) {
    $_SESSION['csrf_eliminar'] = bin2hex(random_bytes(16));
}
$abrirEliminar = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_cuenta"])) {
    $tokenOk = hash_equals($_SESSION['csrf_eliminar'], (string)($_POST['csrf'] ?? ''));

    $stmtPw = $db->pdo->prepare("SELECT password, avatar FROM usuarios WHERE id = ? LIMIT 1");
    $stmtPw->execute([$usuario["id"]]);
    $filaUsuario = $stmtPw->fetch(PDO::FETCH_ASSOC) ?: [];
    $passOk = !empty($filaUsuario['password'])
              && password_verify((string)($_POST['password_eliminar'] ?? ''), $filaUsuario['password']);

    if (!$tokenOk || empty($_POST['confirmo'])) {
        $mensaje = "No se pudo eliminar la cuenta. Inténtalo de nuevo.";
    } elseif (!$passOk) {
        $mensaje = "La contraseña no es correcta. Tu cuenta no se ha eliminado.";
        $abrirEliminar = true;
    } else {
        $idEliminar = (int)$usuario["id"];
        try {
            // Todas las tablas que guardan datos de este usuario (listas, puntuaciones, ajustes, seguidores...)
            $tablas = $db->pdo->query(
                "SELECT c.TABLE_NAME, c.COLUMN_NAME
                   FROM information_schema.COLUMNS c
                   JOIN information_schema.TABLES t
                     ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                  WHERE c.TABLE_SCHEMA = DATABASE()
                    AND c.COLUMN_NAME IN ('usuario_id', 'user_id', 'seguidor_id', 'seguido_id')
                    AND c.TABLE_NAME <> 'usuarios'"
            )->fetchAll(PDO::FETCH_ASSOC);

            $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $db->pdo->beginTransaction();
            foreach ($tablas as $t) {
                $db->pdo->prepare("DELETE FROM `{$t['TABLE_NAME']}` WHERE `{$t['COLUMN_NAME']}` = ?")
                        ->execute([$idEliminar]);
            }
            $db->pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$idEliminar]);
            $db->pdo->commit();
            $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // Foto de perfil subida 
            $av = (string)($filaUsuario['avatar'] ?? '');
            if (strpos($av, 'img:') === 0) {
                $archivo = __DIR__ . '/../img/avatares/' . basename(substr($av, 4));
                if (is_file($archivo)) @unlink($archivo);
            }

            // Cerrar sesión por completo
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();

            header("Location: login.php?cuenta_eliminada=1");
            exit;
        } catch (Throwable $e) {
            if ($db->pdo->inTransaction()) $db->pdo->rollBack();
            try { $db->pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Throwable $e2) {}
            $mensaje = "No se pudo eliminar la cuenta. Inténtalo más tarde.";
        }
    }
}

// Obtener o crear ajustes en la tabla 'ajustes_usuario'
$stmtAjustes = $db->pdo->prepare("SELECT * FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
$stmtAjustes->execute([$usuario["id"]]);
$ajustes = $stmtAjustes->fetch(PDO::FETCH_ASSOC);

if (!$ajustes) {
    $db->pdo->prepare("INSERT INTO ajustes_usuario (usuario_id, mostrar_email, mostrar_listas, objetivo_anual) VALUES (?, 1, 1, 20)")
            ->execute([$usuario["id"]]);
    $ajustes = ["mostrar_email" => 1, "mostrar_listas" => 1, "objetivo_anual" => 20];
}

// Contar total de libros del usuario
$stmtCount = $db->pdo->prepare("SELECT COUNT(*) FROM listas_lectura WHERE usuario_id = ?");
$stmtCount->execute([$usuario["id"]]);
$totalLibrosUsuario = (int)$stmtCount->fetchColumn();

// Exportar CSV de Goodreads
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

// Importar CSV de Goodreads.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["importar_goodreads"])) {
    @set_time_limit(300);

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
            $colBookId    = $colMap['book id'] ?? null;
            $colPaginas   = $colMap['number of pages'] ?? null;
            $colNota      = $colMap['my rating'] ?? null;
            $colShelves = $colMap['bookshelves'] ?? null;

            $isbnValido = function ($raw): string {
                $v = strtoupper(preg_replace('/[^0-9X]/i', '', (string)$raw));
                return preg_match('/^(\d{9}[\dX]|\d{13})$/', $v) ? $v : '';
            };

            $filas = [];
            $omitidas = 0;   // filas sin título (no se pueden importar)
            while (($data = fgetcsv($tempStream, 5000, ",")) !== false) {
                if ($colTitulo === null || !isset($data[$colTitulo])) { $omitidas++; continue; }
                $titulo = trim($data[$colTitulo]);
                if (empty($titulo)) { $omitidas++; continue; }

                $autor = ($colAutor !== null && isset($data[$colAutor])) ? trim($data[$colAutor]) : 'Autor desconocido';
                $estanteGR = ($colEstante !== null && isset($data[$colEstante])) ? strtolower(trim($data[$colEstante])) : 'to-read';

                if ($estanteGR === 'read') {
                    $estado = 'leido';
                } elseif ($estanteGR === 'currently-reading') {
                    $estado = 'leyendo';
                } else {
                    $estado = 'tbr';
                }

                $isbnLimpio = '';
                if ($colIsbn13 !== null && isset($data[$colIsbn13])) $isbnLimpio = $isbnValido($data[$colIsbn13]);
                if ($isbnLimpio === '' && $colIsbn !== null && isset($data[$colIsbn])) $isbnLimpio = $isbnValido($data[$colIsbn]);

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

                $bookId = ($colBookId !== null && isset($data[$colBookId])) ? preg_replace('/\D/', '', $data[$colBookId]) : '';
                if ($isbnLimpio !== '')   $idRef = $isbnLimpio;
                elseif ($bookId !== '')   $idRef = 'gr_' . $bookId;
                else                      $idRef = uniqid('gr_');

                $paginas = ($colPaginas !== null && isset($data[$colPaginas])) ? (int)preg_replace('/\D/', '', $data[$colPaginas]) : 0;
                $nota    = ($colNota !== null && isset($data[$colNota])) ? max(0, min(5, (int)$data[$colNota])) : 0;

                $filas[] = [
                    'id_ref'    => $idRef,
                    'titulo'    => $titulo,
                    'autor'     => $autor,
                    'estado'    => $estado,
                    'fecha_fin' => $fechaFin,
                    'paginas'   => $paginas,
                    'nota'      => $nota,
                    'generos'   => ($colShelves !== null && isset($data[$colShelves])) ? normalizarGeneros(separarGeneros($data[$colShelves])) : [],
                ];
            }
            fclose($tempStream);

            $importados = 0;
            $colGen = columnaGeneros($db->pdo);
            foreach ($filas as $libro) {
                $listaService->agregarLibro(
                    $usuario["id"], $libro['id_ref'], $libro['titulo'],
                    '/Reads/img/default_cover.jpg', $libro['estado'],
                    $libro['autor'], $libro['fecha_fin']
                );
                if ($colGen && !empty($libro['generos'])) {
                try {
                 $db->pdo->prepare("UPDATE listas_lectura SET `{$colGen}` = ? WHERE usuario_id = ? AND libro_id = ?")
                    ->execute([implode(', ', $libro['generos']), $usuario["id"], $libro['id_ref']]);
                 } catch (Throwable $e) {}
                    }

                if ($libro['paginas'] > 0) {
                    try {
                        $leidas = ($libro['estado'] === 'leido') ? $libro['paginas'] : 0;
                        $db->pdo->prepare("UPDATE listas_lectura SET paginas_totales = ?, paginas_leidas = ? WHERE usuario_id = ? AND libro_id = ?")
                                ->execute([$libro['paginas'], $leidas, $usuario["id"], $libro['id_ref']]);
                    } catch (Throwable $e) {}
                }

                if ($libro['nota'] > 0) {
                    try {
                        $existe = $db->pdo->prepare("SELECT 1 FROM puntuaciones WHERE usuario_id = ? AND libro_id = ? LIMIT 1");
                        $existe->execute([$usuario["id"], $libro['id_ref']]);
                        if ($existe->fetchColumn()) {
                            $db->pdo->prepare("UPDATE puntuaciones SET estrellas = ? WHERE usuario_id = ? AND libro_id = ?")
                                    ->execute([$libro['nota'], $usuario["id"], $libro['id_ref']]);
                        } else {
                            $db->pdo->prepare("INSERT INTO puntuaciones (usuario_id, libro_id, estrellas, romance, spicy, lagrimas, plot_twist) VALUES (?, ?, ?, 0, 0, 0, 0)")
                                    ->execute([$usuario["id"], $libro['id_ref'], $libro['nota']]);
                        }
                    } catch (Throwable $e) {}
                }

                $importados++;
            }

            if ($importados > 0) {
                $mensaje = "Importación completada. Se han importado $importados libros"
                          . ($omitidas > 0 ? " ($omitidas filas sin título se han omitido)" : "")
                          . ".";
            } else {
                $mensaje = "No se ha importado ningún libro. Comprueba que el archivo es el CSV de Goodreads (My Books > Import/Export)"
                         . ($omitidas > 0 ? " — se han encontrado $omitidas filas sin título." : ".");
            }
            $iniciarCola = $importados > 0;
            $totalLibrosUsuario += $importados;
        } else {
            $mensaje = "Error: El archivo CSV está vacío o es inválido.";
        }
    } else {
        // Motivo concreto del fallo de subida
        $codigoSubida = $_FILES["csv_file"]["error"] ?? UPLOAD_ERR_NO_FILE;
        if ($codigoSubida === UPLOAD_ERR_INI_SIZE || $codigoSubida === UPLOAD_ERR_FORM_SIZE) {
            $mensaje = "El archivo es demasiado grande para el servidor (límite: " . ini_get('upload_max_filesize') . ").";
        } elseif ($codigoSubida === UPLOAD_ERR_NO_FILE) {
            $mensaje = "Por favor, selecciona un archivo CSV válido.";
        } else {
            $mensaje = "No se pudo subir el archivo (código de error $codigoSubida). Inténtalo de nuevo.";
        }
    }
}

// Guardar ajustes del perfil generales
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_ajustes"])) {
    $nuevoNombre    = trim($_POST["nombre"] ?? "");
    $nuevoEmail     = trim($_POST["email"] ?? "");
    $privacidad     = $_POST["privacidad"] ?? "publico";
    $mostrar_email  = ($privacidad === "publico") ? 1 : 0;
    $mostrar_listas = ($privacidad === "publico") ? 1 : 0;
    $objetivo_anual = isset($_POST["objetivo_anual"]) ? (int)$_POST["objetivo_anual"] : 20;
    $idiomasValidos = ['spa', 'eng', 'cat', 'fra', 'ita', 'por', 'deu', ''];
    $idioma_lectura = in_array($_POST["idioma_lectura"] ?? 'spa', $idiomasValidos, true)
        ? $_POST["idioma_lectura"]
        : 'spa';

    // Actualizar nombre y email en la tabla 'usuarios'
    if (!empty($nuevoNombre) && !empty($nuevoEmail)) {
        $stmtUser = $db->pdo->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
        $stmtUser->execute([$nuevoNombre, $nuevoEmail, $usuario["id"]]);

        if (isset($_SESSION["usuario"])) {
            $_SESSION["usuario"]["nombre"] = $nuevoNombre;
            $_SESSION["usuario"]["email"]  = $nuevoEmail;
        }
        $usuario["nombre"] = $nuevoNombre;
        $usuario["email"]  = $nuevoEmail;
        $datos["nombre"]   = $nuevoNombre;
        $datos["email"]    = $nuevoEmail;
    }

    // Actualizar objetivo y visibilidad en 'ajustes_usuario'
    $stmtUp = $db->pdo->prepare("UPDATE ajustes_usuario SET mostrar_email = ?, mostrar_listas = ?, objetivo_anual = ?, idioma_lectura = ? WHERE usuario_id = ?");
    $stmtUp->execute([$mostrar_email, $mostrar_listas, $objetivo_anual, $idioma_lectura, $usuario["id"]]);

    $ajustes["mostrar_email"]  = $mostrar_email;
    $ajustes["mostrar_listas"] = $mostrar_listas;
    $ajustes["objetivo_anual"] = $objetivo_anual;
    $ajustes["idioma_lectura"] = $idioma_lectura;

    $mensaje = "Ajustes del perfil guardados correctamente.";
}

// Enviar mensaje de contacto
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enviar_contacto"])) {
    $asunto          = trim((string)($_POST["asunto"] ?? ""));
    $mensajeContacto = trim((string)($_POST["mensaje_contacto"] ?? ""));

    if (session_status() === PHP_SESSION_NONE) session_start();
    $ultimoEnvio = (int)($_SESSION['contacto_ultimo'] ?? 0);

    if ($asunto === '' || $mensajeContacto === '') {
        $mensaje = "Por favor, completa todos los campos del formulario de contacto.";
    } elseif (mb_strlen($asunto) > 150 || mb_strlen($mensajeContacto) > 3000) {
        $mensaje = "El mensaje es demasiado largo. Reduce el asunto o el texto.";
    } elseif (time() - $ultimoEnvio < 60) {
        $mensaje = "Espera un minuto antes de enviar otro mensaje.";
    } else {
        $correoService = new CorreoService();
        $enviado = $correoService->enviarContacto(
            $datos['email']  ?? $usuario['email']  ?? '',
            $datos['nombre'] ?? $usuario['nombre'] ?? 'Usuario Reads',
            $asunto,
            $mensajeContacto
        );

        if ($enviado) {
            $_SESSION['contacto_ultimo'] = time();
            $mensaje = "¡Gracias por contactarnos! Tu mensaje ha sido enviado correctamente.";
        } else {
            $esAdminTmp = (strtolower(trim($datos['email'] ?? '')) === 'admin@admin.com');
           $mensaje = "Hubo un problema al enviar el mensaje. Inténtalo más tarde."
         . ($correoService->ultimoError !== '' ? ' [Detalle: ' . $correoService->ultimoError . ']' : '');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes de la aplicación</title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
</head>
<body class="page-ajustes">

<!-- Aviso mientras se importa el CSV (se muestra desde el JavaScript de abajo) -->
<div class="import-overlay" id="overlay-importando" role="status" aria-live="polite">
    <div class="import-card">
        <div class="import-spinner"></div>
        <h2>Importando tu biblioteca…</h2>
        <p>Estamos leyendo tu archivo y añadiendo los libros. Puede tardar un minuto si tienes muchos.</p>
        <span class="import-archivo" id="import-nombre"></span>
        <p><strong>No cierres ni recargues esta página.</strong></p>
    </div>
</div>

<div class="container">

    <!-- Cabecera -->
    <div class="header-section">
        <h1>⚙️ Ajustes de la aplicación</h1>
        <p>Personaliza tu experiencia, estilos visuales e importación de libros</p>
    </div>

    <?php if ($mensaje): ?>
        <div class="panel alert-box" id="mensaje-ajustes" role="status" style="margin-bottom: 20px; font-weight: bold;">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($iniciarCola): ?>
        <div class="panel card-panel" id="cola-progreso" <?= $esAdmin ? '' : 'hidden style="display:none"' ?>>
            <h2 class="card-title"> Buscando las portadas…</h2>
            <p class="card-subtitle">Puedes seguir usando la app: se van rellenando solas, también mientras navegas por otras páginas.</p>
            <div style="height:10px; border-radius:10px; background:var(--tint, rgba(0,0,0,.08)); overflow:hidden;">
                <div id="cola-barra" style="height:100%; width:0; background:var(--primary-color, #d87d8a); transition:width .4s;"></div>
            </div>
            <p id="cola-texto" style="margin:10px 0 0; font-size:.88rem; opacity:.85;">Empezando…</p>
        </div>
    <?php endif; ?>

    <!-- Estilo Visual y Temas -->
    <div class="panel card-panel">
        <h2 class="card-title"> Estilo Visual & Temas</h2>
        <p class="card-subtitle">Elige la paleta visual que mejor se adapte a tu estado de ánimo o momento del día:</p>

        <form method="POST" id="formTema">
            <input type="hidden" name="guardar_tema" value="1">
            <input type="hidden" name="tema_visual" id="inputTemaVisual" value="<?= htmlspecialchars($tema) ?>">

            <div class="temas-grid">
                <?php foreach ($temasDisponibles as $key => $tInfo): ?>
                    <button type="button"
                            class="tema-card <?= ($tema === $key) ? 'activo' : '' ?>"
                            style="background-color: <?= $tInfo['bg'] ?>; border-color: <?= ($tema === $key) ? 'currentColor' : $tInfo['border'] ?>; color: <?= $tInfo['text'] ?>;"
                            onclick="seleccionarTema('<?= $key ?>')">
                        <?php if ($tema === $key): ?>
                            <span class="tema-card-dot"></span>
                        <?php endif; ?>
                        <div class="tema-card-icon"><?= $tInfo['icono'] ?></div>
                        <div class="tema-card-name"><?= $tInfo['nombre'] ?></div>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <!-- Ajustes generales del perfil -->
    <div class="panel card-panel">
        <h2 class="card-title">Ajustes generales del perfil</h2>

        <form method="POST">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nombre de usuario:</label>
                    <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($datos['nombre'] ?? $usuario['nombre'] ?? 'Usuario') ?>" required>
                </div>

                <div class="form-group">
                    <label>Correo electrónico:</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($datos['email'] ?? $usuario['email'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Objetivo anual de lectura (libros):</label>
                    <input type="number" name="objetivo_anual" class="form-control" min="1" max="500" value="<?= htmlspecialchars($ajustes['objetivo_anual'] ?? 20) ?>" required>
                </div>

                <div class="form-group">
                    <label>Privacidad del perfil:</label>
                    <select name="privacidad" class="form-control">
                        <option value="publico" <?= ($ajustes['mostrar_listas'] == 1) ? 'selected' : '' ?>>Público</option>
                        <option value="privado" <?= ($ajustes['mostrar_listas'] == 0) ? 'selected' : '' ?>>Privado</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Idioma de las recomendaciones:</label>
                    <select name="idioma_lectura" class="form-control">
                        <?php
                        $idiomasUI = ['spa'=>'Español','eng'=>'Inglés','cat'=>'Català','fra'=>'Français',
                                      'ita'=>'Italiano','por'=>'Português','deu'=>'Deutsch','' =>'Cualquier idioma'];
                        $actual = $ajustes['idioma_lectura'] ?? 'spa';
                        foreach ($idiomasUI as $cod => $nombre): ?>
                            <option value="<?= $cod ?>" <?= ($actual === $cod) ? 'selected' : '' ?>><?= $nombre ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="btn-submit-container">
                <button type="submit" name="guardar_ajustes" class="submit-btn btn-pill">Guardar ajustes</button>
            </div>
        </form>
    </div>

    <!-- Importar / Exportar Goodreads -->
    <div class="panel card-panel">
        <h2 class="card-title"> Importar / Exportar Goodreads</h2>

        <div class="goodreads-grid">
            <div class="review-card goodreads-box">
                <div>
                    <h3> Importar biblioteca desde CSV:</h3>
                    <p>Sube tu archivo <code>.csv</code> descargado de Goodreads (<em>My Books &gt; Import/Export</em>). </p>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formImportarCSV">
                    <input type="hidden" name="importar_goodreads" value="1">
                    <input type="file" name="csv_file" id="inputCSV" accept=".csv" style="display: none;" onchange="importarCSV(this);">
                    <button type="button" class="btn-white-card" id="btnSeleccionarCSV" onclick="document.getElementById('inputCSV').click();">
                         Seleccionar archivo CSV
                    </button>
                </form>
            </div>

            <div class="review-card goodreads-box">
                <div>
                    <h3> Exportar mi biblioteca:</h3>
                    <p>Descarga tu biblioteca completa (<strong><?= $totalLibrosUsuario ?> libros</strong>) en un archivo <code>.csv</code> estándar compatible con Goodreads, Excel y Notion.</p>
                </div>
                <a href="ajustes.php?exportar=goodreads" class="btn-white-card">
                     Descargar mi biblioteca (.CSV)
                </a>
            </div>
        </div>
    </div>

    <!-- Contacto con el equipo -->
    <div class="panel card-panel">
        <h2 class="card-title"> Contacta con el equipo</h2>
        <p class="card-subtitle">¿Tienes sugerencias, ideas de nuevas funciones o has encontrado alguna duda? Envíanos tu mensaje:</p>

        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <input type="text" name="asunto" class="form-control" placeholder="Asunto (Ej: Sugerencia de mejora / Nuevos filtros)" required>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <textarea name="mensaje_contacto" class="form-control" rows="4" placeholder="Escribe aquí tu consulta o comentario detallado..." required style="resize: vertical;"></textarea>
            </div>

            <button type="submit" name="enviar_contacto" class="submit-btn btn-pill">
                 Enviar mensaje
            </button>
        </form>
    </div>

           <!-- Eliminar cuenta -->
    <div class="zona-eliminar">
        <span>¿Quieres dejar Reads?</span>
        <button type="button" class="btn-eliminar-cuenta" onclick="document.getElementById('dlg-eliminar').showModal()">Eliminar mi cuenta</button>
    </div>

    <dialog class="del-dialog" id="dlg-eliminar">
        <form method="POST" class="del-cuerpo">
            <h2>¿Eliminar tu cuenta?</h2>
            <p>Se borrarán tus libros, valoraciones, reseñas y estadísticas. No se puede deshacer.</p>

            <input type="hidden" name="eliminar_cuenta" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_eliminar']) ?>">

            <label for="password_eliminar">Tu contraseña para confirmar:</label>
            <input type="password" id="password_eliminar" name="password_eliminar" class="form-control" required autocomplete="current-password">

            <label class="del-check">
                <input type="checkbox" name="confirmo" value="1" required>
                <span>Entiendo que es permanente.</span>
            </label>

            <div class="del-acciones">
                <button type="button" class="del-cancelar" onclick="document.getElementById('dlg-eliminar').close()">Cancelar</button>
                <button type="submit" class="del-confirmar">Eliminar</button>
            </div>
        </form>
    </dialog>
<!-- Footer -->
<footer class="site-footer">
    <span style="font-size: 0.85rem; color: #777;">Reads &copy; <?= date("Y") ?></span>
    <a href="logout.php" class="btn-logout-footer">
        <span>🚪</span> Cerrar sesión
    </a>
</footer>

</div>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float"><span class="nav-icon">🏠</span><span>Inicio</span></a>
        <a href="perfil.php" class="nav-card-float"><span class="nav-icon">👤</span><span>Mi perfil</span></a>
        <a href="biblioteca.php" class="nav-card-float"><span class="nav-icon">📚</span><span>Mi estantería</span></a>
        <a href="estadisticas.php" class="nav-card-float"><span class="nav-icon">📊</span><span>Estadísticas</span></a>
        <a href="calendario.php" class="nav-card-float"><span class="nav-icon">📅</span><span>Calendario</span></a>
        <a href="buscar.php" class="nav-card-float"><span class="nav-icon">🔍</span><span>Buscar</span></a>
        <a href="comunidad.php" class="nav-card-float"><span class="nav-icon">👥</span><span>Comunidad</span></a>
        <a href="ajustes.php" class="nav-card-float active"><span class="nav-icon">⚙️</span><span>Ajustes</span></a>
    </nav>
</div>

<script>
function seleccionarTema(nombreTema) {
    document.getElementById('inputTemaVisual').value = nombreTema;
    document.getElementById('formTema').submit();
}

// Importar CSV: avisa al usuario de que se está importando y envía el formulario
function importarCSV(input) {
    const archivo = input.files && input.files[0];
    if (!archivo) return;

    if (!/\.csv$/i.test(archivo.name)) {
        alert('Selecciona el archivo .csv que descargaste de Goodreads.');
        input.value = '';
        return;
    }

    const tam = archivo.size < 1048576
        ? Math.max(1, Math.round(archivo.size / 1024)) + ' KB'
        : (archivo.size / 1048576).toFixed(1) + ' MB';
    document.getElementById('import-nombre').textContent = archivo.name + ' · ' + tam;
    document.getElementById('overlay-importando').classList.add('visible');

    const boton = document.getElementById('btnSeleccionarCSV');
    if (boton) {
        boton.disabled = true;
        boton.textContent = '⏳ Importando…';
    }

    // Pequeña espera para que el aviso se pinte antes de enviar el formulario
    setTimeout(() => document.getElementById('formImportarCSV').submit(), 80);
}

// Si se vuelve a esta página con el botón "atrás", quitar el aviso
window.addEventListener('pageshow', () => {
    document.getElementById('overlay-importando').classList.remove('visible');
    const boton = document.getElementById('btnSeleccionarCSV');
    if (boton) {
        boton.disabled = false;
        boton.textContent = 'Seleccionar archivo CSV';
    }
});

// Al terminar, llevar la vista al mensaje de resultado
document.addEventListener('DOMContentLoaded', () => {
    const msg = document.getElementById('mensaje-ajustes');
    if (msg) msg.scrollIntoView({ behavior: 'smooth', block: 'center' });

    <?php if ($abrirEliminar): ?>
    document.getElementById('dlg-eliminar').showModal();
<?php endif; ?>
});
</script>
<?php if ($iniciarCola): ?>
<script src="/Reads/public/portadas_cola.js"></script>
<script>
// Géneros: se detectan solos en segundo plano tras importar (el usuario no ve nada)
(async function () {
    try {
        for (let i = 0; i < 60; i++) {
            const fd = new FormData();
            fd.append("accion", "procesar");
            fd.append("ajax", "1");
            const r = await fetch("rellenar_generos.php", { method: "POST", body: fd, credentials: "same-origin" });
            if (!r.ok) break;
            const j = await r.json();
            if (j.pausa || !j.pendientes || !j.procesados) break;
            await new Promise(r => setTimeout(r, 800));
        }
    } catch (e) {}
})();
</script>
<?php endif; ?>
</body>
</html>
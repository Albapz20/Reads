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

// Obtener usuario e información del tema
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";
$tema = strtolower(trim($tema));

$mensaje = "";

/* Guardar tema visual */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_tema"])) {
    $tema_visual = $_POST["tema_visual"];

    $sql = "UPDATE usuarios SET tema_visual = ? WHERE id = ?";
    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$tema_visual, $usuario["id"]]);

    if (isset($_SESSION["usuario"])) {
        $_SESSION["usuario"]["tema_visual"] = $tema_visual;
    }

    $usuario["tema_visual"] = $tema_visual;
    $tema = $tema_visual;

    $mensaje = "Tema visual actualizado a " . ucfirst($tema) . ".";
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

// Importar CSV de Goodreads
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
                    'portada_local' => 'uploads/portadas/default.jpg'
                ];
            }
            fclose($tempStream);

            $importados = 0;
            foreach ($filas as $libro) {
                $listaService->agregarLibro(
    $usuario["id"], $libro['id_ref'], $libro['titulo'],
    $libro['portada_local'], $libro['estado'],
    $libro['autor'], $libro['fecha_fin']
);
                $importados++;
            }

            $mensaje = "¡Se han importado exitosamente $importados libros!";
        } else {
            $mensaje = "Error: El archivo CSV está vacío o es inválido.";
        }
    } else {
        $mensaje = "Por favor, selecciona un archivo CSV válido.";
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
            $mensaje = "¡Gracias por contactarnos! Tu mensaje ha sido enviado correctamente.";
        } else {
            $mensaje = "Hubo un problema al enviar el mensaje. Inténtalo más tarde.";
        }
    } else {
        $mensaje = "Por favor, completa todos los campos del formulario de contacto.";
    }
}

// Configuración de las 9 tarjetas de temas
$temasDisponibles = [
    'pastel'      => ['nombre' => 'Pastel', 'icono' => '🌸', 'bg' => '#fdf0f2', 'border' => '#e8a5b2', 'text' => '#4a2c32'],
    'sand'        => ['nombre' => 'Sand', 'icono' => '🏖️', 'bg' => '#fbf7ee', 'border' => '#d9c9a3', 'text' => '#4a3f2c'],
    'dracula'     => ['nombre' => 'Dracula', 'icono' => '🐉', 'bg' => '#383a59', 'border' => '#ff79c6', 'text' => '#f8f8f2'],
    'coffee'      => ['nombre' => 'Coffee', 'icono' => '☕', 'bg' => '#f5efe6', 'border' => '#c2b09b', 'text' => '#3e2723'],
    'dark'        => ['nombre' => 'Dark / Slate', 'icono' => '🔮', 'bg' => '#2d3748', 'border' => '#4a5568', 'text' => '#edf2f7'],
    'minimalista' => ['nombre' => 'Minimalista', 'icono' => '📐', 'bg' => '#ffffff', 'border' => '#cbd5e1', 'text' => '#1e293b'],
    'sunset'      => ['nombre' => 'Sunset', 'icono' => '🌅', 'bg' => '#fff5eb', 'border' => '#f97316', 'text' => '#431407'],
    'azul'        => ['nombre' => 'Azul Calmado', 'icono' => '💙', 'bg' => '#f0f7ff', 'border' => '#3b82f6', 'text' => '#1e3a8a'],
    'naturalista' => ['nombre' => 'Naturalista', 'icono' => '🌿', 'bg' => '#f4f7f4', 'border' => '#4d7c0f', 'text' => '#14532d']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes de la aplicación</title>

    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            padding: 30px 15px 110px 15px;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
        }
        .header-section {
            margin-bottom: 25px;
        }
        .header-section h1 {
            font-size: 1.8rem;
            margin: 0 0 5px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-section p {
            margin: 0;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        .card-panel {
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
        }
        .card-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-subtitle {
            font-size: 0.88rem;
            opacity: 0.75;
            margin: 0 0 20px 0;
        }
        
        /* TEMAS GRID */
        .temas-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        @media (max-width: 650px) {
            .temas-grid { grid-template-columns: 1fr; }
        }
        .tema-card {
            border-radius: 16px;
            padding: 16px;
            border: 2px solid transparent;
            cursor: pointer;
            position: relative;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 75px;
            text-align: left;
            width: 100%;
            box-sizing: border-box;
        }
        .tema-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .tema-card.activo {
            border-color: currentColor !important;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.2);
        }
        .tema-card-dot {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 10px;
            height: 10px;
            background: currentColor;
            border-radius: 50%;
        }
        .tema-card-icon {
            font-size: 1.4rem;
            margin-bottom: 10px;
        }
        .tema-card-name {
            font-weight: 800;
            font-size: 0.92rem;
        }

        /* FORMULARIOS & INPUTS */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        @media (max-width: 600px) {
            .form-grid-2 { grid-template-columns: 1fr; }
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 0.85rem;
            font-weight: 700;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.95rem;
            box-sizing: border-box;
            outline: none;
        }
        .btn-submit-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .btn-pill {
            border: none;
            padding: 11px 26px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s, transform 0.2s;
        }
        .btn-pill:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* IMPORTAR / EXPORTAR GRID */
        .goodreads-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 650px) {
            .goodreads-grid { grid-template-columns: 1fr; }
        }
        .goodreads-box {
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .goodreads-box h3 {
            font-size: 0.95rem;
            font-weight: 800;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .goodreads-box p {
            font-size: 0.85rem;
            margin: 0 0 15px 0;
            line-height: 1.4;
            opacity: 0.8;
        }
        .btn-white-card {
            border-radius: 12px;
            padding: 12px 16px;
            width: 100%;
            text-align: center;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            box-sizing: border-box;
            display: inline-block;
            text-decoration: none;
            border: 1px solid rgba(0,0,0,0.15);
        }

        /* NAVEGACIÓN FLOTANTE */
        .floating-nav-container {
            position: fixed !important;
            bottom: 20px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            z-index: 999999 !important;
            width: calc(100% - 40px) !important;
            max-width: 600px !important;
            display: block !important;
        }

        .quick-nav-floating {
            display: flex !important;
            align-items: center !important;
            justify-content: space-around !important;
            padding: 8px 12px !important;
            background: #ffffff !important;
            border: 1px solid rgba(0, 0, 0, 0.12) !important;
            border-radius: 20px !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
        }

        .nav-card-float {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            padding: 6px 12px !important;
            text-decoration: none !important;
            color: #2d3748 !important;
            font-weight: 700 !important;
            font-size: 0.8rem !important;
            border-radius: 12px !important;
            transition: all 0.2s ease !important;
        }

        .nav-card-float:hover,
        .nav-card-float.active {
            color: var(--color-primario, #d87d8a) !important;
            transform: translateY(-2px) !important;
        }

        .nav-card-float .nav-icon {
            font-size: 1.25rem !important;
            margin-bottom: 2px !important;
        }
    </style>

    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
</head>
<body>

<div class="container">

    <!-- Cabecera -->
    <div class="header-section">
        <h1>⚙️ Ajustes de la aplicación</h1>
        <p>Personaliza tu experiencia, estilos visuales e importación de libros</p>
    </div>

    <?php if ($mensaje): ?>
        <div class="panel alert-box" style="margin-bottom: 20px; font-weight: bold;">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <!-- Estilo Visual y Temas -->
    <div class="panel card-panel">
        <h2 class="card-title">🎨 Estilo Visual & Temas (9)</h2>
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
        <h2 class="card-title">📤📚 Importar / Exportar Goodreads</h2>

        <div class="goodreads-grid">
            <div class="review-card goodreads-box">
                <div>
                    <h3>📌 Importar biblioteca desde CSV:</h3>
                    <p>Sube tu archivo <code>.csv</code> descargado de Goodreads (<em>My Books &gt; Import/Export</em>). Detectará títulos, autores, número de páginas, estado y valoraciones.</p>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formImportarCSV">
                    <input type="hidden" name="importar_goodreads" value="1">
                    <input type="file" name="csv_file" id="inputCSV" accept=".csv" style="display: none;" onchange="document.getElementById('formImportarCSV').submit();">
                    <button type="button" class="btn-white-card" onclick="document.getElementById('inputCSV').click();">
                        📤 Seleccionar archivo CSV
                    </button>
                </form>
            </div>

            <div class="review-card goodreads-box">
                <div>
                    <h3>📌 Exportar mi biblioteca:</h3>
                    <p>Descarga tu biblioteca completa (<strong><?= $totalLibrosUsuario ?> libros</strong>) en un archivo <code>.csv</code> estándar compatible con Goodreads, Excel y Notion.</p>
                </div>
                <a href="ajustes.php?exportar=goodreads" class="btn-white-card">
                    📥 Descargar mi biblioteca (.CSV)
                </a>
            </div>
        </div>
    </div>

    <!-- Contacto con el equipo -->
    <div class="panel card-panel">
        <h2 class="card-title">📩 Contacta con el equipo</h2>
        <p class="card-subtitle">¿Tienes sugerencias, ideas de nuevas funciones o has encontrado alguna duda? Envíanos tu mensaje:</p>

        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <input type="text" name="asunto" class="form-control" placeholder="Asunto (Ej: Sugerencia de mejora / Nuevos filtros)" required>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <textarea name="mensaje_contacto" class="form-control" rows="4" placeholder="Escribe aquí tu consulta o comentario detallado..." required style="resize: vertical;"></textarea>
            </div>

            <button type="submit" name="enviar_contacto" class="submit-btn btn-pill">
                📩 Enviar mensaje
            </button>
        </form>
    </div>

    <div style="text-align: center; margin-top: 20px;">
        <a href="perfil.php" style="text-decoration: none; font-size: 0.9rem; font-weight: 600;">← Volver al perfil</a>
    </div>

</div>

<!-- Navegación flotante -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>
        <a href="perfil.php" class="nav-card-float">
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
        </a>
        <a href="buscar.php" class="nav-card-float">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>
    </nav>
</div>

<script>
function seleccionarTema(nombreTema) {
    document.getElementById('inputTemaVisual').value = nombreTema;
    document.getElementById('formTema').submit();
}
</script>
</body>
</html>
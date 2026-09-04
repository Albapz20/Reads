<?php

require_once "../src/BookService.php";
require_once "../src/Database.php";
require_once "../src/Auth.php";
require_once "../src/ReviewService.php";
require_once "../src/RatingService.php";
require_once "../src/ListaService.php";
require_once "../src/UserService.php";

$authUser = Auth::usuario();
$reviewService = new ReviewService();
$ratingService = new RatingService();
$service = new BookService();
$db = new Database();
$listaService = new ListaService();

// Tema visual
$tema = "pastel";
if ($authUser) {
    $userService = new UserService();
    $datos = $userService->obtenerUsuarioPorId($authUser["id"]);
    $tema = $datos["tema_visual"] ?? "pastel";
}

function renderStars($rating) {
    $full = floor($rating);
    $half = ($rating - $full >= 0.5) ? 1 : 0;
    $empty = 5 - $full - $half;

    $html = str_repeat('<span class="star full">★</span>', $full);
    if ($half) $html .= '<span class="star half">★</span>';
    $html .= str_repeat('<span class="star empty">★</span>', $empty);

    return $html;
}

if (!isset($_GET['id'])) die("Libro no encontrado");

$id_externo = $_GET['id'];
$descripcionURL = isset($_GET['desc']) ? trim(urldecode($_GET['desc'])) : '';

$libroAPI = $service->obtenerLibro($id_externo);

if (!$libroAPI) die("No se pudo obtener información del libro.");

$paginasTotalesAPI = 0;
$proveedor = "google_books";

// ==========================================
// TRATAMIENTO UNIFICADO DE DATOS Y PORTADA
// ==========================================
if (isset($libroAPI["volumeInfo"])) {
    // ---- GOOGLE BOOKS DIRECTO ----
    $info = $libroAPI["volumeInfo"];
    $titulo = $info["title"] ?? "Sin título";
    $autor = isset($info["authors"]) ? implode(", ", $info["authors"]) : "Autor desconocido";
    
    $images = $info["imageLinks"] ?? [];
    $portada = $images["extraLarge"] ?? $images["large"] ?? $images["medium"] ?? $images["thumbnail"] ?? $images["smallThumbnail"] ?? "";
    
    if (!empty($portada)) {
        $portada = str_replace("http://", "https://", $portada);
    } else {
        $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
    }

    $descripcion = $info["description"] ?? "Sin descripción disponible.";
    $idioma = $info["language"] ?? "";
    $paginasTotalesAPI = (int)($info["pageCount"] ?? 0);
} else {
    // ---- NORMALIZADO (BookService u Open Library) ----
    $proveedor = "open_library";
    $titulo = $libroAPI["title"] ?? "Sin título";
    
    if (isset($libroAPI["author_name"]) && is_array($libroAPI["author_name"])) {
        $autor = implode(", ", $libroAPI["author_name"]);
    } else {
        $autor = $libroAPI["autor"] ?? "Autor desconocido";
    }

    $descripcion = $libroAPI["description"] ?? "Sin descripción disponible.";

    // Lógica de Portada
    if (!empty($libroAPI["portada"])) {
        $portada = $libroAPI["portada"];
    } elseif (isset($libroAPI["covers"][0]) && is_numeric($libroAPI["covers"][0])) {
        $portada = "https://covers.openlibrary.org/b/id/" . $libroAPI["covers"][0] . "-L.jpg";
    } elseif (!empty($libroAPI["cover_i"])) {
        $portada = "https://covers.openlibrary.org/b/id/" . $libroAPI["cover_i"] . "-L.jpg";
    } else {
        $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
    }

    $idioma = "";
    $paginasTotalesAPI = (int)($libroAPI["number_of_pages"] ?? 0);
}

// Prioridad: Si viene por URL
if (!empty($descripcionURL)) {
    $descripcion = $descripcionURL;
}

// ==========================================
// RESCATE DE DESCRIPCIÓN Y PORTADA
// ==========================================
if (empty(trim($descripcion)) || $descripcion === "Sin descripción disponible." || strpos($portada, 'placehold.co') !== false) {
    
    $apiKey = "AIzaSyBWAS9W-oky5pAt-GlDDSUCv5KEraFA7qI"; 

    $tituloLimpio = trim(explode('/', $titulo)[0]);
    $tituloLimpio = trim(explode('-', $tituloLimpio)[0]);
    $autorLimpio = ($autor !== "Autor desconocido") ? trim(explode(',', $autor)[0]) : '';

    $intentos = [];
    if (!empty($autorLimpio)) {
        $intentos[] = $tituloLimpio . " " . $autorLimpio;
    }
    $intentos[] = $tituloLimpio;

    foreach ($intentos as $query) {
        $urlAPI = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode(trim($query)) . "&key={$apiKey}&langRestrict=es&maxResults=1";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $urlAPI,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);
        
        $json = curl_exec($ch);
        curl_close($ch);

        if ($json) {
            $dataGB = json_decode($json, true);
            $item = $dataGB["items"][0]["volumeInfo"] ?? null;
            
            if ($item) {
                if (!empty($item["description"]) && ($descripcion === "Sin descripción disponible." || empty(trim($descripcion)))) {
                    $descripcion = $item["description"];
                }
                
                if (strpos($portada, 'placehold.co') !== false && !empty($item["imageLinks"])) {
                    $imgRescate = $item["imageLinks"]["thumbnail"] ?? $item["imageLinks"]["smallThumbnail"] ?? "";
                    if (!empty($imgRescate)) {
                        $portada = str_replace("http://", "https://", $imgRescate);
                    }
                }
            }
        }
    }
}

// Fallback final
if (empty(trim($descripcion))) {
    $descripcion = "Sin descripción disponible.";
}

// ==========================================
// PROCESAR GUARDADO/CAMBIO DE ESTADO EN LISTA
// ==========================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["accion"]) && $authUser) {
    $estado = trim($_POST["accion"]);
    
    // 1. Añade o actualiza el registro base
    $listaService->agregarLibro($authUser["id"], $id_externo, $titulo, $portada, $estado);
    
    // 2. Busca la ID generada y asegura el estado / fechas / páginas
    $sqlObtenerId = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) ORDER BY id DESC LIMIT 1";
    $stmtId = $db->pdo->prepare($sqlObtenerId);
    $stmtId->execute([$authUser["id"], $id_externo, $titulo]);
    $registro = $stmtId->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        $listaService->cambiarEstado($authUser["id"], $registro["id"], $estado);

        if ($paginasTotalesAPI > 0) {
            $paginasLeidas = ($estado === "leido") ? $paginasTotalesAPI : 0;
            $listaService->actualizarPaginas($authUser["id"], $registro["id"], $paginasTotalesAPI, $paginasLeidas);
        }
    }

    header("Location: perfil.php");
    exit;
}

// OBTENER EL ESTADO ACTUAL DEL LIBRO
$estadoActual = null;
if ($authUser) {
    $sqlEstado = "SELECT estado FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?) LIMIT 1";
    $stmtEstado = $db->pdo->prepare($sqlEstado);
    $stmtEstado->execute([$authUser["id"], $id_externo, $titulo]);
    $resEstado = $stmtEstado->fetch(PDO::FETCH_ASSOC);
    if ($resEstado) {
        $estadoActual = $resEstado["estado"];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo) ?></title>
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <style>
        body {
            padding-bottom: 90px;
        }
        
        .acciones-estado-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
        }

        .btn-estado {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            background: var(--color-primario, #2d5a27);
            color: #ffffff;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .btn-estado:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            opacity: 0.95;
        }

        .btn-estado.active {
            background: #1b3818;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);
            border: 2px solid #ffffff;
            font-weight: 800;
        }

        /* BARRA FLOTANTE */
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
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .nav-card-float {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 6px 12px;
            text-decoration: none;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.8rem;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .nav-card-float:hover {
            color: var(--color-primario, #2d5a27);
            transform: translateY(-2px);
        }

        .nav-card-float .nav-icon {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="panel">
        <div class="libro-header">
            <img src="<?= htmlspecialchars($portada) ?>" 
                 alt="Portada de <?= htmlspecialchars($titulo) ?>" 
                 class="img-fluid rounded shadow"
                 style="max-width: 250px; height: auto;"
                 onerror="this.onerror=null; this.src='https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada';" />

            <div class="libro-info">
                <h1><?= htmlspecialchars($titulo) ?></h1>
                <p><strong>Autor:</strong> <?= htmlspecialchars($autor) ?></p>
            </div>
        </div>
    </div>

    <!-- SECCIÓN ESTADO DEL LIBRO -->
    <?php if ($authUser): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>📌 Estado de lectura</h2>
        </div>

        <div class="acciones-estado-grid">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="accion" value="guardado">
                <button type="submit" class="btn-estado <?= ($estadoActual === 'guardado') ? 'active' : '' ?>">
                    🎁 Wishlist
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="accion" value="tbr">
                <button type="submit" class="btn-estado <?= ($estadoActual === 'tbr') ? 'active' : '' ?>">
                    🎯 TBR
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="accion" value="leyendo">
                <button type="submit" class="btn-estado <?= ($estadoActual === 'leyendo') ? 'active' : '' ?>">
                    📖 Estoy leyendo
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="accion" value="leido">
                <button type="submit" class="btn-estado <?= ($estadoActual === 'leido') ? 'active' : '' ?>">
                    ✅ Marcar como leído
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="accion" value="abandonado">
                <button type="submit" class="btn-estado <?= ($estadoActual === 'abandonado') ? 'active' : '' ?>">
                    ❌ Abandonar
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">
            <h2>Descripción</h2>
        </div>
        <p><?= nl2br(strip_tags($descripcion)) ?></p>
    </div>

    <?php
    if ($authUser) {
        $sql = "SELECT * FROM listas_lectura WHERE titulo = ? AND usuario_id = ?";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute([$titulo, $authUser["id"]]);
        $libroUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($libroUser) {
            $paginasTotales = (int)$libroUser["paginas_totales"];
            $paginasLeidas  = (int)$libroUser["paginas_leidas"];
            $progreso = $paginasTotales > 0 ? round(($paginasLeidas / $paginasTotales) * 100) : $libroUser["progreso"];
    ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Tu progreso</h2>
        </div>

        <div class="review-card">
            <p><strong>Páginas leídas:</strong> <?= $paginasLeidas ?></p>
            <p><strong>Páginas totales:</strong> <?= $paginasTotales ?></p>
            <p><strong>Progreso:</strong> <?= $progreso ?>%</p>

            <div class="barra-progreso">
                <div class="barra-progreso-inner" style="width: <?= $progreso ?>%"></div>
            </div>
        </div>

        <a href="editar_libro.php?id=<?= $libroUser["id"] ?>" class="submit-btn">✏️ Editar progreso</a>
    </div>
    <?php
        }
    }
    ?>

    <?php if ($authUser): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Escribe una reseña</h2>
        </div>

        <form action="guardar_reseña.php" method="POST">
            <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">
            <textarea name="contenido" rows="5" placeholder="Escribe tu reseña..." required></textarea><br>
            <button type="submit" class="submit-btn">Guardar reseña</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">
            <h2>Reseñas</h2>
        </div>

        <?php
        $reseñas = $reviewService->obtenerReseñas($id_externo);

        if (count($reseñas) === 0):
        ?>
            <p>No hay reseñas todavía.</p>
        <?php else: ?>
            <?php foreach ($reseñas as $r): ?>
                <div class="review-card">
                    <strong><?= htmlspecialchars($r['nombre']) ?></strong><br>
                    <?= nl2br(htmlspecialchars($r['contenido'])) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php
    $puntuacionUsuario = null;
    if ($authUser) {
        $puntuacionUsuario = $ratingService->obtenerPuntuacionUsuario($authUser["id"], $id_externo);
    }
    ?>
    
    <?php if ($authUser): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Puntuación</h2>
        </div>

        <form action="guardar_puntuacion.php" method="POST" id="formPuntuacion">
            <input type="hidden" name="libro_id" value="<?= htmlspecialchars($id_externo) ?>">

            <div class="rating-row" style="margin-bottom: 15px;">
                <label style="display:block; font-weight:bold; margin-bottom: 5px;">
                    ⭐ General: <span id="val-estrellas"><?= $puntuacionUsuario['estrellas'] ?? 0 ?></span> / 5
                </label>
                <div class="icon-selector" data-target="input-estrellas" data-label="val-estrellas" style="cursor: pointer; font-size: 1.8rem; display: flex; gap: 5px;">
                    <span data-val="1">★</span><span data-val="2">★</span><span data-val="3">★</span><span data-val="4">★</span><span data-val="5">★</span>
                </div>
                <input type="hidden" name="estrellas" id="input-estrellas" value="<?= $puntuacionUsuario['estrellas'] ?? 0 ?>">
            </div>

            <div class="rating-row" style="margin-bottom: 15px;">
                <label style="display:block; font-weight:bold; margin-bottom: 5px;">
                    💖 Romance: <span id="val-romance"><?= $puntuacionUsuario['romance'] ?? 0 ?></span> / 5
                </label>
                <div class="icon-selector" data-target="input-romance" data-label="val-romance" style="cursor: pointer; font-size: 1.8rem; display: flex; gap: 5px;">
                    <span data-val="1">💖</span><span data-val="2">💖</span><span data-val="3">💖</span><span data-val="4">💖</span><span data-val="5">💖</span>
                </div>
                <input type="hidden" name="romance" id="input-romance" value="<?= $puntuacionUsuario['romance'] ?? 0 ?>">
            </div>

            <div class="rating-row" style="margin-bottom: 15px;">
                <label style="display:block; font-weight:bold; margin-bottom: 5px;">
                    🌶️ Spicy: <span id="val-spicy"><?= $puntuacionUsuario['spicy'] ?? 0 ?></span> / 5
                </label>
                <div class="icon-selector" data-target="input-spicy" data-label="val-spicy" style="cursor: pointer; font-size: 1.8rem; display: flex; gap: 5px;">
                    <span data-val="1">🌶️</span><span data-val="2">🌶️</span><span data-val="3">🌶️</span><span data-val="4">🌶️</span><span data-val="5">🌶️</span>
                </div>
                <input type="hidden" name="spicy" id="input-spicy" value="<?= $puntuacionUsuario['spicy'] ?? 0 ?>">
            </div>

            <div class="rating-row" style="margin-bottom: 15px;">
                <label style="display:block; font-weight:bold; margin-bottom: 5px;">
                    💧 Lágrimas / Drama: <span id="val-lagrimas"><?= $puntuacionUsuario['lagrimas'] ?? 0 ?></span> / 5
                </label>
                <div class="icon-selector" data-target="input-lagrimas" data-label="val-lagrimas" style="cursor: pointer; font-size: 1.8rem; display: flex; gap: 5px;">
                    <span data-val="1">💧</span><span data-val="2">💧</span><span data-val="3">💧</span><span data-val="4">💧</span><span data-val="5">💧</span>
                </div>
                <input type="hidden" name="lagrimas" id="input-lagrimas" value="<?= $puntuacionUsuario['lagrimas'] ?? 0 ?>">
            </div>

            <div class="rating-row" style="margin-bottom: 20px;">
                <label style="display:block; font-weight:bold; margin-bottom: 5px;">
                    ⚡ Plot Twist: <span id="val-plot_twist"><?= $puntuacionUsuario['plot_twist'] ?? 0 ?></span> / 5
                </label>
                <div class="icon-selector" data-target="input-plot_twist" data-label="val-plot_twist" style="cursor: pointer; font-size: 1.8rem; display: flex; gap: 5px;">
                    <span data-val="1">⚡</span><span data-val="2">⚡</span><span data-val="3">⚡</span><span data-val="4">⚡</span><span data-val="5">⚡</span>
                </div>
                <input type="hidden" name="plot_twist" id="input-plot_twist" value="<?= $puntuacionUsuario['plot_twist'] ?? 0 ?>">
            </div>

            <button type="submit" class="submit-btn">Guardar puntuación</button>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.icon-selector').forEach(container => {
            const inputId = container.dataset.target;
            const labelId = container.dataset.label;
            const input = document.getElementById(inputId);
            const label = document.getElementById(labelId);

            let currentVal = parseFloat(input.value) || 0;
            updateVisuals(container, currentVal);

            container.querySelectorAll('span').forEach(icon => {
                icon.addEventListener('click', (e) => {
                    const rect = icon.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    const baseVal = parseInt(icon.dataset.val);
                    
                    let finalVal = (clickX < rect.width / 2) ? baseVal - 0.5 : baseVal;
                    
                    input.value = finalVal;
                    if (label) label.textContent = finalVal;
                    updateVisuals(container, finalVal);
                });
            });
        });

        function updateVisuals(container, val) {
            container.querySelectorAll('span').forEach(icon => {
                const iconVal = parseInt(icon.dataset.val);
                if (iconVal <= val) {
                    icon.style.opacity = '1';
                    icon.style.filter = 'grayscale(0%)';
                } else if (iconVal - 0.5 === val) {
                    icon.style.opacity = '0.6';
                    icon.style.filter = 'grayscale(30%)';
                } else {
                    icon.style.opacity = '0.25';
                    icon.style.filter = 'grayscale(100%)';
                }
            });
        }
    });
    </script>
    <?php endif; ?>

    <?php if ($puntuacionUsuario): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Tu puntuación</h2>
        </div>

        <?= renderStars($puntuacionUsuario["estrellas"] ?? 0) ?>
        
        <div class="metricas-resumen" style="margin-top: 10px; display: flex; flex-direction: column; gap: 6px;">
            <p style="margin: 0;">⭐ <strong>General:</strong> <?= $puntuacionUsuario["estrellas"] ?? 0 ?> / 5</p>
            <p style="margin: 0;">💖 <strong>Romance:</strong> <?= $puntuacionUsuario["romance"] ?? 0 ?> / 5</p>
            <p style="margin: 0;">🌶️ <strong>Spicy:</strong> <?= $puntuacionUsuario["spicy"] ?? 0 ?> / 5</p>
            <p style="margin: 0;">💧 <strong>Lágrimas:</strong> <?= $puntuacionUsuario["lagrimas"] ?? 0 ?> / 5</p>
            <p style="margin: 0;">⚡ <strong>Plot Twist:</strong> <?= $puntuacionUsuario["plot_twist"] ?? 0 ?> / 5</p>
        </div>
    </div>
    <?php endif; ?>

    <?php $medias = $ratingService->obtenerMedias($id_externo); ?>

    <div class="panel">
        <div class="panel-header">
            <h2>Media global de la comunidad</h2>
        </div>

        <?php if ($medias && $medias["media_estrellas"] !== null): ?>
            <?= renderStars(round($medias["media_estrellas"], 1)) ?>
            
            <div class="metricas-comunidad" style="margin-top: 10px; display: flex; flex-direction: column; gap: 6px;">
                <p style="margin: 0;">⭐ <strong>Estrellas:</strong> <?= round($medias["media_estrellas"], 1) ?> / 5</p>
                <p style="margin: 0;">💖 <strong>Romance:</strong> <?= round($medias["media_romance"] ?? 0, 1) ?> / 5</p>
                <p style="margin: 0;">🌶️ <strong>Spicy:</strong> <?= round($medias["media_spicy"] ?? 0, 1) ?> / 5</p>
                <p style="margin: 0;">💧 <strong>Lágrimas:</strong> <?= round($medias["media_lagrimas"] ?? 0, 1) ?> / 5</p>
                <p style="margin: 0;">⚡ <strong>Plot Twist:</strong> <?= round($medias["media_plot_twist"] ?? 0, 1) ?> / 5</p>
            </div>
        <?php else: ?>
            <p style="color: #888;">Sin puntuaciones aún.</p>
        <?php endif; ?>
    </div>

</div>

<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="index.php" class="nav-card-float">
            <span class="nav-icon">🏠</span>
            <span>Inicio</span>
        </a>
        <a href="perfil.php" class="nav-card-float">
            <span class="nav-icon">👤</span>
            <span>Mi Perfil</span>
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

</body>
</html>
<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";
require_once "../src/helpers.php";

$usuario = Auth::usuario();

if (!$usuario) {
    header("Location: login.php");
    exit;
}

$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datos["tema_visual"] ?? "pastel";

$db = new Database();

// Año actual para métricas dinámicas
$year = date("Y");
$diaDelAno = date("z") + 1; // Días transcurridos en el año actual

// Obtener todos los libros que el usuario está leyendo actualmente
$sqlMisLecturas = "SELECT l.* 
                   FROM listas_lectura l 
                   WHERE l.usuario_id = ? AND l.estado = 'leyendo' 
                   ORDER BY l.id DESC";
$stmtMisLecturas = $db->pdo->prepare($sqlMisLecturas);
$stmtMisLecturas->execute([$usuario["id"]]);
$misLecturasActuales = $stmtMisLecturas->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas de libros leídos y páginas leídas este año
$sql = "SELECT 
            COUNT(*) AS libros_leidos,
            COALESCE(SUM(CASE WHEN paginas_leidas > 0 THEN paginas_leidas ELSE paginas_totales END), 0) AS paginas_leidas
        FROM listas_lectura
        WHERE usuario_id = ? 
          AND estado = 'leido' 
          AND YEAR(fecha_fin) = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"], $year]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Cálculos de métricas
$librosLeidosNum   = (int)($stats["libros_leidos"] ?? 0);
$paginasLeidasNum  = (int)($stats["paginas_leidas"] ?? 0);

// Obtener el objetivo de lectura dinámico desde ajustes_usuario
$sqlAjustes = "SELECT objetivo_anual FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1";
$stmtAjustes = $db->pdo->prepare($sqlAjustes);
$stmtAjustes->execute([$usuario["id"]]);
$rowAjustes = $stmtAjustes->fetch(PDO::FETCH_ASSOC);

$metaLibrosAnual = !empty($rowAjustes["objetivo_anual"]) ? (int)$rowAjustes["objetivo_anual"] : 20;
$porcentajeMeta  = ($metaLibrosAnual > 0) ? min(100, round(($librosLeidosNum / $metaLibrosAnual) * 100)) : 0;

// Variables enlazadas con la vista HTML
$librosLeidos   = $librosLeidosNum;
$objetivoAnual  = $metaLibrosAnual;
$porcentajeReto = $porcentajeMeta;

// Páginas por día este año
$paginasPorDia = ($diaDelAno > 0) ? round($paginasLeidasNum / $diaDelAno, 1) : 0;

// Métricas extra calculadas
$horasLeidasEstimadas = round($paginasLeidasNum / 60, 1);
$promedioPaginasPorLibro = ($librosLeidosNum > 0) ? round($paginasLeidasNum / $librosLeidosNum) : 0;

// Proyección a fin de año
$diasTotalesAno = (date("L") == 1) ? 366 : 365;
$proyeccionLibros = ($diaDelAno > 0) ? round(($librosLeidosNum / $diaDelAno) * $diasTotalesAno) : 0;

// Estado del reto
if ($porcentajeMeta >= 100) {
    $estadoReto = "¡Reto completado!";
    $claseEstado = "color: #2e7d32; font-weight: bold;";
} elseif ($librosLeidosNum >= round(($metaLibrosAnual / $diasTotalesAno) * $diaDelAno)) {
    $estadoReto = "Vas bien, ¡sigue así!";
    $claseEstado = "color: #0078ff; font-weight: 600;";
} else {
    $estadoReto = "¡Aún puedes lograrlo!";
    $claseEstado = "color: #e65100; font-weight: 600;";
}

// Saludo dinámico
$hora = date("H");
if ($hora < 12) $saludo = "Buenos días";
elseif ($hora < 19) $saludo = "Buenas tardes";
else $saludo = "Buenas noches";

// Libros leídos este año
$sqlBiblioteca = "SELECT id, libro_id AS id_externo, titulo, autores, portada, descripcion 
                  FROM listas_lectura
                  WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                  ORDER BY fecha_fin DESC LIMIT 6";
$stmt = $db->pdo->prepare($sqlBiblioteca);
$stmt->execute([$usuario["id"], $year]);
$librosPreview = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ultimoAutor = $librosPreview[0]['autores'] ?? '';

// Historial de búsqueda
$sql = "SELECT termino, fecha 
        FROM historial_busqueda 
        WHERE usuario_id = ?
        ORDER BY fecha DESC
        LIMIT 10";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"]]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

$busquedasUnicas = [];
if (!empty($historial) && is_array($historial)) {
    foreach ($historial as $h) {
        $term = $h['termino'] ?? '';
        $termLimpio = mb_strtolower(trim($term));
        if (!empty($termLimpio) && !in_array($termLimpio, array_map('mb_strtolower', $busquedasUnicas))) {
            $busquedasUnicas[] = trim($term);
        }
    }
    $busquedasUnicas = array_slice($busquedasUnicas, 0, 6);
}

function e($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= e($tema) ?>.css">
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=3">
    <script src="main.js"></script>
    <title>Inicio - Dashboard Reads</title>
</head>

<body class="page-index">

<div class="container">

    <!-- Sección de bienvenida -->
    <div class="welcome-header">
        <h1><?= $saludo ?>, <?= e($usuario["nombre"]) ?> 👋</h1>
        <p style="color: #666; margin: 0;">¿Qué libro tienes en mente para hoy?</p>
    </div>

    <!-- Qué estás leyendo ahora -->
    <div style="margin-bottom: 25px;">
        <h2 style="font-size: 1.25rem; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
            <span>📖 Qué estás leyendo ahora</span>
            <?php if (count($misLecturasActuales) > 1): ?>
                <span style="font-size: 0.8rem; font-weight: normal; color: #777;">Desliza para ver más →</span>
            <?php endif; ?>
        </h2>

        <?php if (!empty($misLecturasActuales)): ?>
            <div class="my-reading-swipe-container">
                <?php foreach ($misLecturasActuales as $miLibro): 
                    $miPct = ($miLibro['paginas_totales'] > 0) 
                        ? round(($miLibro['paginas_leidas'] / $miLibro['paginas_totales']) * 100) 
                        : ($miLibro['progreso'] ?? 0);

                    // Validación automática de portada en local/remota
                    $portadaSrc = obtenerPortadaValida($miLibro['portada'] ?? '', (int)$miLibro['id']);
                    $tienePortada = !empty($portadaSrc);
                ?>
                    <div class="Reads-reading-hero">
                        <div class="Reads-cover-wrap">
                            <a href="libro.php?id=<?= urlencode($miLibro['libro_id'] ?? $miLibro['id']) ?>" style="display:block; width:100%; height:100%;">
                                <?php if ($tienePortada): ?>
                                    <img src="<?= e($portadaSrc) ?>" 
                                         alt="<?= e($miLibro['titulo']) ?>"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>

                                <div class="cubierta-generada-card" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                                    <span style="font-size: 10px; font-weight: bold; line-height: 1.2; max-height: 50px; overflow: hidden;"><?= e($miLibro['titulo']) ?></span>
                                    <span style="font-size: 16px; margin-top: 6px;">📖</span>
                                </div>
                            </a>
                        </div>
                        <div class="Reads-info">
                            <span class="Reads-tag">En curso</span>
                            <h3 class="Reads-title"><?= e($miLibro['titulo']) ?></h3>
                            <p class="Reads-author"><?= e($miLibro['autores'] ?? 'Autor desconocido') ?></p>

                            <div style="margin-bottom: 12px;">
                                <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px;">
                                    <span>Progreso</span>
                                    <span><?= $miPct ?>%</span>
                                </div>
                                <div class="barra-progreso-bg" style="height: 8px; margin: 0;">
                                    <div class="barra-progreso-fill" style="width: <?= $miPct ?>%;"></div>
                                </div>
                            </div>

                            <a href="libro.php?id=<?= urlencode($miLibro['libro_id'] ?? $miLibro['id']) ?>" class="btn-Reads-continue">Continuar Lectura →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="Reads-reading-hero">
                <div class="Reads-info" style="text-align: center; padding: 10px 0;">
                    <span class="Reads-tag">💡 Sugerencia Reads</span>
                    <h3 class="Reads-title" style="margin-top: 5px;">No tienes ningún libro en curso</h3>
                    <p class="Reads-author">Busca en el catálogo o revisa tus pendientes para empezar a leer hoy.</p>
                    <a href="biblioteca.php" class="btn-Reads-continue">Explorar mi biblioteca</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Buscador -->
    <div class="panel buscador-wrapper">
        <form action="buscar.php" method="GET" class="buscador-form">
            <input 
                type="text" 
                name="q" 
                placeholder="Escribe título, autor o tema..."
                class="input-text-hero"
                autocomplete="off"
            >
            <button type="submit" class="btn-buscar-hero">🔍 Buscar</button>
        </form>

        <div id="sugerencias"></div>
    </div>

    <!-- Vista previa de la biblioteca -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2>📚 Leídos en <?= $year ?></h2>
            <a href="biblioteca.php" style="font-size: 0.88rem; font-weight: bold; text-decoration: none;">Ver todo →</a>
        </div>

        <?php if (empty($librosPreview)): ?>
            <p style="color: #777; margin-top: 10px;">Aún no has marcado libros como leídos este año.</p>
        <?php else: ?>
            <div class="shelf-grid">
                <?php foreach ($librosPreview as $libro): 
                    $descParam = !empty($libro['descripcion']) ? '&desc=' . urlencode($libro['descripcion']) : '';
                    
                    // Validación automática de portada en local/remota
                    $portadaSrc = obtenerPortadaValida($libro['portada'] ?? '', (int)$libro['id']);
                    $tienePortada = !empty($portadaSrc);
                    $portadaParam = $tienePortada ? '&portada=' . urlencode($portadaSrc) : '';
                ?>
                    <a href="libro.php?id=<?= urlencode($libro['id_externo']) ?><?= $descParam ?><?= $portadaParam ?>" class="shelf-item" title="<?= e($libro['titulo']) ?>">
                        <?php if ($tienePortada): ?>
                            <img src="<?= e($portadaSrc) ?>" 
                                 alt="<?= e($libro['titulo']) ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php endif; ?>

                        <div class="cubierta-generada-card" style="<?= $tienePortada ? 'display:none;' : 'display:flex;' ?>">
                            <span style="font-size: 9px; font-weight: bold; line-height: 1.1; max-height: 40px; overflow: hidden;"><?= e($libro['titulo']) ?></span>
                            <span style="font-size: 12px; margin-top: 4px;">📖</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- Recomendados para ti -->
<div class="panel" style="margin-top: 25px;">
    <div class="panel-header">
        <h2>✨ Recomendados para ti</h2>
    </div>

    <p id="subtitulo-recomendados" style="font-size: 0.85rem; color: #666; margin: -5px 0 10px 0;">
        Basado en tus últimas lecturas
    </p>

    <div id="carrusel-recomendados" class="horizontal-scroll">
        <span style="color: #888; font-size: 0.85rem;">Cargando sugerencias...</span>
    </div>
</div>

    <!-- La comunidad está leyendo -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2>👥 La comunidad está leyendo</h2>
        </div>
        <p style="color: #777; margin-top: 10px; font-size: 0.9rem;">
            Próximamente podrás ver en tiempo real las lecturas de otros usuarios.
        </p>
    </div>

    <!-- Resumen de lectura -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="color: var(--primary-color, inherit); margin: 0;">📈 Resumen de lectura <?= $year ?></h2>
            <span style="font-size: 0.8rem; padding: 4px 10px; background: rgba(0,120,255,0.08); border-radius: 20px; <?= $claseEstado ?>">
                <?= $estadoReto ?>
            </span>
        </div>

        <!-- Rejilla de 4 tarjetas estadísticas -->
        <div class="stats-grid-index" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-top: 15px;">
            
            <div class="stat-card-index">
                <div class="stat-icon-index">🏆</div>
                <div class="stat-info-index">
                    <h3><?= number_format($librosLeidosNum) ?></h3>
                    <p>Libros en <?= $year ?></p>
                </div>
            </div>

            <div class="stat-card-index">
                <div class="stat-icon-index">📖</div>
                <div class="stat-info-index">
                    <h3><?= number_format($paginasLeidasNum) ?></h3>
                    <p>Páginas leídas</p>
                </div>
            </div>

            <div class="stat-card-index">
                <div class="stat-icon-index">⚡</div>
                <div class="stat-info-index">
                    <h3><?= $paginasPorDia ?></h3>
                    <p>Págs / día</p>
                </div>
            </div>

            <div class="stat-card-index">
                <div class="stat-icon-index">⏱️</div>
                <div class="stat-info-index">
                    <h3>~<?= $horasLeidasEstimadas ?>h</h3>
                    <p>Tiempo leído</p>
                </div>
            </div>

        </div>

        <!-- Barra del Reto de Lectura -->
        <div class="panel" style="margin-top: 15px; padding: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span>🎯 <strong>Reto <?= date('Y') ?>:</strong> <?= $librosLeidos ?> de <?= $objetivoAnual ?> libros</span>
                <strong><?= $porcentajeReto ?>%</strong>
            </div>

            <div class="barra-progreso-bg" style="height: 10px; background: #e2e8f0; border-radius: 10px; overflow: hidden;">
                <div class="barra-progreso-fill" style="width: <?= $porcentajeReto ?>%; height: 100%; background: var(--color-primario, #2d5a27);"></div>
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: #777; margin-top: 6px;">
                <span>Media: <?= $promedioPaginasPorLibro ?> págs/libro</span>
                <span>Proyección a fin de año: ~<?= $proyeccionLibros ?> libros</span>
            </div>
        </div>
    </div>

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

        <a href="index.php" class="nav-card-float active">
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
        
        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>   
        </a>
        
        <a href="buscar.php" class="nav-card-float">
            <span class="nav-icon">🔍</span>
            <span>Buscar</span>
        </a>

          <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>
        

<script>
const input = document.querySelector('input[name="q"]');
const sugerencias = document.getElementById('sugerencias');

// Utilidad para escapar caracteres HTML y prevenir XSS
function escapeHtml(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function generarPortadaSVG(titulo) {
    const t = encodeURIComponent((titulo || 'Libro').substring(0, 30));
    return `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='160' viewBox='0 0 120 160'><defs><linearGradient id='g' x1='0%' y1='0%' x2='100%' y2='100%'><stop offset='0%' style='stop-color:%231e293b;stop-opacity:1'/><stop offset='100%' style='stop-color:%230f172a;stop-opacity:1'/></linearGradient></defs><rect width='100%' height='100%' fill='url(%23g)' rx='4'/><rect x='3' y='0' width='3' height='100%' fill='%23ffffff' opacity='0.25'/><text x='50%' y='45%' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='10' font-weight='bold' fill='%23ffffff'>${t}</text><text x='50%' y='75%' dominant-baseline='middle' text-anchor='middle' font-size='14' fill='%23ffffff'>📖</text></svg>`;
}

// Fallback de portada, al fallar la imagen se sustituye por la generada
function fallbackPortada(img) {
    img.onerror = null;
    img.src = generarPortadaSVG(img.alt);
}

//Búsqueda con sugerencias en tiempo real
if (input && sugerencias) {
    input.addEventListener('input', async () => {
        const texto = input.value.trim();
        if (texto.length < 2) {
            sugerencias.style.display = "none";
            return;
        }

        try {
            const res = await fetch(`https://openlibrary.org/search.json?q=${encodeURIComponent(texto)}&limit=5`);
            const data = await res.json();

            sugerencias.innerHTML = "";
            sugerencias.style.display = "block";

            if (!data.docs || data.docs.length === 0) {
                sugerencias.innerHTML = "<div style='padding:12px; color: #777;'>Sin resultados</div>";
                return;
            }

            data.docs.forEach(item => {
                const titulo = item.title || "Sin título";
                const autor = item.author_name ? item.author_name[0] : "Autor desconocido";

                const div = document.createElement("div");
                div.innerHTML = `<strong>${escapeHtml(titulo)}</strong><br><small style="color: #666;">${escapeHtml(autor)}</small>`;
                div.onclick = () => {
                    input.value = titulo;
                    sugerencias.style.display = "none";
                    input.form.submit();
                };
                sugerencias.appendChild(div);
            });
        } catch (err) {
            sugerencias.style.display = "none";
        }
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !sugerencias.contains(e.target)) {
            sugerencias.style.display = "none";
        }
    });
}

// Cargar novedades recientes
async function cargarNovedadesRecientes(contenedorId) {
    const contenedor = document.getElementById(contenedorId);
    if (!contenedor) return;

    const tituloPanel = contenedor.closest('.panel')?.querySelector('h2');

    try {
        const res = await fetch('api_novedades.php');
        if (!res.ok) throw new Error("HTTP Error " + res.status);

        const data = await res.json();

        if (tituloPanel && data.tituloSeccion) {
            tituloPanel.textContent = `🔥 ${data.tituloSeccion}`;
        }

        if (!data.libros || data.libros.length === 0) {
            contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem; padding: 10px;'>No hay novedades disponibles en este momento.</p>";
            return;
        }

        contenedor.innerHTML = "";

        data.libros.forEach(item => {
            const titulo = item.titulo || 'Sin título';
            const autor = item.autor || 'Autor desconocido';
            const portadaFinal = item.portada ? item.portada : generarPortadaSVG(titulo);

            const html = `
                <a href="libro.php?id=${encodeURIComponent(item.id)}" class="book-card-scroll" title="${escapeHtml(titulo)}">
                    <div style="width:110px; height:155px; position:relative; overflow:hidden; border-radius:8px; background:#1e293b;">
                        <img src="${escapeHtml(portadaFinal)}" alt="${escapeHtml(titulo)}" style="width:100%; height:100%; object-fit:cover;" onerror="fallbackPortada(this)">
                    </div>
                    <span class="title" style="display:block; margin-top:8px;">${escapeHtml(titulo)}</span>
                    <span class="subtitle" style="display:block; color:#888;">${escapeHtml(autor)}</span>
                </a>
            `;
            contenedor.insertAdjacentHTML('beforeend', html);
        });

    } catch (error) {
        console.error("Error al cargar novedades:", error);
        contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem; padding: 10px;'>No se pudieron cargar las novedades en tiempo real.</p>";
    }
}

// Cargar recomendaciones basadas en lecturas recientes
async function cargarRecomendaciones() {
    const contenedorRec = document.getElementById('carrusel-recomendados');
    const subtituloRec = document.getElementById('subtitulo-recomendados');
    if (!contenedorRec) return;

    try {
        const res = await fetch('api_recomendaciones.php');
        if (!res.ok) throw new Error("HTTP Error " + res.status);

        const data = await res.json();
        const base = data.basadoEn || [];
        const libros = data.libros || [];

        // Subtítulo según los últimos libros leídos
       if (subtituloRec) {
    if (base.length === 0) {
        subtituloRec.textContent = 'Marca libros como leídos para recibir recomendaciones';
    } else {
        const nombres = base.slice(0, 2).map(t => `<strong>${escapeHtml(t)}</strong>`);
        const resto = base.length - 2;
        subtituloRec.innerHTML = 'Porque leíste ' + nombres.join(' y ') +
            (resto > 0 ? ` y ${resto} más` : '');
    }
}

        if (libros.length === 0) {
            contenedorRec.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>No hay sugerencias disponibles en este momento.</p>";
            return;
        }

        contenedorRec.innerHTML = '';

        libros.forEach(libro => {
            const titulo = libro.titulo || 'Sin título';
            const autor = libro.autor || 'Autor desconocido';
            const portadaFinal = libro.portada ? libro.portada : generarPortadaSVG(titulo);

            const html = `
                <a href="libro.php?id=${encodeURIComponent(libro.id)}&portada=${encodeURIComponent(libro.portada || '')}" class="book-card-scroll" title="${escapeHtml(titulo)}">
                    <div style="width:110px; height:155px; position:relative; overflow:hidden; border-radius:8px; background:#1e293b;">
                        <img src="${escapeHtml(portadaFinal)}" alt="${escapeHtml(titulo)}" style="width:100%; height:100%; object-fit:cover;" onerror="this.closest('a').remove()">
                    </div>
                    <span class="title" style="display:block; margin-top:6px; font-size:0.85rem; line-height:1.2;">${escapeHtml(titulo)}</span>
                    <span class="subtitle" style="display:block; color:#888; font-size:0.75rem;">${escapeHtml(autor)}</span>
                </a>
            `;
            contenedorRec.insertAdjacentHTML('beforeend', html);
        });

    } catch (err) {
        console.error("Error al cargar sugerencias:", err);
        contenedorRec.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>No se pudieron cargar las sugerencias.</p>";
    }
}

// Inicializar funciones al cargar la página
document.addEventListener('DOMContentLoaded', () => {
    cargarNovedadesRecientes('carrusel-novedades');
    cargarRecomendaciones();
});
</script>
</body>
</html>
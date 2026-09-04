<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/UserService.php";

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

// 1. Obtener TODOS los libros que el propio usuario está leyendo actualmente (Swipe / Carrusel personal)
$sqlMisLecturas = "SELECT l.* 
                   FROM listas_lectura l 
                   WHERE l.usuario_id = ? AND l.estado = 'leyendo' 
                   ORDER BY l.id DESC";
$stmtMisLecturas = $db->pdo->prepare($sqlMisLecturas);
$stmtMisLecturas->execute([$usuario["id"]]);
$misLecturasActuales = $stmtMisLecturas->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas rápidas: Contamos libros terminados vs totales y páginas
$sql = "SELECT 
            COUNT(CASE WHEN estado = 'leido' THEN 1 END) AS libros_leidos,
            COUNT(*) AS total_libros,
            COALESCE(SUM(paginas_leidas), 0) AS paginas_leidas,
            COALESCE(SUM(paginas_totales), 0) AS paginas_totales
        FROM listas_lectura
        WHERE usuario_id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"]]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Cálculos de métricas gamificadas
$librosLeidosNum   = (int)($stats["libros_leidos"] ?? 0);
$paginasLeidasNum  = (int)($stats["paginas_leidas"] ?? 0);
$paginasTotalesNum = (int)($stats["paginas_totales"] ?? 0);

// Páginas por día este año
$paginasPorDia = ($diaDelAno > 0) ? round($paginasLeidasNum / $diaDelAno, 1) : 0;

// Objetivo de libros para el año
$metaLibrosAnual = 20; 
$porcentajeMeta = ($metaLibrosAnual > 0) ? min(100, round(($librosLeidosNum / $metaLibrosAnual) * 100)) : 0;

// Saludo dinámico
$hora = date("H");
if ($hora < 12) $saludo = "Buenos días";
elseif ($hora < 19) $saludo = "Buenas tardes";
else $saludo = "Buenas noches";

// Libros leídos este año
$sqlBiblioteca = "SELECT libro_id AS id_externo, titulo, autores, portada, descripcion 
                  FROM listas_lectura
                  WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
                  ORDER BY fecha_fin DESC LIMIT 6";
$stmt = $db->pdo->prepare($sqlBiblioteca);
$stmt->execute([$usuario["id"], $year]);
$librosPreview = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtenemos el último autor leído
$ultimoAutor = !empty($librosPreview[0]['autores']) ? $librosPreview[0]['autores'] : 'Elísabet Benavent';

// Historial de búsqueda
$sql = "SELECT termino, fecha 
        FROM historial_busqueda 
        WHERE usuario_id = ?
        ORDER BY fecha DESC
        LIMIT 10";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$usuario["id"]]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrado de búsquedas únicas
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <title>Inicio - Dashboard Reads</title>

    <style>
        body {
            padding-bottom: 90px;
        }

        .welcome-header { margin-bottom: 25px; }
        .welcome-header h1 { font-size: 1.8rem; margin-bottom: 5px; }
        
        .buscador-wrapper { position: relative; margin-bottom: 25px; }
        .buscador-form { display: flex; gap: 10px; }
        
        .input-text-hero {
            flex: 1;
            padding: 12px 18px;
            font-size: 1.05rem;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 10px;
            outline: none;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }

        .btn-buscar-hero {
            padding: 12px 24px;
            font-size: 1rem;
            font-weight: bold;
            background-color: var(--primary-color, var(--color-principal, #2c3e50));
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .btn-buscar-hero:hover { opacity: 0.9; }

        #sugerencias {
            display: none;
            background: #ffffff;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            position: absolute;
            top: 105%;
            left: 0;
            width: 100%;
            z-index: 999;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        #sugerencias div {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }

        #sugerencias div:last-child { border-bottom: none; }
        #sugerencias div:hover { background: #f8fafc; }

        /* MENÚ FLOTANTE READS */
        .floating-nav-container {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: calc(100% - 40px);
            max-width: 550px;
        }

        .quick-nav-floating {
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .nav-card-float {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            text-decoration: none;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.85rem;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .nav-card-float .nav-icon {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }

        .nav-card-float:hover {
            background: rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
            text-decoration: none;
        }

        /* SECCIÓN QUÉ ESTÁS LEYENDO AHORA (SWIPE CONTAINER) */
        .my-reading-swipe-container {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding-bottom: 10px;
            scrollbar-width: thin;
        }

        .my-reading-swipe-container::-webkit-scrollbar { height: 6px; }
        .my-reading-swipe-container::-webkit-scrollbar-thumb {
            background-color: rgba(0,0,0,0.15);
            border-radius: 10px;
        }

        .Reads-reading-hero {
            flex: 0 0 100%;
            scroll-snap-align: start;
            box-sizing: border-box;
            background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(245,247,250,0.85));
            border-radius: 16px;
            padding: 20px;
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            gap: 20px;
            align-items: center;
        }

        @media (min-width: 768px) {
            .Reads-reading-hero {
                flex: 0 0 calc(100% - 10px);
            }
        }

        .Reads-cover-wrap img {
            width: 100px;
            height: 145px;
            object-fit: cover;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }

        .Reads-info { flex: 1; }

        .Reads-tag {
            display: inline-block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            color: var(--primary-color, #2c3e50);
            background: rgba(44, 62, 80, 0.08);
            padding: 3px 8px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .Reads-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        .Reads-author {
            font-size: 0.88rem;
            color: #666;
            margin: 0 0 12px 0;
        }

        .btn-Reads-continue {
            display: inline-block;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            background-color: var(--primary-color, var(--color-principal, #2c3e50));
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            transition: opacity 0.2s;
        }

        .btn-Reads-continue:hover { opacity: 0.9; text-decoration: none; }

        @media (max-width: 550px) {
            .Reads-reading-hero {
                flex-direction: column;
                text-align: center;
            }
            .Reads-cover-wrap img {
                width: 90px;
                height: 130px;
            }
        }

        .shelf-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .shelf-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 6px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
            transition: transform 0.2s;
        }

        .shelf-item img:hover { transform: scale(1.05); }

        .horizontal-scroll {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            padding: 10px 0 15px 0;
            scrollbar-width: thin;
        }

        .horizontal-scroll::-webkit-scrollbar { height: 6px; }
        .horizontal-scroll::-webkit-scrollbar-thumb {
            background-color: rgba(0,0,0,0.15);
            border-radius: 10px;
        }

        .book-card-scroll {
            flex: 0 0 110px;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
        }

        .book-card-scroll img {
            width: 100%;
            height: 155px;
            object-fit: cover;
            border-radius: 8px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.12);
            transition: transform 0.2s;
        }

        .book-card-scroll:hover img { transform: translateY(-4px); }

        .book-card-scroll .title {
            font-size: 0.8rem;
            font-weight: bold;
            margin-top: 8px;
            line-height: 1.2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-card-scroll .subtitle {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge-date {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: bold;
            background-color: var(--primary-color, var(--color-principal, #2c3e50));
            color: #ffffff;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            width: fit-content;
        }

        .stats-grid-index {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
            margin-top: 1rem;
            margin-bottom: 1.25rem;
        }

        .stat-card-index {
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            transition: transform 0.2s ease;
        }

        .stat-card-index:hover { transform: translateY(-2px); }

        .stat-icon-index {
            font-size: 1.6rem;
            background: var(--bg-card, rgba(0, 0, 0, 0.04));
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-info-index h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color, inherit);
            line-height: 1.1;
        }

        .stat-info-index p {
            margin: 2px 0 0 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            opacity: 0.7;
            font-weight: 600;
        }

        .progreso-global-box {
            margin-top: 1rem;
            padding: 1.1rem;
            background: rgba(0, 0, 0, 0.02);
            border-radius: 12px;
            border: 1px solid var(--border-color, rgba(0, 0, 0, 0.05));
        }

        .barra-progreso-bg {
            height: 10px;
            background: rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 0.6rem;
        }

        .barra-progreso-fill {
            height: 100%;
            background: var(--primary-color, var(--color-principal, #2c3e50));
            border-radius: 10px;
            transition: width 0.4s ease;
        }

        .search-tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .search-tag-item {
            background: var(--bg-card, #ffffff);
            border: 1px solid var(--border-color, rgba(0, 0, 0, 0.12));
            color: var(--text-color, inherit);
            padding: 0.4rem 0.85rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .search-tag-item:hover {
            background: var(--primary-color, var(--color-principal, #2c3e50));
            color: #ffffff;
            border-color: transparent;
        }

        .site-footer {
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-logout-footer {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #c53030;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            padding: 6px 14px;
            border: 1px solid rgba(197, 48, 48, 0.2);
            border-radius: 8px;
            transition: background 0.2s;
        }

        .btn-logout-footer:hover {
            background: rgba(197, 48, 48, 0.08);
            text-decoration: none;
        }
    </style>
</head>

<body>

<div class="container">

    <!-- BIENVENIDA -->
    <div class="welcome-header">
        <h1><?= $saludo ?>, <?= htmlspecialchars($usuario["nombre"]) ?> 👋</h1>
        <p style="color: #666; margin: 0;">¿Qué libro tienes en mente para hoy?</p>
    </div>

    <!-- 📖 QUÉ ESTÁS LEYENDO AHORA (SWIPE / CARROUSEL PERSONAL) -->
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
                ?>
                    <div class="Reads-reading-hero">
                        <div class="Reads-cover-wrap">
                            <a href="libro.php?id=<?= urlencode($miLibro['libro_id']) ?>">
                                <img src="<?= htmlspecialchars($miLibro['portada'] ?: '/Reads/img/default_cover.jpg') ?>" 
                                     alt="<?= htmlspecialchars($miLibro['titulo']) ?>">
                            </a>
                        </div>
                        <div class="Reads-info">
                            <span class="Reads-tag">En curso</span>
                            <h3 class="Reads-title"><?= htmlspecialchars($miLibro['titulo']) ?></h3>
                            <p class="Reads-author"><?= htmlspecialchars($miLibro['autores'] ?? 'Autor desconocido') ?></p>

                            <div style="margin-bottom: 12px;">
                                <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px;">
                                    <span>Progreso</span>
                                    <span><?= $miPct ?>%</span>
                                </div>
                                <div class="barra-progreso-bg" style="height: 8px; margin: 0;">
                                    <div class="barra-progreso-fill" style="width: <?= $miPct ?>%;"></div>
                                </div>
                            </div>

                            <a href="libro.php?id=<?= urlencode($miLibro['libro_id']) ?>" class="btn-Reads-continue">Continuar Lectura →</a>
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

    <!-- BUSCADOR -->
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

    <!-- VISTA PREVIA BIBLIOTECA -->
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
                    $portadaParam = !empty($libro['portada']) ? '&portada=' . urlencode($libro['portada']) : '';
                ?>
                    <a href="libro.php?id=<?= urlencode($libro['id_externo']) ?><?= $descParam ?><?= $portadaParam ?>" class="shelf-item" title="<?= htmlspecialchars($libro['titulo']) ?>">
                        <img src="<?= htmlspecialchars($libro['portada'] ?: '/Reads/img/default_cover.jpg') ?>" alt="<?= htmlspecialchars($libro['titulo']) ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ✨ RECOMENDACIONES SEGÚN LECTURA -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2>✨ Recomendados para ti</h2>
        </div>
        <p style="font-size: 0.85rem; color: #666; margin: -5px 0 10px 0;">
            Porque leíste a <strong><?= htmlspecialchars($ultimoAutor) ?></strong>
        </p>

        <div id="carrusel-recomendados" class="horizontal-scroll">
            <span style="color: #888; font-size: 0.85rem;">Cargando sugerencias...</span>
        </div>
    </div>

    <!-- 👥 LA COMUNIDAD ESTÁ LEYENDO (PLANTILLA PARA CUANDO LA TRABAJEMOS) -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2>👥 La comunidad está leyendo</h2>
        </div>
        <p style="color: #777; margin-top: 10px; font-size: 0.9rem;">
            Próximamente podrás ver en tiempo real las lecturas de otros usuarios.
        </p>
    </div>

    <!-- 🔥 NOVEDADES EDITORIALES -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2>🔥 Novedades editoriales</h2>
        </div>

        <div id="carrusel-novedades" class="horizontal-scroll">
            <span style="color: #888; font-size: 0.85rem;">Cargando novedades...</span>
        </div>
    </div>

    <!-- 📈 RESUMEN DE LECTURA GAMIFICADO -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2 style="color: var(--primary-color, inherit);">📈 Resumen de lectura</h2>
        </div>

        <div class="stats-grid-index">
            <div class="stat-card-index">
                <div class="stat-icon-index">🏆</div>
                <div class="stat-info-index">
                    <h3><?= number_format($librosLeidosNum) ?></h3>
                    <p>Libros leídos</p>
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
                    <p>Págs / día este año</p>
                </div>
            </div>
        </div>

        <div class="progreso-global-box">
            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600;">
                <span>🎯 Reto de lectura <?= $year ?> (<?= $librosLeidosNum ?> de <?= $metaLibrosAnual ?> libros)</span>
                <span><?= $porcentajeMeta ?>%</span>
            </div>
            <div class="barra-progreso-bg">
                <div class="barra-progreso-fill" style="width: <?= $porcentajeMeta ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- 🔎 BÚSQUEDAS RECIENTES -->
    <div class="panel" style="margin-top: 25px;">
        <div class="panel-header">
            <h2 style="color: var(--primary-color, inherit);">🔎 Búsquedas recientes</h2>
        </div>

        <div class="search-tags-container">
            <?php if (!empty($busquedasUnicas)): ?>
                <?php foreach ($busquedasUnicas as $busqueda): ?>
                    <a href="buscar.php?q=<?= urlencode($busqueda) ?>" class="search-tag-item">
                        <?= htmlspecialchars($busqueda) ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="font-size: 0.85rem; opacity: 0.6;">Sin búsquedas recientes</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- FOOTER Y BOTÓN DE SALIDA -->
    <footer class="site-footer">
        <span style="font-size: 0.85rem; color: #777;">Reads &copy; <?= date("Y") ?></span>
        <a href="logout.php" class="btn-logout-footer">
            <span>🚪</span> Cerrar sesión
        </a>
    </footer>

</div>

<!-- NAVEGACIÓN FLOTANTE READS -->
<div class="floating-nav-container">
    <nav class="quick-nav-floating">
        <a href="perfil.php" class="nav-card-float">
            <span class="nav-icon">👤</span>
            <span>Mi perfil</span>
        </a>
        <a href="biblioteca.php" class="nav-card-float">
            <span class="nav-icon">📚</span>
            <span>Estanteria</span>
        </a>
        <a href="estadisticas.php" class="nav-card-float">
            <span class="nav-icon">📊</span>
            <span>Estadísticas</span>
        </a>
        <a href="calendario.php" class="nav-card-float">
            <span class="nav-icon">📅</span>
            <span>Calendario</span>
        </a>
        <a href="ajustes.php" class="nav-card-float">
            <span class="nav-icon">⚙️</span>
            <span>Ajustes</span>
        </a>
    </nav>
</div>

<!-- SCRIPTS -->
<script>
const input = document.querySelector('input[name="q"]');
const sugerencias = document.getElementById('sugerencias');

const DEFAULT_COVER = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='160' viewBox='0 0 120 160'><rect width='100%' height='100%' fill='%23e2e8f0'/><text x='50%' y='50%' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='12' fill='%2394a3b8'>Sin portada</text></svg>";

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
                div.innerHTML = `<strong>${titulo}</strong><br><small style="color: #666;">${autor}</small>`;
                div.onclick = () => {
                    input.value = titulo;
                    sugerencias.style.display = "none";
                    input.form.submit();
                };
                sugerencias.appendChild(div);
            });
        } catch (e) {
            sugerencias.style.display = "none";
        }
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !sugerencias.contains(e.target)) {
            sugerencias.style.display = "none";
        }
    });
}

// Cargar Carruseles
async function cargarCarrusel(url, contenedorId, esNovedad = false) {
    const contenedor = document.getElementById(contenedorId);
    if (!contenedor) return;

    try {
        const res = await fetch(url);
        const data = await res.json();
        const docs = data.docs || data.works || [];

        if (docs.length > 0) {
            contenedor.innerHTML = "";
            const currentYear = new Date().getFullYear();

            docs.slice(0, 10).forEach(item => {
                const titulo = item.title || "Sin título";
                
                let autor = 'Autor desconocido';
                if (item.author_name) autor = item.author_name[0];
                else if (item.authors && item.authors.length > 0) autor = item.authors[0].name;

                let fecha = item.first_publish_year || item.first_publish_date || currentYear;
                if (typeof fecha === 'string') fecha = fecha.match(/\d{4}/)?.[0] || currentYear;

                const idLibro = item.key ? item.key.replace('/works/', '') : '';
                const coverId = item.cover_i || item.cover_id;
                
                let portada = DEFAULT_COVER;
                if (coverId) {
                    portada = `https://covers.openlibrary.org/b/id/${coverId}-L.jpg`;
                }

                const subetiqueta = esNovedad 
                    ? `<span class="badge-date">${fecha}</span>` 
                    : `<span class="subtitle">${autor}</span>`;

                const linkHref = `libro.php?id=${encodeURIComponent(idLibro)}&portada=${encodeURIComponent(portada)}`;

                const html = `
                    <a href="${linkHref}" class="book-card-scroll" title="${titulo}">
                        <img src="${portada}" alt="${titulo}" onerror="this.onerror=null;this.src='${DEFAULT_COVER}';">
                        <span class="title">${titulo}</span>
                        ${subetiqueta}
                    </a>
                `;
                contenedor.insertAdjacentHTML('beforeend', html);
            });
        } else {
            contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>No hay libros disponibles en este momento.</p>";
        }
    } catch (error) {
        contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>Error al cargar los datos.</p>";
    }
}

document.addEventListener("DOMContentLoaded", () => {
    let autorRaw = <?= json_encode($ultimoAutor) ?>;
    let autorLimpio = autorRaw
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/["']/g, "")
        .trim();

    // Recomendados
    const urlRecomendados = `https://openlibrary.org/search.json?author=${encodeURIComponent(autorLimpio)}&limit=10`;
    cargarCarrusel(urlRecomendados, 'carrusel-recomendados', false);

    // Novedades
    cargarNovedadesRecientes('carrusel-novedades');
});

async function cargarNovedadesRecientes(contenedorId) {
    const contenedor = document.getElementById(contenedorId);
    if (!contenedor) return;

    const url = `https://openlibrary.org/search.json?q=language:spa&sort=new&limit=30`;

    try {
        const res = await fetch(url);
        const data = await res.json();
        const docs = data.docs || [];

        if (docs.length > 0) {
            contenedor.innerHTML = "";
            const currentYear = new Date().getFullYear();

            const novedadesValidas = docs.filter(item => {
                const year = item.first_publish_year || 0;
                return item.cover_i && year <= currentYear && year >= (currentYear - 1);
            });

            const seleccion = novedadesValidas.length >= 4 
                ? novedadesValidas 
                : docs.filter(item => item.cover_i);

            seleccion.slice(0, 8).forEach(item => {
                const titulo = item.title || "Sin título";
                let fecha = item.first_publish_year || currentYear;

                const idLibro = item.key ? item.key.replace('/works/', '') : '';
                const portada = `https://covers.openlibrary.org/b/id/${item.cover_i}-L.jpg`;

                const linkHref = `libro.php?id=${encodeURIComponent(idLibro)}&portada=${encodeURIComponent(portada)}`;

                const html = `
                    <a href="${linkHref}" class="book-card-scroll" title="${titulo}">
                        <img src="${portada}" alt="${titulo}" onerror="this.onerror=null;this.src='${DEFAULT_COVER}';">
                        <span class="title">${titulo}</span>
                        <span class="badge-date">${fecha}</span>
                    </a>
                `;
                contenedor.insertAdjacentHTML('beforeend', html);
            });
        } else {
            contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>No hay novedades disponibles en este momento.</p>";
        }
    } catch (error) {
        contenedor.innerHTML = "<p style='color:#888; font-size: 0.85rem;'>Error al cargar las novedades.</p>";
    }
}
</script>

</body>
</html>
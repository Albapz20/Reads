<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/BookService.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

// 1. Obtener el tema visual directamente desde la BD (igual que en perfil.php)
$userService = new UserService();
$datosUsuario = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema = $datosUsuario["tema_visual"] ?? "pastel";

$service = new BookService();
$db = new Database();

$resultados = [];
$termino = "";

if (isset($_GET['q'])) {
    $termino = trim($_GET['q']);
    
    if (!empty($termino)) {
        $resultados = $service->buscarLibros($termino);

        // Guardar en el historial de búsqueda
        $sql = "INSERT INTO historial_busqueda (usuario_id, termino, fecha)
                VALUES (?, ?, NOW())";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute([$usuario["id"], $termino]);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- ENLACE AL TEMA VISUAL DEL USUARIO -->
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <title>Buscar libros</title>
    <style>
        .buscador-box {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            margin-bottom: 10px;
        }

        .buscador-input {
            flex: 1;
            padding: 10px 14px;
            font-size: 1rem;
            border: 1px solid #ccc;
            border-radius: 6px;
            outline: none;
        }

        .buscador-input:focus {
            border-color: #4a5568;
        }

        .buscador-btn {
            padding: 10px 20px;
            font-size: 1rem;
            background-color: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        .buscador-btn:hover {
            opacity: 0.9;
        }
    </style>
</head>

<body>

<div class="container">

    <!-- PANEL DEL BUSCADOR -->
    <div class="panel">
        <div class="panel-header">
            <h1>Buscar libros</h1>
        </div>

        <!-- FORMULARIO CON LA BARRA DE BÚSQUEDA -->
        <form method="GET" action="buscar.php" class="buscador-box">
            <input type="text" 
                   name="q" 
                   class="buscador-input" 
                   placeholder="Escribe un título, autor o palabra clave..." 
                   value="<?= htmlspecialchars($termino) ?>" 
                   required>
            <button type="submit" class="buscador-btn">Buscar</button>
        </form>

        <?php if (!empty($termino)): ?>
            <p style="margin-top: 10px;"><strong>Término buscado:</strong> <?= htmlspecialchars($termino) ?></p>
        <?php endif; ?>

        <div class="acciones-perfil" style="margin-top: 15px;">
            <a href="index.php">🏠 Volver al inicio</a>
        </div>
    </div>

    <!-- RESULTADOS DE LA BÚSQUEDA -->
    <?php if (isset($_GET['q'])): ?>

        <?php if (empty($resultados)): ?>
            <div class="panel">
                <div class="panel-header">
                    <h2>No se encontraron resultados</h2>
                </div>
                <p>Prueba con otro título, autor o palabra clave.</p>
            </div>

        <?php else: ?>

            <div class="panel">
                <div class="panel-header">
                    <h2>Libros encontrados (<?= count($resultados) ?>)</h2>
                </div>

                <div class="resultados-grid">
                    <?php foreach ($resultados as $libro): ?>
                        <div class="review-card">

                            <img src="<?= htmlspecialchars($libro["portada"] ?? '/Reads/img/default_cover.jpg') ?>" 
                                 alt="Portada del libro" 
                                 class="libro-portada">

                            <h3><?= htmlspecialchars($libro["titulo"]) ?></h3>

                            <p><strong>Autor:</strong> <?= htmlspecialchars($libro["autor"] ?? 'Desconocido') ?></p>
                            <p><strong>Año:</strong> <?= htmlspecialchars($libro["anio"] ?? 'N/A') ?></p>

                            <p><?= htmlspecialchars($libro["descripcion"] ?? '') ?></p>

                            <div class="acciones-perfil">
                               <a href="libro.php?id=<?= urlencode($libro["id"]) ?>&desc=<?= urlencode($libro["descripcion"] ?? '') ?>">🔎 Ver más</a>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>
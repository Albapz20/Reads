<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";
require_once "../src/Database.php";

$usuario = Auth::usuario();
if (!$usuario) {
    header("Location: login.php");
    exit;
}

$userService = new UserService();
$db          = new Database();

$datos = $userService->obtenerUsuarioPorId($usuario["id"]);
$tema  = $datos["tema_visual"] ?? "pastel";

$mensajePerfil = "";
$mensajeTema   = "";

// 1. PROCESAR GUARDADO DE DATOS PERSONALES
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_perfil"])) {
    $nombre     = trim($_POST["nombre"]);
    $email      = trim($_POST["email"]);
    $privacidad = $_POST["privacidad"];

    if ($userService->actualizarUsuario($usuario["id"], $nombre, $email, $privacidad, $tema)) {
        $mensajePerfil = "Perfil actualizado correctamente.";

        // Actualizar sesión
        $_SESSION["usuario"]["nombre"]     = $nombre;
        $_SESSION["usuario"]["email"]      = $email;
        $_SESSION["usuario"]["privacidad"] = $privacidad;

        // Recargar datos
        $datos = $userService->obtenerUsuarioPorId($usuario["id"]);
    } else {
        $mensajePerfil = "Error al actualizar el perfil.";
    }
}

// 2. PROCESAR GUARDADO DE TEMA VISUAL
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_tema"])) {
    $tema_visual = $_POST["tema_visual"];

    $sql  = "UPDATE usuarios SET tema_visual = ? WHERE id = ?";
    $stmt = $db->pdo->prepare($sql);

    if ($stmt->execute([$tema_visual, $usuario["id"]])) {
        $_SESSION["usuario"]["tema_visual"] = $tema_visual;
        $tema = $tema_visual;
        $mensajeTema = "Estilo visual actualizado correctamente.";
    } else {
        $mensajeTema = "Error al actualizar el estilo visual.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar perfil</title>
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
</head>

<body>

<div class="container">

    <!-- PANEL 1: DATOS DE PERFIL -->
    <div class="panel">
        <div class="panel-header">
            <h1>Editar perfil</h1>
        </div>

        <?php if ($mensajePerfil): ?>
            <div class="review-card" style="margin-bottom: 15px;">
                <strong><?= htmlspecialchars($mensajePerfil) ?></strong>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label><strong>Nombre:</strong></label><br>
            <input type="text" name="nombre" value="<?= htmlspecialchars($datos["nombre"]) ?>" required><br><br>

            <label><strong>Email:</strong></label><br>
            <input type="email" name="email" value="<?= htmlspecialchars($datos["email"]) ?>" required><br><br>

            <label><strong>Privacidad:</strong></label><br>
            <select name="privacidad">
                <option value="publico" <?= $datos["privacidad"] === "publico" ? "selected" : "" ?>>Público</option>
                <option value="privado" <?= $datos["privacidad"] === "privado" ? "selected" : "" ?>>Privado</option>
            </select><br><br>

            <button type="submit" name="guardar_perfil">Guardar cambios</button>
        </form>
    </div>

    <!-- PANEL 2: ESTILO VISUAL -->
    <div class="panel">
        <div class="panel-header">
            <h2>Estilo visual</h2>
        </div>

        <?php if ($mensajeTema): ?>
            <div class="review-card" style="margin-bottom: 15px;">
                <strong><?= htmlspecialchars($mensajeTema) ?></strong>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label><strong>Elige tu estilo visual:</strong></label><br>
            <select name="tema_visual">
                <option value="pastel" <?= $tema === "pastel" ? "selected" : "" ?>>🌸 Pastel</option>
                <option value="sand" <?= $tema === "sand" ? "selected" : "" ?>>🏖️ Sand</option>
                <option value="dracula" <?= $tema === "dracula" ? "selected" : "" ?>>🐉 Dracula</option>
                <option value="coffee" <?= $tema === "coffee" ? "selected" : "" ?>>☕ Coffee</option>
                <option value="slate" <?= $tema === "slate" ? "selected" : "" ?>>🌑 Dark</option>
                <option value="minimalista" <?= $tema === "minimalista" ? "selected" : "" ?>>📐 Minimalista</option>
                <option value="sunset" <?= $tema === "sunset" ? "selected" : "" ?>>🌅 Sunset</option>
                <option value="azul" <?= $tema === "azul" ? "selected" : "" ?>>💙 Azul Calmado</option>
                <option value="naturalista" <?= $tema === "naturalista" ? "selected" : "" ?>>🌿 Naturalista</option>
            </select>

            <br><br>
            <button type="submit" name="guardar_tema">Guardar tema</button>
        </form>
    </div>

    <div class="acciones-perfil" style="margin-top: 15px;">
        <a href="perfil.php">← Volver al perfil</a>
    </div>

</div>

</body>
</html>
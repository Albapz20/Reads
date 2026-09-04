<?php
require_once "../src/Auth.php";
require_once "../src/UserService.php";

$usuario = Auth::usuario();

if (!$usuario) {
    header("Location: login.php");
    exit;
}

$userService = new UserService();
$datos = $userService->obtenerUsuarioPorId($usuario["id"]);

// Tema visual actualizado
$tema = $datos["tema_visual"] ?? "pastel";

$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $actual = $_POST["actual"];
    $nueva  = $_POST["nueva"];
    $repetir = $_POST["repetir"];

    // Validaciones
    if (empty($actual) || empty($nueva) || empty($repetir)) {
        $mensaje = "Debes completar todos los campos.";
    } elseif ($nueva !== $repetir) {
        $mensaje = "Las contraseñas nuevas no coinciden.";
    } elseif (!$userService->verificarPassword($usuario["id"], $actual)) {
        $mensaje = "La contraseña actual no es correcta.";
    } else {
        // Cambiar contraseña
        if ($userService->cambiarPassword($usuario["id"], $nueva)) {
            $mensaje = "Contraseña actualizada correctamente.";
        } else {
            $mensaje = "Error al actualizar la contraseña.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar contraseña</title>
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
</head>

<body>

<div class="container">

    <div class="panel">
        <div class="panel-header">
            <h1>Cambiar contraseña</h1>
        </div>

        <?php if ($mensaje): ?>
            <p><strong><?= htmlspecialchars($mensaje) ?></strong></p>
        <?php endif; ?>

        <form method="POST">

            <label><strong>Contraseña actual:</strong></label><br>
            <input type="password" name="actual" required><br><br>

            <label><strong>Nueva contraseña:</strong></label><br>
            <input type="password" name="nueva" required><br><br>

            <label><strong>Repetir nueva contraseña:</strong></label><br>
            <input type="password" name="repetir" required><br><br>

            <button type="submit">Guardar cambios</button>

        </form>

        <div class="acciones-perfil" style="margin-top:20px;">
            <a href="perfil.php">← Volver al perfil</a>
        </div>

    </div>

</div>

</body>
</html>

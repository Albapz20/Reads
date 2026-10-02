<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";


$tema = "pastel";

$service = new UserService();
$mensaje = "";
$exito = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = $_POST["email"];
    $nueva = $_POST["nueva"];
    $repetir = $_POST["repetir"];

    // Validaciones
    if (empty($email) || empty($nueva) || empty($repetir)) {
        $mensaje = "Debes completar todos los campos.";
    } elseif ($nueva !== $repetir) {
        $mensaje = "Las contraseñas no coinciden.";
    } else {
        // Verificar si el email existe
        $usuario = $service->obtenerUsuarioPorEmail($email);

        if (!$usuario) {
            $mensaje = "No existe ninguna cuenta con ese email.";
        } else {
            // Cambiar contraseña
            if ($service->cambiarPassword($usuario["id"], $nueva)) {
                $mensaje = "Contraseña actualizada correctamente.";
                $exito = true;
            } else {
                $mensaje = "Error al actualizar la contraseña.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar contraseña</title>
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <link rel="stylesheet" href="/Reads/public/css/styles.css">

</head>

<body class="page-recuperar-contraseña">

<div class="recuperar-box">
    <h1>Recuperar contraseña</h1>

    <?php if ($mensaje): ?>
        <p class="<?= $exito ? 'mensaje-ok' : 'mensaje-error' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </p>
    <?php endif; ?>

    <?php if (!$exito): ?>
    <form method="POST">

        <input type="email" name="email" placeholder="Tu email" required>

        <input type="password" name="nueva" placeholder="Nueva contraseña" required>

        <input type="password" name="repetir" placeholder="Repetir contraseña" required>

        <button type="submit">Actualizar contraseña</button>
    </form>
    <?php endif; ?>

    <p style="margin-top:15px;">
        <a href="login.php">← Volver al login</a>
    </p>
</div>

</body>
</html>

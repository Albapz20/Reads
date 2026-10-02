<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";

// En registro NO hay usuario todavía → tema por defecto
$tema = "pastel";

$service = new UserService();
$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = $_POST["nombre"];
    $email = $_POST["email"];
    $password = $_POST["password"];

    $resultado = $service->registrar($nombre, $email, $password);

    if ($resultado === true) {
        header("Location: login.php");
        exit;
    } else {
        $mensaje = $resultado;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear cuenta</title>
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <link rel="stylesheet" href="/Reads/public/css/styles.css">

</head>

<body class="page-register">

<div class="registro-box">
    <h1>Crear cuenta</h1>

    <?php if ($mensaje): ?>
        <p class="mensaje-error"><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <form method="POST">

        <input type="text" name="nombre" placeholder="Nombre" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Contraseña" required>

        <button type="submit">Registrarse</button>
    </form>

    <p style="margin-top:15px;">
        ¿Ya tienes cuenta?  
        <a href="login.php">Iniciar sesión</a>
    </p>
</div>

</body>
</html>

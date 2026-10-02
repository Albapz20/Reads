<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";

$tema = "pastel";

$service = new UserService();
$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST["email"];
    $password = $_POST["password"];

    $resultado = $service->login($email, $password);

    if (is_array($resultado)) {
        Auth::iniciarSesion($resultado);
        header("Location: index.php");
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
    <title>Iniciar sesión</title>
    <link rel="stylesheet" href="/Reads/temas/<?= $tema ?>.css">
    <link rel="stylesheet" href="/Reads/public/css/styles.css">

</head>

<body class="page-login">

<div class="login-box">
    <h1>Iniciar sesión</h1>

    <?php if ($mensaje): ?>
        <p class="mensaje-error"><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Contraseña" required>
        <button type="submit">Entrar</button>
    </form>

    <p style="margin-top:15px;">
        ¿No tienes cuenta?  
        <a href="registro.php">Crear cuenta</a>
    </p>
</div>

</body>
</html>

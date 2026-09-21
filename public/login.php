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

    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            width: 350px;
            text-align: center;
        }

        .login-box h1 {
            margin-bottom: 20px;
        }

        .login-box input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #ccc;
        }

        .login-box button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: #4a90e2;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        .login-box button:hover {
            background: #357ABD;
        }

        .mensaje-error {
            color: red;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

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

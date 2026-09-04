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

    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .registro-box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            width: 350px;
            text-align: center;
        }

        .registro-box h1 {
            margin-bottom: 20px;
        }

        .registro-box input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #ccc;
        }

        .registro-box button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: #4a90e2;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        .registro-box button:hover {
            background: #357ABD;
        }

        .mensaje-error {
            color: red;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

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

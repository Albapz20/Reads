<?php
require_once "../src/UserService.php";

$tema = "pastel";
$token = (string)($_GET["token"] ?? "");
$ok = (new UserService())->verificarEmail($token);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmar cuenta · Reads</title>
    <link rel="stylesheet" href="/Reads/public/css/styles.css">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css">
    <link rel="stylesheet" href="/Reads/public/css/auth.css">
</head>
<body class="page-register">
<main class="rg-page">
    <div class="rg-card" style="max-width:480px">
        <section class="rg-form-wrap">
            <?php if ($ok): ?>
                <h1>¡Cuenta confirmada! 🎉</h1>
                <p class="rg-sub">Ya puedes iniciar sesión.</p>
                <a class="rg-boton" href="login.php">Ir al login</a>
            <?php else: ?>
                <h1>Enlace no válido</h1>
                <p class="rg-sub">El enlace ha caducado o ya se usó. Regístrate de nuevo o pide otro email.</p>
                <a class="rg-boton" href="registro.php">Volver al registro</a>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
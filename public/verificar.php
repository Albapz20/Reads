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
    <style>
        .vf-card { max-width: 440px; width: 100%; }
        .vf-card .rg-form-wrap { text-align: center; }
        .vf-logo { font-size: 1.4rem; font-weight: 800; letter-spacing: -.03em; margin-bottom: 18px; color: var(--rd-accent, #8f456d); }
        .vf-icono { font-size: 3.4rem; line-height: 1; margin-bottom: 10px; }
        .vf-card h1 { margin: 0 0 8px; }
        .vf-card .rg-sub { margin: 0 0 24px; }
        .vf-card .rg-boton {
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            width: 100%;
            height: auto;
            min-height: 50px;
            padding: 12px 18px;
            line-height: 1.2;
            text-align: center;
            text-decoration: none;
            color: #fff;
        }
        .vf-ayuda { margin: 16px 0 0; font-size: .85rem; opacity: .7; }
    </style>
</head>
<body class="page-register">
<main class="rg-page">
    <div class="rg-card vf-card">
        <section class="rg-form-wrap">
            <div class="vf-logo">Reads</div>

            <?php if ($ok): ?>
            
                <h1>¡Cuenta confirmada!</h1>
                <p class="rg-sub">Tu correo está verificado. Ya puedes iniciar sesión y empezar a organizar tus lecturas.</p>
                <a class="rg-boton" href="login.php">Iniciar sesión</a>
            <?php else: ?>
             
                <h1>Enlace no válido</h1>
                <p class="rg-sub">El enlace ha caducado o ya se usó. Puedes registrarte de nuevo con el mismo correo y te enviaremos otro.</p>
                <a class="rg-boton" href="registro.php">Volver al registro</a>
                <p class="vf-ayuda">¿Ya confirmaste tu cuenta? <a href="login.php">Inicia sesión</a></p>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
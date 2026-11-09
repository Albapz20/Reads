<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";
require_once "../src/helpers.php";

$tema = "pastel";

$service = new UserService();
$mensaje = "";
$email   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email   = trim((string)($_POST["email"] ?? ""));
    $nueva   = (string)($_POST["nueva"] ?? "");
    $repetir = (string)($_POST["repetir"] ?? "");

    if ($email === "" || $nueva === "" || $repetir === "") {
        $mensaje = "Debes completar todos los campos.";
    } elseif (strlen($nueva) < 6) {
        $mensaje = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($nueva !== $repetir) {
        $mensaje = "Las contraseñas no coinciden.";
    } else {
        $usuario = $service->obtenerUsuarioPorEmail($email);

        if (!$usuario) {
            $mensaje = "No existe ninguna cuenta con ese email.";
        } elseif ($service->cambiarPassword($usuario["id"], $nueva)) {
            header("Location: login.php?pass_cambiada=1");
            exit;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña · Reads</title>
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/auth.css?v=<?= @filemtime(__DIR__ . '/css/auth.css') ?>">
</head>

<body class="page-login">

<main class="rg-page">
    <div class="rg-card">

        <aside class="rg-pitch">
            <div class="rg-logo"><span> </span> Reads</div>
            <h2>¿Olvidaste tu contraseña?</h2>
            <p>No pasa nada, elige una nueva y vuelve a tus lecturas.</p>
        </aside>

        <section class="rg-form-wrap">
            <h1>Recuperar contraseña</h1>
            <p class="rg-sub">Introduce tu email y tu nueva contraseña.</p>

            <?php if ($mensaje): ?>
                <p class="rg-error" role="alert">⚠️ <?= htmlspecialchars($mensaje) ?></p>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="rg-campo">
                    <span class="ico">✉️</span>
                    <input class="rg-input" type="email" name="email" placeholder="Correo electrónico"
                           value="<?= htmlspecialchars($email) ?>" required>
                </div>

                <div class="rg-campo">
                    <span class="ico">🔒</span>
                    <input class="rg-input" type="password" name="nueva" placeholder="Nueva contraseña"
                           autocomplete="new-password" required>
                </div>

                <div class="rg-campo">
                    <span class="ico">🔒</span>
                    <input class="rg-input" type="password" name="repetir" placeholder="Repetir contraseña"
                           autocomplete="new-password" required>
                </div>

                <button type="submit" class="rg-boton">Actualizar contraseña</button>
            </form>

            <p class="rg-login"><a href="login.php">← Volver al login</a></p>
        </section>

    </div>
</main>

</body>
</html>
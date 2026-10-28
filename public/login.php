<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";
require_once "../src/helpers.php";   

$tema = "pastel";

if (Auth::usuario()) {
    header("Location: index.php");
    exit;
}

$service = new UserService();
$mensaje = "";
$email   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim((string)($_POST["email"] ?? ""));
    $password = (string)($_POST["password"] ?? "");

    $resultado = $service->login($email, $password);

    if (is_array($resultado)) {
        Auth::iniciarSesion($resultado);
        header("Location: index.php");
        exit;
    }
    $mensaje = (is_string($resultado) && $resultado !== '') ? $resultado : 'Email o contraseña incorrectos.';
}

$recienRegistrado = isset($_GET['registrado']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · Reads</title>
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/auth.css?v=<?= @filemtime(__DIR__ . '/css/auth.css') ?>">
</head>

<body class="page-login">

<main class="rg-page">
    <div class="rg-card">

        <!-- Presentación -->
        <aside class="rg-pitch">
            <div class="rg-logo"><span> </span> Reads</div>
            <h2>Qué bien verte de nuevo</h2>
            <p>Entra y sigue justo donde lo dejaste.</p>

            <ul class="rg-ventajas">
                <li><span class="ico">📖</span><div><strong>Retoma tu lectura</strong><small>Tu progreso y tus listas, tal como las dejaste.</small></div></li>
                <li><span class="ico">👥</span><div><strong>Mira a tus amigos</strong><small>Descubre qué están leyendo ahora mismo.</small></div></li>
                <li><span class="ico">🎁</span><div><strong>Tu Wrap Up</strong><small>El resumen de tu año lector, siempre al día.</small></div></li>
            </ul>
        </aside>

        <!-- Formulario -->
        <section class="rg-form-wrap">
            <h1>Iniciar sesión</h1>
            <p class="rg-sub">Accede a tu biblioteca.</p>

            <?php if ($recienRegistrado && !$mensaje): ?>
                <p class="rg-ok" role="status">✅ ¡Cuenta creada! Ya puedes iniciar sesión.</p>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <p class="rg-error" role="alert">⚠️ <?= htmlspecialchars($mensaje) ?></p>
            <?php endif; ?>

            <form method="POST" autocomplete="on">

                <div class="rg-campo">
                    <span class="ico">✉️</span>
                    <input class="rg-input" type="email" name="email" placeholder="Correo electrónico"
                           value="<?= htmlspecialchars($email) ?>" autocomplete="username"
                           <?= $email === '' ? 'autofocus' : '' ?> required>
                </div>

                <div class="rg-campo">
                    <span class="ico">🔒</span>
                    <input class="rg-input" type="password" name="password" id="rgPassword" placeholder="Contraseña"
                           autocomplete="current-password" <?= $email !== '' ? 'autofocus' : '' ?> required>
                    <button type="button" class="rg-ojo" id="rgOjo" aria-label="Mostrar u ocultar la contraseña">👁️</button>
                </div>

                <span class="rg-olvido"><a href="recuperar_contrase%C3%B1a.php">¿Olvidaste tu contraseña?</a></span>

                <button type="submit" class="rg-boton">Entrar</button>
            </form>

            <p class="rg-login">¿Todavía no tienes cuenta? <a href="registro.php">Crear cuenta</a></p>
        </section>

    </div>
</main>

<script>
    const pass = document.getElementById('rgPassword');
    const ojo  = document.getElementById('rgOjo');

    // Mostrar / ocultar contraseña
    ojo.addEventListener('click', () => {
        const oculta = pass.type === 'password';
        pass.type = oculta ? 'text' : 'password';
        ojo.textContent = oculta ? '🙈' : '👁️';
    });
</script>

</body>
</html>
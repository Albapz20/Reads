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

$pendiente       = $_GET['pendiente'] ?? '';   // "1" = email enviado, "error" = fallo al enviar
$verificado      = isset($_GET['verificado']);
$cuentaEliminada = isset($_GET['cuenta_eliminada']);
$passCambiada = isset($_GET['pass_cambiada']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · Reads</title>
    <?php include __DIR__ . '/pwa_head.php'; ?>
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

            <?php if ($pendiente === '1' && !$mensaje): ?>
                <p class="rg-ok" role="status">📧 ¡Cuenta creada! Te hemos enviado un email para confirmarla. Revisa también el spam.</p>
            <?php elseif ($pendiente === 'error' && !$mensaje): ?>
                <p class="rg-error" role="alert">⚠️ Tu cuenta se creó, pero no hemos podido enviar el email de confirmación. Inténtalo de nuevo más tarde.</p>
            <?php endif; ?>

            <?php if ($verificado && !$mensaje): ?>
                <p class="rg-ok" role="status">✅ ¡Correo confirmado! Ya puedes iniciar sesión.</p>
            <?php endif; ?>
            
            <?php if ($mensaje): ?>
   				 <p class="rg-error" role="alert">⚠️ <?= htmlspecialchars($mensaje) ?></p>
    		<?php if (strpos($mensaje, 'confirmar tu correo') !== false): ?>
       		 <p class="rg-sub"><a href="reenviar.php?email=<?= urlencode($email) ?>">Reenviar el email de confirmación</a></p>
   			 <?php endif; ?>
			<?php endif; ?>

            <?php if ($cuentaEliminada && !$mensaje): ?>
                <p class="rg-ok" role="status">Tu cuenta se ha eliminado correctamente.</p>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <p class="rg-error" role="alert">⚠️ <?= htmlspecialchars($mensaje) ?></p>
            <?php endif; ?>
            
            <?php if ($passCambiada && !$mensaje): ?>
    <p class="rg-ok" role="status">✅ Contraseña actualizada. Ya puedes iniciar sesión.</p>
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

                <span class="rg-olvido"><a href="recuperar_password.php">¿Olvidaste tu contraseña?</a></span>

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
<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";
require_once "../src/Mailer.php";

$tema = "pastel";

$service = new UserService();
$mensaje = "";
$nombre  = "";
$email   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre    = trim((string)($_POST["nombre"] ?? ""));
    $email     = trim((string)($_POST["email"] ?? ""));
    $password  = (string)($_POST["password"] ?? "");
    $password2 = (string)($_POST["password2"] ?? "");

    if ($password !== $password2) {
        $mensaje = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 8) {
        $mensaje = "La contraseña debe tener al menos 8 caracteres.";
    } else {
        $resultado = $service->registrar($nombre, $email, $password);

        // Si todo va bien, registrar() devuelve ['email' => ..., 'token' => ...]
        if (is_array($resultado)) {
            $enviado = Mailer::enviarVerificacion($resultado["email"], $nombre, $resultado["token"]);
            header("Location: login.php?pendiente=" . ($enviado ? "1" : "error"));
            exit;
        }
        $mensaje = (string)$resultado;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear cuenta · Reads</title>
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <link rel="stylesheet" href="/Reads/public/css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <link rel="stylesheet" href="/Reads/temas/<?= htmlspecialchars($tema) ?>.css?v=<?= @filemtime(__DIR__ . '/../temas/' . $tema . '.css') ?>">
    <link rel="stylesheet" href="/Reads/public/css/auth.css?v=<?= @filemtime(__DIR__ . '/css/auth.css') ?>">
</head>

<body class="page-register">

<main class="rg-page">
    <div class="rg-card">

        <!-- Presentación -->
        <aside class="rg-pitch">
            <div class="rg-logo"><span></span> Reads</div>
            <h2>Tu rincón de lectura, siempre contigo</h2>
            <p>Organiza lo que lees, mide tu progreso y descubre qué leen otros lectores.</p>

            <ul class="rg-ventajas">
                <li><span class="ico">📖</span><div><strong>Tu estantería</strong><small>Leyendo, leídos, TBR y wishlist en un solo sitio.</small></div></li>
                <li><span class="ico">🎯</span><div><strong>Retos y estadísticas</strong><small>Objetivo anual, ritmo de lectura y tu Wrap Up del año.</small></div></li>
                <li><span class="ico">✨</span><div><strong>Recomendaciones</strong><small>Libros nuevos según lo que ya has leído.</small></div></li>
                <li><span class="ico">👥</span><div><strong>Comunidad</strong><small>Sigue a otros lectores y mira qué están leyendo.</small></div></li>
            </ul>
        </aside>

        <!-- Formulario -->
        <section class="rg-form-wrap">
            <h1>Crear cuenta</h1>
            <p class="rg-sub">Es gratis y solo te llevará un minuto. Te enviaremos un email para confirmar tu cuenta.</p>

            <?php if ($mensaje): ?>
                <p class="rg-error" role="alert">⚠️ <?= htmlspecialchars($mensaje) ?></p>
            <?php endif; ?>

            <form method="POST" autocomplete="on" id="rgForm">

                <div class="rg-campo">
                    <span class="ico">👤</span>
                    <input class="rg-input" type="text" name="nombre" placeholder="Nombre de usuario"
                           value="<?= htmlspecialchars($nombre) ?>" autocomplete="name" required>
                </div>

               <div class="rg-campo">
                    <span class="ico">✉️</span>
                    <input class="rg-input" type="email" name="email" placeholder="Correo electrónico"
                           value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
                </div>

                <div class="rg-campo">
                    <span class="ico">🔒</span>
                    <input class="rg-input" type="password" name="password" id="rgPassword" placeholder="Contraseña"
                           autocomplete="new-password" minlength="8" required>
                    <button type="button" class="rg-ojo" id="rgOjo" aria-label="Mostrar u ocultar la contraseña">👁️</button>
                </div>

                <div class="rg-fuerza" aria-hidden="true"><i id="rgBarra"></i></div>
                <div class="rg-fuerza-texto" id="rgFuerzaTexto"></div>
                <div class="rg-fuerza" id="rgFuerza" aria-hidden="true"><i id="rgBarra"></i></div>

                <div class="rg-campo">
                    <span class="ico">🔒</span>
                    <input class="rg-input" type="password" name="password2" id="rgPassword2" placeholder="Repite la contraseña"
                           autocomplete="new-password" minlength="8" required>
                </div>
                <div class="rg-coincide" id="rgCoincide" aria-live="polite"></div>

                <button type="submit" class="rg-boton">Crear mi cuenta</button>
            </form>

            <p class="rg-login">¿Ya tienes cuenta? <a href="login.php">Iniciar sesión</a></p>
        </section>

    </div>
</main>

<script>
    const pass     = document.getElementById('rgPassword');
    const pass2    = document.getElementById('rgPassword2');
    const ojo      = document.getElementById('rgOjo');
    const barra    = document.getElementById('rgBarra');
    const texto    = document.getElementById('rgFuerzaTexto');
    const coincide = document.getElementById('rgCoincide');
    const form     = document.getElementById('rgForm');
    const contenedorFuerza = document.getElementById('rgFuerza');

    // Mostrar / ocultar contraseña (afecta a los dos campos)
    ojo.addEventListener('click', () => {
        const oculta = pass.type === 'password';
        pass.type  = oculta ? 'text' : 'password';
        pass2.type = pass.type;
        ojo.textContent = oculta ? '🙈' : '👁️';
    });

    function comprobarCoincidencia() {
        if (!pass2.value) {
            coincide.textContent = '';
            coincide.className = 'rg-coincide';
            return true;
        }
        const ok = pass.value === pass2.value;
        coincide.textContent = ok ? '✔ Las contraseñas coinciden' : '✖ Las contraseñas no coinciden';
        coincide.className = 'rg-coincide ' + (ok ? 'ok' : 'ko');
        return ok;
    }

    pass.addEventListener('input', () => {
        const v = pass.value;
        let puntos = 0;
        if (v.length >= 8) puntos++;
        if (v.length >= 12) puntos++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) puntos++;
        if (/\d/.test(v)) puntos++;
        if (/[^A-Za-z0-9]/.test(v)) puntos++;

        comprobarCoincidencia();

        // Si no hay nada escrito, ocultamos la barra
        if (!v) {
            barra.style.width = '0';
            texto.textContent = '';
            contenedorFuerza.classList.remove('visible');
            return;
        }

        // Si hay texto, mostramos la barra
        contenedorFuerza.classList.add('visible');

        const niveles = [
            { w: '25%',  c: '#e5484d', t: 'Muy débil' },
            { w: '25%',  c: '#e5484d', t: 'Débil' },
            { w: '50%',  c: '#f5a524', t: 'Mejorable' },
            { w: '75%',  c: '#99c93b', t: 'Buena' },
            { w: '100%', c: '#2fb36d', t: 'Muy buena' },
            { w: '100%', c: '#2fb36d', t: 'Excelente' },
        ];
        const n = niveles[puntos];
        barra.style.width = n.w;
        barra.style.background = n.c;
        texto.textContent = 'Seguridad: ' + n.t;
    });

    pass2.addEventListener('input', comprobarCoincidencia);

    form.addEventListener('submit', (e) => {
        if (!comprobarCoincidencia()) e.preventDefault();
    });
</script>

</body>
</html>
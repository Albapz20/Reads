<?php
// Landing page de instalación. Se publica dentro de /Reads/public para respetar APP_BASE.
require_once __DIR__ . '/../config/config.php';
$base = rtrim(APP_BASE, '/');
if ($base === '') $base = '';
$appUrl = $base . '/public';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#8f456d">
    <meta name="description" content="Reads: organiza tus lecturas, sigue tu progreso y comparte tu afición por los libros.">
    <link rel="manifest" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/manifest.webmanifest">
    <link rel="icon" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/reads-icon.svg" type="image/svg+xml">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Reads">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Reads · Tu espacio para leer</title>
    <style>
        :root{color-scheme:light;--ink:#392b35;--muted:#715f6b;--pink:#8f456d;--rose:#f8e9f0;--paper:#fffafc}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(ellipse at 10% 5%,#f4dce8 0,transparent 36%),linear-gradient(135deg,#fffafc,#f7edf2);}
        a{color:inherit}.wrap{width:min(1060px,100% - 32px);margin:auto}.bar{height:76px;display:flex;align-items:center;justify-content:space-between}.brand{font-size:1.55rem;font-weight:850;letter-spacing:-.04em;color:var(--pink);text-decoration:none}.links{display:flex;gap:18px;align-items:center}.links a{text-decoration:none;font-weight:650}.hero{padding:64px 0 42px;display:grid;grid-template-columns:1.2fr .8fr;gap:40px;align-items:center}.eyebrow{display:inline-block;padding:8px 13px;border-radius:99px;background:#fff;color:var(--pink);font-weight:750;font-size:.88rem}.hero h1{font-size:clamp(2.6rem,7vw,5rem);line-height:.98;letter-spacing:-.06em;margin:19px 0}.hero p{font-size:1.15rem;line-height:1.7;color:var(--muted);max-width:620px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}.btn{display:inline-flex;justify-content:center;align-items:center;min-height:48px;padding:12px 19px;border-radius:14px;border:1px solid #e8d5df;text-decoration:none;font-weight:800;background:#fff}.btn.primary{background:var(--pink);color:white;border-color:var(--pink);box-shadow:0 10px 24px #8f456d30}.book{justify-self:center;width:min(280px,80%);aspect-ratio:3/4;border-radius:22px;background:linear-gradient(150deg,#bc7699,#75415f 72%);box-shadow:20px 24px 0 #f0d9e4,0 30px 60px #54344b30;display:grid;place-items:center;color:#fff;text-align:center;padding:24px;transform:rotate(3deg)}.book .mark{font-size:5rem}.book strong{display:block;font-size:2rem;letter-spacing:.04em}.book span{opacity:.85}.features{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;padding:26px 0 52px}.feature{padding:22px;border:1px solid #f0dce5;border-radius:18px;background:#ffffffc9}.feature b{display:block;margin:10px 0 5px}.feature p{margin:0;color:var(--muted);line-height:1.55}.feature i{font-style:normal;font-size:1.6rem}.install-help{max-width:760px;margin:0 auto 50px;padding:22px 24px;background:#fff;border:1px solid #f0dce5;border-radius:18px;color:var(--muted);line-height:1.6}.install-help h2{color:var(--ink);margin:0 0 8px}.status{min-height:1.5em;color:var(--pink);font-weight:700}.footer{padding:20px 0 32px;text-align:center;color:var(--muted);font-size:.9rem}
        @media(max-width:720px){.hero{grid-template-columns:1fr;padding-top:38px;gap:35px}.book{width:190px}.features{grid-template-columns:1fr}.links{gap:12px}.bar{height:64px}.hero h1{font-size:3.3rem}}
    </style>
</head>
<body>
<header class="wrap bar">
    <a class="brand" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/instalar.php">📚 Reads</a>
    <nav class="links" aria-label="Acceso">
        <a href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/login.php">Iniciar sesión</a>
        <a href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/registro.php">Crear cuenta</a>
    </nav>
</header>
<main>
    <section class="wrap hero">
        <div>
            <span class="eyebrow">Tu rincón de lectura</span>
            <h1>Lee. Guarda.<br>Disfruta.</h1>
            <p>Organiza tu biblioteca, registra tu progreso, cumple tus retos y descubre lo que leen otros lectores, todo en Reads.</p>
            <div class="actions">
                <button class="btn primary" id="installButton" type="button" hidden>Instalar Reads</button>
                <a class="btn primary" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/registro.php">Empezar gratis</a>
                <a class="btn" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/login.php">Ya tengo cuenta</a>
            </div>
            <p class="status" id="installStatus" role="status" aria-live="polite"></p>
        </div>
        <div class="book" aria-label="Ilustración de un libro">
            <div><div class="mark">✦</div><strong>READS</strong><span>Un hogar para tus historias</span></div>
        </div>
    </section>
    <section class="wrap features" aria-label="Funciones de Reads">
        <article class="feature"><i>📚</i><b>Tu estantería</b><p>Ten en un solo sitio tus libros leídos, pendientes y en curso.</p></article>
        <article class="feature"><i>🎯</i><b>Progreso y estadísticas</b><p>Marca páginas, fija objetivos y mira cómo avanzas.</p></article>
        <article class="feature"><i>👥</i><b>Comunidad lectora</b><p>Sigue a otros lectores y comparte tu afición.</p></article>
    </section>
    <section class="install-help">
        <h2>¿No aparece el botón de instalar?</h2>
        <p>En Android, abre esta página con Chrome y usa el menú ⋮ → <strong>Instalar aplicación</strong> o <strong>Añadir a pantalla de inicio</strong>. En iPhone, abre con Safari, toca <strong>Compartir</strong> y luego <strong>Añadir a pantalla de inicio</strong>. La instalación PWA no descarga un APK: crea un acceso instalable a la app web y necesita Internet para usar Reads.</p>
    </section>
</main>
<footer class="wrap footer">Reads · Tu biblioteca, a tu manera.</footer>
<script>
let instalacionPendiente = null;
const botonInstalar = document.getElementById('installButton');
const estadoInstalacion = document.getElementById('installStatus');
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    instalacionPendiente = event;
    botonInstalar.hidden = false;
});
botonInstalar.addEventListener('click', async () => {
    if (!instalacionPendiente) return;
    instalacionPendiente.prompt();
    const resultado = await instalacionPendiente.userChoice;
    estadoInstalacion.textContent = resultado.outcome === 'accepted' ? '¡Reads se está instalando!' : 'Puedes instalarla cuando quieras desde el menú del navegador.';
    instalacionPendiente = null;
    botonInstalar.hidden = true;
});
window.addEventListener('appinstalled', () => {
    estadoInstalacion.textContent = 'Reads ya está instalada en este dispositivo.';
    botonInstalar.hidden = true;
});
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
</script>
</body>
</html>

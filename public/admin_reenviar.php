<?php
require_once "../src/UserService.php";
require_once "../src/Auth.php";
require_once "../src/Mailer.php";

// Solo el administrador
$usuario = Auth::usuario();
if (!$usuario || strtolower($usuario['email']) !== 'admin@admin.com') {
    http_response_code(403);
    exit('Acceso denegado. Inicia sesión como administrador.');
}

// Token anti-CSRF
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_admin'];

$service    = new UserService();
$db         = new Database();
$resultados = [];

function pendientes(Database $db): array {
    $st = $db->pdo->query("SELECT id, nombre, email, fecha_registro, token_expira
                             FROM usuarios WHERE verificado = 0 ORDER BY id");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    set_time_limit(120);
    $accion = $_POST['accion'] ?? '';
    $emails = [];

    if ($accion === 'todos') {
        foreach (pendientes($db) as $p) $emails[] = $p['email'];
    } elseif ($accion === 'uno') {
        $emails[] = trim((string)($_POST['email'] ?? ''));
    }

    foreach ($emails as $email) {
        // regenerarToken() solo actúa sobre cuentas con verificado = 0
        $r = $service->regenerarToken($email);
        if (!$r) {
            $resultados[] = [$email, false, 'No está pendiente o no existe'];
            continue;
        }
        $ok = Mailer::enviarVerificacion($r['email'], $r['nombre'], $r['token']);
        $resultados[] = [$email, $ok, $ok ? 'Enviado' : Mailer::$ultimoError];
        sleep(1); // sin prisa, para no saturar Gmail
    }
}

$lista = pendientes($db);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reenviar confirmaciones · Admin</title>
<style>
  body{font-family:system-ui,sans-serif;max-width:760px;margin:30px auto;padding:0 16px;color:#392b35}
  table{width:100%;border-collapse:collapse;margin:16px 0}
  th,td{padding:8px 10px;border-bottom:1px solid #eadbe3;text-align:left;font-size:.92rem}
  button{padding:8px 14px;border:0;border-radius:8px;background:#8f456d;color:#fff;font-weight:700;cursor:pointer}
  .ok{color:#1f8a4c}.ko{color:#c0392b}
</style>
</head>
<body>
<h1>Cuentas pendientes de confirmar</h1>

<?php if ($resultados): ?>
  <h3>Resultado del envío</h3>
  <ul>
  <?php foreach ($resultados as [$email, $ok, $detalle]): ?>
    <li class="<?= $ok ? 'ok' : 'ko' ?>">
      <?= htmlspecialchars($email) ?> — <?= $ok ? '✅' : '❌' ?> <?= htmlspecialchars((string)$detalle) ?>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if (!$lista): ?>
  <p>No hay cuentas pendientes. 🎉</p>
<?php else: ?>
  <table>
    <tr><th>Nombre</th><th>Email</th><th>Registro</th><th>Token caduca</th><th></th></tr>
    <?php foreach ($lista as $p): ?>
    <tr>
      <td><?= htmlspecialchars($p['nombre']) ?></td>
      <td><?= htmlspecialchars($p['email']) ?></td>
      <td><?= htmlspecialchars((string)$p['fecha_registro']) ?></td>
      <td><?= htmlspecialchars((string)$p['token_expira']) ?></td>
      <td>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="accion" value="uno">
          <input type="hidden" name="email" value="<?= htmlspecialchars($p['email']) ?>">
          <button type="submit">Enviar</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <form method="POST" onsubmit="return confirm('¿Enviar el email a TODAS las cuentas pendientes?')">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="accion" value="todos">
    <button type="submit">Enviar a todos (<?= count($lista) ?>)</button>
  </form>
<?php endif; ?>
</body>
</html>
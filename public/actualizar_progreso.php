<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: perfil.php");
    exit;
}

$listaService = new ListaService();
$db = new Database();

$libroId = (int)($_POST["libro_id"] ?? 0);

// El libro tiene que ser del usuario
$stmt = $db->pdo->prepare("SELECT * FROM listas_lectura WHERE id = ? AND usuario_id = ?");
$stmt->execute([$libroId, $usuario["id"]]);
$libro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$libro) {
    header("Location: perfil.php");
    exit;
}

// Motivo de abandono (formulario de la ficha del libro)
if (isset($_POST["motivo_abandono"])) {
    $listaService->actualizarMotivoAbandono($usuario["id"], $libroId, trim($_POST["motivo_abandono"]));
}

// Progreso en porcentaje (solo si se envía)
if (isset($_POST["progreso"])) {
    $listaService->actualizarProgreso($usuario["id"], $libroId, $_POST["progreso"]);
}

// Lomo del libro al marcarlo como leído (solo si se envía el nuevo estado y existe la función)
$nuevoEstado = $_POST["estado"] ?? null;
if ($nuevoEstado === "leido" && function_exists('generarLomoDesdePortada')) {

    $rutaPortada = $libro["portada"];
    $titulo = $libro["titulo"];
    $idLibro = $libro["id"];

    $lomo = generarLomoDesdePortada($rutaPortada, $titulo, $idLibro);

    if ($lomo) {
        $sql = "UPDATE listas_lectura SET portada_lomo = ? WHERE id = ?";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute([$lomo, $idLibro]);
    }
}

// Volver a la ficha si se pidió; si no, al perfil como antes
if (($_POST["volver"] ?? "") === "libro") {
    $ref = !empty($libro["libro_id"]) ? $libro["libro_id"] : $libro["id"];
    header("Location: libro.php?id=" . urlencode((string)$ref));
} else {
    header("Location: perfil.php");
}
exit;
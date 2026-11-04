<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/GenerosHelper.php";

$usuario = Auth::usuario();
if (!$usuario) { header("Location: login.php"); exit; }

$ref = (string)($_POST['libro_ref'] ?? '');
$destino = $ref !== '' ? 'libro.php?id=' . urlencode($ref) : 'biblioteca.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registroId = (int)($_POST['registro_id'] ?? 0);

    // Solo se aceptan géneros de la lista conocida (máximo 3)
    $conocidos = array_keys(reglasGeneros());
    $elegidos = array_values(array_intersect($conocidos, (array)($_POST['generos'] ?? [])));

    $db = new Database();
    $col = columnaGeneros($db->pdo);

    if ($registroId > 0 && $col !== null) {
        $valor = valorGeneroManual($elegidos);   // "A, B, [manual]" o "-" si no marca ninguno
        $db->pdo->prepare("UPDATE listas_lectura SET `{$col}` = ? WHERE id = ? AND usuario_id = ?")
                ->execute([$valor, $registroId, $usuario["id"]]);
    }
}

header("Location: " . $destino);
exit;
<?php
require_once "../src/Auth.php";
require_once "../src/Database.php";
require_once "../src/ListaService.php";

$usuario = Auth::usuario();
if (!$usuario) { 
    header("Location: login.php"); 
    exit; 
}

$db = new Database();
$listaService = new ListaService();

$libro_id = $_POST["libro_id"] ?? null;

if (!$libro_id) {
    header("Location: perfil.php");
    exit;
}

// 1. Obtener datos actuales del registro
$sql = "SELECT id, libro_id, titulo, autores, estado, paginas_totales, paginas_leidas 
        FROM listas_lectura 
        WHERE id = ? AND usuario_id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$libro_id, $usuario["id"]]);
$libro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$libro) {
    die("Libro no encontrado.");
}

$paginas_totales = (int)($libro["paginas_totales"] ?? 0);
$paginas_leidas  = isset($_POST["paginas_leidas"]) ? (int)$_POST["paginas_leidas"] : (int)$libro["paginas_leidas"];
$estado          = $_POST["estado"] ?? $libro["estado"];

/**
 * Función auxiliar para buscar páginas en Open Library (API gratuita y pública)
 */
function buscarPaginasEnOpenLibrary($titulo, $autor) {
    $query = urlencode($titulo . " " . $autor);
    $url = "https://openlibrary.org/search.json?q=" . $query;

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: ReadingApp/1.0 (PHP)\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);

    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data["docs"][0])) {
            $doc = $data["docs"][0];
            // Open Library suele devolver el número de páginas en 'number_of_pages_median'
            if (!empty($doc["number_of_pages_median"])) {
                return (int)$doc["number_of_pages_median"];
            }
            if (!empty($doc["number_of_pages"])) {
                return (int)$doc["number_of_pages"];
            }
        }
    }
    return 0;
}

// 2. RECUPERACIÓN AUTOMÁTICA
if ($paginas_totales === 0) {
    // Intento 1: Consultar Open Library por Título + Autor
    $paginas_totales = buscarPaginasEnOpenLibrary($libro["titulo"], $libro["autores"] ?? "");

    // Intento 2: Si no encuentra, busca solo por la primera palabra clave del título en Open Library
    if ($paginas_totales === 0) {
        $primerPalabra = explode(" ", trim($libro["titulo"]))[0];
        $paginas_totales = buscarPaginasEnOpenLibrary($primerPalabra, $libro["autores"] ?? "");
    }

    // Intento 3: Fallback de seguridad (Si no aparece en bases de datos abiertas, asigna un estándar de 300 pág.)
    if ($paginas_totales === 0) {
        $paginas_totales = 300; 
    }
}

// 3. Si el libro está en estado "leído", igualamos automáticamente las leídas a las totales
if ($estado === "leido") {
    $paginas_leidas = $paginas_totales;
}

// 4. Guardar en BD
$listaService->actualizarPaginas(
    $usuario["id"],
    $libro_id,
    $paginas_totales,
    $paginas_leidas
);

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'perfil.php'));
exit;
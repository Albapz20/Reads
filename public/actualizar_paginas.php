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

// Obtener datos actuales del registro
$sql = "SELECT id, libro_id, titulo, autores, estado, paginas_totales, paginas_leidas 
        FROM listas_lectura 
        WHERE id = ? AND usuario_id = ?";
$stmt = $db->pdo->prepare($sql);
$stmt->execute([$libro_id, $usuario["id"]]);
$libro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$libro) {
    die("Libro no encontrado.");
}

// Si el usuario envió manualmente un número de páginas desde un formulario, lo usamos
$paginas_manuales = isset($_POST["paginas_totales"]) ? (int)$_POST["paginas_totales"] : 0;
$paginas_totales  = (int)($libro["paginas_totales"] ?? 0);
$paginas_leidas   = isset($_POST["paginas_leidas"]) ? (int)$_POST["paginas_leidas"] : (int)$libro["paginas_leidas"];
$estado           = $_POST["estado"] ?? $libro["estado"];

// 1. Función para buscar páginas en Google Books API (mucho más precisa)
function buscarPaginasGoogleBooks($titulo, $autor) {
    $busqueda = urlencode(trim($titulo . " " . $autor));
    $url = "https://www.googleapis.com/books/v1/volumes?q=" . $busqueda . "&maxResults=1&langRestrict=es";

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: ReadingApp/1.0 (PHP)\r\n",
            "timeout" => 4
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);

    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data["items"][0]["volumeInfo"]["pageCount"])) {
            return (int)$data["items"][0]["volumeInfo"]["pageCount"];
        }
    }
    return 0;
}

// 2. Función para buscar páginas en Open Library (Respaldo)
function buscarPaginasEnOpenLibrary($titulo, $autor) {
    $query = urlencode(trim($titulo . " " . $autor));
    $url = "https://openlibrary.org/search.json?q=" . $query . "&limit=1";

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: ReadingApp/1.0 (PHP)\r\n",
            "timeout" => 4
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);

    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data["docs"][0])) {
            $doc = $data["docs"][0];
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

// LÓGICA DE REVISIÓN Y RECUPERACIÓN DE PÁGINAS:
// Si el usuario introdujo manualmente las páginas, se respeta ese valor
if ($paginas_manuales > 0) {
    $paginas_totales = $paginas_manuales;
} 
// Si las páginas actuales son 0 O SON 300 (valor por defecto erróneo), buscamos de verdad en las APIs
elseif ($paginas_totales === 0 || $paginas_totales === 300) {
    
    // Intento 1: Google Books API (Título + Autor)
    $paginas_encontradas = buscarPaginasGoogleBooks($libro["titulo"], $libro["autores"] ?? "");

    // Intento 2: Open Library API (Si Google falla)
    if ($paginas_encontradas === 0) {
        $paginas_encontradas = buscarPaginasEnOpenLibrary($libro["titulo"], $libro["autores"] ?? "");
    }

    // Intento 3: Búsqueda flexible solo con la primera palabra del título
    if ($paginas_encontradas === 0) {
        $primerPalabra = explode(" ", trim($libro["titulo"]))[0];
        $paginas_encontradas = buscarPaginasGoogleBooks($primerPalabra, $libro["autores"] ?? "");
    }

    // Si se encontró una cifra válida en las APIs, actualizamos $paginas_totales
    if ($paginas_encontradas > 0) {
        $paginas_totales = $paginas_encontradas;
    }
}

// Si el libro está marcado como "leído", igualamos las páginas leídas a las totales reales
if ($estado === "leido") {
    $paginas_leidas = $paginas_totales;
}

// Guardar los cambios actualizados en la base de datos
$listaService->actualizarPaginas(
    $usuario["id"],
    $libro_id,
    $paginas_totales,
    $paginas_leidas
);

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'perfil.php'));
exit;
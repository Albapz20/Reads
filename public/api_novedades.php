<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// Mes y año actual dinámico
$meses = [
    '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
    '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
    '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
];

$anioActual = date('Y');
$mesNum = date('m');
$nombreMes = $meses[$mesNum] ?? 'este mes';

// Leer las novedades locales
$archivoJson = __DIR__ . '/novedades.json';
$libros = [];

if (file_exists($archivoJson)) {
    $contenido = file_get_contents($archivoJson);
    $libros = json_decode($contenido, true) ?? [];
}

// Respuesta limpia
echo json_encode([
    'success' => true,
    'tituloSeccion' => "Novedades de " . $nombreMes . " " . $anioActual,
    'libros' => $libros
], JSON_UNESCAPED_UNICODE);
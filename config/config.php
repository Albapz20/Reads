<?php

define('APP_BASE', '/Reads');
define('APP_TEMA_DEFECTO', 'pastel');
define('APP_CONTACTO', 'tu-correo@dominio.com');   // para el User-Agent de las APIs externas
define('LECTURA_MIN_POR_PAGINA', 1.5);
define('LIBROS_POR_PAGINA', 24);

function config(string $clave, $defecto = null) {
    static $local = null;
    if ($local === null) {
        $archivo = __DIR__ . '/config.local.php';
        $local = is_file($archivo) ? (require $archivo) : [];
        if (!is_array($local)) $local = [];
    }
    if (array_key_exists($clave, $local)) return $local[$clave];
    $env = getenv(strtoupper($clave));
    return $env !== false ? $env : $defecto;
}
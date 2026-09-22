<?php

class PortadaHelper {
    public static function obtenerUrl($urlGuardada, $titulo, $idExterno = '') {
        $urlGuardada = trim($urlGuardada ?? '');

        //Si hay ID de Google, probamos la URL directa de la API
        if (!empty($idExterno) && !empty($urlGuardada) && str_contains($urlGuardada, 'google')) {
            return "https://books.google.com/books/content?id=" . urlencode($idExterno) . "&printsec=frontcover&img=1&zoom=1";
        }

        //Si hay una URL de OpenLibrary o externa limpia
        if (!empty($urlGuardada) && str_starts_with($urlGuardada, 'http')) {
            return str_replace('http://', 'https://', $urlGuardada);
        }

        //Si no hay URL válida, devolvemos una portada generada al vuelo con el título
        $texto = urlencode($titulo);
        return "https://placehold.co/300x450/2c3e50/ffffff?text=" . $texto;
    }
}

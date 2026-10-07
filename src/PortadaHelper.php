<?php

class PortadaHelper {
    public static function obtenerUrl($urlGuardada, $titulo, $idExterno = '') {
        $url = trim((string)($urlGuardada ?? ''));
        $idExterno = trim((string)$idExterno);
        $esWeb = (bool)preg_match('~^https?://~i', $url);

        // 1) Imagen guardada en tu propio servidor (uploads/portadas/…): se usa tal cual
        if ($url !== '' && !$esWeb && stripos($url, 'default') === false) {
            return $url;
        }

        // 2) Portada de Google: solo si el id parece un id de volumen de Google (12 caracteres).
        if ($esWeb && str_contains($url, 'google') && preg_match('/^[A-Za-z0-9_-]{12}$/', $idExterno)) {
            return "https://books.google.com/books/content?id=" . urlencode($idExterno) . "&printsec=frontcover&img=1&zoom=1";
        }

        // 3) Cualquier otra URL válida (que no sea un placeholder)
        if ($esWeb && stripos($url, 'placehold') === false) {
            return str_replace('http://', 'https://', $url);
        }

        // 4) Sin portada válida: una generada al vuelo con el título
        return "https://placehold.co/300x450/2c3e50/ffffff?text=" . urlencode((string)$titulo);
    }
}
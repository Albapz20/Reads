<?php

class PortadasService
{
    public static function obtenerDatosLibro($titulo, $autor = null)
    {
        $titulo = trim($titulo);

        // Buscar primero por título completo
        $datos = self::buscarGoogleBooks("intitle:" . $titulo);
        if (self::validarDatos($datos)) {
            return self::extraerDatos($datos);
        }

        // Buscar por autor si se proporciona
        if (!empty($autor)) {
            $datos = self::buscarGoogleBooks("inauthor:" . $autor);
            if (self::validarDatos($datos)) {
                return self::extraerDatos($datos);
            }
        }

        // Buscar por la primera palabra del título como último recurso
        $primeraPalabra = explode(" ", $titulo)[0];
        $datos = self::buscarGoogleBooks($primeraPalabra);
        if (self::validarDatos($datos)) {
            return self::extraerDatos($datos);
        }

        // No se encontraron datos válidos, devolver valores por defecto
        return [
            "paginas_totales" => 0,
            "portada" => null
        ];
    }

    private static function buscarGoogleBooks($query)
    {
        $apiKey = "AIzaSyBWAS9W-oky5pAt-GlDDSUCv5KEraFA7qI";

        $query = urlencode($query);
        $url = "https://www.googleapis.com/books/v1/volumes?q=$query&key=$apiKey";

        $json = @file_get_contents($url);
        if (!$json) return null;

        return json_decode($json, true);
    }

    private static function validarDatos($data)
    {
        return isset($data["items"][0]["volumeInfo"]["pageCount"]);
    }

    private static function extraerDatos($data)
    {
        $info = $data["items"][0]["volumeInfo"];

        return [
            "paginas_totales" => $info["pageCount"] ?? 0,
            "portada" => $info["imageLinks"]["thumbnail"] ?? null
        ];
    }
}

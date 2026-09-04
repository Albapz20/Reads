<?php

class BookService {

    // ============================
    // BUSCAR LIBROS
    // ============================
    public function buscarLibros($query) {

        $queryUrl = urlencode($query);

        // 1) Google Books
        $urlGoogle = "https://www.googleapis.com/books/v1/volumes?q={$queryUrl}&maxResults=20";
        $google = $this->curlGet($urlGoogle);

        $resultados = [];

        if ($google && isset($google["items"]) && is_array($google["items"])) {

            foreach ($google["items"] as $item) {
                $info = $item["volumeInfo"] ?? [];

                // Normalizar URL de portada de Google
                $portada = $info["imageLinks"]["thumbnail"] ?? $info["imageLinks"]["smallThumbnail"] ?? "";
                if (!empty($portada)) {
                    $portada = str_replace("http://", "https://", $portada);
                } else {
                    $portada = "/Reads/img/default_cover.jpg";
                }

                $resultados[] = [
                    "id" => $item["id"],
                    "titulo" => $info["title"] ?? "Sin título",
                    "autor" => isset($info["authors"]) ? implode(", ", $info["authors"]) : "Autor desconocido",
                    "anio" => $info["publishedDate"] ?? "Desconocido",
                    "descripcion" => $info["description"] ?? "Sin descripción disponible.",
                    "portada" => $portada,
                    "paginas_totales" => $info["pageCount"] ?? 0
                ];
            }

            return $resultados;
        }

        // 2) Open Library Fallback
        $urlOL = "https://openlibrary.org/search.json?q={$queryUrl}";
        $ol = $this->curlGet($urlOL);

        if ($ol && isset($ol["docs"]) && is_array($ol["docs"])) {

            foreach ($ol["docs"] as $doc) {

                $coverId = $doc["cover_i"] ?? null;
                $portada = $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg" : "/Reads/img/default_cover.jpg";

                $resultados[] = [
                    "id" => str_replace("/works/", "", $doc["key"] ?? ""),
                    "titulo" => $doc["title"] ?? "Sin título",
                    "autor" => isset($doc["author_name"]) ? implode(", ", $doc["author_name"]) : "Autor desconocido",
                    "anio" => $doc["first_publish_year"] ?? "Desconocido",
                    "descripcion" => "",
                    "portada" => $portada,
                    "paginas_totales" => $doc["number_of_pages"] ?? 0
                ];
            }

            return $resultados;
        }

        return [];
    }

 // ============================
    // OBTENER LIBRO CON API KEY
    // ============================
    public function obtenerLibro($id) {

        // 🔑 API Key configurada
        $apiKey = "AIzaSyBWAS9W-oky5pAt-GlDDSUCv5KEraFA7qI";

        $esOpenLibrary = (substr($id, 0, 7) === "/works/" || substr($id, 0, 2) === "OL");

        // 1. GOOGLE BOOKS (Directo por ID con API Key)
        if (!$esOpenLibrary) {
            $urlGoogle = "https://www.googleapis.com/books/v1/volumes/" . urlencode($id) . "?key={$apiKey}";
            $google = $this->curlGet($urlGoogle);

            if ($google && isset($google["volumeInfo"])) {
                $info = $google["volumeInfo"];
                
                $images = $info["imageLinks"] ?? [];
                $portada = $images["extraLarge"] ?? $images["large"] ?? $images["medium"] ?? $images["thumbnail"] ?? $images["smallThumbnail"] ?? "";

                if (!empty($portada)) {
                    // Forzar HTTPS
                    $portada = str_replace("http://", "https://", $portada);
                } else {
                    // Placeholder alternativo estable
                    $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
                }

                return [
                    "title" => $info["title"] ?? "Sin título",
                    "author_name" => $info["authors"] ?? ["Autor desconocido"],
                    "portada" => $portada,
                    "description" => $info["description"] ?? "Sin descripción disponible.",
                    "number_of_pages" => $info["pageCount"] ?? 0
                ];
            }
        }

        // 2. OPEN LIBRARY
        $idOL = str_replace("/works/", "", $id);
        $urlOL = "https://openlibrary.org/works/{$idOL}.json";
        $ol = $this->curlGet($urlOL);

        if ($ol && is_array($ol) && !isset($ol["error"]) && isset($ol["title"])) {
            
            $nombreAutor = "Autor desconocido";
            if (isset($ol["authors"][0]["author"]["key"])) {
                $authorKey = $ol["authors"][0]["author"]["key"];
                $authorData = $this->curlGet("https://openlibrary.org{$authorKey}.json");
                if ($authorData && isset($authorData["name"])) {
                    $nombreAutor = $authorData["name"];
                }
            }

            $desc = "";
            if (isset($ol["description"])) {
                $desc = is_array($ol["description"]) ? ($ol["description"]["value"] ?? "") : $ol["description"];
            }

            $covers = $ol["covers"] ?? [];
            $portada = "https://placehold.co/350x500/e2e8f0/1e293b?text=Sin+Portada";
            if (!empty($covers) && is_array($covers) && isset($covers[0]) && $covers[0] > 0) {
                $portada = "https://covers.openlibrary.org/b/id/" . $covers[0] . "-L.jpg";
            }

            return [
                "title" => $ol["title"],
                "author_name" => [$nombreAutor],
                "covers" => $covers,
                "portada" => $portada,
                "description" => $desc,
                "number_of_pages" => $ol["number_of_pages"] ?? 0
            ];
        }

        return null;
    }
    // ============================
    // HELPER CURL / HTTP
    // ============================
    private function curlGet($url) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return $response ? json_decode($response, true) : null;
    }
}
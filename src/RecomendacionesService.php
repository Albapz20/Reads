<?php

class RecomendacionesService {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getDatosRecomendacion($usuarioId = null) {
        $autor = '';
        $titulo = '';
        $categoria = '';
        $biblioteca = [];

        try {
            // Obtener directamente el último libro añadido a la tabla (sin importar usuario o estado)
            $stmt = $this->pdo->query("SELECT * FROM listas_lectura ORDER BY id DESC LIMIT 1");
            $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ultimo) {
                // Extraer autor de cualquier columna existente
                $autor = trim($ultimo['autores'] ?? $ultimo['autor'] ?? $ultimo['author'] ?? '');
                $titulo = trim($ultimo['titulo'] ?? $ultimo['title'] ?? '');
                $categoria = trim($ultimo['categorias'] ?? $ultimo['categoria'] ?? $ultimo['genero'] ?? '');
            }

            //  Títulos guardados para evitar recomendarlos
            $stmtBib = $this->pdo->query("SELECT LOWER(TRIM(titulo)) FROM listas_lectura");
            $biblioteca = $stmtBib->fetchAll(PDO::FETCH_COLUMN) ?: [];

        } catch (Exception $e) {}

        return [
            'ultimoAutor' => $autor,
            'ultimoTitulo' => $titulo,
            'categoria' => $categoria,
            'biblioteca' => $biblioteca
        ];
    }
}
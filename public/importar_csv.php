<?php

session_start();
require_once __DIR__ . '/../config/database.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['csv_goodreads']) || isset($_FILES['csv_file']) || isset($_FILES['archivo_csv']))) {
    
    $fileKey = isset($_FILES['csv_goodreads']) ? 'csv_goodreads' : (isset($_FILES['csv_file']) ? 'csv_file' : 'archivo_csv');
    $fileTmpPath = $_FILES[$fileKey]['tmp_name'];

    if (!empty($fileTmpPath) && ($handle = fopen($fileTmpPath, "r")) !== FALSE) {
        
        // Leer la primera fila para obtener los encabezados y mapear las columnas
        $header = fgetcsv($handle, 5000, ",");
        if ($header === false) {
            header("Location: ajustes.php?error=csv_invalido");
            exit;
        }

        $colMap = array_flip(array_map('trim', $header));

        // Verificar columnas mínimas requeridas de Goodreads
        if (!isset($colMap['Title']) || !isset($colMap['Author'])) {
            header("Location: ajustes.php?error=formato_goodreads_incorrecto");
            exit;
        }

        // Preparar la inserción en la base de datos
        $stmt = $pdo->prepare("
            INSERT INTO libros (usuario_id, titulo, autor, isbn, paginas, estado, fecha_fin, fecha_creacion, portada)
            VALUES (:usuario_id, :titulo, :autor, :isbn, :paginas, :estado, :fecha_fin, :fecha_creacion, :portada)
        ");

        $importados = 0;

        while (($row = fgetcsv($handle, 5000, ",")) !== FALSE) {
            $titulo = $row[$colMap['Title']] ?? 'Sin título';
            $autor = $row[$colMap['Author']] ?? 'Autor desconocido';

            $isbn13Raw = $row[$colMap['ISBN13']] ?? '';
            $isbnRaw = $row[$colMap['ISBN']] ?? '';
            $isbnLimpio = preg_replace('/[^0-9X]/i', '', !empty($isbn13Raw) ? $isbn13Raw : $isbnRaw);

            $paginas = intval($row[$colMap['Number of Pages']] ?? 0);
            $estanteria = $row[$colMap['Exclusive Shelf']] ?? 'read';

            // Mapeo de estado
            $estado = 'leido';
            if ($estanteria === 'currently-reading') $estado = 'leyendo';
            if ($estanteria === 'to-read') $estado = 'pendiente';

            // Manejo de fechas
            $dateReadRaw = trim($row[$colMap['Date Read']] ?? '');
            $dateAddedRaw = trim($row[$colMap['Date Added']] ?? '');

            $fechaFin = null;
            if (!empty($dateReadRaw)) {
                $timeRead = strtotime(str_replace('/', '-', $dateReadRaw));
                if ($timeRead) $fechaFin = date('Y-m-d', $timeRead);
            }

            $fechaCreacion = date('Y-m-d');
            if (!empty($dateAddedRaw)) {
                $timeAdded = strtotime(str_replace('/', '-', $dateAddedRaw));
                if ($timeAdded) $fechaCreacion = date('Y-m-d', $timeAdded);
            }

            // Si está leído pero no tiene Date Read, usamos la fecha en que se añadió a Goodreads
            if ($estado === 'leido' && empty($fechaFin)) {
                $fechaFin = $fechaCreacion;
            }

            // Generar portada desde Open Library si hay ISBN
            $portada = '';
            if (!empty($isbnLimpio)) {
                $portada = "https://covers.openlibrary.org/b/isbn/{$isbnLimpio}-M.jpg";
            }

            // Insertar libro
            $stmt->execute([
                ':usuario_id'     => $userId,
                ':titulo'         => $titulo,
                ':autor'          => $autor,
                ':isbn'           => $isbnLimpio,
                ':paginas'        => $paginas,
                ':estado'         => $estado,
                ':fecha_fin'      => $fechaFin,
                ':fecha_creacion' => $fechaCreacion,
                ':portada'        => $portada
            ]);

            $importados++;
        }

        fclose($handle);
        header("Location: biblioteca.php?msg=Importados+{$importados}+libros+correctamente");
        exit;
    }
}

header("Location: ajustes.php?error=no_file");
exit;
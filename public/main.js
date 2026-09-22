// Función para obtener la mejor URL de portada real posible desde Open Library
function obtenerUrlPortadaReal(item) {
    // Si viene con ID de portada directo
    if (item.cover_i) {
        return `https://covers.openlibrary.org/b/id/${item.cover_i}-M.jpg`;
    }
    // Si no tiene cover_i pero tiene ISBN, Open Library suele tener la portada guardada por ISBN
    if (item.isbn && item.isbn.length > 0) {
        return `https://covers.openlibrary.org/b/isbn/${item.isbn[0]}-M.jpg`;
    }
    // Si tiene clave de edición (OLID)
    if (item.cover_edition_key) {
        return `https://covers.openlibrary.org/b/olid/${item.cover_edition_key}-M.jpg`;
    }
    
    // Si la API no tiene absolutamente ninguna imagen registrada para este libro
    return null;
}

// Generador de respaldo (solo se usará si la API de Open Library no tiene imagen en lo absoluto)
const DEGRADADOS_PORTADA = [
    'linear-gradient(135deg, #1e3c72 0%, #2a5298 100%)',
    'linear-gradient(135deg, #2b5876 0%, #4e4376 100%)',
    'linear-gradient(135deg, #cc2b5e 0%, #753a88 100%)',
    'linear-gradient(135deg, #42275a 0%, #734b6d 100%)',
    'linear-gradient(135deg, #000428 0%, #004e92 100%)'
];

function crearPortadaElegante(titulo, autor = '') {
    const hash = Array.from(titulo).reduce((acc, char) => acc + char.charCodeAt(0), 0);
    const gradiente = DEGRADADOS_PORTADA[hash % DEGRADADOS_PORTADA.length];
    
    const tituloEscapado = titulo.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const autorEscapado = autor.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="200" height="300" viewBox="0 0 200 300">
        <rect width="200" height="300" rx="6" fill="${gradiente}"/>
        <foreignObject x="20" y="30" width="160" height="240">
            <div xmlns="http://www.w3.org/1999/xhtml" style="height:100%; display:flex; flex-direction:column; justify-content:space-between; text-align:center; color:#ffffff; font-family:sans-serif; padding:10px 5px;">
                <div style="font-size:14px; font-weight:700; word-break:break-word; max-height:140px; overflow:hidden;">${tituloEscapado}</div>
                <div style="font-size:11px; opacity:0.85; border-top:1px solid rgba(255,255,255,0.3); padding-top:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${autorEscapado}</div>
            </div>
        </foreignObject>
    </svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
// Busca la portada real del libro en Google Books API como segunda opción
async function intentarRecuperarPortadaReal(imgElement, titulo, autor = '') {
    if (imgElement.dataset.buscando) return;
    imgElement.dataset.buscando = "true";

    try {
        const query = encodeURIComponent(`${titulo} ${autor}`);
        const res = await fetch(`https://www.googleapis.com/books/v1/volumes?q=${query}&maxResults=1`);
        const data = await res.json();

        if (data.items && data.items[0].volumeInfo.imageLinks) {
            const links = data.items[0].volumeInfo.imageLinks;
            let portadaReal = links.thumbnail || links.smallThumbnail;
            if (portadaReal) {
                imgElement.src = portadaReal.replace('http://', 'https://');
                return;
            }
        }
    } catch (e) {
        console.warn("No se pudo obtener la portada automática para:", titulo);
    }

    // Si Google Books tampoco la encuentra, genera la cubierta en SVG
    imgElement.onerror = null;
    imgElement.src = crearPortadaElegante(titulo, autor);
}

function validarImagenReal(imgElement, titulo) {
    // Si la imagen mide menos de 10px o está rota
    if (imgElement.naturalWidth < 10 || imgElement.naturalHeight < 10) {
        intentarRecuperarPortadaReal(imgElement, titulo);
        return;
    }

    // Dibujamos la imagen en un canvas invisible para analizar sus colores
    try {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = imgElement.naturalWidth || 100;
        canvas.height = imgElement.naturalHeight || 150;
        
        ctx.drawImage(imgElement, 0, 0, canvas.width, canvas.height);
        const pixelData = ctx.getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data;
        
        // Si el centro de la imagen es 100% negro (r=0, g=0, b=0), es la portada fallida
        const esNegra = (pixelData[0] === 0 && pixelData[1] === 0 && pixelData[2] === 0);
        
        if (esNegra) {
            intentarRecuperarPortadaReal(imgElement, titulo);
        }
    } catch (e) {
        // Si da error de CORS al analizar la imagen, ignoramos
    }
}
// Manejador global de errores por si una portada real falla al cargar de los servidores de Open Library
document.addEventListener('error', function (e) {
    if (e.target.tagName.toLowerCase() === 'img') {
        const img = e.target;
        if (img.dataset.fallbackApplied) return;
        img.dataset.fallbackApplied = "true";

        const titulo = img.getAttribute('alt') || 'Libro';
        const autor = img.dataset.autor || '';
        img.src = crearPortadaElegante(titulo, autor);
    }
}, true);
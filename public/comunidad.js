
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.cm-seguir');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    btn.disabled = true;

    const sigo = btn.dataset.sigo === '1';
    const fd = new FormData();
    fd.append('accion', sigo ? 'dejar' : 'seguir');
    fd.append('id', btn.dataset.id);
    fd.append('csrf', btn.dataset.csrf);

    try {
        const res = await fetch('api_comunidad.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await res.json();

        if (d.ok) {
            btn.dataset.sigo = d.sigo ? '1' : '0';
            btn.textContent = d.sigo ? '✓ Siguiendo' : '➕ Seguir';
            btn.classList.toggle('sigo', d.sigo);
            // Actualiza el contador de seguidores si la página lo muestra
            document.querySelectorAll('[data-seguidores-de="' + btn.dataset.id + '"]')
                .forEach(el => { el.textContent = d.seguidores; });
        } else {
            alert(d.error || 'No se pudo completar la acción.');
        }
    } catch (err) {
        alert('Error de conexión. Inténtalo de nuevo.');
    }
    btn.disabled = false;
});
(function () {
    const caja  = document.getElementById('cola-progreso');
    const barra = document.getElementById('cola-barra');
    const texto = document.getElementById('cola-texto');

    let vueltas = 0, inicial = null, encontradas = 0, acumuladas = [], panelAdmin = null, rapidaAplicada = false;

    /*  Cambiar una portada en la página sin recargar  */
    function aplicar(id, ruta) {
        const cajas = document.querySelectorAll('[data-portada-id="' + id + '"]');
        cajas.forEach(c => {
            let img = c.querySelector('img');
            const generada = c.querySelector('.portada-generada');
            if (!img) {
                img = document.createElement('img');
                img.alt = '';
                img.loading = 'lazy';
                c.insertBefore(img, c.firstChild);
            }
            if (generada) generada.remove();
            img.onerror = null;
            img.style.display = '';
            img.src = ruta;
        });
        return cajas.length > 0;
    }

    function usuarioOcupado() {
        const a = document.activeElement;
        const escribiendo = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
        return !!document.querySelector('dialog[open]') || escribiendo;
    }

    function recargarCuandoPueda(intentos) {
        const previa = Number(sessionStorage.getItem('readsColaRecarga') || 0);
        if (Date.now() - previa < 120000) return;            // nunca en bucle
        if (!usuarioOcupado()) {
            sessionStorage.setItem('readsColaRecarga', String(Date.now()));
            location.reload();
        } else if (intentos < 20) {
            setTimeout(() => recargarCuandoPueda(intentos + 1), 3000);
        }
    }

    /* Aplica de golpe todas las portadas acumuladas: aparecen a la vez */
    function aplicarTodas() {
        if (!acumuladas.length) return;
        let faltan = false;
        acumuladas.forEach(n => { if (!aplicar(n.id, n.portada)) faltan = true; });
        acumuladas = [];
        if (faltan && !caja) recargarCuandoPueda(0);
    }

    /*  Solo administrador  */
    function indicadorAdmin(d) {
        if (!d.admin) return;
        if (!panelAdmin) {
            panelAdmin = document.createElement('div');
            panelAdmin.style.cssText = 'position:fixed;right:14px;bottom:96px;z-index:999998;padding:6px 12px;' +
                'border-radius:999px;background:var(--bg-card,#fff);color:var(--text-color,#333);' +
                'border:1px solid var(--border-color,#ddd);box-shadow:0 4px 14px rgba(0,0,0,.2);' +
                'font:600 .75rem system-ui,sans-serif;opacity:.9';
            document.body.appendChild(panelAdmin);
        }
        panelAdmin.textContent = d.pendientes > 0
            ? '🖼️ Cola de portadas: ' + d.pendientes + ' pendientes · ' + encontradas + ' encontradas' + (d.pausa ? ' · en pausa' : '')
            : '🖼️ Cola terminada · ' + encontradas + ' encontradas · ' + (d.sin_portada || 0) + ' libros siguen sin portada real';
    }

    /*  Barra de progreso  */
    function pintarProgreso(d) {
        if (!caja || !barra || !texto) return;
        if (inicial === null) inicial = Math.max(1, d.pendientes + d.nuevas.length);
        const hechos = Math.max(0, inicial - d.pendientes);
        barra.style.width = Math.min(100, Math.round(hechos / inicial * 100)) + '%';
        texto.textContent = d.pendientes > 0
            ? (d.pausa ? 'Un momento: seguimos en unos minutos…' : 'Buscando portadas… quedan ' + d.pendientes + ' libros.')
            : '¡Listo! Tus portadas ya están en tu estantería.';
        if (d.pendientes === 0) barra.style.width = '100%';
    }

    async function paso() {
        if (++vueltas > 600) { aplicarTodas(); return; }
        let d;
        try {
            const r = await fetch('portadas_cola.php', { credentials: 'same-origin', cache: 'no-store' });
            if (!r.ok) return;
            d = await r.json();
        } catch (e) { return; }

        const nuevas = d.nuevas || [];
        d.nuevas = nuevas;
        encontradas += nuevas.length;
        acumuladas.push(...nuevas);
        pintarProgreso(d);
        indicadorAdmin(d);

        if (!rapidaAplicada && !d.rapida) { rapidaAplicada = true; aplicarTodas(); }
        else if (d.pendientes === 0 || d.pausa) aplicarTodas();

        if (d.pendientes > 0) setTimeout(paso, d.pausa ? 60000 : (d.rapida ? 400 : 1200));
    }

    window.addEventListener('load', () => setTimeout(paso, 600));
})();
<?php
$pgCsrf = $_SESSION['csrf_progreso'] ?? '';
?>
<style>
.pg-overlay { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(30, 20, 60, .55); backdrop-filter: blur(3px); }
.pg-overlay[hidden], .pg-overlay [hidden] { display: none !important; }

.pg-card { --pg-acento: var(--color-primario, var(--primary-color, #7c4dcc));
    position: relative; width: 100%; max-width: 440px; max-height: 92vh; overflow-y: auto;
    background: var(--card-bg, #fff); color: var(--text-color, #2b2140);
    border-radius: 22px; padding: 24px 22px 22px; box-shadow: 0 24px 60px rgba(40, 20, 90, .35);
    animation: pgEntrar .18s ease-out; }
@keyframes pgEntrar { from { transform: translateY(14px) scale(.98); opacity: 0; } to { transform: none; opacity: 1; } }

.pg-cerrar { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: 0; border-radius: 50%;
    background: rgba(124, 77, 204, .1); color: inherit; font-size: 1rem; cursor: pointer; }
.pg-cerrar:hover { background: rgba(124, 77, 204, .2); }

.pg-cabecera { display: flex; gap: 14px; align-items: center; margin-bottom: 18px; padding-right: 30px; }
.pg-portada { width: 56px; height: 82px; object-fit: cover; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.2); flex-shrink: 0; }
.pg-cabecera h3 { margin: 0 0 2px; font-size: 1.05rem; line-height: 1.25; }
.pg-cabecera p { margin: 0; font-size: .82rem; opacity: .7; }

.pg-cargando { text-align: center; padding: 26px 0; font-size: .9rem; opacity: .75; }

/* Selector Digital / Físico */
.pg-modos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 18px;
    border-radius: 14px; background: rgba(124, 77, 204, .1); }
.pg-modo { border: 0; border-radius: 11px; padding: 9px 6px; background: transparent; color: inherit;
    font-weight: 800; font-size: .88rem; cursor: pointer; line-height: 1.2; }
.pg-modo small { display: block; font-weight: 600; font-size: .7rem; opacity: .7; }
.pg-modo.activo { background: var(--pg-acento); color: #fff; box-shadow: 0 3px 10px rgba(124, 77, 204, .35); }
.pg-modo.activo small { opacity: .9; }

.pg-grande { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 10px; }
.pg-grande span { font-size: 2.6rem; font-weight: 800; color: var(--pg-acento); line-height: 1; }
.pg-grande small { font-size: .85rem; opacity: .75; font-weight: 600; }

#pgRange { --pg-fill: 0%; -webkit-appearance: none; appearance: none; width: 100%; height: 14px; border-radius: 999px;
    outline: none; cursor: pointer; margin: 4px 0 14px;
    background: linear-gradient(to right, var(--pg-acento) var(--pg-fill), rgba(124, 77, 204, .15) var(--pg-fill)); }
#pgRange:disabled { opacity: .4; cursor: not-allowed; }
#pgRange::-webkit-slider-thumb { -webkit-appearance: none; width: 28px; height: 28px; border-radius: 50%;
    background: #fff; border: 4px solid var(--pg-acento); box-shadow: 0 2px 8px rgba(0,0,0,.25); }
#pgRange::-moz-range-thumb { width: 20px; height: 20px; border-radius: 50%;
    background: #fff; border: 4px solid var(--pg-acento); box-shadow: 0 2px 8px rgba(0,0,0,.25); }

.pg-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.pg-chips button { flex: 1; min-width: 60px; padding: 8px 6px; border: 1.5px solid rgba(124, 77, 204, .3); border-radius: 12px;
    background: transparent; color: inherit; font-weight: 700; font-size: .85rem; cursor: pointer; }
.pg-chips button:hover { background: rgba(124, 77, 204, .1); }

.pg-campos { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.pg-campos.solo { grid-template-columns: 1fr; }
.pg-campos label { display: flex; flex-direction: column; gap: 6px; font-size: .8rem; font-weight: 700; }
.pg-campos label > span { display: flex; align-items: center; gap: 6px; font-weight: 600; }
.pg-campos input[type=number] { width: 100%; min-width: 0; padding: 10px 12px; font-size: 1.05rem; font-weight: 700;
    border: 1.5px solid rgba(124, 77, 204, .3); border-radius: 12px; background: transparent; color: inherit; }
.pg-campos input[type=number]:focus { outline: none; border-color: var(--pg-acento); box-shadow: 0 0 0 3px rgba(124, 77, 204, .15); }
.pg-campos em { font-style: normal; font-size: .85rem; opacity: .75; white-space: nowrap; }

.pg-ayuda { grid-column: 1 / -1; margin: 0; padding: 9px 12px; border-radius: 10px; background: rgba(245, 179, 1, .15); font-size: .8rem; }
.pg-ayuda a { font-weight: 700; color: var(--pg-acento); }
.pg-nota { margin: -4px 0 14px; font-size: .78rem; opacity: .7; }

.pg-error { margin: 0 0 12px; padding: 9px 12px; border-radius: 10px; background: rgba(192, 57, 43, .12); color: #c0392b; font-size: .85rem; font-weight: 600; }

.pg-acciones { display: flex; flex-direction: column; gap: 10px; }
.pg-btn { width: 100%; padding: 13px 16px; border: 0; border-radius: 14px; font-size: .95rem; font-weight: 800; cursor: pointer; }
.pg-btn[disabled] { opacity: .6; cursor: wait; }
.pg-primario { background: var(--pg-acento); color: #fff; }
.pg-sec { background: transparent; color: inherit; border: 1.5px solid rgba(124, 77, 204, .35); }
.pg-sec:hover { background: rgba(124, 77, 204, .08); }
</style>

<div class="pg-overlay" id="pgModal" hidden>
    <div class="pg-card" role="dialog" aria-modal="true" aria-labelledby="pgTitulo">
        <button type="button" class="pg-cerrar" id="pgCerrar" aria-label="Cerrar">✕</button>

        <div class="pg-cabecera">
            <img id="pgPortada" class="pg-portada" alt="" hidden>
            <div>
                <h3 id="pgTitulo">Tu progreso</h3>
                <p id="pgAutor"></p>
            </div>
        </div>

        <div class="pg-cargando" id="pgCargando">📖 Cargando…</div>

        <div id="pgCuerpo" hidden>

            <!-- ¿Cómo lo estás leyendo? -->
            <div class="pg-modos" role="tablist" aria-label="Formato de lectura">
                <button type="button" class="pg-modo" data-modo="digital" role="tab">📱 Digital<small>lo marco en %</small></button>
                <button type="button" class="pg-modo" data-modo="fisico" role="tab">📖 Físico<small>por páginas</small></button>
            </div>

            <div class="pg-grande">
                <span id="pgPctGrande">0%</span>
                <small id="pgPagsTexto"></small>
            </div>

            <input type="range" id="pgRange" min="0" max="100" step="1" value="0" aria-label="Progreso de lectura">

            <div class="pg-chips" id="pgChips"></div>

            <!-- Digital: porcentaje -->
            <div class="pg-campos solo" id="pgPanelDigital">
                <label>Porcentaje que marca tu lector
                    <span><input type="number" id="pgPct" min="0" max="100" inputmode="numeric"> <em>%</em></span>
                </label>
            </div>

            <!-- Físico: página actual y total del libro -->
            <div class="pg-campos" id="pgPanelFisico" hidden>
                <label>Página por la que vas
                    <span><input type="number" id="pgLeidas" min="0" inputmode="numeric" placeholder="0"></span>
                </label>
                <label>Páginas del libro
                    <span><input type="number" id="pgTotal" min="1" inputmode="numeric" placeholder="Total"></span>
                </label>
                <p class="pg-ayuda" id="pgAyudaTotal" hidden></p>
            </div>

            <p class="pg-error" id="pgError" hidden></p>

            <div class="pg-acciones">
                <button type="button" class="pg-btn pg-primario" id="pgGuardar">Guardar progreso</button>
                <button type="button" class="pg-btn pg-sec" id="pgTerminar">✅ Lo he terminado</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const CSRF = <?= json_encode($pgCsrf) ?>;
    const $ = id => document.getElementById(id);
    const modal = $('pgModal');
    if (!modal) return;

    // Estado: { id, mode: 'digital'|'fisico', total, leidas, pct, totalTocado, recargar }
    let S = null;

    const clamp = (n, a, b) => Math.min(b, Math.max(a, n));
    const num = v => { const n = parseInt(v, 10); return isNaN(n) ? 0 : n; };

    // Se recuerda el formato elegido para cada libro (solo en este navegador)
    const leerPref = id => { try { return localStorage.getItem('pg_modo_' + id); } catch (e) { return null; } };
    const guardarPref = (id, m) => { try { localStorage.setItem('pg_modo_' + id, m); } catch (e) {} };

    function mostrarError(msg) {
        const e = $('pgError');
        e.textContent = msg || '';
        e.hidden = !msg;
    }

    /*  Cálculos  */
    function recalcDesdePaginas() {
        S.pct = S.total > 0 ? clamp(Math.round(S.leidas / S.total * 100), 0, 100) : 0;
    }
    function ponerPorcentaje(p) {
        S.pct = clamp(p, 0, 100);
        if (S.total > 0) S.leidas = Math.round(S.total * S.pct / 100);
    }
    function ponerPaginas(n) {
        n = Math.max(0, n);
        if (S.total > 0) n = Math.min(n, S.total);
        S.leidas = n;
        recalcDesdePaginas();
    }

    /*  Dibujo  */
    function pintar(omitir) {
        const fis = S.mode === 'fisico';

        $('pgPctGrande').textContent = S.pct + '%';
        const r = $('pgRange');
        r.value = S.pct;
        r.style.setProperty('--pg-fill', S.pct + '%');
        r.disabled = fis && S.total <= 0;

        if (omitir !== 'pct')    $('pgPct').value = S.pct;
        if (omitir !== 'leidas') $('pgLeidas').value = S.leidas;
        if (omitir !== 'total')  $('pgTotal').value = S.total > 0 ? S.total : '';

        if (S.total > 0)              $('pgPagsTexto').textContent = S.leidas + ' de ' + S.total + ' páginas';
        else if (fis && S.leidas > 0) $('pgPagsTexto').textContent = 'Página ' + S.leidas;
        else                          $('pgPagsTexto').textContent = '';
    }

    function crearChips() {
        const fis = S.mode === 'fisico';
        const cont = $('pgChips');
        cont.innerHTML = '';
        (fis ? [-10, 10, 25, 50] : [-5, 5, 10, 25]).forEach(p => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = (p > 0 ? '+' : '') + p + (fis ? ' págs' : '%');
            b.addEventListener('click', () => {
                if (fis) ponerPaginas(S.leidas + p); else ponerPorcentaje(S.pct + p);
                pintar();
            });
            cont.appendChild(b);
        });
    }

    function ayudaTotal(estado) {
        const a = $('pgAyudaTotal');
        a.textContent = '';
        if (estado === 'buscando') {
            a.textContent = '🔎 Buscando cuántas páginas tiene… también puedes escribirlas tú.';
            a.hidden = false;
        } else if (estado === 'no') {
            a.appendChild(document.createTextNode('No hemos encontrado cuántas páginas tiene: escríbelas tú (las verás en la contraportada o en el último número del libro). '));
            const l = document.createElement('a');
            l.href = '#';
            l.textContent = 'Buscar de nuevo';
            l.addEventListener('click', ev => {
                ev.preventDefault();
                ayudaTotal('buscando');
                buscarPaginas(S.id, true);
            });
            a.appendChild(l);
            a.hidden = false;
        } else {
            a.hidden = true;
        }
    }

    function ponerModo(m, guardar) {
        S.mode = m;
        $('pgPanelDigital').hidden = m !== 'digital';
        $('pgPanelFisico').hidden  = m !== 'fisico';
        document.querySelectorAll('#pgModal .pg-modo').forEach(b => {
            const activo = b.dataset.modo === m;
            b.classList.toggle('activo', activo);
            b.setAttribute('aria-selected', activo ? 'true' : 'false');
        });

        // Al pasar a físico, si solo había porcentaje, se calcula la página
        if (m === 'fisico' && S.total > 0 && S.leidas <= 0 && S.pct > 0) {
            S.leidas = Math.round(S.total * S.pct / 100);
        }

        if (guardar) guardarPref(S.id, m);
        mostrarError('');
        crearChips();
        pintar();
    }

    /*  Abrir / cerrar  */
    function abrir(trigger) {
        const id = trigger.dataset.id;
        if (!id) return;

        S = { id: id, mode: 'digital', total: 0, leidas: 0, pct: 0, totalTocado: false, recargar: trigger.dataset.recargar === '1' };

        $('pgTitulo').textContent = 'Tu progreso';
        $('pgAutor').textContent = '';
        $('pgPortada').hidden = true;
        $('pgCuerpo').hidden = true;
        $('pgCargando').hidden = false;
        $('pgCargando').textContent = '📖 Cargando…';
        mostrarError('');

        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        fetch('api_progreso.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { $('pgCargando').textContent = d.error || 'No se pudo cargar el libro.'; return; }
                if (!S || String(S.id) !== String(id)) return;

                S.total  = d.paginas_totales || 0;
                S.leidas = d.paginas_leidas || 0;
                S.pct    = S.total > 0 ? clamp(Math.round(S.leidas / S.total * 100), 0, 100) : (d.progreso || 0);

                $('pgTitulo').textContent = d.titulo;
                $('pgAutor').textContent = d.autores || '';
                if (d.portada) {
                    $('pgPortada').src = d.portada;
                    $('pgPortada').onerror = () => { $('pgPortada').hidden = true; };
                    $('pgPortada').hidden = false;
                }

                // Formato: el último que usó con este libro; si no, físico cuando ya se conocen las páginas
                const pref = leerPref(id);
                const modo = (pref === 'digital' || pref === 'fisico') ? pref : (S.total > 0 ? 'fisico' : 'digital');

                $('pgCargando').hidden = true;
                $('pgCuerpo').hidden = false;
                ponerModo(modo, false);

                // El recuadro se abre al instante; las páginas totales se buscan en segundo plano
                if (S.total > 0) {
                    ayudaTotal('');
                } else if (d.buscar) {
                    ayudaTotal('buscando');
                    buscarPaginas(S.id, false);
                } else {
                    ayudaTotal('no');
                }
            })
            .catch(() => { $('pgCargando').textContent = 'No se pudo conectar. Inténtalo de nuevo.'; });
    }

    function buscarPaginas(id, forzar) {
        fetch('api_progreso.php?accion=buscar&id=' + encodeURIComponent(id) + (forzar ? '&forzar=1' : ''), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!S || String(S.id) !== String(id)) return;   // se cerró o se abrió otro libro
                if (d.ok && d.paginas_totales > 0) {
                    if (!S.totalTocado) {
                        S.total = d.paginas_totales;
                        if (S.mode === 'fisico' && S.leidas > 0) recalcDesdePaginas();
                        else S.leidas = Math.round(S.total * S.pct / 100);
                    }
                    ayudaTotal('');
                    pintar();
                } else if (S.total <= 0) {
                    ayudaTotal('no');
                }
            })
            .catch(() => { if (S && S.total <= 0) ayudaTotal('no'); });
    }

    function cerrar() {
        modal.hidden = true;
        document.body.style.overflow = '';
        S = null;
    }

    /*  Guardar  */
    function actualizarTarjetas(d) {
        document.querySelectorAll('[data-card="' + d.id + '"]').forEach(c => {
            const p = c.querySelector('.js-pct');  if (p) p.textContent = d.progreso + '%';
            const f = c.querySelector('.js-fill'); if (f) f.style.width = d.progreso + '%';
            const g = c.querySelector('.js-pags'); if (g) g.textContent = d.paginas_totales > 0 ? d.paginas_leidas + ' / ' + d.paginas_totales + ' págs.' : '';
        });
        document.dispatchEvent(new CustomEvent('progreso:guardado', { detail: d }));
    }

    function enviar(accion) {
        mostrarError('');
        const body = new URLSearchParams({ csrf: CSRF, id: S.id, accion: accion });

        if (accion === 'guardar') {
            if (S.mode === 'fisico') {
                if (S.total <= 0) { mostrarError('Indica cuántas páginas tiene el libro para calcular tu porcentaje.'); return; }
                body.set('modo', 'paginas');
                body.set('valor', Math.min(S.leidas, S.total));
                body.set('paginas_totales', S.total);
            } else {
                body.set('modo', 'porcentaje');
                body.set('valor', S.pct);
            }
            guardarPref(S.id, S.mode);
        }

        const btnG = $('pgGuardar'), btnT = $('pgTerminar');
        btnG.disabled = btnT.disabled = true;

        fetch('api_progreso.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { mostrarError(d.error || 'No se pudo guardar.'); btnG.disabled = btnT.disabled = false; return; }

                actualizarTarjetas(d);

                if (accion === 'terminar' || S.recargar) { location.reload(); return; }

                btnG.textContent = '✅ Guardado';
                setTimeout(() => { btnG.textContent = 'Guardar progreso'; btnG.disabled = btnT.disabled = false; cerrar(); }, 650);
            })
            .catch(() => { mostrarError('No se pudo conectar. Inténtalo de nuevo.'); btnG.disabled = btnT.disabled = false; });
    }

    /*  Eventos  */
    document.addEventListener('click', e => {
        const t = e.target.closest('.js-editar-progreso');
        if (t) { e.preventDefault(); abrir(t); }
    });

    $('pgCerrar').addEventListener('click', cerrar);
    modal.addEventListener('click', e => { if (e.target === modal) cerrar(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) cerrar(); });

    document.querySelectorAll('#pgModal .pg-modo').forEach(b => {
        b.addEventListener('click', () => { if (S) ponerModo(b.dataset.modo, true); });
    });

    $('pgRange').addEventListener('input', e => { ponerPorcentaje(num(e.target.value)); pintar(); });
    $('pgPct').addEventListener('input', e => { ponerPorcentaje(num(e.target.value)); pintar('pct'); });
    $('pgLeidas').addEventListener('input', e => { ponerPaginas(num(e.target.value)); pintar('leidas'); });

    // Total del libro: se puede corregir a mano (no se recorta la página mientras se teclea)
    $('pgTotal').addEventListener('input', e => {
        S.totalTocado = true;
        S.total = Math.max(0, num(e.target.value));
        recalcDesdePaginas();
        if (S.total > 0) ayudaTotal('');
        pintar('total');
    });
    $('pgTotal').addEventListener('change', () => {
        if (S.total > 0 && S.leidas > S.total) S.leidas = S.total;
        recalcDesdePaginas();
        pintar();
    });

    $('pgGuardar').addEventListener('click', () => enviar('guardar'));
    $('pgTerminar').addEventListener('click', () => {
        if (confirm('¿Marcar este libro como leído?')) enviar('terminar');
    });
})();
</script>
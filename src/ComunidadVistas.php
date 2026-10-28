<?php

require_once __DIR__ . '/AvatarHelper.php';
function cmH($t): string
{
    return htmlspecialchars((string)($t ?? ''), ENT_QUOTES, 'UTF-8');
}

function cmAvatar(string $nombre, string $clase = '', ?string $avatar = null): string
{
    return AvatarHelper::render($nombre, $avatar, $clase);
}

function cmPortada(array $l): string
{
    $p = (string)($l['portada'] ?? '');
    if (function_exists('obtenerPortadaValida') && isset($l['id'])) {
        return (string)obtenerPortadaValida($p, (int)$l['id']);
    }
    return $p;
}

function cmBotonSeguir(int $id, bool $sigo, string $csrf): string
{
    return '<button type="button" class="cm-btn cm-seguir ' . ($sigo ? 'sigo' : '') . '"'
         . ' data-id="' . $id . '" data-sigo="' . ($sigo ? '1' : '0') . '" data-csrf="' . cmH($csrf) . '">'
         . ($sigo ? '✓ Siguiendo' : '➕ Seguir') . '</button>';
}

function cmFilaUsuario(array $u, string $csrf): string
{
    $id = (int)$u['id'];
    $detalle = [];
    if (!empty($u['en_comun'])) $detalle[] = '📚 ' . (int)$u['en_comun'] . ' en común';
    if (isset($u['leidos']))    $detalle[] = (int)$u['leidos'] . ' leídos';
    if (!empty($u['leyendo']))  $detalle[] = '📖 leyendo ' . (int)$u['leyendo'];

    return '<li class="cm-fila">'
         . cmAvatar((string)$u['nombre'], '', $u['avatar'] ?? null)
         . '<div class="cm-datos"><a class="cm-nombre" href="usuario.php?id=' . $id . '">' . cmH($u['nombre']) . '</a>'
         . '<small>' . cmH(implode(' · ', $detalle)) . '</small></div>'
         . cmBotonSeguir($id, !empty($u['sigo']), $csrf)
         . '</li>';
}

function cmTarjetaLeyendo(array $l): string
{
    $portada = cmPortada($l);
    $pct = (int)($l['pct'] ?? 0);

    $img = $portada !== ''
        ? '<img src="' . cmH($portada) . '" alt="" loading="lazy" onerror="this.remove()">'
        : '';

    return '<a class="cm-leyendo" href="libro.php?id=' . urlencode((string)($l['libro_id'] ?? $l['id'])) . '" title="' . cmH($l['titulo']) . '">'
         . '<div class="portada">' . $img . '</div>'
         . '<div class="info">'
         .   '<span class="titulo">' . cmH($l['titulo']) . '</span>'
         .   '<span class="quien">' . cmAvatar((string)$l['nombre'], 'sm', $l['avatar'] ?? null) . cmH($l['nombre']) . '</span>'
         .   '<small>' . $pct . '%</small>'
         .   '<div class="cm-barra"><i style="width:' . $pct . '%"></i></div>'
         . '</div></a>';
}

function cmAnillo(int $pct, string $centro, string $pie = ''): string
{
    $pct = max(0, min(100, $pct));
    $c   = 2 * M_PI * 42;
    $off = $c * (1 - $pct / 100);

    return '<div class="cm-anillo">'
         . '<svg viewBox="0 0 100 100" aria-hidden="true">'
         .   '<circle cx="50" cy="50" r="42" class="fondo"/>'
         .   '<circle cx="50" cy="50" r="42" class="valor" style="stroke-dasharray:' . round($c, 2) . ';stroke-dashoffset:' . round($off, 2) . '"/>'
         . '</svg>'
         . '<div class="centro"><strong>' . cmH($centro) . '</strong>' . ($pie !== '' ? '<small>' . cmH($pie) . '</small>' : '') . '</div>'
         . '</div>';
}

function cmFraseGrupo(string $estado, int $n, ?string $tituloHtml): string
{
    if ($n === 1 && $tituloHtml !== null) {
        return [
            'leido'      => 'terminó de leer ' . $tituloHtml,
            'leyendo'    => 'está leyendo ' . $tituloHtml,
            'tbr'        => 'quiere leer ' . $tituloHtml,
            'guardado'   => 'añadió ' . $tituloHtml . ' a su wishlist',
            'abandonado' => 'abandonó ' . $tituloHtml,
        ][$estado] ?? 'añadió ' . $tituloHtml;
    }
    $libros = '<strong>' . $n . ' libros</strong>';
    return [
        'leido'      => 'terminó de leer ' . $libros,
        'leyendo'    => 'está leyendo ' . $libros,
        'tbr'        => 'quiere leer ' . $libros,
        'guardado'   => 'añadió ' . $libros . ' a su wishlist',
        'abandonado' => 'abandonó ' . $libros,
    ][$estado] ?? 'añadió ' . $libros;
}

function cmItemGrupo(array $g, int $maxPortadas = 6): string
{
    $libros = $g['libros'];
    $n = count($libros);

    $titulo = null;
    if ($n === 1) {
        $titulo = '<a class="cm-titulo-inline" href="libro.php?id=' . urlencode((string)$libros[0]['libro_id']) . '">' . cmH($libros[0]['titulo']) . '</a>';
    }

    $portadas = '';
    foreach (array_slice($libros, 0, $maxPortadas) as $l) {
        $src = cmPortada($l);
        $portadas .= '<a href="libro.php?id=' . urlencode((string)$l['libro_id']) . '" title="' . cmH($l['titulo']) . '">'
                   . ($src !== ''
                        ? '<img class="cm-mini" src="' . cmH($src) . '" alt="" loading="lazy" onerror="this.remove()">'
                        : '<span class="cm-mini"></span>')
                   . '</a>';
    }
    if ($n > $maxPortadas) {
        $portadas .= '<span class="cm-mas">+' . ($n - $maxPortadas) . '</span>';
    }

    return '<li class="cm-grupo">'
         . cmAvatar((string)$g['nombre'], 'sm', $g['avatar'] ?? null)
         . '<div class="cm-datos">'
         .   '<span><a class="cm-nombre-inline" href="usuario.php?id=' . (int)$g['uid'] . '">' . cmH($g['nombre']) . '</a> '
         .   cmFraseGrupo((string)$g['estado'], $n, $titulo) . '</span>'
         .   '<div class="cm-portadas">' . $portadas . '</div>'
         . '</div></li>';
}

function cmGradienteTema(string $tema): string
{
    return AvatarHelper::gradienteTema($tema);
}
<?php
/**
 * admin/estadisticas.php
 * Panel de estadísticas para administradores (grupo ADMINISTRACIÓN o
 * superadmin): totales y desgloses de TODOS los partes de trabajo, en TODOS
 * los estados (borrador, validado, en proceso, terminado, cancelado),
 * facturados/no facturados, firmados/sin firmar, por tipo de parte y por
 * técnico, más una evolución mensual — independientemente del filtro de
 * "estados activos" que aplica el listado normal de los técnicos.
 */

// ─── Bootstrap Dolibarr ─────────────────────────────────────────────────────
$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 6 && !$res; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) {
        $res = @include $dir.'/main.inc.php';
    }
}
if (!$res) die('Imposible encontrar main.inc.php. __DIR__: '.__DIR__);

$userId = (int)$user->id;

// ─── Acceso: solo grupo ADMINISTRACIÓN o superadmin ───────────────────────────
$esAdmin = !empty($user->admin);
if (!$esAdmin) {
    $sqlGrp  = "SELECT g.rowid FROM ".MAIN_DB_PREFIX."usergroup g";
    $sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
    $sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
    $sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
    $resGrp = $db->query($sqlGrp);
    if ($resGrp && $db->num_rows($resGrp) > 0) $esAdmin = true;
}
if (!$esAdmin) {
    accessforbidden();
}

require_once __DIR__.'/../inc/config_grupos.php';
require_once __DIR__.'/../inc/helpers_horas.php';

// ─── Filtros (todos por GET; vacío = sin filtrar) ─────────────────────────────
// Fechas: por defecto, todo el año en curso.
$fechaDesde = GETPOST('fecha_desde', 'alpha');
$fechaHasta = GETPOST('fecha_hasta', 'alpha');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaDesde)) $fechaDesde = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaHasta)) $fechaHasta = date('Y-m-d');

$fTecnico = (string) GETPOST('tecnico', 'alpha');           // '' | 'none' (sin asignar) | id de usuario
if ($fTecnico !== 'none' && !ctype_digit($fTecnico)) $fTecnico = '';
$fTipo    = (string) GETPOST('tipo', 'alpha');              // '' | 1..4
if (!in_array($fTipo, array('1', '2', '3', '4'), true)) $fTipo = '';
$fEstado  = (string) GETPOST('estado', 'alpha');            // '' | -1..3
if (!in_array($fEstado, array('-1', '0', '1', '2', '3'), true)) $fEstado = '';
$fFact    = (string) GETPOST('facturado', 'alpha');         // '' | si | no
if (!in_array($fFact, array('si', 'no'), true)) $fFact = '';
$fFirma   = (string) GETPOST('firmado', 'alpha');           // '' | si | no
if (!in_array($fFirma, array('si', 'no'), true)) $fFirma = '';
$fBuscar  = trim((string) GETPOST('q', 'alphanohtml'));     // referencia, ref. cliente o nombre del cliente

$sqlFirmado = "((ef.firma_cliente IS NOT NULL AND ef.firma_cliente != '')"
            . " OR (ef.firma_tecnico IS NOT NULL AND ef.firma_tecnico != ''))";
$sqlTecAsig = "SELECT 1 FROM ".MAIN_DB_PREFIX."actioncomm acx"
            . " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acrx"
            . "         ON acrx.fk_actioncomm = acx.id AND acrx.element_type = 'user'"
            . " WHERE acx.fk_element = c.rowid AND acx.elementtype = 'order'";

$whereBase  = " c.entity IN (".getEntity('commande').")";
$whereBase .= " AND c.date_commande BETWEEN '".$db->escape($fechaDesde)." 00:00:00' AND '".$db->escape($fechaHasta)." 23:59:59'";
if ($fTecnico === 'none')     $whereBase .= " AND NOT EXISTS (".$sqlTecAsig.")";
elseif ($fTecnico !== '')     $whereBase .= " AND EXISTS (".$sqlTecAsig." AND acrx.fk_element = ".(int) $fTecnico.")";
if ($fTipo !== '')            $whereBase .= " AND ef.tipo_albaran = ".(int) $fTipo;
if ($fEstado !== '')          $whereBase .= " AND c.fk_statut = ".(int) $fEstado;
if ($fFact === 'si')          $whereBase .= " AND c.facture = 1";
if ($fFact === 'no')          $whereBase .= " AND (c.facture IS NULL OR c.facture = 0)";
if ($fFirma === 'si')         $whereBase .= " AND ".$sqlFirmado;
if ($fFirma === 'no')         $whereBase .= " AND NOT ".$sqlFirmado;
if ($fBuscar !== '') {
    $qEsc = $db->escape($fBuscar);
    $whereBase .= " AND (c.ref LIKE '%".$qEsc."%' OR c.ref_client LIKE '%".$qEsc."%' OR s.nom LIKE '%".$qEsc."%')";
}

// FROM común (las firmas NO se descargan, solo se comprueba si existen: son
// imágenes base64 pesadas).
$sqlFrom  = " FROM ".MAIN_DB_PREFIX."commande c";
$sqlFrom .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
$sqlFrom .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sqlFrom .= " WHERE ".$whereBase;

$hayFiltros = ($fTecnico !== '' || $fTipo !== '' || $fEstado !== '' || $fFact !== '' || $fFirma !== '' || $fBuscar !== '');

// ─── Mapas de referencia (mismos colores/etiquetas que en index.php) ──────────
$statusLabels = array(
   -1 => array('label' => 'Cancelado',  'color' => '#DC2626', 'icon' => '🔴'),
    0 => array('label' => 'Borrador',   'color' => '#94A3B8', 'icon' => '⚪'),
    1 => array('label' => 'Validado',   'color' => '#2563EB', 'icon' => '🔵'),
    2 => array('label' => 'En proceso', 'color' => '#16A34A', 'icon' => '🟢'),
    3 => array('label' => 'Terminado',  'color' => '#7C3AED', 'icon' => '🟣'),
);
$tipo_labels_default = array(1 => 'Parte de trabajo', 2 => 'Puesta en marcha', 3 => 'Avería', 4 => 'Mantenimiento');
$tipoInfo = array(
    1 => array('label' => $conf->global->PARTES_TIPO_LABEL_1 ?? $tipo_labels_default[1], 'color' => '#2563EB', 'icon' => '🔧'),
    2 => array('label' => $conf->global->PARTES_TIPO_LABEL_2 ?? $tipo_labels_default[2], 'color' => '#16A34A', 'icon' => '▶️'),
    3 => array('label' => $conf->global->PARTES_TIPO_LABEL_3 ?? $tipo_labels_default[3], 'color' => '#DC2626', 'icon' => '⚠️'),
    4 => array('label' => $conf->global->PARTES_TIPO_LABEL_4 ?? $tipo_labels_default[4], 'color' => '#D97706', 'icon' => '🛠️'),
);

// ─── Técnicos del grupo "SERVICIO TÉCNICO" (para el desplegable y la tabla) ───
$tecnicosGrupo = array(); // id => nombre
$sqlUsr  = "SELECT u.rowid, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."user u";
$sqlUsr .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu";
$sqlUsr .= "         ON gu.fk_user = u.rowid AND gu.fk_usergroup = ".(int) PT_GRUPO_SERVICIO_TECNICO_ID;
$sqlUsr .= " WHERE u.entity IN (".getEntity('user').")";
$sqlUsr .= " ORDER BY u.lastname ASC, u.firstname ASC";
$resUsr = $db->query($sqlUsr);
if ($resUsr) {
    while ($u = $db->fetch_object($resUsr)) {
        $tecnicosGrupo[(int) $u->rowid] = trim($u->firstname.' '.$u->lastname) ?: $u->login;
    }
    $db->free($resUsr);
}

// ─── Una sola lectura de los partes filtrados; todo se agrega en PHP ──────────
$sqlPartes  = "SELECT c.rowid, c.fk_statut, c.facture, c.fk_soc, s.nom AS soc_nom,";
$sqlPartes .= "  UNIX_TIMESTAMP(c.date_commande) AS ts, ef.tipo_albaran,";
$sqlPartes .= "  ef.horadeinicio, ef.horadefinalizacion,";
$sqlPartes .= "  CASE WHEN ".$sqlFirmado." THEN 1 ELSE 0 END AS firmado,";
$sqlPartes .= "  (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."actioncomm aca";
$sqlPartes .= "     INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acra";
$sqlPartes .= "             ON acra.fk_actioncomm = aca.id AND acra.element_type = 'user'";
$sqlPartes .= "    WHERE aca.fk_element = c.rowid AND aca.elementtype = 'order') AS n_asignados";
$sqlPartes .= $sqlFrom;
$resPartes = $db->query($sqlPartes);

$totalPartes = 0;
$porEstado   = array(-1 => 0, 0 => 0, 1 => 0, 2 => 0, 3 => 0);
$facturados = $firmados = $terminadosSinFacturar = $sinTecnico = 0;
$horasTotales = 0.0; $horasN = 0;
$porTipo = array();    // tipo => [n, term, horas]
$porCliente = array(); // fk_soc => [nombre, n, term, pend, fact, horas]
$porMes = array();     // 'YYYY-MM' => [n, term, horas]
$edadPend = array('0-7' => 0, '8-30' => 0, '31-90' => 0, '+90' => 0); // pendientes por antigüedad
$pendMas30 = 0;
$infoParte = array();  // rowid => datos mínimos para el desglose por técnico
$ahora = time();

if ($resPartes) {
    while ($p = $db->fetch_object($resPartes)) {
        $st = (int) $p->fk_statut;
        $esTerm = ($st === 3);
        $esPend = in_array($st, array(0, 1, 2), true);
        $horas  = pt_horas_entre($p->horadeinicio, $p->horadefinalizacion);
        $tipo   = (int) $p->tipo_albaran;
        $ym     = $p->ts ? date('Y-m', (int) $p->ts) : '';

        $totalPartes++;
        $porEstado[$st] = ($porEstado[$st] ?? 0) + 1;
        if (!empty($p->facture)) $facturados++;
        if ($p->firmado)         $firmados++;
        if ($esTerm && empty($p->facture)) $terminadosSinFacturar++;
        if ($esPend && (int) $p->n_asignados === 0) $sinTecnico++;
        if ($horas !== null) { $horasTotales += $horas; $horasN++; }

        if (!isset($porTipo[$tipo])) $porTipo[$tipo] = array('n' => 0, 'term' => 0, 'horas' => 0.0);
        $porTipo[$tipo]['n']++;
        if ($esTerm) $porTipo[$tipo]['term']++;
        if ($horas !== null) $porTipo[$tipo]['horas'] += $horas;

        $sid = (int) $p->fk_soc;
        if (!isset($porCliente[$sid])) $porCliente[$sid] = array('nombre' => $p->soc_nom, 'n' => 0, 'term' => 0, 'pend' => 0, 'fact' => 0, 'horas' => 0.0);
        $porCliente[$sid]['n']++;
        if ($esTerm) $porCliente[$sid]['term']++;
        if ($esPend) $porCliente[$sid]['pend']++;
        if (!empty($p->facture)) $porCliente[$sid]['fact']++;
        if ($horas !== null) $porCliente[$sid]['horas'] += $horas;

        if ($ym !== '') {
            if (!isset($porMes[$ym])) $porMes[$ym] = array('n' => 0, 'term' => 0, 'horas' => 0.0);
            $porMes[$ym]['n']++;
            if ($esTerm) $porMes[$ym]['term']++;
            if ($horas !== null) $porMes[$ym]['horas'] += $horas;
        }

        if ($esPend && $p->ts) {
            $dias = (int) floor(($ahora - (int) $p->ts) / 86400);
            if ($dias > 30) $pendMas30++;
            if ($dias <= 7)       $edadPend['0-7']++;
            elseif ($dias <= 30)  $edadPend['8-30']++;
            elseif ($dias <= 90)  $edadPend['31-90']++;
            else                  $edadPend['+90']++;
        }

        $infoParte[(int) $p->rowid] = array('st' => $st, 'fact' => !empty($p->facture), 'firmado' => (bool) $p->firmado, 'horas' => $horas);
    }
    $db->free($resPartes);
} else {
    dol_print_error($db);
}
ksort($porMes);
uasort($porCliente, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcasecmp($a['nombre'], $b['nombre']); });
$maxMes = 0;
foreach ($porMes as $m) $maxMes = max($maxMes, $m['n']);

// ─── Desglose por técnico (SOLO grupo "SERVICIO TÉCNICO") ─────────────────────
// Se parte de los USUARIOS del grupo para que un técnico sin partes en el
// rango/filtros también aparezca (a cero). Con el filtro de técnico activo solo
// se muestra ese técnico. Un mismo parte cuenta para todos sus técnicos.
$porTecnico = array();
foreach ($tecnicosGrupo as $uid => $nombre) {
    if (ctype_digit($fTecnico) && (int) $fTecnico !== $uid) continue;
    if ($fTecnico === 'none') continue; // "sin asignar" no tiene técnicos que mostrar
    $porTecnico[$uid] = array('id' => $uid, 'nombre' => $nombre, 'total' => 0, 'terminados' => 0, 'pendientes' => 0,
        'cancelados' => 0, 'facturados' => 0, 'firmados' => 0, 'horas_totales' => 0.0, 'horas_n' => 0);
}
if ($porTecnico && $infoParte) {
    $sqlMap  = "SELECT DISTINCT c.rowid AS cid, acr.fk_element AS uid";
    $sqlMap .= " FROM ".MAIN_DB_PREFIX."commande c";
    $sqlMap .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
    $sqlMap .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
    $sqlMap .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac ON ac.fk_element = c.rowid AND ac.elementtype = 'order'";
    $sqlMap .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr ON acr.fk_actioncomm = ac.id AND acr.element_type = 'user'";
    $sqlMap .= " WHERE ".$whereBase;
    $sqlMap .= "   AND acr.fk_element IN (".implode(',', array_map('intval', array_keys($porTecnico))).")";
    $resMap = $db->query($sqlMap);
    if ($resMap) {
        while ($m = $db->fetch_object($resMap)) {
            $uid = (int) $m->uid; $cid = (int) $m->cid;
            if (!isset($porTecnico[$uid], $infoParte[$cid])) continue;
            $i = $infoParte[$cid];
            $porTecnico[$uid]['total']++;
            if ($i['st'] === 3)       $porTecnico[$uid]['terminados']++;
            elseif ($i['st'] === -1)  $porTecnico[$uid]['cancelados']++;
            else                      $porTecnico[$uid]['pendientes']++;
            if ($i['fact'])    $porTecnico[$uid]['facturados']++;
            if ($i['firmado']) $porTecnico[$uid]['firmados']++;
            if ($i['horas'] !== null) { $porTecnico[$uid]['horas_totales'] += $i['horas']; $porTecnico[$uid]['horas_n']++; }
        }
        $db->free($resMap);
    }
}
$porTecnico = array_values($porTecnico);

// ─── HTML ──────────────────────────────────────────────────────────────────────
// pt_base apunta a la raíz del módulo (un nivel por encima de admin/), calculado
// igual que en index.php para no depender de DOL_URL_ROOT (puede no incluir
// /htdocs/ según la instalación).
$pt_self = dirname(dirname($_SERVER['PHP_SELF']));
$pt_base = rtrim($pt_self, '/');
$pt_v    = '?v=8.6'; // v8.6: más estadísticas y filtros en la vista de administración

$moreheadcontent  = '<link rel="stylesheet" href="'.$pt_base.'/css/partes.css'.$pt_v.'">'."\n";
$moreheadcontent .= '<link rel="stylesheet" href="'.$pt_base.'/css/estadisticas.css'.$pt_v.'">'."\n";
$moreheadcontent .= '<script>'."\n";
$moreheadcontent .= '  window.PT_BASE = "'.$pt_base.'";'."\n";
$moreheadcontent .= '  window.PT_ESTAD_FECHA_DESDE = "'.$fechaDesde.'";'."\n";
$moreheadcontent .= '  window.PT_ESTAD_FECHA_HASTA = "'.$fechaHasta.'";'."\n";
$moreheadcontent .= '</script>'."\n";
$moreheadcontent .= '<script defer src="'.$pt_base.'/js/estadisticas.js'.$pt_v.'"></script>'."\n";

llxHeader($moreheadcontent, 'Estadísticas - Partes de Trabajo');

$urlEstad = htmlspecialchars($pt_base).'/admin/estadisticas.php';

print '<div class="pt-page">';

// Cabecera
print '<div class="pt-header">';
print '  <div class="pt-header-inner">';
print '    <div class="pt-header-left">';
print '      <div class="pt-logo">📊</div>';
print '      <div>';
print '        <h1 class="pt-title">Estadísticas de Partes de Trabajo</h1>';
print '        <p class="pt-subtitle">Todos los estados, tipos y técnicos — vista de administración</p>';
print '      </div>';
print '    </div>';
print '    <a class="pe-btn-volver" href="'.htmlspecialchars($pt_base).'/index.php">← Volver al listado</a>';
print '  </div>';
print '</div>';

// ── Filtros ──────────────────────────────────────────────────────────────────
$opt = function ($valor, $texto, $actual) {
    return '<option value="'.htmlspecialchars($valor).'"'.((string) $valor === (string) $actual ? ' selected' : '').'>'.htmlspecialchars($texto).'</option>';
};

// Atajos de rango de fechas (conservan el resto de filtros)
$qsSinFechas = $_GET; unset($qsSinFechas['fecha_desde'], $qsSinFechas['fecha_hasta']);
$atajo = function ($etiqueta, $desde, $hasta) use ($qsSinFechas, $urlEstad, $fechaDesde, $fechaHasta) {
    $url = $urlEstad.'?'.htmlspecialchars(http_build_query(array_merge($qsSinFechas, array('fecha_desde' => $desde, 'fecha_hasta' => $hasta))));
    $activo = ($desde === $fechaDesde && $hasta === $fechaHasta) ? ' pe-atajo--activo' : '';
    return '<a class="pe-atajo'.$activo.'" href="'.$url.'">'.htmlspecialchars($etiqueta).'</a>';
};
$hoy = date('Y-m-d');
print '<div class="pe-atajos">';
print '  <span class="pe-atajos-lbl">Periodo:</span>';
print $atajo('Este mes',       date('Y-m-01'), $hoy);
print $atajo('Últimos 30 días', date('Y-m-d', strtotime('-30 days')), $hoy);
print $atajo('Últimos 90 días', date('Y-m-d', strtotime('-90 days')), $hoy);
print $atajo('Este año',       date('Y-01-01'), $hoy);
print $atajo('Año anterior',   date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year')));
print '</div>';

print '<form method="GET" class="pe-filtros">';
print '  <label>Desde <input type="date" name="fecha_desde" value="'.htmlspecialchars($fechaDesde).'"></label>';
print '  <label>Hasta <input type="date" name="fecha_hasta" value="'.htmlspecialchars($fechaHasta).'"></label>';
print '  <label>Técnico <select name="tecnico">'.$opt('', 'Todos', $fTecnico).$opt('none', 'Sin asignar', $fTecnico);
foreach ($tecnicosGrupo as $uid => $nombre) print $opt((string) $uid, $nombre, $fTecnico);
print '</select></label>';
print '  <label>Tipo <select name="tipo">'.$opt('', 'Todos', $fTipo);
foreach ($tipoInfo as $tId => $tData) print $opt((string) $tId, $tData['icon'].' '.$tData['label'], $fTipo);
print '</select></label>';
print '  <label>Estado <select name="estado">'.$opt('', 'Todos', $fEstado);
foreach ($statusLabels as $sId => $sData) print $opt((string) $sId, $sData['label'], $fEstado);
print '</select></label>';
print '  <label>Facturado <select name="facturado">'.$opt('', 'Todos', $fFact).$opt('si', 'Sí', $fFact).$opt('no', 'No', $fFact).'</select></label>';
print '  <label>Firmado <select name="firmado">'.$opt('', 'Todos', $fFirma).$opt('si', 'Sí', $fFirma).$opt('no', 'No', $fFirma).'</select></label>';
print '  <label class="pe-filtro-buscar">Buscar <input type="search" name="q" value="'.htmlspecialchars($fBuscar).'" placeholder="Ref., ref. cliente o cliente…"></label>';
print '  <button type="submit" class="pe-btn-filtrar">Filtrar</button>';
print '  <a class="pe-btn-reset" href="'.$urlEstad.'">Restablecer</a>';
print '</form>';
if ($hayFiltros) {
    print '<p class="pe-filtros-activos">🔎 Filtros activos: todas las cifras de esta página corresponden solo a los '.$totalPartes.' partes que los cumplen.</p>';
}

// ── KPIs principales — TODOS los estados a la vez, más facturación y firmas ──
$kpiCard = function ($k) {
    return '  <div class="pe-kpi-card" style="border-top-color:'.htmlspecialchars($k['color']).'">'
        .'<div class="pe-kpi-icon">'.$k['icon'].'</div>'
        .'<div class="pe-kpi-valor" style="color:'.htmlspecialchars($k['color']).'">'.htmlspecialchars((string) $k['valor']).'</div>'
        .'<div class="pe-kpi-label">'.htmlspecialchars($k['label']).'</div>'
        .(isset($k['sub']) ? '<div class="pe-kpi-sub">'.htmlspecialchars($k['sub']).'</div>' : '')
        .'</div>';
};
print '<div class="pe-kpis">';
foreach (array(
    array('label' => 'Total de partes', 'valor' => $totalPartes,   'color' => '#0F172A',                'icon' => '📋'),
    array('label' => 'Borradores',      'valor' => $porEstado[0],  'color' => $statusLabels[0]['color'],  'icon' => $statusLabels[0]['icon']),
    array('label' => 'Validados',       'valor' => $porEstado[1],  'color' => $statusLabels[1]['color'],  'icon' => $statusLabels[1]['icon']),
    array('label' => 'En proceso',      'valor' => $porEstado[2],  'color' => $statusLabels[2]['color'],  'icon' => $statusLabels[2]['icon']),
    array('label' => 'Terminados',      'valor' => $porEstado[3],  'color' => $statusLabels[3]['color'],  'icon' => $statusLabels[3]['icon']),
    array('label' => 'Cancelados',      'valor' => $porEstado[-1], 'color' => $statusLabels[-1]['color'], 'icon' => $statusLabels[-1]['icon']),
    array('label' => 'Facturados',      'valor' => $facturados,    'color' => '#0EA5E9',                  'icon' => '💶'),
    array('label' => 'Firmados',        'valor' => $firmados,      'color' => '#15803D',                  'icon' => '✍️'),
) as $k) print $kpiCard($k);
print '</div>';

// ── Indicadores de gestión ───────────────────────────────────────────────────
$pctCompletado = $totalPartes > 0 ? round($porEstado[3] / $totalPartes * 100) : 0;
$pctFacturado  = $porEstado[3] > 0 ? round(($porEstado[3] - $terminadosSinFacturar) / $porEstado[3] * 100) : 0;
$pctFirmado    = $totalPartes > 0 ? round($firmados / $totalPartes * 100) : 0;
print '<div class="pe-section pe-section--kpis">';
print '  <h2 class="pe-section-title">🎯 Indicadores de gestión</h2>';
print '  <div class="pe-kpis pe-kpis--inline">';
foreach (array(
    array('label' => '% completado',            'valor' => $pctCompletado.'%', 'color' => '#16A34A', 'icon' => '✅', 'sub' => $porEstado[3].' de '.$totalPartes),
    array('label' => '% firmado',               'valor' => $pctFirmado.'%',    'color' => '#15803D', 'icon' => '✍️', 'sub' => $firmados.' de '.$totalPartes),
    array('label' => 'Terminados facturados',   'valor' => $pctFacturado.'%',  'color' => '#0EA5E9', 'icon' => '💶', 'sub' => ($porEstado[3] - $terminadosSinFacturar).' de '.$porEstado[3]),
    array('label' => 'Terminados sin facturar', 'valor' => $terminadosSinFacturar, 'color' => '#DC2626', 'icon' => '🧾'),
    array('label' => 'Pendientes > 30 días',    'valor' => $pendMas30,         'color' => '#D97706', 'icon' => '⏰', 'sub' => 'borrador, validado o en proceso'),
    array('label' => 'Pendientes sin técnico',  'valor' => $sinTecnico,        'color' => '#DC2626', 'icon' => '🙋'),
    array('label' => 'Horas totales',           'valor' => pt_formatear_horas($horasTotales > 0 ? $horasTotales : null), 'color' => '#D97706', 'icon' => '⏱️', 'sub' => $horasN.' partes con horas'),
    array('label' => 'Horas / parte (media)',   'valor' => pt_formatear_horas($horasN > 0 ? $horasTotales / $horasN : null), 'color' => '#D97706', 'icon' => '📐'),
) as $k) print $kpiCard($k);
print '  </div>';
print '</div>';

// ── Por tipo de parte (nº, % terminados y horas) ─────────────────────────────
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">🏷 Por tipo de parte</h2>';
print '  <div class="pe-barras">';
foreach ($tipoInfo as $tId => $tData) {
    $n    = $porTipo[$tId]['n'] ?? 0;
    $term = $porTipo[$tId]['term'] ?? 0;
    $hor  = $porTipo[$tId]['horas'] ?? 0.0;
    $pct  = $totalPartes > 0 ? round(($n / $totalPartes) * 100) : 0;
    print '    <div class="pe-barra-row pe-barra-row--tipo">';
    print '      <span class="pe-barra-label">'.$tData['icon'].' '.htmlspecialchars($tData['label']).'</span>';
    print '      <div class="pe-barra-track"><div class="pe-barra-fill" style="width:'.$pct.'%;background:'.htmlspecialchars($tData['color']).'"></div></div>';
    print '      <span class="pe-barra-valor">'.$n.' ('.$pct.'%)</span>';
    print '      <span class="pe-barra-extra">'.$term.' terminados · '.htmlspecialchars(pt_formatear_horas($hor > 0 ? $hor : null)).'</span>';
    print '    </div>';
}
print '  </div>';
print '</div>';

// ── Antigüedad de los partes pendientes ──────────────────────────────────────
$totalPend = array_sum($edadPend);
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">⏳ Antigüedad de los partes pendientes <span class="pe-section-sub">(borrador, validado o en proceso, desde su fecha)</span></h2>';
if ($totalPend === 0) {
    print '  <p class="pe-empty">No hay partes pendientes con los filtros actuales.</p>';
} else {
    $coloresEdad = array('0-7' => '#16A34A', '8-30' => '#D97706', '31-90' => '#EA580C', '+90' => '#DC2626');
    $labelsEdad  = array('0-7' => 'Hasta 7 días', '8-30' => '8 a 30 días', '31-90' => '31 a 90 días', '+90' => 'Más de 90 días');
    print '  <div class="pe-barras">';
    foreach ($edadPend as $k => $n) {
        $pct = round($n / $totalPend * 100);
        print '    <div class="pe-barra-row">';
        print '      <span class="pe-barra-label">'.$labelsEdad[$k].'</span>';
        print '      <div class="pe-barra-track"><div class="pe-barra-fill" style="width:'.$pct.'%;background:'.$coloresEdad[$k].'"></div></div>';
        print '      <span class="pe-barra-valor">'.$n.' ('.$pct.'%)</span>';
        print '    </div>';
    }
    print '  </div>';
}
print '</div>';

// ── Por técnico — SOLO grupo "SERVICIO TÉCNICO" ──────────────────────────────
// Cada fila abre el detalle completo de ese técnico (js/estadisticas.js). La
// tabla se puede ordenar por cualquier columna haciendo clic en su título.
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">👥 Por técnico <span class="pe-section-sub">(grupo Servicio Técnico)</span></h2>';
if (empty($porTecnico)) {
    print '  <p class="pe-empty">'.($fTecnico === 'none' ? 'Filtrando por partes sin técnico asignado: no hay técnicos que mostrar.' : 'No hay empleados en el grupo "Servicio Técnico".').'</p>';
} else {
    print '  <table class="pe-tabla pe-tabla--tecnicos pe-tabla--ordenable">';
    print '    <thead><tr>';
    foreach (array('Técnico', 'Asignados', 'Terminados', 'Pendientes', 'Cancelados', 'Facturados', 'Firmados', 'Horas totales', 'Horas / parte', '% completado') as $i => $th) {
        print '<th data-sort="'.($i === 0 ? 'texto' : 'num').'"><button type="button" class="pe-th-sort"><span>'.htmlspecialchars($th).'</span> <span class="pe-th-flecha">↕</span></button></th>';
    }
    print '</tr></thead><tbody>';
    foreach ($porTecnico as $t) {
        $pctT  = $t['total'] > 0 ? round(($t['terminados'] / $t['total']) * 100) : 0;
        $media = $t['horas_n'] > 0 ? $t['horas_totales'] / $t['horas_n'] : null;
        print '    <tr class="pe-tec-row" data-tecnico-id="'.(int) $t['id'].'" data-tecnico-nombre="'.htmlspecialchars($t['nombre']).'" tabindex="0" role="button">';
        print '      <td class="pe-tec-nombre" data-val="'.htmlspecialchars(mb_strtolower($t['nombre'])).'">'.htmlspecialchars($t['nombre']).' <span class="pe-tec-ver">Ver detalle →</span></td>';
        print '      <td>'.$t['total'].'</td>';
        print '      <td>'.$t['terminados'].'</td>';
        print '      <td>'.$t['pendientes'].'</td>';
        print '      <td>'.$t['cancelados'].'</td>';
        print '      <td>'.$t['facturados'].'</td>';
        print '      <td>'.$t['firmados'].'</td>';
        print '      <td data-val="'.round($t['horas_totales'], 2).'">'.htmlspecialchars(pt_formatear_horas($t['horas_totales'] > 0 ? $t['horas_totales'] : null)).'</td>';
        print '      <td data-val="'.($media === null ? '' : round($media, 2)).'">'.htmlspecialchars(pt_formatear_horas($media)).'</td>';
        print '      <td data-val="'.$pctT.'"><span class="pe-barra-track pe-barra-track--sm"><span class="pe-barra-fill" style="width:'.$pctT.'%;background:#16A34A"></span></span> '.$pctT.'%</td>';
        print '    </tr>';
    }
    print '</tbody></table>';
    print '  <p class="pe-nota">Un mismo parte cuenta para todos sus técnicos asignados. Haz clic en un técnico para ver su detalle completo (con filtros propios) o en un título de columna para ordenar.</p>';
}
print '</div>';

// ── Por cliente (top 15) ─────────────────────────────────────────────────────
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">🏢 Clientes con más partes <span class="pe-section-sub">(top 15)</span></h2>';
if (empty($porCliente)) {
    print '  <p class="pe-empty">Sin datos con los filtros actuales.</p>';
} else {
    print '  <table class="pe-tabla pe-tabla--ordenable">';
    print '    <thead><tr>';
    foreach (array('Cliente', 'Partes', 'Terminados', 'Pendientes', 'Facturados', 'Horas totales') as $i => $th) {
        print '<th data-sort="'.($i === 0 ? 'texto' : 'num').'"><button type="button" class="pe-th-sort"><span>'.htmlspecialchars($th).'</span> <span class="pe-th-flecha">↕</span></button></th>';
    }
    print '</tr></thead><tbody>';
    foreach (array_slice($porCliente, 0, 15, true) as $c) {
        print '    <tr>';
        print '      <td data-val="'.htmlspecialchars(mb_strtolower($c['nombre'])).'">'.htmlspecialchars($c['nombre']).'</td>';
        print '      <td>'.$c['n'].'</td><td>'.$c['term'].'</td><td>'.$c['pend'].'</td><td>'.$c['fact'].'</td>';
        print '      <td data-val="'.round($c['horas'], 2).'">'.htmlspecialchars(pt_formatear_horas($c['horas'] > 0 ? $c['horas'] : null)).'</td>';
        print '    </tr>';
    }
    print '</tbody></table>';
    if (count($porCliente) > 15) print '  <p class="pe-nota">Mostrando 15 de '.count($porCliente).' clientes; usa el buscador de arriba para ver uno concreto.</p>';
}
print '</div>';

// ── Evolución mensual: partes creados vs. terminados, y horas ────────────────
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">📈 Evolución mensual</h2>';
if (empty($porMes)) {
    print '  <p class="pe-empty">Sin datos en este rango de fechas.</p>';
} else {
    $mesLabels = array('01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic');
    print '  <div class="pe-leyenda"><span><i style="background:#3B82F6"></i> Partes</span><span><i style="background:#7C3AED"></i> Terminados</span><span>⏱ horas del mes</span></div>';
    print '  <div class="pe-chart">';
    foreach ($porMes as $ym => $m) {
        list($anio, $mes) = explode('-', $ym);
        $mesLabel = ($mesLabels[$mes] ?? $mes).' '.substr($anio, 2);
        $hN = $maxMes > 0 ? max(round(($m['n'] / $maxMes) * 100), 4) : 4;
        $hT = $maxMes > 0 ? max(round(($m['term'] / $maxMes) * 100), $m['term'] > 0 ? 4 : 0) : 0;
        print '    <div class="pe-chart-col" title="'.htmlspecialchars($mesLabel).': '.$m['n'].' partes, '.$m['term'].' terminados">';
        print '      <span class="pe-chart-valor">'.$m['n'].'<small>/'.$m['term'].'</small></span>';
        print '      <div class="pe-chart-bars"><div class="pe-chart-bar" style="height:'.$hN.'%"></div><div class="pe-chart-bar pe-chart-bar--term" style="height:'.$hT.'%"></div></div>';
        print '      <span class="pe-chart-mes">'.htmlspecialchars($mesLabel).'</span>';
        print '      <span class="pe-chart-horas">'.htmlspecialchars(pt_formatear_horas($m['horas'] > 0 ? $m['horas'] : null)).'</span>';
        print '    </div>';
    }
    print '  </div>';
}
print '</div>';

print '</div>'; // .pt-page

llxFooter();
$db->close();

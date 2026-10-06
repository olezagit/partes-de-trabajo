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

// ─── Filtro de fechas (por defecto: todo el año en curso) ─────────────────────
$fechaDesde = GETPOST('fecha_desde', 'alpha');
$fechaHasta = GETPOST('fecha_hasta', 'alpha');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaDesde)) $fechaDesde = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaHasta)) $fechaHasta = date('Y-m-d');

$whereBase  = " c.entity IN (".getEntity('commande').")";
$whereBase .= " AND c.date_commande BETWEEN '".$db->escape($fechaDesde)." 00:00:00' AND '".$db->escape($fechaHasta)." 23:59:59'";

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

// ─── 1. Totales por estado (TODOS, sin el filtro de "activos" del listado) ────
$porEstado = array(-1 => 0, 0 => 0, 1 => 0, 2 => 0, 3 => 0);
$sqlEstados  = "SELECT c.fk_statut AS statut, COUNT(*) AS n FROM ".MAIN_DB_PREFIX."commande c";
$sqlEstados .= " WHERE ".$whereBase;
$sqlEstados .= " GROUP BY c.fk_statut";
$resEstados = $db->query($sqlEstados);
$totalPartes = 0;
if ($resEstados) {
    while ($row = $db->fetch_object($resEstados)) {
        $s = (int) $row->statut;
        $porEstado[$s] = (int) $row->n;
        $totalPartes  += (int) $row->n;
    }
    $db->free($resEstados);
} else {
    dol_print_error($db);
}

// ─── 2. Facturados / No facturados (llx_commande.facture) ────────────────────
$sqlFact  = "SELECT c.facture, COUNT(*) AS n FROM ".MAIN_DB_PREFIX."commande c";
$sqlFact .= " WHERE ".$whereBase;
$sqlFact .= " GROUP BY c.facture";
$resFact = $db->query($sqlFact);
$facturados = 0;
if ($resFact) {
    while ($row = $db->fetch_object($resFact)) {
        if (!empty($row->facture)) $facturados += (int) $row->n;
    }
    $db->free($resFact);
}

// ─── 3. Firmados / Sin firmar ──────────────────────────────────────────────────
$sqlFirma  = "SELECT SUM(CASE WHEN (ef.firma_cliente IS NOT NULL AND ef.firma_cliente != '')";
$sqlFirma .= "   OR (ef.firma_tecnico IS NOT NULL AND ef.firma_tecnico != '') THEN 1 ELSE 0 END) AS firmados";
$sqlFirma .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlFirma .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sqlFirma .= " WHERE ".$whereBase;
$resFirma = $db->query($sqlFirma);
$firmados = 0;
if ($resFirma && $db->num_rows($resFirma) > 0) {
    $firmados = (int) ($db->fetch_object($resFirma)->firmados ?? 0);
    $db->free($resFirma);
}

// ─── 4. Desglose por tipo de parte ─────────────────────────────────────────────
$sqlTipos  = "SELECT ef.tipo_albaran, COUNT(*) AS n FROM ".MAIN_DB_PREFIX."commande c";
$sqlTipos .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sqlTipos .= " WHERE ".$whereBase;
$sqlTipos .= " GROUP BY ef.tipo_albaran";
$resTipos = $db->query($sqlTipos);
$porTipo = array();
if ($resTipos) {
    while ($row = $db->fetch_object($resTipos)) {
        $porTipo[(int) $row->tipo_albaran] = (int) $row->n;
    }
    $db->free($resTipos);
}

// ─── 5. Desglose por técnico asignado (un pedido puede tener varios) ──────────
$sqlTec  = "SELECT u.rowid, u.firstname, u.lastname, u.login,";
$sqlTec .= "  COUNT(DISTINCT c.rowid) AS total,";
$sqlTec .= "  SUM(CASE WHEN c.fk_statut = 3 THEN 1 ELSE 0 END) AS terminados";
$sqlTec .= " FROM ".MAIN_DB_PREFIX."actioncomm_resources acr";
$sqlTec .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac ON ac.id = acr.fk_actioncomm AND ac.elementtype = 'order'";
$sqlTec .= " INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = ac.fk_element";
$sqlTec .= " INNER JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = acr.fk_element AND acr.element_type = 'user'";
$sqlTec .= " WHERE ".$whereBase;
$sqlTec .= " GROUP BY u.rowid, u.firstname, u.lastname, u.login";
$sqlTec .= " ORDER BY total DESC";
$resTec = $db->query($sqlTec);
$porTecnico = array();
if ($resTec) {
    while ($row = $db->fetch_object($resTec)) {
        $porTecnico[] = array(
            'nombre'     => trim($row->firstname.' '.$row->lastname) ?: $row->login,
            'total'      => (int) $row->total,
            'terminados' => (int) $row->terminados,
        );
    }
    $db->free($resTec);
}

// ─── 6. Evolución mensual dentro del rango filtrado ───────────────────────────
$sqlMes  = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m') AS ym, COUNT(*) AS n";
$sqlMes .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlMes .= " WHERE ".$whereBase;
$sqlMes .= " GROUP BY ym ORDER BY ym ASC";
$resMes = $db->query($sqlMes);
$porMes = array();
if ($resMes) {
    while ($row = $db->fetch_object($resMes)) {
        $porMes[$row->ym] = (int) $row->n;
    }
    $db->free($resMes);
}
$maxMes = $porMes ? max($porMes) : 0;

// ─── HTML ──────────────────────────────────────────────────────────────────────
// pt_base apunta a la raíz del módulo (un nivel por encima de admin/), calculado
// igual que en index.php para no depender de DOL_URL_ROOT (puede no incluir
// /htdocs/ según la instalación).
$pt_self = dirname(dirname($_SERVER['PHP_SELF']));
$pt_base = rtrim($pt_self, '/');
$pt_v    = '?v=8.3';

$moreheadcontent  = '<link rel="stylesheet" href="'.$pt_base.'/css/partes.css'.$pt_v.'">'."\n";
$moreheadcontent .= '<link rel="stylesheet" href="'.$pt_base.'/css/estadisticas.css'.$pt_v.'">'."\n";

llxHeader($moreheadcontent, 'Estadísticas - Partes de Trabajo');

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

// Filtro de fechas
print '<form method="GET" class="pe-filtros">';
print '  <label>Desde <input type="date" name="fecha_desde" value="'.htmlspecialchars($fechaDesde).'"></label>';
print '  <label>Hasta <input type="date" name="fecha_hasta" value="'.htmlspecialchars($fechaHasta).'"></label>';
print '  <button type="submit" class="pe-btn-filtrar">Filtrar</button>';
print '  <a class="pe-btn-reset" href="'.htmlspecialchars($pt_base).'/admin/estadisticas.php">Restablecer</a>';
print '</form>';

// KPIs principales — TODOS los estados a la vez, más facturación y firmas
print '<div class="pe-kpis">';
$kpis = array(
    array('label' => 'Total de partes', 'valor' => $totalPartes,        'color' => '#0F172A',                'icon' => '📋'),
    array('label' => 'Borradores',      'valor' => $porEstado[0],       'color' => $statusLabels[0]['color'],  'icon' => $statusLabels[0]['icon']),
    array('label' => 'Validados',       'valor' => $porEstado[1],       'color' => $statusLabels[1]['color'],  'icon' => $statusLabels[1]['icon']),
    array('label' => 'En proceso',      'valor' => $porEstado[2],       'color' => $statusLabels[2]['color'],  'icon' => $statusLabels[2]['icon']),
    array('label' => 'Terminados',      'valor' => $porEstado[3],       'color' => $statusLabels[3]['color'],  'icon' => $statusLabels[3]['icon']),
    array('label' => 'Cancelados',      'valor' => $porEstado[-1],      'color' => $statusLabels[-1]['color'], 'icon' => $statusLabels[-1]['icon']),
    array('label' => 'Facturados',      'valor' => $facturados,         'color' => '#0EA5E9',                  'icon' => '💶'),
    array('label' => 'Firmados',        'valor' => $firmados,           'color' => '#15803D',                  'icon' => '✍️'),
);
foreach ($kpis as $k) {
    print '  <div class="pe-kpi-card" style="border-top-color:'.htmlspecialchars($k['color']).'">';
    print '    <div class="pe-kpi-icon">'.$k['icon'].'</div>';
    print '    <div class="pe-kpi-valor" style="color:'.htmlspecialchars($k['color']).'">'.(int) $k['valor'].'</div>';
    print '    <div class="pe-kpi-label">'.htmlspecialchars($k['label']).'</div>';
    print '  </div>';
}
print '</div>';

// Desglose por tipo de parte
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">🏷 Por tipo de parte</h2>';
print '  <div class="pe-barras">';
foreach ($tipoInfo as $tId => $tData) {
    $n   = $porTipo[$tId] ?? 0;
    $pct = $totalPartes > 0 ? round(($n / $totalPartes) * 100) : 0;
    print '    <div class="pe-barra-row">';
    print '      <span class="pe-barra-label">'.$tData['icon'].' '.htmlspecialchars($tData['label']).'</span>';
    print '      <div class="pe-barra-track"><div class="pe-barra-fill" style="width:'.$pct.'%;background:'.htmlspecialchars($tData['color']).'"></div></div>';
    print '      <span class="pe-barra-valor">'.$n.' ('.$pct.'%)</span>';
    print '    </div>';
}
print '  </div>';
print '</div>';

// Desglose por técnico
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">👥 Por técnico</h2>';
if (empty($porTecnico)) {
    print '  <p class="pe-empty">No hay partes asignados a ningún técnico en este rango de fechas.</p>';
} else {
    print '  <table class="pe-tabla">';
    print '    <tr><th>Técnico</th><th>Total asignados</th><th>Terminados</th><th>% completado</th></tr>';
    foreach ($porTecnico as $t) {
        $pctT = $t['total'] > 0 ? round(($t['terminados'] / $t['total']) * 100) : 0;
        print '    <tr>';
        print '      <td>'.htmlspecialchars($t['nombre']).'</td>';
        print '      <td>'.$t['total'].'</td>';
        print '      <td>'.$t['terminados'].'</td>';
        print '      <td><span class="pe-barra-track pe-barra-track--sm"><span class="pe-barra-fill" style="width:'.$pctT.'%;background:#16A34A"></span></span> '.$pctT.'%</td>';
        print '    </tr>';
    }
    print '  </table>';
    print '  <p class="pe-nota">Un mismo parte puede contar para varios técnicos si tiene más de uno asignado.</p>';
}
print '</div>';

// Evolución mensual
print '<div class="pe-section">';
print '  <h2 class="pe-section-title">📈 Evolución mensual</h2>';
if (empty($porMes)) {
    print '  <p class="pe-empty">Sin datos en este rango de fechas.</p>';
} else {
    $mesLabels = array('01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic');
    print '  <div class="pe-chart">';
    foreach ($porMes as $ym => $n) {
        $alturaPct = $maxMes > 0 ? round(($n / $maxMes) * 100) : 0;
        list($anio, $mes) = explode('-', $ym);
        $mesLabel = ($mesLabels[$mes] ?? $mes).' '.substr($anio, 2);
        print '    <div class="pe-chart-col">';
        print '      <span class="pe-chart-valor">'.$n.'</span>';
        print '      <div class="pe-chart-bar" style="height:'.max($alturaPct, 4).'%"></div>';
        print '      <span class="pe-chart-mes">'.htmlspecialchars($mesLabel).'</span>';
        print '    </div>';
    }
    print '  </div>';
}
print '</div>';

print '</div>'; // .pt-page

llxFooter();
$db->close();

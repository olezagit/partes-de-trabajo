<?php
/**
 * api/partes.php
 * Puede llamarse de dos formas:
 *   A) Directo: GET /api/partes.php          (con header X-Requested-With)
 *   B) Via dispatcher: index.php?pt_action=partes  (ya tiene bootstrap y sesión)
 */

// Si no viene del dispatcher (index.php), hacer bootstrap propio
if (!defined('PT_FROM_DISPATCHER')) {  // solo si NO viene del dispatcher de index.php
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        die(json_encode(['error' => 'Acceso denegado']));
    }

    $res = 0; $dir = __DIR__;
    for ($i = 0; $i < 6 && !$res; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir.'/main.inc.php')) $res = @include $dir.'/main.inc.php';
    }
    if (!$res) { http_response_code(500); die(json_encode(['error' => 'main.inc.php no encontrado'])); }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300, must-revalidate');

    if (!$user || !$user->id) { http_response_code(401); die(json_encode(['error' => 'No autenticado'])); }

    // Acceso: basta con estar logueado (permiso activo por defecto)
    $tiene_permiso = !empty($user->id);
    if (!$tiene_permiso) { http_response_code(403); die(json_encode(['error' => 'Sin permiso'])); }
}

// ── Mapa de tipos ─────────────────────────────────────────────────────────────
$tipos = array(
    1 => array('label' => $conf->global->PARTES_TIPO_LABEL_1 ?? 'Parte de trabajo', 'color' => '#2563EB', 'bg' => '#DBEAFE', 'icon' => '🔧'),
    2 => array('label' => $conf->global->PARTES_TIPO_LABEL_2 ?? 'Puesta en marcha', 'color' => '#16A34A', 'bg' => '#DCFCE7', 'icon' => '▶️'),
    3 => array('label' => $conf->global->PARTES_TIPO_LABEL_3 ?? 'Avería',           'color' => '#DC2626', 'bg' => '#FEE2E2', 'icon' => '⚠️'),
    4 => array('label' => $conf->global->PARTES_TIPO_LABEL_4 ?? 'Mantenimiento',    'color' => '#D97706', 'bg' => '#FEF3C7', 'icon' => '🛠️'),
);

$statuts_activos_raw = $conf->global->PARTES_STATUTS_ACTIVOS ?? '1,2'; // 1=Confirmado, 2=En proceso. 3=Cerrado excluido
$todos_statuts       = array(-1, 0, 1, 2, 3);  // estados reales llx_commande
$statuts_activos     = array_map('intval', explode(',', $statuts_activos_raw));
$statuts_excluir     = array_diff($todos_statuts, $statuts_activos);

$userId = (int)$user->id;

// Admin (grupo ADMINISTRACIÓN o superadmin): ve todos los pedidos
// Técnico: solo los asignados a él
$esAdmin = !empty($user->admin);
if (!$esAdmin) {
    $sqlGrp  = "SELECT g.rowid FROM ".MAIN_DB_PREFIX."usergroup g";
    $sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
    $sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
    $sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
    $resGrp = $db->query($sqlGrp);
    if ($resGrp && $db->num_rows($resGrp) > 0) $esAdmin = true;
}

$sql  = "SELECT DISTINCT";
$sql .= "  c.rowid, c.ref, c.ref_client,";
$sql .= "  UNIX_TIMESTAMP(c.date_commande) AS date_commande,";
$sql .= "  c.fk_statut AS statut,";
$sql .= "  ef.tipo_albaran,";
$sql .= "  s.rowid AS soc_id, s.nom AS soc_nom,";
$sql .= "  s.address AS soc_address, s.zip AS soc_zip, s.town AS soc_town,";
$sql .= "  sa.address AS dep_address, sa.zip AS dep_zip, sa.town AS dep_town,";
$sql .= "  ef.firma_cliente, ef.firma_tecnico";
$sql .= " FROM ".MAIN_DB_PREFIX."commande c";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe s           ON s.rowid  = c.fk_soc";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."societe_address sa  ON sa.rowid = c.fk_delivery_address";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
// user_filter: admin ve los partes asignados a otro técnico específico
$userFilter = 0;
if ($esAdmin && !empty($_GET['user_filter'])) {
    $userFilter = (int)$_GET['user_filter'];
}

if (!$esAdmin || $userFilter > 0) {
    $filterUserId = $userFilter > 0 ? $userFilter : $userId;
    $sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac";
    $sql .= "         ON ac.fk_element = c.rowid AND ac.elementtype = 'order'";
    $sql .= "         AND ac.entity IN (".getEntity('actioncomm').")";
    $sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr";
    $sql .= "         ON acr.fk_actioncomm = ac.id AND acr.element_type = 'user'";
    $sql .= "         AND acr.fk_element = ".$filterUserId;
}
$sql .= " WHERE c.entity IN (".getEntity('commande').")";
if (!$esAdmin) {
    $sql .= "   AND c.fk_statut NOT IN (".implode(',', array_map('intval', $statuts_excluir)).")";
}
$sql .= " ORDER BY c.date_commande DESC";

$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    die(json_encode(['error' => 'Error SQL: '.$db->lasterror()]));
}

$partes = array();
while ($obj = $db->fetch_object($resql)) {
    $tipoId   = (int)$obj->tipo_albaran;
    $tipoInfo = $tipos[$tipoId] ?? null;

    // Dirección con misma prioridad que el detalle: contacto → sede → central
    $dir_ct = ''; $zip_ct = ''; $town_ct = '';
    $sqlCt  = "SELECT ct.address, ct.zip, ct.town";
    $sqlCt .= " FROM ".MAIN_DB_PREFIX."element_contact ec";
    $sqlCt .= " INNER JOIN ".MAIN_DB_PREFIX."socpeople ct ON ct.rowid = ec.fk_socpeople";
    $sqlCt .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact ctc ON ctc.rowid = ec.fk_c_type_contact";
    $sqlCt .= " WHERE ec.element_id = ".(int)$obj->rowid." AND ctc.element = 'commande'";
    $sqlCt .= "   AND (ct.address != '' OR ct.town != '') LIMIT 1";
    $resCt = $db->query($sqlCt);
    if ($resCt && $db->num_rows($resCt) > 0) {
        $ctRow   = $db->fetch_object($resCt);
        $dir_ct  = $ctRow->address ?? '';
        $zip_ct  = $ctRow->zip     ?? '';
        $town_ct = $ctRow->town    ?? '';
    }

    if ($dir_ct !== '' || $town_ct !== '') {
        $fin_address = $dir_ct;  $fin_zip = $zip_ct;  $fin_town = $town_ct;
    } elseif (!empty($obj->dep_address)) {
        $fin_address = $obj->dep_address; $fin_zip = $obj->dep_zip; $fin_town = $obj->dep_town;
    } else {
        $fin_address = $obj->soc_address; $fin_zip = $obj->soc_zip; $fin_town = $obj->soc_town;
    }

    $partes[] = array(
        'rowid'         => (int)$obj->rowid,
        'ref'           => $obj->ref,
        'date_commande' => (int)$obj->date_commande,
        'statut'        => (int)$obj->statut,
        'tipo_albaran'  => $tipoId,
        'tipo_label'    => $tipoInfo ? $tipoInfo['label'] : 'Tipo '.$tipoId,
        'tipo_color'    => $tipoInfo ? $tipoInfo['color'] : '#6B7280',
        'tipo_bg'       => $tipoInfo ? $tipoInfo['bg']    : '#F3F4F6',
        'tipo_icon'     => $tipoInfo ? $tipoInfo['icon']  : '📄',
        'ref_client'    => $obj->ref_client ?? '',
        'soc_id'        => (int)$obj->soc_id,
        'soc_nom'       => $obj->soc_nom,
        'soc_address'   => $obj->soc_address,
        'soc_zip'       => $obj->soc_zip,
        'soc_town'      => $obj->soc_town,
        'dep_address'   => $fin_address,
        'dep_zip'       => $fin_zip,
        'dep_town'      => $fin_town,
        'tiene_firma_tecnico' => !empty($obj->firma_tecnico),
        'tiene_firma_cliente' => !empty($obj->firma_cliente),
        'firmado'             => (!empty($obj->firma_cliente) || !empty($obj->firma_tecnico)),
        'en_proceso'          => ((int)$obj->statut === 1 && !empty($obj->firma_tecnico) && empty($obj->firma_cliente)),
        'url'           => DOL_URL_ROOT.'/commande/card.php?id='.(int)$obj->rowid,
    );
}
$db->free($resql);

echo json_encode(['partes' => $partes, 'count' => count($partes), 'ts' => time(), 'user_id' => $userId], JSON_UNESCAPED_UNICODE);

<?php
/**
 * api/pedidos_sin_asignar.php
 * Devuelve pedidos activos con su técnico(s) asignado(s).
 * Solo para el grupo ADMINISTRACIÓN.
 */
if (!defined('PT_FROM_DISPATCHER')) { http_response_code(403); die(json_encode(['error'=>'Acceso denegado'])); }

// ── Mapa de tipos ──────────────────────────────────────────────────────────────
$tipos = array(
    1 => $conf->global->PARTES_TIPO_LABEL_1 ?? 'Parte de trabajo',
    2 => $conf->global->PARTES_TIPO_LABEL_2 ?? 'Puesta en marcha',
    3 => $conf->global->PARTES_TIPO_LABEL_3 ?? 'Avería',
    4 => $conf->global->PARTES_TIPO_LABEL_4 ?? 'Mantenimiento',
);

$statuts_activos_raw = $conf->global->PARTES_STATUTS_ACTIVOS ?? '1,2';
$statuts_activos     = array_map('intval', explode(',', $statuts_activos_raw));
$todos_statuts       = array(-1, 0, 1, 2, 3);
$statuts_excluir     = array_diff($todos_statuts, $statuts_activos);

$sql  = "SELECT DISTINCT c.rowid, c.ref, c.ref_client, c.fk_statut AS statut,";
$sql .= " UNIX_TIMESTAMP(c.date_commande) AS date_commande,";
$sql .= " ef.tipo_albaran, s.nom AS soc_nom";
$sql .= " FROM ".MAIN_DB_PREFIX."commande c";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sql .= " WHERE c.entity IN (".getEntity('commande').")";
$sql .= "   AND c.fk_statut NOT IN (".implode(',', array_map('intval', $statuts_excluir)).")";
$sql .= " ORDER BY c.date_commande DESC";
$sql .= " LIMIT 200";

$resql = $db->query($sql);
$pedidos = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        // Obtener técnicos asignados
        $sqlT  = "SELECT u.rowid, u.firstname, u.lastname, u.login";
        $sqlT .= " FROM ".MAIN_DB_PREFIX."user u";
        $sqlT .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr ON acr.fk_element = u.rowid AND acr.element_type = 'user'";
        $sqlT .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac ON ac.id = acr.fk_actioncomm";
        $sqlT .= "   AND ac.fk_element = ".(int)$obj->rowid." AND ac.elementtype = 'order'";
        $resT  = $db->query($sqlT);
        $tecnicos = array();
        if ($resT) {
            while ($t = $db->fetch_object($resT)) {
                $tecnicos[] = array(
                    'id'     => (int)$t->rowid,
                    'nombre' => trim($t->firstname.' '.$t->lastname) ?: $t->login,
                );
            }
        }
        $tipoId = (int)$obj->tipo_albaran;
        $pedidos[] = array(
            'rowid'        => (int)$obj->rowid,
            'ref'          => $obj->ref,
            'statut'       => (int)$obj->statut,
            'date_commande'=> (int)$obj->date_commande,
            'tipo_albaran' => $tipoId,
            'tipo_label'   => $tipos[$tipoId] ?? 'Tipo '.$tipoId,
            'ref_client'   => $obj->ref_client ?? '',
            'soc_nom'      => $obj->soc_nom,
            'tecnicos'     => $tecnicos,
        );
    }
    $db->free($resql);
}
echo json_encode(['pedidos' => $pedidos, 'count' => count($pedidos)], JSON_UNESCAPED_UNICODE);

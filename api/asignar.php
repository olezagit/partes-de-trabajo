<?php
/**
 * api/asignar.php
 * Asigna un pedido a un usuario creando/actualizando una entrada en
 * llx_actioncomm + llx_actioncomm_resources.
 *
 * POST JSON: { commande_id: int, user_id: int }
 */
if (!defined('PT_FROM_DISPATCHER')) { http_response_code(403); die(json_encode(['error'=>'Acceso denegado'])); }

$body = json_decode(file_get_contents('php://input'), true);
$commandeId = (int)($body['commande_id'] ?? 0);
$targetUserId = (int)($body['user_id']     ?? 0);

if ($commandeId <= 0 || $targetUserId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Parámetros inválidos']));
}

// Verificar que el pedido existe
$sqlChk = "SELECT rowid FROM ".MAIN_DB_PREFIX."commande WHERE rowid = ".$commandeId." AND entity IN (".getEntity('commande').")";
$resChk = $db->query($sqlChk);
if (!$resChk || $db->num_rows($resChk) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Pedido no encontrado']));
}

// Buscar si ya existe una actioncomm para este pedido con elementtype='order'
$sqlAc  = "SELECT id FROM ".MAIN_DB_PREFIX."actioncomm";
$sqlAc .= " WHERE fk_element = ".$commandeId." AND elementtype = 'order'";
$sqlAc .= " AND entity IN (".getEntity('actioncomm').")";
$sqlAc .= " LIMIT 1";
$resAc  = $db->query($sqlAc);
$acId   = 0;

if ($resAc && $db->num_rows($resAc) > 0) {
    $acId = (int)$db->fetch_object($resAc)->id;
} else {
    // Crear nueva actioncomm vinculada al pedido
    $now = dol_now();
    $sqlNew  = "INSERT INTO ".MAIN_DB_PREFIX."actioncomm";
    $sqlNew .= " (datep, datep2, label, fk_action, fk_element, elementtype, percent, entity, fk_user_author, datec, tms)";
    $sqlNew .= " VALUES (";
    $sqlNew .= " '".$db->idate($now)."',";
    $sqlNew .= " '".$db->idate($now)."',";
    $sqlNew .= " 'Parte de trabajo asignado',";
    $sqlNew .= " 0,";
    $sqlNew .= " ".$commandeId.",";
    $sqlNew .= " 'order',";
    $sqlNew .= " 0,";
    $sqlNew .= " ".$conf->entity.",";
    $sqlNew .= " ".(int)$user->id.",";
    $sqlNew .= " '".$db->idate($now)."',";
    $sqlNew .= " '".$db->idate($now)."'";
    $sqlNew .= ")";
    if (!$db->query($sqlNew)) {
        http_response_code(500);
        die(json_encode(['error' => 'Error creando actividad: '.$db->lasterror()]));
    }
    $acId = $db->last_insert_id(MAIN_DB_PREFIX."actioncomm");
}

// Verificar si el usuario ya está asignado
$sqlRes  = "SELECT rowid FROM ".MAIN_DB_PREFIX."actioncomm_resources";
$sqlRes .= " WHERE fk_actioncomm = ".$acId." AND element_type = 'user' AND fk_element = ".$targetUserId;
$resRes  = $db->query($sqlRes);

if (!$resRes || $db->num_rows($resRes) === 0) {
    // Añadir recurso
    $sqlIns  = "INSERT INTO ".MAIN_DB_PREFIX."actioncomm_resources (fk_actioncomm, element_type, fk_element, mandatory)";
    $sqlIns .= " VALUES (".$acId.", 'user', ".$targetUserId.", 0)";
    if (!$db->query($sqlIns)) {
        http_response_code(500);
        die(json_encode(['error' => 'Error asignando usuario: '.$db->lasterror()]));
    }
}

// Nombre del usuario asignado
$sqlU = "SELECT firstname, lastname, login FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".$targetUserId;
$resU = $db->query($sqlU);
$uObj = $resU ? $db->fetch_object($resU) : null;
$nombre = $uObj ? trim($uObj->firstname.' '.$uObj->lastname) ?: $uObj->login : 'Usuario '.$targetUserId;

echo json_encode(['ok' => true, 'ac_id' => $acId, 'usuario' => $nombre, 'ts' => time()], JSON_UNESCAPED_UNICODE);

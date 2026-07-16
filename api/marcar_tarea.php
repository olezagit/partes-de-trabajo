<?php
/**
 * api/marcar_tarea.php
 * Marca/desmarca una línea del albarán (commandedet) como realizada,
 * guardando en llx_commandedet_extrafields.tarea_realizada.
 *
 * POST JSON: { linea_id: int, realizada: bool, parte_id: int }
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data     = json_decode(file_get_contents('php://input'), true);
$lineaId  = (int)($data['linea_id'] ?? 0);
$parteId  = (int)($data['parte_id'] ?? 0);
$realizada = !empty($data['realizada']);

if ($lineaId <= 0 || $parteId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Parámetros inválidos']));
}

$userId = (int)$user->id;

// Admin: superadmin de Dolibarr O miembro del grupo ADMINISTRACIÓN
$esAdmin = !empty($user->admin);
if (!$esAdmin) {
    $sqlGrp  = "SELECT g.rowid FROM ".MAIN_DB_PREFIX."usergroup g";
    $sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
    $sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
    $sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
    $resGrp = $db->query($sqlGrp);
    if ($resGrp && $db->num_rows($resGrp) > 0) $esAdmin = true;
}

// Verificar acceso: técnico asignado al pedido o admin
if (!$esAdmin) {
    $sqlCheck  = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."commande c";
    $sqlCheck .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac";
    $sqlCheck .= "   ON ac.fk_element = c.rowid AND ac.elementtype = 'order'";
    $sqlCheck .= "   AND ac.entity IN (".getEntity('actioncomm').")";
    $sqlCheck .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr";
    $sqlCheck .= "   ON acr.fk_actioncomm = ac.id AND acr.element_type = 'user'";
    $sqlCheck .= "   AND acr.fk_element = ".$userId;
    $sqlCheck .= " WHERE c.rowid = ".$parteId;
    $sqlCheck .= "   AND c.entity IN (".getEntity('commande').")";
    $resCheck = $db->query($sqlCheck);
    $objCheck = $resCheck ? $db->fetch_object($resCheck) : null;
    if (!$objCheck || (int)$objCheck->cnt === 0) {
        http_response_code(403);
        die(json_encode(['error' => 'Sin acceso a este parte']));
    }
}

// Verificar que la línea pertenece realmente a este pedido
$sqlLn  = "SELECT rowid FROM ".MAIN_DB_PREFIX."commandedet";
$sqlLn .= " WHERE rowid = ".$lineaId." AND fk_commande = ".$parteId;
$resLn = $db->query($sqlLn);
if (!$resLn || $db->num_rows($resLn) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Línea no encontrada en este pedido']));
}

$valor = $realizada ? 1 : 0;

// Comprobar si ya existe registro extrafields para esta línea
$sqlEx  = "SELECT rowid FROM ".MAIN_DB_PREFIX."commandedet_extrafields WHERE fk_object = ".$lineaId;
$resEx  = $db->query($sqlEx);
$existe = $resEx && $db->num_rows($resEx) > 0;

if ($existe) {
    $sqlSave = "UPDATE ".MAIN_DB_PREFIX."commandedet_extrafields";
    $sqlSave .= " SET tarea_realizada = ".$valor;
    $sqlSave .= " WHERE fk_object = ".$lineaId;
} else {
    $sqlSave = "INSERT INTO ".MAIN_DB_PREFIX."commandedet_extrafields (fk_object, tarea_realizada)";
    $sqlSave .= " VALUES (".$lineaId.", ".$valor.")";
}

$resSave = $db->query($sqlSave);
if (!$resSave) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
}

echo json_encode(['ok' => true, 'linea_id' => $lineaId, 'realizada' => (bool)$realizada, 'ts' => time()]);

<?php
/**
 * api/desasignar.php
 * Elimina la asignación de un usuario a un pedido
 * borrando su entrada en llx_actioncomm_resources.
 *
 * POST JSON: { commande_id: int, user_id: int }
 */
if (!defined('PT_FROM_DISPATCHER')) { http_response_code(403); die(json_encode(['error'=>'Acceso denegado'])); }

$body         = json_decode(file_get_contents('php://input'), true);
$commandeId   = (int)($body['commande_id'] ?? 0);
$targetUserId = (int)($body['user_id']     ?? 0);

if ($commandeId <= 0 || $targetUserId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Parámetros inválidos']));
}

// Obtener el id de la actioncomm vinculada al pedido
$sqlAc  = "SELECT id FROM ".MAIN_DB_PREFIX."actioncomm";
$sqlAc .= " WHERE fk_element = ".$commandeId." AND elementtype = 'order'";
$sqlAc .= " AND entity IN (".getEntity('actioncomm').")";
$resAc  = $db->query($sqlAc);

if (!$resAc || $db->num_rows($resAc) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'No se encontró la actividad vinculada al pedido']));
}

$acIds = [];
while ($row = $db->fetch_object($resAc)) {
    $acIds[] = (int)$row->id;
}

// Eliminar el recurso de cada actioncomm vinculada
$deleted = 0;
foreach ($acIds as $acId) {
    $sqlDel  = "DELETE FROM ".MAIN_DB_PREFIX."actioncomm_resources";
    $sqlDel .= " WHERE fk_actioncomm = ".$acId;
    $sqlDel .= "   AND element_type  = 'user'";
    $sqlDel .= "   AND fk_element    = ".$targetUserId;
    if ($db->query($sqlDel)) {
        $deleted += $db->affected_rows();
    }
}

if ($deleted === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'El usuario no estaba asignado a este parte']));
}

echo json_encode(['ok' => true, 'deleted' => $deleted, 'ts' => time()]);

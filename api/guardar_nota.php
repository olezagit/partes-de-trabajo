<?php
/**
 * api/guardar_nota.php
 * Añade una anotación a la nota pública del pedido (llx_commande.note_public).
 * La nueva anotación se añade al principio con fecha y hora.
 * Llamado via: index.php?action=nota  (POST, JSON body: {id, nota})
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

// Leer body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

$parteId = (int)($data['id'] ?? 0);
$nota    = trim($data['nota'] ?? '');

if ($parteId <= 0 || $nota === '') {
    http_response_code(400);
    die(json_encode(['error' => 'ID o nota inválidos']));
}

$userId = (int)$user->id;

// Verificar que el usuario tiene acceso a este parte
$sqlCheck  = "SELECT DISTINCT c.rowid, c.note_public";
$sqlCheck .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlCheck .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac";
$sqlCheck .= "   ON ac.fk_element = c.rowid AND ac.elementtype = 'order'";
$sqlCheck .= "   AND ac.entity IN (".getEntity('actioncomm').")";
$sqlCheck .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr";
$sqlCheck .= "   ON acr.fk_actioncomm = ac.id AND acr.element_type = 'user'";
$sqlCheck .= "   AND acr.fk_element = ".$userId;
$sqlCheck .= " WHERE c.rowid = ".$parteId;
$sqlCheck .= "   AND c.entity IN (".getEntity('commande').")";

$resql = $db->query($sqlCheck);
if (!$resql || $db->num_rows($resql) === 0) {
    http_response_code(403);
    die(json_encode(['error' => 'Sin acceso a este parte']));
}
$parte = $db->fetch_object($resql);
$db->free($resql);

// Construir nueva nota: [fecha hora] usuario: texto + nota anterior
$fecha   = date('d/m/Y H:i');
$userObj = new User($db);
$userObj->fetch($userId);
$nombreUser = trim($userObj->firstname.' '.$userObj->lastname) ?: $userObj->login;

$nuevaNota = "[$fecha - $nombreUser]\n$nota";
if (!empty($parte->note_public)) {
    $nuevaNota .= "\n\n" . $parte->note_public;
}

// Guardar
$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."commande";
$sqlUpdate .= " SET note_public = '".$db->escape($nuevaNota)."'";
$sqlUpdate .= ", tms = NOW()";
$sqlUpdate .= " WHERE rowid = ".$parteId;

$resUpdate = $db->query($sqlUpdate);
if (!$resUpdate) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
}

echo json_encode([
    'ok'         => true,
    'note_public'=> $nuevaNota,
    'ts'         => time(),
]);

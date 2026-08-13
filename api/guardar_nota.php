<?php
/**
 * api/guardar_nota.php
 * Crea una NUEVA anotación estructurada (llx_partes_notas) para el parte.
 * A diferencia del modelo anterior, cada anotación queda como una fila propia
 * con su autor y fecha, de forma que el propio técnico pueda editarla después
 * (ver api/editar_nota.php). El histórico antiguo en note_public NO se toca.
 *
 * Llamado via: index.php?pt_do=nota  (POST, JSON body: {id, nota})
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

// Verificar que el usuario tiene acceso a este parte (asignado como técnico)
$sqlCheck  = "SELECT DISTINCT c.rowid";
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
$db->free($resql);

// Insertar la nueva anotación como fila propia
$sqlInsert  = "INSERT INTO ".MAIN_DB_PREFIX."partes_notas";
$sqlInsert .= " (fk_commande, fk_user, nota, date_creation, entity)";
$sqlInsert .= " VALUES (";
$sqlInsert .= "  ".$parteId.",";
$sqlInsert .= "  ".$userId.",";
$sqlInsert .= "  '".$db->escape($nota)."',";
$sqlInsert .= "  NOW(),";
$sqlInsert .= "  ".(int)$conf->entity;
$sqlInsert .= " )";

$resInsert = $db->query($sqlInsert);
if (!$resInsert) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
}

$notaId = $db->last_insert_id(MAIN_DB_PREFIX."partes_notas");

$userObj = new User($db);
$userObj->fetch($userId);
$nombreUser = trim($userObj->firstname.' '.$userObj->lastname) ?: $userObj->login;

echo json_encode([
    'ok'    => true,
    'nota'  => [
        'id'            => (int)$notaId,
        'texto'         => $nota,
        'autor_id'      => $userId,
        'autor_nombre'  => $nombreUser,
        'fecha'         => date('d/m/Y H:i'),
        'editada'       => false,
        'editable'      => true,
    ],
]);

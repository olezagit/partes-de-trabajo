<?php
/**
 * api/usuarios.php
 * Devuelve la lista de usuarios activos para el selector de asignación.
 * Solo accesible por miembros del grupo ADMINISTRACIÓN.
 */
if (!defined('PT_FROM_DISPATCHER')) { http_response_code(403); die(json_encode(['error'=>'Acceso denegado'])); }

$sql  = "SELECT u.rowid, u.login, u.firstname, u.lastname";
$sql .= " FROM ".MAIN_DB_PREFIX."user u";
$sql .= " WHERE u.statut = 1 AND u.entity IN (".getEntity('user').")";
$sql .= " ORDER BY u.lastname ASC, u.firstname ASC";

$resql = $db->query($sql);
$lista = array();
if ($resql) {
    while ($u = $db->fetch_object($resql)) {
        $lista[] = array(
            'id'     => (int)$u->rowid,
            'login'  => $u->login,
            'nombre' => trim($u->firstname.' '.$u->lastname) ?: $u->login,
        );
    }
    $db->free($resql);
}
echo json_encode(['usuarios' => $lista], JSON_UNESCAPED_UNICODE);

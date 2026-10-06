<?php
/**
 * api/eliminar_linea.php
 * Elimina una línea (tarea) de un parte de trabajo, pero SOLO si el usuario
 * que lo pide fue quien la añadió desde este módulo (campo interno
 * pt_creado_por en llx_commandedet_extrafields). Regla estricta, sin
 * excepciones de rol: ni siquiera un admin puede eliminar una línea que no
 * añadió él mismo. Las líneas originales del pedido nunca se pueden
 * eliminar desde aquí.
 *
 * POST JSON: { linea_id, parte_id }
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = array();

$lineaId = (int)($data['linea_id'] ?? 0);
$parteId = (int)($data['parte_id'] ?? 0);

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

// Pedido: existencia + estado
$sqlPedido  = "SELECT c.rowid, c.fk_statut FROM ".MAIN_DB_PREFIX."commande c";
$sqlPedido .= " WHERE c.rowid = ".$parteId." AND c.entity IN (".getEntity('commande').")";
$resPedido = $db->query($sqlPedido);
if (!$resPedido || $db->num_rows($resPedido) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Parte no encontrado']));
}
$pedido = $db->fetch_object($resPedido);
$db->free($resPedido);

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

if (in_array((int)$pedido->fk_statut, array(-1, 3), true)) {
    http_response_code(403);
    die(json_encode(['error' => 'Este parte ya está cerrado, no se pueden eliminar líneas']));
}

// Comprobar que la línea pertenece a este pedido y quién la añadió
$sqlLn  = "SELECT cd.rowid, cdef.pt_creado_por";
$sqlLn .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
$sqlLn .= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet_extrafields cdef ON cdef.fk_object = cd.rowid";
$sqlLn .= " WHERE cd.rowid = ".$lineaId." AND cd.fk_commande = ".$parteId;
$resLn = $db->query($sqlLn);
if (!$resLn || $db->num_rows($resLn) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Línea no encontrada en este pedido']));
}
$linea = $db->fetch_object($resLn);
$db->free($resLn);

$creadoPor = (int)($linea->pt_creado_por ?? 0);
if ($creadoPor <= 0 || $creadoPor !== $userId) {
    http_response_code(403);
    die(json_encode(['error' => 'Solo puedes eliminar las líneas que tú mismo has añadido']));
}

// Borrar primero sus datos de ficha técnica / metadatos internos, luego la línea
$db->query("DELETE FROM ".MAIN_DB_PREFIX."commandedet_extrafields WHERE fk_object = ".$lineaId);

$sqlDel = "DELETE FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".$lineaId." AND fk_commande = ".$parteId;
$resDel = $db->query($sqlDel);
if (!$resDel) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al eliminar: '.$db->lasterror()]));
}

try {
    require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
    $objCommande = new Commande($db);
    if ($objCommande->fetch($parteId) > 0) {
        $objCommande->update_price(1);
    }
} catch (Throwable $e) {
    error_log('PartesTrabajo: no se pudo recalcular precios del pedido '.$parteId.': '.$e->getMessage());
}

echo json_encode(['ok' => true, 'linea_id' => $lineaId, 'ts' => time()], JSON_UNESCAPED_UNICODE);

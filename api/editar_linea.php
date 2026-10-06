<?php
/**
 * api/editar_linea.php
 * Edita una línea (tarea) de un parte de trabajo, pero SOLO si el usuario
 * que lo pide fue quien la añadió desde este módulo (campo interno
 * pt_creado_por en llx_commandedet_extrafields). Regla estricta, sin
 * excepciones de rol: ni siquiera un admin puede editar una línea que no
 * añadió él mismo. Las líneas originales del pedido (pt_creado_por vacío,
 * creadas fuera de este módulo) nunca se pueden editar aquí: cualquier
 * usuario solo puede visualizarlas.
 *
 * Campos editables:
 *   - Línea descriptiva (sin producto): label, description.
 *   - Línea de producto/servicio: qty, description (el producto vinculado
 *     no se puede cambiar; si se eligió mal, hay que eliminarla y añadir
 *     una nueva para que se revalide correctamente la regla de "máquina").
 *
 * POST JSON: { linea_id, parte_id, label?, description?, qty? }
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = array();

$lineaId    = (int)($data['linea_id'] ?? 0);
$parteId    = (int)($data['parte_id'] ?? 0);
$tieneLabel = array_key_exists('label', $data);
$tieneDesc  = array_key_exists('description', $data);
$tieneQty   = array_key_exists('qty', $data);
$label      = trim((string)($data['label']       ?? ''));
$description= trim((string)($data['description'] ?? ''));
$qty        = isset($data['qty']) ? (float)$data['qty'] : null;

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
    die(json_encode(['error' => 'Este parte ya está cerrado, no se pueden editar líneas']));
}

// Comprobar que la línea pertenece a este pedido y quién la añadió
$sqlLn  = "SELECT cd.rowid, cd.fk_product, cdef.pt_creado_por";
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
    die(json_encode(['error' => 'Solo puedes editar las líneas que tú mismo has añadido']));
}

$sets = array();
if ($tieneLabel) {
    $sets[] = "label = '".$db->escape($label)."'";
}
if ($tieneDesc) {
    $sets[] = "description = '".$db->escape($description)."'";
}
if ($tieneQty) {
    if ($qty === null || !($qty > 0)) {
        http_response_code(400);
        die(json_encode(['error' => 'Indica una cantidad válida']));
    }
    $sets[] = "qty = ".(float)$qty;
}

if (empty($sets)) {
    http_response_code(400);
    die(json_encode(['error' => 'Nada que actualizar']));
}

$sqlUpd  = "UPDATE ".MAIN_DB_PREFIX."commandedet SET ".implode(', ', $sets);
$sqlUpd .= " WHERE rowid = ".$lineaId." AND fk_commande = ".$parteId;
$resUpd = $db->query($sqlUpd);
if (!$resUpd) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
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

// Devolver los valores finales de la línea (ya guardados en BD)
$sqlFin = "SELECT label, description, qty FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".$lineaId;
$resFin = $db->query($sqlFin);
$fin    = $resFin ? $db->fetch_object($resFin) : null;

echo json_encode(array(
    'ok'    => true,
    'linea' => array(
        'rowid'       => $lineaId,
        'label'       => $fin ? $fin->label       : $label,
        'description' => $fin ? $fin->description : $description,
        'qty'         => $fin ? (float)$fin->qty  : $qty,
    ),
), JSON_UNESCAPED_UNICODE);

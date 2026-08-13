<?php
/**
 * api/guardar_linea_extrafields.php
 * Guarda los campos de "ficha técnica" de UNA línea de producto del albarán
 * en llx_commandedet_extrafields (marca, modelo, nº de serie, y los campos
 * pm_ o man_ según el tipo de albarán).
 *
 * POST JSON: { linea_id: int, parte_id: int, campos: { nombre_campo: valor, ... } }
 *
 * Solo se guardan los campos que aparecen en inc/campos_linea_extra.php — el
 * nombre de columna nunca se toma directamente del cliente, para que una
 * petición manipulada no pueda escribir en columnas arbitrarias de la tabla.
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data     = json_decode(file_get_contents('php://input'), true);
$lineaId  = (int)($data['linea_id'] ?? 0);
$parteId  = (int)($data['parte_id'] ?? 0);
$campos   = is_array($data['campos'] ?? null) ? $data['campos'] : array();

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

// Construir la whitelist de columnas permitidas (name => type) a partir del metadato compartido
$camposLineaExtra = include __DIR__.'/../inc/campos_linea_extra.php';
$permitidos = array(); // nombre_columna => tipo
foreach (array('global', 'pm', 'man') as $grp) {
    foreach ($camposLineaExtra[$grp] as $campo) {
        if (isset($campo['name'])) $permitidos[$campo['name']] = $campo['type'];
    }
}

$updates = array();
foreach ($campos as $nombre => $valor) {
    if (!array_key_exists($nombre, $permitidos)) continue; // ignora cualquier campo no reconocido

    switch ($permitidos[$nombre]) {
        case 'boolean':
            $updates[$nombre] = !empty($valor) ? 1 : 0;
            break;
        case 'number':
            $updates[$nombre] = ($valor === '' || $valor === null) ? null : (float)$valor;
            break;
        case 'multiselect':
            // El cliente manda un array de opciones marcadas; se guarda como
            // texto separado por comas (la columna es VARCHAR, no hay tabla aparte)
            $lista = is_array($valor) ? $valor : array();
            $lista = array_values(array_filter(array_map('trim', $lista), function ($v) { return $v !== ''; }));
            $updates[$nombre] = implode(', ', $lista);
            break;
        default: // text, select
            $updates[$nombre] = trim((string)$valor);
    }
}

if (empty($updates)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ningún campo válido para guardar']));
}

// Comprobar si ya existe registro extrafields para esta línea
$sqlEx  = "SELECT rowid FROM ".MAIN_DB_PREFIX."commandedet_extrafields WHERE fk_object = ".$lineaId;
$resEx  = $db->query($sqlEx);
$existe = $resEx && $db->num_rows($resEx) > 0;

if ($existe) {
    $sets = array();
    foreach ($updates as $col => $val) {
        $sets[] = $col." = ".($val === null ? "NULL" : "'".$db->escape($val)."'");
    }
    $sqlSave  = "UPDATE ".MAIN_DB_PREFIX."commandedet_extrafields SET ".implode(', ', $sets);
    $sqlSave .= " WHERE fk_object = ".$lineaId;
} else {
    $cols = array('fk_object');
    $vals = array((string)$lineaId);
    foreach ($updates as $col => $val) {
        $cols[] = $col;
        $vals[] = $val === null ? "NULL" : "'".$db->escape($val)."'";
    }
    $sqlSave  = "INSERT INTO ".MAIN_DB_PREFIX."commandedet_extrafields (".implode(', ', $cols).")";
    $sqlSave .= " VALUES (".implode(', ', $vals).")";
}

$resSave = $db->query($sqlSave);
if (!$resSave) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
}

echo json_encode([
    'ok'             => true,
    'linea_id'       => $lineaId,
    'campos_guardados' => array_keys($updates),
    'ts'             => time(),
]);

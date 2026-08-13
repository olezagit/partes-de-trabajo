<?php
/**
 * api/guardar_progreso.php
 * Guarda de forma incremental cualquier campo del progreso del parte
 * (firma del técnico, firma del cliente, nombre/dni del firmante, horas,
 * fotos del trabajo realizado — foto_1..foto_10)
 * SIN cambiar fk_statut ni exigir que estén todos los campos.
 * Así, si el técnico sale del albarán antes de terminarlo, lo que ya
 * confirmó queda guardado en BD y se recupera al volver a abrirlo.
 *
 * POST JSON: { id: int, ...campos opcionales }
 *   firma_tecnico, firma_cliente, nombre_cliente, dni_cliente,
 *   horadeinicio, horadefin, foto_1..foto_10 (dataURL base64 o '' para borrar)
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data    = json_decode(file_get_contents('php://input'), true);
$parteId = (int)($data['id'] ?? 0);

if ($parteId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'ID de parte inválido']));
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

// Verificar acceso: técnico asignado o admin
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
        die(json_encode(['error' => 'Sin acceso a este parte. El usuario no esta asignado como tecnico.']));
    }
}

// Mapa de campos permitidos: clave_payload => columna_real_en_extrafields
$camposPermitidos = array(
    'firma_tecnico'  => 'firma_tecnico',
    'firma_cliente'  => 'firma_cliente',
    'nombre_cliente' => 'nombre_firmante',
    'dni_cliente'    => 'dni_firmante',
    'horadeinicio'   => 'horadeinicio',
    'horadefin'      => 'horadefinalizacion',
);
// Fotos del trabajo: foto_1..foto_10 (dataURL base64, o '' para eliminar la foto)
for ($i = 1; $i <= 10; $i++) {
    $camposPermitidos['foto_'.$i] = 'foto_'.$i;
}

$updates = array();
foreach ($camposPermitidos as $payloadKey => $columna) {
    if (array_key_exists($payloadKey, $data)) {
        $valor = (string)$data[$payloadKey];
        // Límite de seguridad: ~8MB en base64 por foto (de sobra para una foto
        // ya comprimida en el navegador, que normalmente pesa unos cientos de KB)
        if (strpos($payloadKey, 'foto_') === 0 && strlen($valor) > 8 * 1024 * 1024) {
            http_response_code(400);
            die(json_encode(['error' => 'La foto "'.$payloadKey.'" es demasiado grande']));
        }
        $updates[$columna] = $valor;
    }
}

if (empty($updates)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ningún campo válido para guardar']));
}

// Si se guarda la firma del técnico y no hay fecha_firma todavía, marcar fecha
if (isset($updates['firma_tecnico']) && $updates['firma_tecnico'] !== '') {
    $sqlCheckFecha = "SELECT fecha_firma FROM ".MAIN_DB_PREFIX."commande_extrafields WHERE fk_object = ".$parteId;
    $resFecha = $db->query($sqlCheckFecha);
    $objFecha = $resFecha && $db->num_rows($resFecha) > 0 ? $db->fetch_object($resFecha) : null;
    if (!$objFecha || empty($objFecha->fecha_firma)) {
        $updates['fecha_firma'] = date('d/m/Y H:i');
    }
}

// Comprobar si ya existe registro extrafields para este pedido
$sqlEx  = "SELECT rowid FROM ".MAIN_DB_PREFIX."commande_extrafields WHERE fk_object = ".$parteId;
$resEx  = $db->query($sqlEx);
$existe = $resEx && $db->num_rows($resEx) > 0;

if ($existe) {
    $sets = array();
    foreach ($updates as $col => $val) {
        $sets[] = $col." = '".$db->escape($val)."'";
    }
    $sqlSave = "UPDATE ".MAIN_DB_PREFIX."commande_extrafields SET ".implode(', ', $sets)." WHERE fk_object = ".$parteId;
} else {
    $cols = array('fk_object');
    $vals = array((string)$parteId);
    foreach ($updates as $col => $val) {
        $cols[] = $col;
        $vals[] = "'".$db->escape($val)."'";
    }
    $sqlSave = "INSERT INTO ".MAIN_DB_PREFIX."commande_extrafields (".implode(',', $cols).")";
    $sqlSave .= " VALUES (".implode(',', $vals).")";
}

$resSave = $db->query($sqlSave);
if (!$resSave) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar progreso: '.$db->lasterror()]));
}

echo json_encode(['ok' => true, 'campos_guardados' => array_keys($updates), 'ts' => time()], JSON_UNESCAPED_UNICODE);

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
 *   horadeinicio, horadefin, fecha_firma, foto_1..foto_10 (dataURL base64 o '' para borrar)
 *
 * HORARIO DEL SERVICIO: horadeinicio / horadefin son SOLO la hora ("HH:MM") que el
 * técnico escribe a mano. La fecha NO se envía aparte: es la de la firma del cliente
 * (fecha_firma, "YYYY-MM-DDTHH:MM" medida en el dispositivo en el momento de firmar,
 * para que sea la real también si el albarán se firmó sin conexión y se sincroniza
 * más tarde; '' = firma del cliente borrada). El servidor une fecha + hora y guarda
 * "YYYY-MM-DDTHH:MM" en horadeinicio/horadefinalizacion.
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

require_once __DIR__.'/../inc/helpers_horas.php';

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
    // horadeinicio / horadefin / fecha_firma se tratan aparte (ver más abajo):
    // hay que combinarlos con la fecha de firma del cliente antes de guardarlos.
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

// ── Horario del servicio + fecha de firma del cliente ────────────────────────
// Compatibilidad con navegadores que aún tengan en caché el JS anterior (no envían
// fecha_firma): la fecha es la de la firma del cliente, así que se fija al guardarla
// y se limpia al borrarla.
if (!array_key_exists('fecha_firma', $data) && array_key_exists('firma_cliente', $data)) {
    $data['fecha_firma'] = ((string)$data['firma_cliente'] !== '') ? date('Y-m-d\TH:i') : '';
}

$tocaHorario = array_key_exists('horadeinicio', $data)
            || array_key_exists('horadefin', $data)
            || array_key_exists('fecha_firma', $data);

if ($tocaHorario) {
    $sqlAct  = "SELECT fecha_firma, horadeinicio, horadefinalizacion FROM ".MAIN_DB_PREFIX."commande_extrafields";
    $sqlAct .= " WHERE fk_object = ".$parteId;
    $resAct  = $db->query($sqlAct);
    $actual  = ($resAct && $db->num_rows($resAct) > 0) ? $db->fetch_object($resAct) : null;

    // Fecha de firma del cliente: la nueva (si viene) o la que ya hay en BD
    if (array_key_exists('fecha_firma', $data)) {
        $fechaRaw = trim((string)$data['fecha_firma']);
        $fechaDmy = ($fechaRaw === '') ? '' : pt_fecha_firma_dmyhi($fechaRaw);
        if ($fechaRaw !== '' && $fechaDmy === '') {
            http_response_code(400);
            die(json_encode(['error' => 'Fecha de firma no válida']));
        }
        $updates['fecha_firma'] = $fechaDmy;
    } else {
        $fechaDmy = $actual ? (string)$actual->fecha_firma : '';
    }
    $ymd = pt_fecha_ymd($fechaDmy);

    // Horas: solo "HH:MM" (la nueva si viene; si no, la parte hora de la guardada)
    $horas = array();
    foreach (array('horadeinicio' => 'inicio', 'horadefin' => 'fin') as $clave => $etiqueta) {
        if (array_key_exists($clave, $data)) {
            $raw = trim((string)$data[$clave]);
            $hm  = ($raw === '') ? '' : pt_hora_hhmm($raw);
            if ($raw !== '' && $hm === '') {
                http_response_code(400);
                die(json_encode(['error' => 'La hora de '.$etiqueta.' no es válida (use HH:MM)']));
            }
        } else {
            $col = ($clave === 'horadeinicio') ? 'horadeinicio' : 'horadefinalizacion';
            $hm  = $actual ? pt_hora_hhmm($actual->$col) : '';
        }
        $horas[$clave] = $hm;
    }
    if ($horas['horadeinicio'] !== '' && $horas['horadefin'] !== '' && $horas['horadefin'] <= $horas['horadeinicio']) {
        http_response_code(400);
        die(json_encode(['error' => 'La hora de fin debe ser posterior a la hora de inicio']));
    }

    // Se recomponen SIEMPRE las dos: si cambia la fecha de firma, cambian ambas
    $updates['horadeinicio']       = pt_componer_fecha_hora($ymd, $horas['horadeinicio']);
    $updates['horadefinalizacion'] = pt_componer_fecha_hora($ymd, $horas['horadefin']);
}

if (empty($updates)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ningún campo válido para guardar']));
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

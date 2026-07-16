<?php
/**
 * api/parte_detalle.php
 * Puede llamarse de dos formas:
 *   A) Directo: GET /api/parte_detalle.php?id=N  (con header X-Requested-With)
 *   B) Via dispatcher: index.php?pt_action=detalle&id=N  (ya tiene bootstrap y sesión)
 */

if (!defined('PT_FROM_DISPATCHER')) {  // solo si NO viene del dispatcher de index.php
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        die(json_encode(['error' => 'Acceso denegado']));
    }

    $res = 0; $dir = __DIR__;
    for ($i = 0; $i < 6 && !$res; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir.'/main.inc.php')) $res = @include $dir.'/main.inc.php';
    }
    if (!$res) { http_response_code(500); die(json_encode(['error' => 'main.inc.php no encontrado'])); }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=120, must-revalidate');

    if (!$user || !$user->id) { http_response_code(401); die(json_encode(['error' => 'No autenticado'])); }

    // Acceso: basta con estar logueado (permiso activo por defecto)
    $tiene_permiso = !empty($user->id);
    if (!$tiene_permiso) { http_response_code(403); die(json_encode(['error' => 'Sin permiso'])); }
}

$parteId = (int)GETPOST('id', 'int');
if ($parteId <= 0) { http_response_code(400); die(json_encode(['error' => 'ID invalido'])); }

$userId = (int)$user->id;

// ── Mapa de tipos ─────────────────────────────────────────────────────────────
$tipos = array(
    1 => array('label' => $conf->global->PARTES_TIPO_LABEL_1 ?? 'Parte de trabajo', 'color' => '#2563EB', 'bg' => '#DBEAFE', 'icon' => '🔧'),
    2 => array('label' => $conf->global->PARTES_TIPO_LABEL_2 ?? 'Puesta en marcha', 'color' => '#16A34A', 'bg' => '#DCFCE7', 'icon' => '▶️'),
    3 => array('label' => $conf->global->PARTES_TIPO_LABEL_3 ?? 'Avería',           'color' => '#DC2626', 'bg' => '#FEE2E2', 'icon' => '⚠️'),
    4 => array('label' => $conf->global->PARTES_TIPO_LABEL_4 ?? 'Mantenimiento',    'color' => '#D97706', 'bg' => '#FEF3C7', 'icon' => '🛠️'),
);

// ── Verificar acceso: el usuario debe estar asignado como recurso ─────────────
$sqlAcceso  = "SELECT COUNT(*) as cnt";
$sqlAcceso .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlAcceso .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac";
$sqlAcceso .= "         ON ac.fk_element = c.rowid AND ac.elementtype = 'order'";
$sqlAcceso .= "         AND ac.entity IN (".getEntity('actioncomm').")";
$sqlAcceso .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr";
$sqlAcceso .= "         ON acr.fk_actioncomm = ac.id AND acr.element_type = 'user'";
$sqlAcceso .= "         AND acr.fk_element = ".$userId;
$sqlAcceso .= " WHERE c.rowid = ".$parteId;
$sqlAcceso .= "   AND c.entity IN (".getEntity('commande').")";

$resAcceso = $db->query($sqlAcceso);
$objAcceso = $resAcceso ? $db->fetch_object($resAcceso) : null;
$tieneAcceso = $objAcceso && (int)$objAcceso->cnt > 0;

// Admins pueden ver cualquier parte
if (!$tieneAcceso && empty($user->admin)) {
    http_response_code(403);
    die(json_encode([
        'error'   => 'Sin acceso a este parte',
        'user_id' => $userId,
        'parte_id'=> $parteId,
        'debug'   => 'Verifica que el usuario está asignado como recurso en llx_actioncomm_resources',
    ]));
}

// ── Cabecera del pedido (sin JOIN de seguridad para evitar DISTINCT issues) ──
$sql  = "SELECT c.rowid, c.ref, c.fk_statut AS statut,";
$sql .= "  c.ref_client, c.note_public, c.note_private,";
$sql .= "  UNIX_TIMESTAMP(c.date_commande) AS date_commande,";
$sql .= "  UNIX_TIMESTAMP(c.date_livraison) AS date_livraison,";
$sql .= "  ef.tipo_albaran,";
$sql .= "  s.rowid AS soc_id, s.nom AS soc_nom,";
$sql .= "  s.address AS soc_address, s.zip AS soc_zip, s.town AS soc_town,";
$sql .= "  s.phone AS soc_phone, s.phone_mobile AS soc_phone_mobile, s.fax AS soc_fax,";
$sql .= "  sef.telefono_2 AS soc_phone2, sef.telefono_3 AS soc_phone3, sef.telefono_4 AS soc_phone4,";
$sql .= "  sa.rowid AS dep_id, sa.label AS dep_label,";
$sql .= "  sa.address AS dep_address, sa.zip AS dep_zip, sa.town AS dep_town,";
$sql .= "  sa.phone AS dep_phone,";
$sql .= "  ef.firma_cliente, ef.firma_tecnico,
";
$sql .= "  ef.nombre_firmante, ef.dni_firmante, ef.fecha_firma,
";
$sql .= "  ef.horadeinicio, ef.horadefinalizacion";
$sql .= " FROM ".MAIN_DB_PREFIX."commande c";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe s           ON s.rowid  = c.fk_soc";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."societe_address sa  ON sa.rowid = c.fk_delivery_address";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."societe_extrafields sef ON sef.fk_object = s.rowid";
$sql .= " WHERE c.rowid = ".$parteId;
$sql .= "   AND c.entity IN (".getEntity('commande').")";
$sql .= " LIMIT 1";

$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Pedido no encontrado', 'id' => $parteId, 'dberror' => $db->lasterror()]));
}
$c = $db->fetch_object($resql);
$db->free($resql);

// ── Contactos asignados al pedido (llx_element_contact) ──────────────────────
// Dolibarr vincula contactos al pedido con element_type = 'commande'
// Devolvemos todos los contactos con nombre, empresa, teléfonos y dirección
$sqlCont  = "SELECT ct.rowid, ct.firstname, ct.lastname, ct.poste,";
$sqlCont .= "  ct.phone AS phone_pro, ct.phone_mobile, ct.phone_perso,";
$sqlCont .= "  ct.address, ct.zip, ct.town,";
$sqlCont .= "  soc.nom AS soc_nom,";
$sqlCont .= "  ctc.code AS tipo_contacto";
$sqlCont .= " FROM ".MAIN_DB_PREFIX."element_contact ec";
$sqlCont .= " INNER JOIN ".MAIN_DB_PREFIX."socpeople ct   ON ct.rowid  = ec.fk_socpeople";
$sqlCont .= " LEFT  JOIN ".MAIN_DB_PREFIX."societe soc    ON soc.rowid = ct.fk_soc";
$sqlCont .= " LEFT  JOIN ".MAIN_DB_PREFIX."c_type_contact ctc ON ctc.rowid = ec.fk_c_type_contact";
$sqlCont .= " WHERE ec.element_id = ".$parteId;
$sqlCont .= "   AND ctc.element = 'commande'";

$resCont   = $db->query($sqlCont);
$contactos = array();
if ($resCont) {
    while ($ct = $db->fetch_object($resCont)) {
        $nombre = trim($ct->firstname.' '.$ct->lastname);
        // Mejor teléfono disponible (profesional > móvil > particular)
        $tel_principal = $ct->phone_pro ?: $ct->phone_mobile ?: $ct->phone_perso;
        $contactos[] = array(
            'rowid'      => (int)$ct->rowid,
            'nombre'     => $nombre,
            'poste'      => $ct->poste ?? '',
            'tipo'       => $ct->tipo_contacto ?? '',
            'phone_pro'  => $ct->phone_pro   ?? '',
            'phone_mob'  => $ct->phone_mobile ?? '',
            'phone_per'  => $ct->phone_perso  ?? '',
            'tel'        => $tel_principal    ?? '',
            'address'    => $ct->address      ?? '',
            'zip'        => $ct->zip          ?? '',
            'town'       => $ct->town         ?? '',
            'empresa'    => $ct->soc_nom      ?? '',
        );
    }
    $db->free($resCont);
}

$tipoId   = (int)$c->tipo_albaran;
$tipoInfo = $tipos[$tipoId] ?? array('label' => 'Tipo '.$tipoId, 'color' => '#6B7280', 'bg' => '#F3F4F6', 'icon' => '📄');

// ── Líneas del pedido ─────────────────────────────────────────────────────────
$sql2  = "SELECT cd.rowid, cd.rang, cd.description, cd.label, cd.qty,";
$sql2 .= "  u.short_label AS unidad, p.ref AS prod_ref, p.label AS prod_label,";
$sql2 .= "  cdef.tarea_realizada";
$sql2 .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
$sql2 .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units u  ON u.rowid  = cd.fk_unit";
$sql2 .= " LEFT JOIN ".MAIN_DB_PREFIX."product  p  ON p.rowid  = cd.fk_product";
$sql2 .= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet_extrafields cdef ON cdef.fk_object = cd.rowid";
$sql2 .= " WHERE cd.fk_commande = ".$parteId;
$sql2 .= " ORDER BY cd.rang ASC";

$resql2 = $db->query($sql2);
$lineas  = array();
if ($resql2) {
    while ($ln = $db->fetch_object($resql2)) {
        // Limpiar HTML de label y description — Dolibarr guarda etiquetas HTML
        $clean_label = trim(html_entity_decode(strip_tags($ln->label ?: $ln->prod_label ?: ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $clean_desc  = trim(html_entity_decode(strip_tags($ln->description ?: ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Eliminar líneas vacías múltiples que quedan tras el strip_tags
        $clean_desc  = preg_replace('/\n{3,}/', "\n\n", $clean_desc);

        $lineas[] = array(
            'rowid'           => (int)$ln->rowid,
            'label'           => $clean_label,
            'description'     => $clean_desc,
            'prod_ref'        => $ln->prod_ref,
            'qty'             => (float)$ln->qty,
            'unidad'          => $ln->unidad,
            'tarea_realizada' => !empty($ln->tarea_realizada),
        );
    }
    $db->free($resql2);
}

// ── Resolver teléfono y dirección con prioridad clara ───────────────────────────
//
// TELÉFONO (botón Llamar):
//   1. Primer teléfono encontrado entre todos los contactos asignados
//      (orden: phone_pro → phone_mob → phone_per, recorriendo todos los contactos)
//   2. Teléfono de la sede del pedido (fk_delivery_address)
//   3. Teléfono central del cliente
//
// DIRECCIÓN (botón Ver ruta):
//   1. Dirección del primer contacto asignado que tenga dirección
//   2. Dirección de la sede del pedido
//   3. Dirección central del cliente

// Teléfono de la sede y central
$tel_obra    = trim($c->dep_phone ?? '');
$tel_central = trim($c->soc_phone ?? '');

// Teléfono del contacto: recorrer todos buscando el primero no vacío
$tel_contacto = '';
foreach ($contactos as $ct) {
    $t = trim($ct['phone_pro'] ?: $ct['phone_mob'] ?: $ct['phone_per'] ?: '');
    if ($t !== '') { $tel_contacto = $t; break; }
}

// Dirección del contacto: primer contacto con dirección o localidad
$dir_contacto = '';
foreach ($contactos as $ct) {
    $addr = trim($ct['address'] ?? '');
    $town = trim($ct['town']    ?? '');
    if ($addr !== '' || $town !== '') {
        $zip  = trim($ct['zip'] ?? '');
        $dir_contacto = implode(' ', array_filter([$addr, $zip, $town]));
        break;
    }
}

// Dirección de la sede del pedido
$dir_obra = implode(' ', array_filter([
    trim($c->dep_address ?? ''),
    trim($c->dep_zip     ?? ''),
    trim($c->dep_town    ?? ''),
]));

// Dirección central del cliente
$dir_soc = implode(' ', array_filter([
    trim($c->soc_address ?? ''),
    trim($c->soc_zip     ?? ''),
    trim($c->soc_town    ?? ''),
]));

// Resultado final con prioridad
$tel_llamar = $tel_contacto ?: $tel_obra ?: $tel_central;
$dir_maps   = $dir_contacto ?: $dir_obra ?: $dir_soc;

echo json_encode(array(
    'parte' => array(
        'rowid'          => (int)$c->rowid,
        'ref'            => $c->ref,
        'date_commande'  => (int)$c->date_commande,
        'date_livraison' => (int)($c->date_livraison ?? 0),
        'statut'         => (int)$c->statut,
        'note_public'    => $c->note_public,
        'tipo_albaran'   => $tipoId,
        'tipo_label'     => $tipoInfo['label'],
        'tipo_color'     => $tipoInfo['color'],
        'tipo_bg'        => $tipoInfo['bg'],
        'tipo_icon'      => $tipoInfo['icon'],
        'ref_client'     => $c->ref_client ?? '',
        'url_dolibarr'   => DOL_URL_ROOT.'/commande/card.php?id='.(int)$c->rowid,
        'firma_cliente'    => $c->firma_cliente    ?? '',
        'firma_tecnico'    => $c->firma_tecnico    ?? '',
        'nombre_firmante'  => $c->nombre_firmante  ?? '',
        'dni_firmante'     => $c->dni_firmante     ?? '',
        'fecha_firma'      => $c->fecha_firma      ?? '',
        'horadeinicio'     => $c->horadeinicio     ?? '',
        'horadefin'        => $c->horadefinalizacion ?? '',
    ),
    'cliente' => array(
        'soc_id'  => (int)$c->soc_id,
        'nom'     => $c->soc_nom,
        'address' => $c->soc_address,
        'zip'     => $c->soc_zip,
        'town'    => $c->soc_town,
        'phone'   => $c->soc_phone,
        'phone2'  => $c->soc_phone2,
        'fax'     => $c->soc_fax,
    ),
    'obra' => array(
        'dep_id'  => (int)($c->dep_id ?? 0),
        'label'   => $c->dep_label ?? '',
        'address' => $c->dep_address ?? '',
        'zip'     => $c->dep_zip ?? '',
        'town'    => $c->dep_town ?? '',
        'phone'   => $tel_obra,
    ),
    'contacto' => array(
        'telefono'    => $tel_llamar,
        'maps_query'  => rawurlencode($dir_maps),
        'maps_address'=> $dir_maps,
    ),
    'lineas' => $lineas,
    'ts'     => time(),
), JSON_UNESCAPED_UNICODE);

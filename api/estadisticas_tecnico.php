<?php
/**
 * api/estadisticas_tecnico.php
 * Detalle de estadísticas de UN técnico del grupo "SERVICIO TÉCNICO": todos
 * sus albaranes (asignados/terminados/pendientes/cancelados), horas
 * trabajadas por albarán y totales, y algunos indicadores más.
 *
 * Solo accesible por administradores (grupo ADMINISTRACIÓN o superadmin) —
 * a diferencia de otros endpoints de este módulo, aquí SÍ se comprueba en
 * servidor (no solo ocultando el botón en el HTML): expone horas trabajadas
 * y datos de rendimiento de otros empleados, así que un técnico no debe
 * poder consultar las estadísticas de otro llamando directamente a esta URL.
 *
 * Llamado via: index.php?pt_do=estadisticas_tecnico&tecnico_id=N&fecha_desde=YYYY-MM-DD&fecha_hasta=YYYY-MM-DD
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

require_once __DIR__.'/../inc/config_grupos.php';
require_once __DIR__.'/../inc/helpers_horas.php';

$userId = (int) $user->id;

// ── Acceso: solo grupo ADMINISTRACIÓN o superadmin ─────────────────────────
$esAdmin = !empty($user->admin);
if (!$esAdmin) {
    $sqlGrp  = "SELECT g.rowid FROM ".MAIN_DB_PREFIX."usergroup g";
    $sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
    $sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
    $sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
    $resGrp = $db->query($sqlGrp);
    if ($resGrp && $db->num_rows($resGrp) > 0) $esAdmin = true;
}
if (!$esAdmin) {
    http_response_code(403);
    die(json_encode(['error' => 'Solo administración puede ver estadísticas de técnicos']));
}

$tecnicoId  = (int) GETPOST('tecnico_id', 'int');
$fechaDesde = GETPOST('fecha_desde', 'alpha');
$fechaHasta = GETPOST('fecha_hasta', 'alpha');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaDesde)) $fechaDesde = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fechaHasta)) $fechaHasta = date('Y-m-d');

if ($tecnicoId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'tecnico_id inválido']));
}

// ── El técnico debe pertenecer al grupo "SERVICIO TÉCNICO" ─────────────────
// Misma restricción que la lista de admin/estadisticas.php: no se exponen
// estadísticas de usuarios fuera de ese grupo desde esta pantalla.
$sqlUsr  = "SELECT u.rowid, u.firstname, u.lastname, u.login";
$sqlUsr .= " FROM ".MAIN_DB_PREFIX."user u";
$sqlUsr .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu";
$sqlUsr .= "         ON gu.fk_user = u.rowid AND gu.fk_usergroup = ".(int) PT_GRUPO_SERVICIO_TECNICO_ID;
$sqlUsr .= " WHERE u.rowid = ".$tecnicoId;
$sqlUsr .= "   AND u.entity IN (".getEntity('user').")";
$resUsr = $db->query($sqlUsr);
if (!$resUsr || $db->num_rows($resUsr) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Técnico no encontrado en el grupo Servicio Técnico']));
}
$tecnico = $db->fetch_object($resUsr);
$db->free($resUsr);

// ── Mapas de referencia (mismos que admin/estadisticas.php) ────────────────
$statusLabels = array(
   -1 => array('label' => 'Cancelado',  'color' => '#DC2626'),
    0 => array('label' => 'Borrador',   'color' => '#94A3B8'),
    1 => array('label' => 'Validado',   'color' => '#2563EB'),
    2 => array('label' => 'En proceso', 'color' => '#16A34A'),
    3 => array('label' => 'Terminado',  'color' => '#7C3AED'),
);
$tipo_labels_default = array(1 => 'Parte de trabajo', 2 => 'Puesta en marcha', 3 => 'Avería', 4 => 'Mantenimiento');
$tipoInfo = array(
    1 => array('label' => $conf->global->PARTES_TIPO_LABEL_1 ?? $tipo_labels_default[1], 'icon' => '🔧'),
    2 => array('label' => $conf->global->PARTES_TIPO_LABEL_2 ?? $tipo_labels_default[2], 'icon' => '▶️'),
    3 => array('label' => $conf->global->PARTES_TIPO_LABEL_3 ?? $tipo_labels_default[3], 'icon' => '⚠️'),
    4 => array('label' => $conf->global->PARTES_TIPO_LABEL_4 ?? $tipo_labels_default[4], 'icon' => '🛠️'),
);

// ── Todos los albaranes asignados a este técnico en el rango de fechas ─────
$sql  = "SELECT c.rowid, c.ref, c.ref_client, c.fk_statut, c.facture,";
$sql .= "  UNIX_TIMESTAMP(c.date_commande) AS date_commande,";
$sql .= "  s.nom AS soc_nom,";
$sql .= "  ef.tipo_albaran, ef.horadeinicio, ef.horadefinalizacion,";
$sql .= "  ef.firma_cliente, ef.firma_tecnico";
$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm_resources acr";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac ON ac.id = acr.fk_actioncomm AND ac.elementtype = 'order'";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = ac.fk_element";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sql .= " WHERE acr.fk_element = ".$tecnicoId." AND acr.element_type = 'user'";
$sql .= "   AND c.entity IN (".getEntity('commande').")";
$sql .= "   AND c.date_commande BETWEEN '".$db->escape($fechaDesde)." 00:00:00' AND '".$db->escape($fechaHasta)." 23:59:59'";
$sql .= " ORDER BY c.date_commande DESC";

$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    die(json_encode(['error' => 'Error SQL: '.$db->lasterror()]));
}

$albaranes      = array();
$resumen = array(
    'total'          => 0,
    'terminados'     => 0,
    'pendientes'     => 0,
    'cancelados'     => 0,
    'facturados'     => 0,
    'firmados'       => 0,
    'horas_totales'  => 0.0,
    'horas_n'        => 0, // nº de albaranes con horas registradas
);
$porEstado = array(-1 => 0, 0 => 0, 1 => 0, 2 => 0, 3 => 0);
$porTipo   = array();

while ($row = $db->fetch_object($resql)) {
    $statut = (int) $row->fk_statut;
    $tipoId = (int) $row->tipo_albaran;
    $horas  = pt_horas_entre($row->horadeinicio, $row->horadefinalizacion);
    $firmado = !empty($row->firma_cliente) || !empty($row->firma_tecnico);

    $resumen['total']++;
    if ($statut === 3)       $resumen['terminados']++;
    elseif ($statut === -1)  $resumen['cancelados']++;
    else                     $resumen['pendientes']++;
    if (!empty($row->facture)) $resumen['facturados']++;
    if ($firmado) $resumen['firmados']++;
    if ($horas !== null) {
        $resumen['horas_totales'] += $horas;
        $resumen['horas_n']++;
    }

    $porEstado[$statut] = ($porEstado[$statut] ?? 0) + 1;
    $porTipo[$tipoId]   = ($porTipo[$tipoId]   ?? 0) + 1;

    $tInfo = $tipoInfo[$tipoId] ?? array('label' => 'Tipo '.$tipoId, 'icon' => '📄');
    $sInfo = $statusLabels[$statut] ?? array('label' => 'Estado '.$statut, 'color' => '#6B7280');

    $albaranes[] = array(
        'rowid'          => (int) $row->rowid,
        // Valores "crudos" para que js/estadisticas.js pueda ordenar y filtrar
        // por cada columna sin tener que interpretar los textos ya formateados.
        'tipo_id'        => $tipoId,
        'fecha_ts'       => $row->date_commande ? (int) $row->date_commande : null,
        'inicio_ts'      => pt_ts_o_null($row->horadeinicio),
        'fin_ts'         => pt_ts_o_null($row->horadefinalizacion),
        'ref'            => $row->ref,
        'ref_client'     => $row->ref_client ?? '',
        'cliente'        => $row->soc_nom ?? '',
        'fecha'          => $row->date_commande ? dol_print_date($row->date_commande, 'day') : '',
        'statut'         => $statut,
        'statut_label'   => $sInfo['label'],
        'statut_color'   => $sInfo['color'],
        'tipo_label'     => $tInfo['label'],
        'tipo_icon'      => $tInfo['icon'],
        'facturado'      => !empty($row->facture),
        'firmado'        => $firmado,
        'horadeinicio'   => pt_formatear_fecha_hora_local($row->horadeinicio),
        'horadefin'      => pt_formatear_fecha_hora_local($row->horadefinalizacion),
        'horas'          => $horas,
        'horas_label'    => pt_formatear_horas($horas),
        'url_dolibarr'   => DOL_URL_ROOT.'/commande/card.php?id='.(int) $row->rowid,
    );
}
$db->free($resql);

$resumen['pct_completado']  = $resumen['total'] > 0 ? round(($resumen['terminados'] / $resumen['total']) * 100) : 0;
$resumen['horas_promedio']  = $resumen['horas_n'] > 0 ? round($resumen['horas_totales'] / $resumen['horas_n'], 2) : null;
$resumen['horas_totales']   = round($resumen['horas_totales'], 2);
$resumen['horas_label']     = pt_formatear_horas($resumen['horas_totales'] > 0 ? $resumen['horas_totales'] : null);
$resumen['horas_promedio_label'] = pt_formatear_horas($resumen['horas_promedio']);

echo json_encode(array(
    'tecnico' => array(
        'id'     => (int) $tecnico->rowid,
        'nombre' => trim($tecnico->firstname.' '.$tecnico->lastname) ?: $tecnico->login,
    ),
    'rango' => array('desde' => $fechaDesde, 'hasta' => $fechaHasta),
    'resumen'    => $resumen,
    'por_estado' => $porEstado,
    'por_tipo'   => $porTipo,
    'albaranes'  => $albaranes,
), JSON_UNESCAPED_UNICODE);

<?php
/**
 * Página principal - Mis Partes de Trabajo
 * Muestra los albaranes asignados al usuario actual que NO están en borrador ni finalizados.
 *
 * Tablas principales:
 *   llx_commande + llx_commande_extrafields (campo tipo_albaran int)
 *   llx_actioncomm + llx_actioncomm_resources (asignación de técnico)
 *   llx_societe + llx_societe_address (cliente y sede)
 *
 * Estados llx_commande:
 *   0 = Borrador    → excluir siempre
 *  -1 = Cancelado   → excluir siempre
 *   1 = Confirmado  → mostrar (configurable en admin/setup.php)
 *   2 = En proceso  → mostrar (configurable en admin/setup.php)
 *   3 = Cerrado     → excluir (configurable en admin/setup.php)
 */

// ─── Bootstrap Dolibarr ─────────────────────────────────────────────────────
// Sube desde __DIR__ hasta encontrar main.inc.php (robusto para cualquier instalacion)
$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 6 && !$res; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) {
        $res = @include $dir.'/main.inc.php';
    }
}
if (!$res) die('Imposible encontrar main.inc.php. __DIR__: '.__DIR__);

// ─── API AJAX dispatcher ─────────────────────────────────────────────────────
// Interceptar peticiones AJAX antes de cualquier output HTML.
// Usar ob_start() por si Dolibarr emitió algo antes de llegar aquí.
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && !empty($_GET['pt_do'])) {

    ob_start(); // capturar cualquier output previo de Dolibarr

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        die(json_encode(['error' => "PHP[$errno]: $errstr en ".basename($errfile).":$errline"]));
    });

    ob_clean(); // limpiar lo que Dolibarr pudo haber emitido
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');

    if (!$user || !$user->id) {
        http_response_code(401);
        die(json_encode(['error' => 'No autenticado - sesion no iniciada']));
    }

    if (!defined('PT_FROM_DISPATCHER')) define('PT_FROM_DISPATCHER', true);

    $pt_action = $_GET['pt_do'];
    try {
        if ($pt_action === 'partes') {
            require __DIR__.'/api/partes.php';
        } elseif ($pt_action === 'detalle') {
            require __DIR__.'/api/parte_detalle.php';
        } elseif ($pt_action === 'nota') {
            require __DIR__.'/api/guardar_nota.php';
        } elseif ($pt_action === 'terminar') {
            require __DIR__.'/api/terminar_parte.php';
        } elseif ($pt_action === 'usuarios') {
            require __DIR__.'/api/usuarios.php';
        } elseif ($pt_action === 'asignar') {
            require __DIR__.'/api/asignar.php';
        } elseif ($pt_action === 'pedidos') {
            require __DIR__.'/api/pedidos_sin_asignar.php';
        } elseif ($pt_action === 'desasignar') {
            require __DIR__.'/api/desasignar.php';
        } elseif ($pt_action === 'progreso') {
            require __DIR__.'/api/guardar_progreso.php';
        } elseif ($pt_action === 'tarea') {
            require __DIR__.'/api/marcar_tarea.php';
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Accion desconocida: '.htmlspecialchars($pt_action)]);
        }
    } catch (Throwable $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine()
        ]);
    }
    ob_end_flush();
    exit;
}

// ─── Comprobaciones de acceso ─────────────────────────────────────────────────
// Basta con estar logueado en Dolibarr. El permiso 'leer' está activo por defecto
// para todos los usuarios (bydefault=1 en el descriptor del módulo).
// Usamos una comprobación segura que no falla si el módulo acaba de activarse.
if (!$user->id) {
    accessforbidden();
}

$langs->loadLangs(array('partes_trabajo@partes_trabajo', 'bills', 'orders', 'sendings'));

// ─── Título de página ─────────────────────────────────────────────────────────
$title = 'Mis Partes de Trabajo';

// ─── Consulta principal ───────────────────────────────────────────────────────

$userId = (int)$user->id;

// ─── Detectar si el usuario pertenece al grupo ADMINISTRACIÓN ─────────────────
$esAdmin = false;
$sqlGrp  = "SELECT g.rowid FROM ".MAIN_DB_PREFIX."usergroup g";
$sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
$sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
$sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
$sqlGrp .= " LIMIT 1";
$resGrp = $db->query($sqlGrp);
if ($resGrp && $db->num_rows($resGrp) > 0) $esAdmin = true;
if ($user->admin) $esAdmin = true;  // superadmin siempre tiene acceso


// ─── Mapa de tipos: campo numérico tipo_albaran en llx_commande_extrafields ───
// Las etiquetas pueden sobreescribirse desde admin/setup.php
$tipo_labels_default = array(
    1 => 'Parte de trabajo',
    2 => 'Puesta en marcha',
    3 => 'Avería',
    4 => 'Mantenimiento',
);
$tipos = array(
    1 => array(
        'label' => $conf->global->PARTES_TIPO_LABEL_1 ?? $tipo_labels_default[1],
        'color' => '#2563EB', 'bg' => '#DBEAFE', 'icon' => '🔧'
    ),
    2 => array(
        'label' => $conf->global->PARTES_TIPO_LABEL_2 ?? $tipo_labels_default[2],
        'color' => '#16A34A', 'bg' => '#DCFCE7', 'icon' => '▶️'
    ),
    3 => array(
        'label' => $conf->global->PARTES_TIPO_LABEL_3 ?? $tipo_labels_default[3],
        'color' => '#DC2626', 'bg' => '#FEE2E2', 'icon' => '⚠️'
    ),
    4 => array(
        'label' => $conf->global->PARTES_TIPO_LABEL_4 ?? $tipo_labels_default[4],
        'color' => '#D97706', 'bg' => '#FEF3C7', 'icon' => '🛠️'
    ),
);

// Estados reales de llx_commande — todos los valores posibles
// "En proceso" no es un fk_statut propio: es un estado VISUAL que se muestra
// cuando el pedido está Validado (1) y ya tiene firma del técnico pero
// todavía no tiene firma del cliente (ver $firmado/$enProceso más abajo).
$statusLabels = array(
   -1 => array('label' => 'Cancelado',  'color' => '#DC2626'),
    0 => array('label' => 'Borrador',   'color' => '#94A3B8'),
    1 => array('label' => 'Validado',   'color' => '#2563EB'),
    2 => array('label' => 'En proceso', 'color' => '#16A34A'),
    3 => array('label' => 'Terminado',  'color' => '#7C3AED'),
);
// Estado visual "En proceso" (no es un fk_statut, se calcula por fila)
$enProcesoLabel = array('label' => 'En proceso', 'color' => '#16A34A');

// Estados activos configurados desde setup.php (default: 1 y 2)
$statuts_activos_raw = $conf->global->PARTES_STATUTS_ACTIVOS ?? '1,2'; // 1=Confirmado, 2=En proceso. 3=Cerrado excluido
$statuts_excluir     = array(0, -1);  // borrador y cancelado: siempre excluidos
// Calcular statuts a excluir = todos excepto los activos configurados
$todos_statuts   = array(-1, 0, 1, 2, 3);  // estados reales llx_commande
$statuts_activos = array_map('intval', explode(',', $statuts_activos_raw));
$statuts_excluir = array_diff($todos_statuts, $statuts_activos);

// ─── Consulta principal ───────────────────────────────────────────────────────
// Tablas:
//   llx_commande              → pedidos (partes de trabajo)
//   llx_commande_extrafields  → campo tipo_albaran (int)
//   llx_societe               → cliente
//   llx_societe_address       → sede/dirección secundaria del cliente
//   llx_actioncomm            → actividad vinculada al pedido (fk_element=commande.rowid, elementtype='order')
//   llx_actioncomm_resource   → técnico asignado a la actividad (fk_element=user.rowid)

// ─── Query según rol ────────────────────────────────────────────────────────
// ADMINISTRACIÓN: ve todos los pedidos activos sin filtro de asignación
// TÉCNICO: solo ve los pedidos que tiene asignados (vía actioncomm_resources)

$sql  = "SELECT DISTINCT";
$sql .= "  c.rowid,";
$sql .= "  c.ref, c.ref_client,";
$sql .= "  c.date_commande,";
$sql .= "  c.fk_statut AS statut,";
$sql .= "  ef.tipo_albaran,";
$sql .= "  s.rowid    AS soc_id,";
$sql .= "  s.nom      AS soc_nom,";
$sql .= "  s.address  AS soc_address,";
$sql .= "  s.zip      AS soc_zip,";
$sql .= "  s.town     AS soc_town,";
$sql .= "  sa.address AS dep_address,";
$sql .= "  sa.zip     AS dep_zip,";
$sql .= "  sa.town    AS dep_town,";
$sql .= "  ef.firma_cliente, ef.firma_tecnico";
$sql .= " FROM ".MAIN_DB_PREFIX."commande c";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe s          ON s.rowid  = c.fk_soc";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."societe_address sa ON sa.rowid = c.fk_delivery_address";
$sql .= " LEFT  JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";

if (!$esAdmin) {
    // Técnico: solo pedidos asignados a él vía actioncomm_resources
    $sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm ac";
    $sql .= "   ON  ac.fk_element  = c.rowid";
    $sql .= "   AND ac.elementtype = 'order'";
    $sql .= "   AND ac.entity IN (".getEntity('actioncomm').")";
    $sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources acr";
    $sql .= "   ON  acr.fk_actioncomm = ac.id";
    $sql .= "   AND acr.element_type  = 'user'";
    $sql .= "   AND acr.fk_element    = ".$userId;
}

$sql .= " WHERE c.entity IN (".getEntity('commande').")";
// Admin: sin filtro de estado — ve todos (borrador, validado, cancelado, enviado, facturado)
// Técnico: solo los estados activos configurados (default: 1 y 2)
if (!$esAdmin) {
    $sql .= "   AND c.fk_statut NOT IN (".implode(',', array_map('intval', $statuts_excluir)).")";
}
$sql .= " ORDER BY c.date_commande DESC";

$resql = $db->query($sql);

$partes = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $partes[] = $obj;
    }
    $db->free($resql);
} else {
    dol_print_error($db);
}

// ─── HTML ─────────────────────────────────────────────────────────────────────
// CSS y JS inyectados directo en <head> via moreheadcontent.
// NO usar los parámetros arrayofjs/arrayofcss de llxHeader: no son fiables en todas las versiones.
// PT_BASE: ruta URL real del modulo, derivada de PHP_SELF para evitar problemas
// con Dolibarr instalado en subdirectorios (ej: /gestion/htdocs/).
// DOL_URL_ROOT puede no incluir /htdocs/ dependiendo de la version/configuracion.
$pt_self = dirname($_SERVER['PHP_SELF']);  // ej: /gestion/htdocs/custom/partes_trabajo
$pt_base = rtrim($pt_self, '/');           // sin barra final
$pt_v    = '?v=7.1'; // cache-busting: incrementar al desplegar cambios

$moreheadcontent  = '<link rel="manifest" href="'.$pt_base.'/manifest.json">'."\n";
$moreheadcontent .= '<meta name="theme-color" content="#0F172A">'."\n";
$moreheadcontent .= '<meta name="apple-mobile-web-app-capable" content="yes">'."\n";
$moreheadcontent .= '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'."\n";
$moreheadcontent .= '<meta name="apple-mobile-web-app-title" content="Mis Partes">'."\n";
$moreheadcontent .= '<link rel="apple-touch-icon" href="'.$pt_base.'/icons/icon-192.png">'."\n";
// CSS del modulo
$moreheadcontent .= '<link rel="stylesheet" href="'.$pt_base.'/css/partes.css'.$pt_v.'">'."\n";
$moreheadcontent .= '<link rel="stylesheet" href="'.$pt_base.'/css/detalle.css'.$pt_v.'">'."\n";
// Variables PHP a JS (antes que los scripts)
$moreheadcontent .= '<script>'."
";
$moreheadcontent .= '  window.PT_API_URL = "' . $pt_base . '/index.php?pt_do=partes";'."
";
$moreheadcontent .= '  window.PT_BASE    = "' . $pt_base . '";'."
";
$moreheadcontent .= '  window.PT_USER_ID = ' . (int)$user->id . ';'."
";
$moreheadcontent .= '  window.PT_ES_ADMIN = ' . ($esAdmin ? 'true' : 'false') . ';'."
";
// Desregistrar TODOS los SW del dominio al cargar — evita conflicto con SW de otros modulos Dolibarr
$moreheadcontent .= '  if ("serviceWorker" in navigator) {'."
";
$moreheadcontent .= '    navigator.serviceWorker.getRegistrations().then(function(regs){'."
";
$moreheadcontent .= '      regs.forEach(function(r){ r.unregister(); });'."
";
$moreheadcontent .= '    }).catch(function(){});'."
";
$moreheadcontent .= '  }'."
";
$moreheadcontent .= '</script>'."
";
// JS del modulo con defer para no bloquear el render
$moreheadcontent .= '<script defer src="'.$pt_base.'/js/db.js'.$pt_v.'"></script>'."\n";
$moreheadcontent .= '<script defer src="'.$pt_base.'/js/app.js'.$pt_v.'"></script>'."\n";
$moreheadcontent .= '<script defer src="'.$pt_base.'/js/detalle.js'.$pt_v.'"></script>'."\n";
$moreheadcontent .= '<script defer src="'.$pt_base.'/js/admin.js'.$pt_v.'"></script>'."
";

// Cabecera anti-caché para forzar que el navegador recargue JS/CSS tras actualización
// Solo en la primera carga (no en peticiones AJAX)
if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Vary: Accept-Encoding');
    // Descomentar la siguiente línea SOLO la primera vez que despliegues una actualización,
    // luego vuelve a comentarla (es muy agresivo: borra TODA la caché del sitio):
    // header('Clear-Site-Data: "cache"');
}

llxHeader($moreheadcontent, $title);

print '<div class="pt-page">';

// Elementos PWA: cache note, toast container
print '<div id="pt-cache-note" class="pt-cache-note" style="display:none">📦 Datos guardados localmente — sin conexión con el servidor</div>';
print '<div id="pt-toast-container"></div>';

// Cabecera
print '<div class="pt-header">';
print '  <div class="pt-header-inner">';
print '    <div class="pt-header-left">';
print '      <div class="pt-logo">⚙</div>';
print '      <div>';
print '        <h1 class="pt-title">Mis Partes de Trabajo</h1>';
print '        <p class="pt-subtitle">Trabajos asignados pendientes de completar</p>';
print '        <p id="pt-last-sync"></p>';
print '      </div>';
print '    </div>';
print '    <div class="pt-badge-count">';
print '      <span class="pt-count-num">'.count($partes).'</span>';
print '      <span class="pt-count-lbl">'.( count($partes) === 1 ? 'parte' : 'partes').'</span>';
print '    </div>';
print '  </div>';
print '</div>';

// ── Barra de filtros ─────────────────────────────────────────────────────────
print '<div class="pt-filtros-wrap">';
print '  <form id="pt-filtro-bar" class="pt-filtro-bar" onsubmit="return false">';

// Filtro tipo
print '  <select id="ft-tipo" class="pt-filtro-select">';
print '    <option value="">🏷 Tipo</option>';
foreach ($tipos as $tId => $tData) {
    print '    <option value="'.htmlspecialchars($tData['label']).'">'.htmlspecialchars($tData['icon']).' '.htmlspecialchars($tData['label']).'</option>';
}
print '  </select>';

// Filtro estado — admins ven todos; técnicos solo los activos
print '  <select id="ft-estado" class="pt-filtro-select">';
print '    <option value="">📊 Estado</option>';
foreach ($statusLabels as $sId => $sData) {
    if ($esAdmin || in_array($sId, $statuts_activos)) {
        $icons = array(-1=>'🔴', 0=>'⚪', 1=>'🔵', 2=>'🟢', 3=>'🟣');
        $icon  = isset($icons[$sId]) ? $icons[$sId] : '';
        print '    <option value="'.htmlspecialchars($sData['label']).'">' . $icon . ' ' . htmlspecialchars($sData['label']) . '</option>';
    }
}
print '  </select>';

// Buscador unificado: filtra por cliente, ref y ref_client simultáneamente
print '  <input type="search" id="ft-buscar" class="pt-filtro-input" placeholder="🔍 Cliente, referencia…">';

// Botón limpiar
print '  <button type="button" id="pt-filtro-clear" class="pt-filtro-clear" title="Limpiar filtros">✕</button>';

print '  </form>';

// Selector de empleado (solo admins) — ver albaranes como otro técnico
if ($esAdmin) {
    $sqlUsuarios  = "SELECT u.rowid, u.login, u.firstname, u.lastname";
    $sqlUsuarios .= " FROM ".MAIN_DB_PREFIX."user u";
    $sqlUsuarios .= " WHERE u.statut = 1 AND u.entity IN (".getEntity('user').")";
    $sqlUsuarios .= " ORDER BY u.lastname ASC, u.firstname ASC";
    $resUsuarios  = $db->query($sqlUsuarios);

    print '  <div class="pt-empleado-wrap">';
    print '  <select id="ft-empleado" class="pt-filtro-select pt-empleado-select" title="Ver albaranes de un técnico">';
    print '    <option value="">👥 Todos los técnicos</option>';
    if ($resUsuarios) {
        while ($u = $db->fetch_object($resUsuarios)) {
            $nombre = trim($u->firstname.' '.$u->lastname) ?: $u->login;
            print '    <option value="'.(int)$u->rowid.'">'.htmlspecialchars($nombre).'</option>';
        }
        $db->free($resUsuarios);
    }
    print '  </select>';
    print '  </div>';
}

// Botón asignar (solo admins)
if ($esAdmin) {
    print '  <button class="pt-btn-asignar" id="pt-btn-asignar">👤 Asignar</button>';
}
print '</div>';


// Grid de fichas — renderizado inicial por PHP (SSR), app.js lo refresca desde IDB/API
// Los IDs ptGrid y ptEmpty son usados por app.js para manipular el DOM offline.
if (empty($partes)) {
    print '<div class="pt-grid" id="ptGrid" style="display:none"></div>';
    print '<div class="pt-empty" id="ptEmpty">';
    print '  <div class="pt-empty-icon">📋</div>';
    print '  <p class="pt-empty-title">Sin partes asignados</p>';
    print '  <p class="pt-empty-sub">No tienes partes de trabajo pendientes en este momento.</p>';
    print '</div>';
} else {
    print '<div id="ptEmpty" class="pt-empty" style="display:none">';
    print '  <div class="pt-empty-icon">📋</div>';
    print '  <p class="pt-empty-title">Sin partes asignados</p>';
    print '  <p class="pt-empty-sub">No tienes partes de trabajo pendientes en este momento.</p>';
    print '</div>';
    print '<div class="pt-grid" id="ptGrid">';

    foreach ($partes as $obj) {
        // tipo_albaran es numérico; mapeamos al array $tipos
        $tipoKey  = (int)$obj->tipo_albaran;
        $tieneFirmaTec = !empty($obj->firma_tecnico);
        $tieneFirmaCli = !empty($obj->firma_cliente);
        $firmado  = ($tieneFirmaTec || $tieneFirmaCli);
        // "En proceso": pedido validado (statut=1), con firma del técnico pero
        // todavía sin firma del cliente.
        $enProceso = ((int)$obj->statut === 1 && $tieneFirmaTec && !$tieneFirmaCli);
        $tipoInfo = isset($tipos[$tipoKey])
            ? $tipos[$tipoKey]
            : array('label' => 'Tipo '.$tipoKey, 'color' => '#6B7280', 'bg' => '#F3F4F6', 'icon' => '📄');

        // Dirección con la misma prioridad que el detalle:
        // 1. Contacto asignado  2. Sede del pedido  3. Central del cliente
        $dir_contacto_card = '';
        $loc_contacto_card = '';
        $sqlCt  = "SELECT ct.address, ct.zip, ct.town";
        $sqlCt .= " FROM ".MAIN_DB_PREFIX."element_contact ec";
        $sqlCt .= " INNER JOIN ".MAIN_DB_PREFIX."socpeople ct ON ct.rowid = ec.fk_socpeople";
        $sqlCt .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact ctc ON ctc.rowid = ec.fk_c_type_contact";
        $sqlCt .= " WHERE ec.element_id = ".(int)$obj->rowid." AND ctc.element = 'commande'";
        $sqlCt .= "   AND (ct.address != '' OR ct.town != '') LIMIT 1";
        $resCt = $db->query($sqlCt);
        if ($resCt && $db->num_rows($resCt) > 0) {
            $ctRow = $db->fetch_object($resCt);
            $dir_contacto_card = trim($ctRow->address ?? '');
            $loc_contacto_card = trim(($ctRow->zip ? $ctRow->zip.' ' : '').($ctRow->town ?? ''));
            $db->free($resCt);
        }

        if ($dir_contacto_card !== '') {
            $direccion = $dir_contacto_card;
            $localidad = $loc_contacto_card;
        } elseif (!empty($obj->dep_address)) {
            $direccion = $obj->dep_address;
            $localidad = trim(($obj->dep_zip ? $obj->dep_zip.' ' : '').$obj->dep_town);
        } else {
            $direccion = $obj->soc_address;
            $localidad = trim(($obj->soc_zip ? $obj->soc_zip.' ' : '').$obj->soc_town);
        }

        $fecha  = dol_print_date($db->jdate($obj->date_commande), 'day');
        if ($enProceso) {
            $stInfo = $enProcesoLabel;
        } else {
            $stInfo = isset($statusLabels[$obj->statut])
                ? $statusLabels[$obj->statut]
                : array('label' => 'Estado '.$obj->statut, 'color' => '#6B7280');
        }
        $url = DOL_URL_ROOT.'/commande/card.php?id='.(int)$obj->rowid;

        // Usamos <div role="button"> en lugar de <a href> para evitar que el navegador
        // navegue antes de que detalle.js pueda interceptar el click.
        // La URL de Dolibarr queda en data-url como fallback.
        print '<div class="pt-card" role="button" tabindex="0"'
            .' data-tipo="'.htmlspecialchars($tipoInfo['label']).'"'
            .' data-estado="'.htmlspecialchars($stInfo['label']).'"'
            .' data-statut="'.($enProceso ? '2' : (int)$obj->statut).'"'
            .' data-cliente="'.htmlspecialchars($obj->soc_nom).'"'
            .' data-ref="'.htmlspecialchars($obj->ref).'"'
            .' data-refcli="'.htmlspecialchars($obj->ref_client ?? '').'"'
            .' data-firmado="'.($firmado ? '1' : '0').'"'
            .' data-url="'.htmlspecialchars($url).'">';
        print '  <div class="pt-card-top">';
        print '    <span class="pt-tipo-badge" style="color:'.htmlspecialchars($tipoInfo['color'])
              .';background:'.htmlspecialchars($tipoInfo['bg']).'">'
              .htmlspecialchars($tipoInfo['icon']).' '.htmlspecialchars($tipoInfo['label']).'</span>';
        print '    <span class="pt-status-dot" style="background:'.htmlspecialchars($stInfo['color']).'"'
              .' title="'.htmlspecialchars($stInfo['label']).'"></span>';
        if ($firmado) {
            print '    <span class="pt-firmado-badge">✍ Firmado</span>';
        }
        print '  </div>';
        print '  <div class="pt-card-ref">'.htmlspecialchars($obj->ref).'</div>';
        if (!empty($obj->ref_client)) {
            print '  <div class="pt-card-refcli">Ref. cliente: '.htmlspecialchars($obj->ref_client).'</div>';
        }
        print '  <div class="pt-card-cliente"><span class="pt-card-icon">🏢</span>'
              .'<span>'.htmlspecialchars($obj->soc_nom).'</span></div>';
        if (!empty($direccion)) {
            print '  <div class="pt-card-dir"><span class="pt-card-icon">📍</span>'
                  .'<span>'.htmlspecialchars($direccion).'</span></div>';
        }
        if (!empty($localidad)) {
            print '  <div class="pt-card-loc"><span class="pt-loc-text">'.htmlspecialchars($localidad).'</span></div>';
        }
        print '  <div class="pt-card-footer">';
        print '    <span class="pt-fecha">📅 '.$fecha.'</span>';
        print '    <span class="pt-status-label" style="color:'.htmlspecialchars($stInfo['color']).'">'
              .htmlspecialchars($stInfo['label']).'</span>';
        print '  </div>';
        print '  <div class="pt-card-arrow">→</div>';
        print '</div>';
    }

    print '</div>'; // .pt-grid
}

print '</div>'; // .pt-page

// app.js gestiona SW, filtros, offline y sincronización
// Solo inicializamos los filtros inline como fallback por si app.js aún carga
print '<script>document.addEventListener("DOMContentLoaded",function(){';
print 'if(typeof wireFilters==="function")wireFilters();';
print '});</script>';

llxFooter();
$db->close();

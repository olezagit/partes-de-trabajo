<?php
/**
 * Página de configuración del módulo Partes de Trabajo
 *
 * Permite al administrador configurar:
 *  - El mapeo entre el valor numérico de ef.tipo_albaran y la etiqueta/color
 *  - Los estados de llx_commande que se consideran "activos" (visibles)
 *  - La plantilla PDF (modelo de documento de pedidos) con la que se genera el
 *    PDF que se adjunta/envía al cliente al terminar el parte, por cada tipo.
 *    Si un tipo no tiene plantilla definida se usa la predeterminada.
 */

$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 6 && !$res; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) {
        $res = @include $dir.'/main.inc.php';
    }
}
if (!$res) die('Imposible encontrar main.inc.php. __DIR__: '.__DIR__);

if (!$user->admin) {
    accessforbidden();
}

require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/modules_commande.php';

$langs->loadLangs(array('partes_trabajo@partes_trabajo', 'admin', 'orders'));
$action = GETPOST('action', 'aZ09');

// ── Plantillas PDF disponibles (las activadas en Pedidos → Plantillas de documentos) ──
// Incluye también las plantillas propias colocadas en custom/<modulo>/core/modules/commande/doc/
// siempre que estén activadas en esa pantalla de Dolibarr.
$modelosPdf = array();
$listaModelos = ModelePDFCommandes::liste_modeles($db);
if (is_array($listaModelos)) {
    $modelosPdf = $listaModelos; // nombre_modelo => descripción
}
$modeloPdfDefecto = !empty($conf->global->COMMANDE_ADDON_PDF) ? $conf->global->COMMANDE_ADDON_PDF : 'eratosthene';

// ── Guardar ───────────────────────────────────────────────────────────────────
if ($action === 'save') {
    // Statuts activos (checkboxes)
    $statuts = array();
    // Para admins, el estado 3 puede ser visible desde el setup
    foreach (array(1, 2, 3) as $s) {
        if (GETPOST('statut_'.$s, 'int')) $statuts[] = $s;
    }
    dolibarr_set_const($db, 'PARTES_STATUTS_ACTIVOS', implode(',', $statuts), 'chaine', 0, '', $conf->entity);

    // Labels personalizados de tipos (por si quieren renombrarlos)
    foreach (array(1, 2, 3, 4) as $t) {
        $label = trim(GETPOST('tipo_label_'.$t, 'alphanohtml'));
        if ($label !== '') {
            dolibarr_set_const($db, 'PARTES_TIPO_LABEL_'.$t, $label, 'chaine', 0, '', $conf->entity);
        }
    }

    // Plantilla PDF por tipo de parte. Vacío = predeterminada → se elimina la constante.
    foreach (array(1, 2, 3, 4) as $t) {
        $modelo = trim(GETPOST('pdf_model_'.$t, 'aZ09'));
        if ($modelo !== '' && isset($modelosPdf[$modelo])) {
            dolibarr_set_const($db, 'PARTES_PDF_MODEL_'.$t, $modelo, 'chaine', 0, '', $conf->entity);
        } else {
            dolibarr_del_const($db, 'PARTES_PDF_MODEL_'.$t, $conf->entity);
        }
    }

    setEventMessages('Configuración guardada correctamente.', null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ── Valores actuales ──────────────────────────────────────────────────────────
// Default: solo 1 y 2 visibles para técnicos. El 3 (Cerrado) se excluye siempre para ellos.
$statuts_activos_raw = $conf->global->PARTES_STATUTS_ACTIVOS ?? '1,2';
$statuts_activos     = array_map('intval', explode(',', $statuts_activos_raw));

// Labels personalizables (fallback a los defaults)
$tipo_labels_default = array(
    1 => 'Parte de trabajo',
    2 => 'Puesta en marcha',
    3 => 'Avería',
    4 => 'Mantenimiento',
);
$tipo_labels = array();
foreach ($tipo_labels_default as $id => $def) {
    $tipo_labels[$id] = $conf->global->{'PARTES_TIPO_LABEL_'.$id} ?? $def;
}

// Plantilla PDF guardada por tipo ('' = predeterminada)
$tipo_pdf_model = array();
foreach ($tipo_labels_default as $id => $def) {
    $tipo_pdf_model[$id] = $conf->global->{'PARTES_PDF_MODEL_'.$id} ?? '';
}

// Colores e iconos fijos (referencia visual)
$tipo_info = array(
    1 => array('color' => '#2563EB', 'bg' => '#DBEAFE', 'icon' => '🔧'),
    2 => array('color' => '#16A34A', 'bg' => '#DCFCE7', 'icon' => '▶️'),
    3 => array('color' => '#DC2626', 'bg' => '#FEE2E2', 'icon' => '⚠️'),
    4 => array('color' => '#D97706', 'bg' => '#FEF3C7', 'icon' => '🛠️'),
);

// Mapa de estados reales de llx_commande
$statut_names = array(
    1 => 'Confirmado — statut=1 (pedido validado, pendiente de ejecución)',
    2 => 'En proceso — statut=2 (en proceso de envío/ejecución)',
    3 => 'Cerrado/Enviado — statut=3 (se asigna al Terminar parte; los técnicos NO lo ven)',
);

llxHeader('', 'Configuración - Partes de Trabajo', '', '', 0, 0,
    array(), array(DOL_URL_ROOT.'/custom/partes_trabajo/css/partes.css'));

print load_fiche_titre('Configuración del módulo Partes de Trabajo', '', 'setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token"  value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

// ── Sección 1: cómo se asigna un técnico ─────────────────────────────────────
print dol_get_fiche_head(array(), '', 'Lógica de asignación de técnicos', -1);
print '<div class="info-box info-box-info">';
print '<span class="info-box-icon">ℹ️</span>';
print '<div class="info-box-content">';
print '<p>Un técnico está asignado a un parte cuando:</p>';
print '<ol>';
print '<li>Existe una entrada en <code>llx_actioncomm</code> con <code>fk_element = commande.rowid</code> y <code>elementtype = \'order\'</code>.</li>';
print '<li>Esa actividad tiene al usuario enlazado en <code>llx_actioncomm_resources</code> con <code>element_type = \'user\'</code>.</li>';
print '</ol>';
print '<p>Crea las actividades desde la ficha del pedido → pestaña <strong>Eventos/Agenda</strong>, y añade los técnicos como recursos.</p>';
print '</div></div>';
print dol_get_fiche_end();

// ── Sección 2: estados visibles ───────────────────────────────────────────────
print dol_get_fiche_head(array(), '', 'Estados de pedido visibles', -1);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Estado</th><th>Mostrar al técnico</th></tr>';
foreach ($statut_names as $s => $nombre) {
    $checked = in_array($s, $statuts_activos) ? ' checked' : '';
    print '<tr class="oddeven">';
    print '  <td>'.$nombre.'</td>';
    print '  <td><input type="checkbox" name="statut_'.$s.'" value="1"'.$checked.'></td>';
    print '</tr>';
}
print '</table>';
print '<p class="opacitymedium" style="padding:8px 0 0 4px">Los pedidos en borrador (0) y cancelados (-1) están siempre excluidos.</p>';
print dol_get_fiche_end();

// ── Sección 3: etiquetas de tipos ─────────────────────────────────────────────
print dol_get_fiche_head(array(), '', 'Tipos de parte (campo <code>tipo_albaran</code> en llx_commande_extrafields)', -1);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th style="width:60px">Valor BD</th><th style="width:200px">Etiqueta mostrada</th><th>Vista previa</th><th class="hideonsmartphone">Descripción</th></tr>';
foreach ($tipo_labels as $id => $label) {
    $info = $tipo_info[$id];
    print '<tr class="oddeven">';
    print '  <td style="text-align:center"><code>'.$id.'</code></td>';
    print '  <td><input type="text" name="tipo_label_'.$id.'" class="minwidth150"'
          .' value="'.htmlspecialchars($label).'"></td>';
    print '  <td>';
    print '    <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;'
          .'border-radius:999px;font-size:.8rem;font-weight:600;'
          .'background:'.$info['bg'].';color:'.$info['color'].'">';
    print $info['icon'].' '.htmlspecialchars($label);
    print '    </span>';
    print '  </td>';
    print '  <td class="hideonsmartphone opacitymedium">El campo <code>ef.tipo_albaran</code> debe contener el valor <strong>'.$id.'</strong> para mostrar esta etiqueta.</td>';
    print '</tr>';
}
print '</table>';
print '<p class="opacitymedium" style="padding:8px 0 0 4px">Los colores e iconos son fijos en el módulo. Si necesitas cambiarlos edita el array <code>$tipos</code> en <code>index.php</code>, <code>api/partes.php</code> y <code>js/app.js</code>.</p>';
print dol_get_fiche_end();

// ── Sección 4: plantilla PDF por tipo de parte ───────────────────────────────
print dol_get_fiche_head(array(), '', 'Plantilla PDF del albarán que se envía al cliente', -1);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th style="width:60px">Valor BD</th><th style="width:200px">Tipo de parte</th><th>Plantilla PDF</th><th class="hideonsmartphone">Fichero</th></tr>';
$hayModeloHuerfano = false;
foreach ($tipo_labels as $id => $label) {
    $info     = $tipo_info[$id];
    $guardado = $tipo_pdf_model[$id];
    $existe   = ($guardado === '' || isset($modelosPdf[$guardado]));
    if (!$existe) $hayModeloHuerfano = true;

    print '<tr class="oddeven">';
    print '  <td style="text-align:center"><code>'.$id.'</code></td>';
    print '  <td>'.$info['icon'].' '.htmlspecialchars($label).'</td>';
    print '  <td>';
    print '    <select name="pdf_model_'.$id.'" class="minwidth200">';
    print '      <option value=""'.($guardado === '' ? ' selected' : '').'>— Predeterminada —</option>';
    foreach ($modelosPdf as $nombreModelo => $descModelo) {
        $sel = ($guardado === $nombreModelo) ? ' selected' : '';
        print '      <option value="'.htmlspecialchars($nombreModelo).'"'.$sel.'>'
              .htmlspecialchars($descModelo ?: $nombreModelo).' ('.htmlspecialchars($nombreModelo).')</option>';
    }
    if (!$existe) {
        // La plantilla guardada ya no está activa/instalada: se muestra para que sea visible
        print '      <option value="'.htmlspecialchars($guardado).'" selected>⚠️ '.htmlspecialchars($guardado).' (no disponible)</option>';
    }
    print '    </select>';
    print '  </td>';
    print '  <td class="hideonsmartphone opacitymedium"><code>pdf_'.htmlspecialchars($guardado !== '' ? $guardado : $modeloPdfDefecto).'.modules.php</code></td>';
    print '</tr>';
}
print '</table>';
print '<p class="opacitymedium" style="padding:8px 0 0 4px">';
print 'Al terminar el parte, el PDF que se adjunta al correo (y se envía al cliente) se genera con la plantilla elegida para su tipo. ';
print 'Con <strong>Predeterminada</strong> se usa la plantilla que ya tenga el propio pedido o, si no tiene ninguna, la configurada por defecto en Dolibarr (actualmente <code>'.htmlspecialchars($modeloPdfDefecto).'</code>). ';
print 'Solo aparecen las plantillas activadas en <em>Inicio → Configuración → Módulos → Pedidos → Plantillas de documentos</em>; ';
print 'si instalas una plantilla propia en <code>custom/…/core/modules/commande/doc/</code>, actívala allí para que aparezca en esta lista.';
print '</p>';
if ($hayModeloHuerfano) {
    print '<p style="padding:4px 0 0 4px;color:#B45309">⚠️ Alguna plantilla guardada ya no está disponible. Mientras tanto, esos partes se generarán con la plantilla predeterminada. Elige otra y guarda para corregirlo.</p>';
}
print dol_get_fiche_end();

// ── Sección 5: referencia de tablas ──────────────────────────────────────────
print dol_get_fiche_head(array(), '', 'Referencia de tablas de base de datos', -1);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Tabla</th><th>Campo clave</th><th>Uso en el módulo</th></tr>';
$tablas = array(
    array('llx_commande',             'rowid, fk_statut, fk_soc, fk_delivery_address, date_commande', 'Pedido = Parte de trabajo'),
    array('llx_commande_extrafields', 'fk_object, tipo_albaran',  'Tipo de parte (int 1-4)'),
    array('llx_societe',              'rowid, nom, address, zip, town', 'Datos del cliente'),
    array('llx_societe_address',      'rowid, address, zip, town', 'Sede/dirección de entrega del pedido'),
    array('llx_actioncomm',           'id, fk_element, elementtype=\'order\'', 'Actividad vinculada al pedido'),
    array('llx_actioncomm_resources', 'fk_actioncomm, element_type=\'user\', fk_element=user.rowid', 'Técnico asignado'),
);
foreach ($tablas as $t) {
    print '<tr class="oddeven">';
    print '  <td><code>'.$t[0].'</code></td>';
    print '  <td><code style="font-size:.8rem">'.$t[1].'</code></td>';
    print '  <td>'.$t[2].'</td>';
    print '</tr>';
}
print '</table>';
print dol_get_fiche_end();

print '<div class="center" style="margin-top:16px">';
print '<input type="submit" class="button" value="Guardar configuración">';
print '</div>';

print '</form>';

llxFooter();
$db->close();

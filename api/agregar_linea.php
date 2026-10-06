<?php
/**
 * api/agregar_linea.php
 * Añade una nueva línea (tarea) a un parte de trabajo ya existente.
 *
 * Tres tipos de línea:
 *   - 'descriptiva' → línea de texto libre, sin producto vinculado.
 *   - 'producto'    → vinculada a un producto de Dolibarr (fk_product_type=0).
 *                     Requiere cantidad. Salvo en Mantenimiento (tipo_albaran=4),
 *                     el producto debe tener el extrafield "maquina" marcado
 *                     (se vuelve a comprobar aquí, nunca solo en el cliente).
 *   - 'servicio'    → vinculada a un servicio de Dolibarr (fk_product_type=1).
 *                     Requiere cantidad.
 *
 * Si el producto elegido tiene "maquina"=1, la línea se devuelve con
 * es_maquina=true: el frontend exige entonces rellenar su ficha técnica
 * (al menos marca, modelo y nº de serie) antes de poder terminar el parte.
 *
 * POST JSON: { parte_id, tipo_linea, label?, description?, fk_product?, qty? }
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = array();
$parteId     = (int)($data['parte_id']   ?? 0);
$tipoLinea   = $data['tipo_linea']       ?? '';
$label       = trim($data['label']       ?? '');
$description = trim($data['description'] ?? '');
$fkProduct   = (int)($data['fk_product'] ?? 0);
$qty         = isset($data['qty']) ? (float)$data['qty'] : 1.0;

if ($parteId <= 0 || !in_array($tipoLinea, array('descriptiva', 'producto', 'servicio'), true)) {
    http_response_code(400);
    die(json_encode(['error' => 'Parámetros inválidos']));
}
if ($tipoLinea === 'descriptiva' && $label === '' && $description === '') {
    http_response_code(400);
    die(json_encode(['error' => 'La línea descriptiva necesita un título o una descripción']));
}
if (in_array($tipoLinea, array('producto', 'servicio'), true)) {
    if ($fkProduct <= 0) {
        http_response_code(400);
        die(json_encode(['error' => 'Selecciona '.($tipoLinea === 'servicio' ? 'un servicio' : 'un producto').' de la lista']));
    }
    if (!($qty > 0)) {
        http_response_code(400);
        die(json_encode(['error' => 'Indica una cantidad válida']));
    }
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

// Cargar el pedido y su tipo de albarán
$sqlPedido  = "SELECT c.rowid, c.fk_statut, ef.tipo_albaran";
$sqlPedido .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlPedido .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sqlPedido .= " WHERE c.rowid = ".$parteId;
$sqlPedido .= "   AND c.entity IN (".getEntity('commande').")";
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

// No se pueden añadir líneas a partes cancelados (-1) o ya terminados (3)
if (in_array((int)$pedido->fk_statut, array(-1, 3), true)) {
    http_response_code(403);
    die(json_encode(['error' => 'Este parte ya está cerrado, no se pueden añadir más líneas']));
}

$tipoAlbaran = (int)$pedido->tipo_albaran;

$esMaquina     = false;
$labelFinal    = $label;
$descFinal     = $description;
$fkProductType = 0;
$prodRef       = '';

if ($fkProduct > 0) {
    $sqlP  = "SELECT p.rowid, p.ref, p.label, p.fk_product_type, pfe.maquina";
    $sqlP .= " FROM ".MAIN_DB_PREFIX."product p";
    $sqlP .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pfe ON pfe.fk_object = p.rowid";
    $sqlP .= " WHERE p.rowid = ".$fkProduct;
    $sqlP .= "   AND p.entity IN (".getEntity('product').")";
    $resP = $db->query($sqlP);
    if (!$resP || $db->num_rows($resP) === 0) {
        http_response_code(404);
        die(json_encode(['error' => 'Producto no encontrado']));
    }
    $prod = $db->fetch_object($resP);
    $db->free($resP);

    $tipoEsperado = ($tipoLinea === 'servicio') ? 1 : 0;
    if ((int)$prod->fk_product_type !== $tipoEsperado) {
        http_response_code(400);
        die(json_encode(['error' => 'El elemento seleccionado no es del tipo esperado ('.$tipoLinea.')']));
    }

    $esMaquina = !empty($prod->maquina);

    // Salvo en Mantenimiento (4), un producto solo se puede añadir si es máquina.
    // Se revalida en servidor: el filtro del buscador es solo una ayuda visual.
    if ($tipoLinea === 'producto' && $tipoAlbaran !== 4 && !$esMaquina) {
        http_response_code(403);
        die(json_encode(['error' => 'Para este tipo de parte solo se pueden añadir productos marcados como máquina']));
    }

    if ($labelFinal === '') $labelFinal = $prod->label;
    $fkProductType = (int)$prod->fk_product_type;
    $prodRef       = $prod->ref;
} else {
    // Línea descriptiva: sin cantidad real, se guarda 1 por consistencia con el esquema de commandedet
    $qty = 1.0;
}

// Siguiente posición (rang) dentro del pedido
$sqlRang = "SELECT MAX(rang) as maxr FROM ".MAIN_DB_PREFIX."commandedet WHERE fk_commande = ".$parteId;
$resRang = $db->query($sqlRang);
$rang    = 1;
if ($resRang && $db->num_rows($resRang) > 0) {
    $objRang = $db->fetch_object($resRang);
    $rang    = ($objRang->maxr !== null ? (int)$objRang->maxr : 0) + 1;
}

$sqlIns  = "INSERT INTO ".MAIN_DB_PREFIX."commandedet";
$sqlIns .= " (fk_commande, label, description, qty, tva_tx, subprice, remise_percent,";
$sqlIns .= "  total_ht, total_tva, total_ttc, product_type, fk_product, rang, special_code)";
$sqlIns .= " VALUES (";
$sqlIns .= "  ".$parteId.",";
$sqlIns .= "  '".$db->escape($labelFinal)."',";
$sqlIns .= "  '".$db->escape($descFinal)."',";
$sqlIns .= "  ".(float)$qty.",";
$sqlIns .= "  0, 0, 0, 0, 0, 0,";
$sqlIns .= "  ".(int)$fkProductType.",";
$sqlIns .= "  ".($fkProduct > 0 ? (int)$fkProduct : "NULL").",";
$sqlIns .= "  ".(int)$rang.",";
$sqlIns .= "  0";
$sqlIns .= ")";

$resIns = $db->query($sqlIns);
if (!$resIns) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al añadir la línea: '.$db->lasterror()]));
}
$lineaId = $db->last_insert_id(MAIN_DB_PREFIX."commandedet");

// Registrar quién añadió la línea (pt_creado_por). Es un campo interno del
// módulo (ver sql/create_extrafields.sql, sección 7): mientras esté vacío,
// una línea se considera "original" del pedido y solo se puede visualizar;
// las líneas con pt_creado_por marcado las puede editar/eliminar después su
// propio autor (o un admin) — ver api/editar_linea.php y api/eliminar_linea.php.
$sqlOwner  = "INSERT INTO ".MAIN_DB_PREFIX."commandedet_extrafields (fk_object, pt_creado_por)";
$sqlOwner .= " VALUES (".$lineaId.", ".$userId.")";
if (!$db->query($sqlOwner)) {
    // No crítico para el técnico (la línea ya se guardó), pero sin este
    // registro nadie podría luego editarla/eliminarla — se deja constancia.
    error_log('PartesTrabajo: no se pudo registrar pt_creado_por para la línea '.$lineaId.': '.$db->lasterror());
}

// Recalcular los totales del pedido. Las líneas de este módulo van a precio 0
// (esto no es una herramienta de facturación), pero conviene mantener los
// totales del pedido consistentes con sus líneas para no confundir a
// quien lo abra directamente en Dolibarr.
try {
    require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
    $objCommande = new Commande($db);
    if ($objCommande->fetch($parteId) > 0) {
        $objCommande->update_price(1);
    }
} catch (Throwable $e) {
    // No crítico: si falla el recálculo de precios no debe impedir haber
    // guardado ya la línea correctamente.
    error_log('PartesTrabajo: no se pudo recalcular precios del pedido '.$parteId.': '.$e->getMessage());
}

echo json_encode(array(
    'ok'    => true,
    'linea' => array(
        'rowid'           => (int)$lineaId,
        'label'           => $labelFinal,
        'description'     => $descFinal,
        'prod_ref'        => $prodRef,
        'qty'             => (float)$qty,
        'unidad'          => '',
        'tarea_realizada' => false,
        'es_maquina'      => $esMaquina,
        'tiene_producto'  => ($fkProduct > 0),
        'creado_por'      => $userId,
        'puede_editar'    => true, // quien la acaba de crear siempre puede editarla/eliminarla
        'ficha'           => new stdClass(), // objeto vacío -> {} en JSON (no [])
    ),
), JSON_UNESCAPED_UNICODE);

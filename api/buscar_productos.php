<?php
/**
 * api/buscar_productos.php
 * Busca productos o servicios en el catálogo de Dolibarr para añadirlos como
 * nueva línea de un parte de trabajo.
 *
 * Llamado via: index.php?pt_do=buscar_productos&q=texto&tipo_linea=producto|servicio&tipo_albaran=N
 *
 * Reglas de selección (ver especificación del módulo):
 *  - tipo_linea = 'servicio' → todos los servicios (fk_product_type = 1). Los
 *    servicios nunca están sujetos a la restricción de "máquina".
 *  - tipo_linea = 'producto' → productos (fk_product_type = 0). Salvo en
 *    partes de tipo Mantenimiento (tipo_albaran = 4), solo se devuelven los
 *    productos marcados con el extrafield "maquina" = 1 (llx_product_extrafields),
 *    para que el técnico no pueda vincular una línea de instalación/avería a
 *    un producto que no es una máquina real. En Mantenimiento no se filtra:
 *    se puede añadir cualquier producto (recambios, consumibles, etc).
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$q           = trim($_GET['q'] ?? '');
$tipoLinea   = $_GET['tipo_linea'] ?? '';
$tipoAlbaran = (int)($_GET['tipo_albaran'] ?? 0);

if (!in_array($tipoLinea, array('producto', 'servicio'), true)) {
    http_response_code(400);
    die(json_encode(['error' => 'tipo_linea inválido (usa "producto" o "servicio")']));
}

// Dolibarr: fk_product_type 0 = producto/bien, 1 = servicio
$fkProductType = ($tipoLinea === 'servicio') ? 1 : 0;

$sql  = "SELECT p.rowid, p.ref, p.label, p.fk_product_type, pfe.maquina";
$sql .= " FROM ".MAIN_DB_PREFIX."product p";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pfe ON pfe.fk_object = p.rowid";
$sql .= " WHERE p.entity IN (".getEntity('product').")";
$sql .= "   AND p.fk_product_type = ".(int)$fkProductType;

// Restricción "máquina": solo aplica a productos (no a servicios) y se
// levanta para partes de tipo Mantenimiento (4)
if ($tipoLinea === 'producto' && $tipoAlbaran !== 4) {
    $sql .= "   AND pfe.maquina = 1";
}

if ($q !== '') {
    $qEsc = $db->escape($q);
    $sql .= "   AND (p.ref LIKE '%".$qEsc."%' OR p.label LIKE '%".$qEsc."%')";
}

$sql .= " ORDER BY p.label ASC";
$sql .= " LIMIT 25";

$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    die(json_encode(['error' => 'Error SQL: '.$db->lasterror()]));
}

$productos = array();
while ($p = $db->fetch_object($resql)) {
    $productos[] = array(
        'rowid'   => (int)$p->rowid,
        'ref'     => $p->ref,
        'label'   => $p->label,
        'maquina' => !empty($p->maquina),
    );
}
$db->free($resql);

echo json_encode(['productos' => $productos, 'count' => count($productos)], JSON_UNESCAPED_UNICODE);

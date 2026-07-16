<?php
/**
 * ajax.php — Dispatcher AJAX del módulo Partes de Trabajo
 *
 * URL de llamada: /custom/partes_trabajo/ajax.php?action=partes
 *                 /custom/partes_trabajo/ajax.php?action=detalle&id=N
 *
 * Este archivo está en la raíz del módulo (mismo nivel que index.php),
 * por lo que Apache lo sirve directamente sin interferencia del .htaccess
 * de Dolibarr (que solo redirige rutas sin extensión o rutas inexistentes).
 */

// Seguridad: solo peticiones AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

// Bootstrap Dolibarr
$res = 0; $dir = __DIR__;
for ($i = 0; $i < 6 && !$res; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) $res = @include $dir.'/main.inc.php';
}
if (!$res) {
    http_response_code(500);
    die(json_encode(['error' => 'main.inc.php no encontrado. __DIR__: '.__DIR__]));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300, must-revalidate');

// Autenticación
if (!$user || !$user->id) {
    http_response_code(401);
    die(json_encode(['error' => 'No autenticado']));
}

$tiene_permiso = !empty($user->admin)
    || (!empty($user->rights->partes_trabajo) && !empty($user->rights->partes_trabajo->leer));
if (!$tiene_permiso) {
    http_response_code(403);
    die(json_encode(['error' => 'Sin permiso. Active el modulo Partes de Trabajo y asigne permisos al usuario.']));
}

// Dispatcher
define('PT_FROM_DISPATCHER', true);

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'partes') {
    require __DIR__.'/api/partes.php';
} elseif ($action === 'detalle') {
    require __DIR__.'/api/parte_detalle.php';
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Accion desconocida: '.htmlspecialchars($action)]);
}

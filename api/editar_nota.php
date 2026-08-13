<?php
/**
 * api/editar_nota.php
 * Edita una anotación EXISTENTE (llx_partes_notas), pero SOLO si pertenece
 * al usuario que hace la petición. Un técnico nunca puede editar anotaciones
 * de otro técnico, aunque manipule la petición a mano.
 *
 * Llamado via: index.php?pt_do=editar_nota  (POST, JSON body: {id_nota, nota})
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

$notaId = (int)($data['id_nota'] ?? 0);
$nota   = trim($data['nota'] ?? '');

if ($notaId <= 0 || $nota === '') {
    http_response_code(400);
    die(json_encode(['error' => 'ID de nota o texto inválidos']));
}

$userId = (int)$user->id;

// Comprobar que la nota existe y que el autor es quien la está editando.
// Esta comprobación es la que de verdad protege el dato: el botón "Editar"
// solo se muestra en el cliente para las notas propias, pero aquí se
// verifica de nuevo por si alguien intenta editar otra nota a mano.
$sqlCheck  = "SELECT rowid, fk_commande, fk_user";
$sqlCheck .= " FROM ".MAIN_DB_PREFIX."partes_notas";
$sqlCheck .= " WHERE rowid = ".$notaId;
$sqlCheck .= "   AND entity = ".(int)$conf->entity;

$resql = $db->query($sqlCheck);
if (!$resql || $db->num_rows($resql) === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'La anotación no existe']));
}
$notaActual = $db->fetch_object($resql);
$db->free($resql);

if ((int)$notaActual->fk_user !== $userId) {
    http_response_code(403);
    die(json_encode(['error' => 'Solo puedes editar tus propias anotaciones']));
}

// Actualizar
$sqlUpdate  = "UPDATE ".MAIN_DB_PREFIX."partes_notas";
$sqlUpdate .= " SET nota = '".$db->escape($nota)."', date_modif = NOW()";
$sqlUpdate .= " WHERE rowid = ".$notaId;
$sqlUpdate .= "   AND fk_user = ".$userId; // doble seguro a nivel de UPDATE

$resUpdate = $db->query($sqlUpdate);
if (!$resUpdate) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar: '.$db->lasterror()]));
}

echo json_encode([
    'ok'    => true,
    'nota'  => [
        'id'     => $notaId,
        'texto'  => $nota,
        'fecha_modif' => date('d/m/Y H:i'),
    ],
]);

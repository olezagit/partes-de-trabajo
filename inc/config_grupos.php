<?php
/**
 * inc/config_grupos.php
 *
 * ID del grupo de usuarios de Dolibarr "SERVICIO TÉCNICO" (llx_usergroup.rowid),
 * usado para decidir qué empleados aparecen en el desglose por técnico de
 * admin/estadisticas.php y en api/estadisticas_tecnico.php. Único sitio donde
 * se fija este valor: si el grupo se llegara a recrear con otro ID en el
 * futuro, solo hay que cambiarlo aquí.
 */
if (!defined('PT_GRUPO_SERVICIO_TECNICO_ID')) {
    define('PT_GRUPO_SERVICIO_TECNICO_ID', 2);
}

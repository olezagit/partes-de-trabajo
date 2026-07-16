<?php
// debug.php — muestra el error PHP exacto que causa el 500
// ELIMINAR tras diagnosticar
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap Dolibarr
$res = 0; $dir = __DIR__;
for ($i = 0; $i < 6 && !$res; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) $res = @include $dir.'/main.inc.php';
}
if (!$res) die('Bootstrap failed. __DIR__: '.__DIR__);

echo '<pre>Bootstrap OK. DOL_URL_ROOT=' . DOL_URL_ROOT . '</pre>';
echo '<pre>User: ' . ($user->id ?? 'null') . ' Admin: ' . ($user->admin ?? 'null') . '</pre>';

// Test group query
$userId = (int)($user->id ?? 0);
$sqlGrp  = "SELECT g.rowid, g.nom FROM ".MAIN_DB_PREFIX."usergroup g";
$sqlGrp .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user gu ON gu.fk_usergroup = g.rowid AND gu.fk_user = ".$userId;
$sqlGrp .= " WHERE (UPPER(g.nom) LIKE 'ADMINISTR%')";
$sqlGrp .= " AND g.entity IN (".getEntity('usergroup').")";
$resGrp = $db->query($sqlGrp);
if ($resGrp) {
    echo '<pre>Group query OK. Rows: '.$db->num_rows($resGrp).'</pre>';
    while ($g = $db->fetch_object($resGrp)) echo '<pre>Group: '.$g->nom.'</pre>';
} else {
    echo '<pre>Group query ERROR: '.$db->lasterror().'</pre>';
}

// Test tipos config
$conf_test = $conf->global->PARTES_TIPO_LABEL_1 ?? 'Parte de trabajo';
echo '<pre>TIPO_LABEL_1: '.$conf_test.'</pre>';

// Test statut query
$sql = "SELECT c.rowid, c.ref, c.fk_statut FROM ".MAIN_DB_PREFIX."commande c LIMIT 3";
$r = $db->query($sql);
if ($r) {
    echo '<pre>Commande query OK. Rows: '.$db->num_rows($r).'</pre>';
    while ($o = $db->fetch_object($r)) echo '<pre>'.htmlspecialchars($o->ref).' statut='.$o->fk_statut.'</pre>';
} else {
    echo '<pre>Commande ERROR: '.$db->lasterror().'</pre>';
}

echo '<hr><b>Checks OK. El 500 debe estar en el HTML rendering. Revisa los siguientes pasos:</b>';
echo '<pre>1. PT_BASE = '.dirname($_SERVER['PHP_SELF']).'</pre>';
echo '<pre>2. PHP version: '.phpversion().'</pre>';

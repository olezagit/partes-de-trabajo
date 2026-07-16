<?php
// Test minimo - sin dependencias de Dolibarr
// Muestra informacion del servidor y archivos del modulo
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Partes de Trabajo</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: monospace; font-size: 14px; background: #0F172A; color: #E2E8F0; padding: 20px; }
h1 { color: #60A5FA; margin-bottom: 20px; font-size: 18px; }
h2 { color: #94A3B8; margin: 20px 0 8px; font-size: 14px; text-transform: uppercase; letter-spacing: .05em; }
.ok   { color: #4ADE80; }
.fail { color: #F87171; }
.warn { color: #FBBF24; }
p { margin: 4px 0; line-height: 1.6; }
code { background: #1E293B; padding: 1px 6px; border-radius: 4px; }
table { width: 100%; border-collapse: collapse; margin: 8px 0; }
td, th { padding: 6px 10px; border: 1px solid #334155; text-align: left; }
th { background: #1E293B; color: #64748B; }
</style>
</head>
<body>
<h1>🔧 Test — Partes de Trabajo</h1>

<h2>Rutas del servidor</h2>
<p>__DIR__: <code><?= htmlspecialchars(__DIR__) ?></code></p>
<p>PHP_SELF: <code><?= htmlspecialchars($_SERVER['PHP_SELF']) ?></code></p>
<p>DOCUMENT_ROOT: <code><?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') ?></code></p>
<p>HTTP_HOST: <code><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'n/a') ?></code></p>
<?php
$pt_self = dirname($_SERVER['PHP_SELF']);
$pt_base = rtrim($pt_self, '/');
?>
<p>PT_BASE calculado: <code><?= htmlspecialchars($pt_base) ?></code></p>
<p>URL API partes: <code><?= htmlspecialchars($pt_base.'/ajax.php?action=partes') ?></code></p>
<p>URL API detalle: <code><?= htmlspecialchars($pt_base.'/ajax.php?action=detalle&id=1') ?></code></p>

<h2>Busqueda de main.inc.php</h2>
<?php
$dir = __DIR__;
$found = false;
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . '/main.inc.php';
    $exists = file_exists($candidate);
    echo '<p>' . ($exists ? '<span class="ok">✅' : '<span class="fail">❌') . ' [+'.$i.'] ' . htmlspecialchars($candidate) . '</span></p>';
    if ($exists && !$found) {
        $found = true;
        echo '<p class="ok" style="margin-left:20px">→ Encontrado aqui</p>';
    }
    $dir = dirname($dir);
}
if (!$found) {
    echo '<p class="fail">❌ main.inc.php no encontrado en ninguna ruta</p>';
}
?>

<h2>Archivos del modulo</h2>
<table>
<tr><th>Archivo</th><th>Estado</th><th>Tamano</th></tr>
<?php
$archivos = [
    'index.php', 'ajax.php', 'sw.js', 'manifest.json', 'offline.html', 'test.php',
    'css/partes.css', 'css/detalle.css',
    'js/db.js', 'js/app.js', 'js/detalle.js',
    'api/partes.php', 'api/parte_detalle.php', 'api/.htaccess',
    'core/modules/modPartesTrabajo.class.php',
    'langs/es_ES/partes_trabajo.lang',
    'admin/setup.php',
];
foreach ($archivos as $f) {
    $path = __DIR__.'/'.$f;
    $exists = file_exists($path);
    $size = $exists ? number_format(filesize($path)) . ' B' : '—';
    $cls = $exists ? 'ok' : 'fail';
    $icon = $exists ? '✅' : '❌ FALTA';
    echo '<tr><td><code>'.$f.'</code></td><td class="'.$cls.'">'.$icon.'</td><td>'.$size.'</td></tr>';
}
?>
</table>

<h2>PHP Info relevante</h2>
<p>PHP Version: <code><?= phpversion() ?></code></p>
<p>curl habilitado: <code><?= function_exists('curl_init') ? 'Si' : 'No' ?></code></p>
<p>PDO habilitado: <code><?= class_exists('PDO') ? 'Si' : 'No' ?></code></p>
<p>Session activa: <code><?= session_status() === PHP_SESSION_ACTIVE ? 'Si' : 'No' ?></code></p>
<?php
$cookies = array_keys($_COOKIE);
echo '<p>Cookies: <code>'.htmlspecialchars(implode(', ', $cookies) ?: 'ninguna').'</code></p>';
?>

<h2>6. Contenido del .htaccess de Dolibarr</h2>
<?php
// Buscar el .htaccess principal de Dolibarr
$htaccess_paths = [
    dirname(dirname(__DIR__)) . '/.htaccess',      // htdocs/.htaccess
    dirname(dirname(dirname(__DIR__))) . '/.htaccess', // un nivel más arriba
    $_SERVER['DOCUMENT_ROOT'] . '/gestion/htdocs/.htaccess',
    $_SERVER['DOCUMENT_ROOT'] . '/.htaccess',
];
$found_ht = false;
foreach ($htaccess_paths as $ht) {
    if (file_exists($ht)) {
        echo '<p class="ok">✅ Encontrado: <code>' . htmlspecialchars($ht) . '</code></p>';
        echo '<pre style="background:#1E293B;padding:12px;border-radius:8px;overflow-x:auto;font-size:11px;color:#94A3B8;max-height:300px;overflow-y:auto">';
        echo htmlspecialchars(file_get_contents($ht));
        echo '</pre>';
        $found_ht = true;
        break;
    }
}
if (!$found_ht) echo '<p class="fail">❌ No encontrado en ninguna ruta conocida</p>';

// También mostrar el .htaccess de custom/ si existe
$ht_custom = dirname(__DIR__) . '/.htaccess';
if (file_exists($ht_custom)) {
    echo '<p class="ok">✅ custom/.htaccess: <code>' . htmlspecialchars($ht_custom) . '</code></p>';
    echo '<pre style="background:#1E293B;padding:12px;border-radius:8px;overflow-x:auto;font-size:11px;color:#94A3B8">';
    echo htmlspecialchars(file_get_contents($ht_custom));
    echo '</pre>';
}
?>

<h2>7. Limpiar cache del navegador (IMPORTANTE tras actualizar)</h2>
<p>Si el navegador sigue usando archivos viejos, sigue estos pasos:</p>
<ol style="margin:10px 0 0 20px;line-height:2">
<li>Abre las DevTools (F12) → pestaña <strong>Application</strong></li>
<li>En el panel izquierdo: <strong>Service Workers</strong> → pulsa <strong>Unregister</strong></li>
<li>En el panel izquierdo: <strong>Cache Storage</strong> → borra todas las entradas <code>pt-*</code></li>
<li>Cierra DevTools y recarga la página con <strong>Ctrl+Shift+R</strong> (recarga forzada)</li>
</ol>
<p style="margin-top:12px">O más rápido: en Chrome dirígete a <code>chrome://settings/clearBrowserData</code> y borra "Archivos e imágenes en caché".</p>

<h2>8. Test directo de ajax.php (desde el servidor)</h2>
<?php
$pt_self = dirname($_SERVER['PHP_SELF']);
$pt_base = rtrim($pt_self, '/');
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ajax_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $pt_base . '/ajax.php?action=partes';
echo '<p>URL probada: <code>' . htmlspecialchars($ajax_url) . '</code></p>';

if (function_exists('curl_init')) {
    // Pasar las cookies de sesión para que Dolibarr autentique
    $cookie_str = '';
    foreach ($_COOKIE as $k => $v) $cookie_str .= "$k=$v; ";

    $ch = curl_init($ajax_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
        CURLOPT_COOKIE         => $cookie_str,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);
    $err = curl_error($ch);
    curl_close($ch);

    $cls = ($http_code >= 200 && $http_code < 300) ? 'ok' : 'fail';
    echo '<p class="'.$cls.'">HTTP ' . $http_code . ($err ? ' — Error: '.htmlspecialchars($err) : '') . '</p>';
    echo '<pre style="background:#1E293B;padding:12px;border-radius:8px;font-size:11px;color:#94A3B8;max-height:200px;overflow:auto">';
    echo htmlspecialchars(substr($body, 0, 1000));
    echo '</pre>';
} else {
    echo '<p class="warn">⚠️ curl no disponible</p>';
}

// También probar si el archivo es accesible directamente
echo '<h3 style="margin-top:12px;color:#94A3B8">Existencia física de ajax.php:</h3>';
echo '<p>' . (file_exists(__DIR__.'/ajax.php') ? '<span class="ok">✅ Existe en disco</span>' : '<span class="fail">❌ No existe</span>') . '</p>';
?>

<p style="margin-top:30px; color:#475569">Elimina <code>test.php</code> del servidor tras verificar.</p>
</body>
</html>

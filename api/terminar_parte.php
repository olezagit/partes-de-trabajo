<?php
/**
 * api/terminar_parte.php
 * Guarda las firmas (base64) y datos de firmante en llx_commande_extrafields
 * y cambia el estado del pedido a "Cerrado" (fk_statut=3).
 *
 * POST body JSON:
 * {
 *   id:             int,      // rowid del pedido
 *   firma_cliente:  string,   // dataURL base64 del canvas
 *   nombre_cliente: string,   // nombre del firmante cliente
 *   dni_cliente:    string,   // DNI del firmante cliente
 *   firma_tecnico:  string,   // dataURL base64 del canvas
 * }
 */

if (!defined('PT_FROM_DISPATCHER')) {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado']));
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

$parteId       = (int)($data['id']             ?? 0);
$firmaCliente  = trim($data['firma_cliente']   ?? '');
$nombreCliente = trim($data['nombre_cliente']  ?? '');
$dniCliente    = trim($data['dni_cliente']     ?? '');
$firmaTecnico  = trim($data['firma_tecnico']   ?? '');
$horaInicio    = trim($data['horadeinicio']    ?? '');
$horaFin       = trim($data['horadefin']       ?? '');

if ($parteId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'ID de parte inválido']));
}
if (empty($firmaCliente) || empty($firmaTecnico)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ambas firmas son obligatorias']));
}
if (empty($nombreCliente) || empty($dniCliente)) {
    http_response_code(400);
    die(json_encode(['error' => 'Nombre y DNI del cliente son obligatorios']));
}
if (empty($horaInicio) || empty($horaFin)) {
    http_response_code(400);
    die(json_encode(['error' => 'La hora de inicio y de fin son obligatorias']));
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

// Verificar acceso
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

// Fecha y hora de firma
$fechaFirma = date('d/m/Y H:i');
$userObj    = new User($db);
$userObj->fetch($userId);
$nombreTecnico = trim($userObj->firstname.' '.$userObj->lastname) ?: $userObj->login;

// Guardar en commande_extrafields
// Comprobar si ya existe el registro extrafields
$sqlEx = "SELECT rowid FROM ".MAIN_DB_PREFIX."commande_extrafields WHERE fk_object = ".$parteId;
$resEx = $db->query($sqlEx);
$existeEx = $resEx && $db->num_rows($resEx) > 0;

if ($existeEx) {
    $sqlSave  = "UPDATE ".MAIN_DB_PREFIX."commande_extrafields SET";
    $sqlSave .= "  firma_cliente   = '".$db->escape($firmaCliente)."',";
    $sqlSave .= "  nombre_firmante = '".$db->escape($nombreCliente)."',";
    $sqlSave .= "  dni_firmante    = '".$db->escape($dniCliente)."',";
    $sqlSave .= "  firma_tecnico   = '".$db->escape($firmaTecnico)."',";
    $sqlSave .= "  tec_firmante   = '".$db->escape($userId)."',";
    $sqlSave .= "  fecha_firma     = '".$db->escape($fechaFirma)."',";
    $sqlSave .= "  horadeinicio    = '".$db->escape($horaInicio)."',";
    $sqlSave .= "  horadefinalizacion = '".$db->escape($horaFin)."'";
    $sqlSave .= " WHERE fk_object = ".$parteId;
} else {
    $sqlSave  = "INSERT INTO ".MAIN_DB_PREFIX."commande_extrafields";
    $sqlSave .= " (fk_object, firma_cliente, nombre_firmante, dni_firmante, tec_firmante, firma_tecnico, fecha_firma, horadeinicio, horadefinalizacion)";
    $sqlSave .= " VALUES (";
    $sqlSave .= $parteId.",";
    $sqlSave .= "'".$db->escape($firmaCliente)."',";
    $sqlSave .= "'".$db->escape($nombreCliente)."',";
    $sqlSave .= "'".$db->escape($dniCliente)."',";
    $sqlSave .= "'".$db->escape($userId)."',";
    $sqlSave .= "'".$db->escape($firmaTecnico)."',";
    $sqlSave .= "'".$db->escape($fechaFirma)."',";
    $sqlSave .= "'".$db->escape($horaInicio)."',";
    $sqlSave .= "'".$db->escape($horaFin)."'";
    $sqlSave .= ")";
}

$resSave = $db->query($sqlSave);
if (!$resSave) {
    http_response_code(500);
    die(json_encode(['error' => 'Error al guardar firmas: '.$db->lasterror()]));
}

// Cambiar estado a 3 (Cerrado/Enviado) para que desaparezca del listado de técnicos
$sqlEstado  = "UPDATE ".MAIN_DB_PREFIX."commande";
$sqlEstado .= " SET fk_statut = 3, tms = NOW()";
$sqlEstado .= " WHERE rowid = ".$parteId;
if (!$db->query($sqlEstado)) {
    error_log('PartesTrabajo: error cambiando estado commande '.$parteId.': '.$db->lasterror());
}

// Añadir nota automática
$nota = "[$fechaFirma] Parte firmado por $nombreCliente (DNI: $dniCliente) y técnico $nombreTecnico";
$sqlNota  = "UPDATE ".MAIN_DB_PREFIX."commande SET";
$sqlNota .= "  note_public = CONCAT('".$db->escape($nota)."',";
$sqlNota .= "    IF(note_public IS NULL OR note_public = '', '', CONCAT('\n\n', note_public))),";
$sqlNota .= "  tms = NOW()";
$sqlNota .= " WHERE rowid = ".$parteId;
$db->query($sqlNota);

// ── Generación del PDF ───────────────────────────────────────────────────────
// Regeneramos el PDF del albarán con las firmas ya guardadas, usando el mismo
// modelo que usa Dolibarr (pdf_eratosthene u otro configurado en el sistema).
$pdfGenerado = false;
$pdfErrorMsg = '';

try {
    require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
    require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

    require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

    $objCommande = new Commande($db);
    if ($objCommande->fetch($parteId) > 0) {
        $objCommande->fetch_thirdparty();
        $objCommande->fetch_lines();  // imprescindible: TCPDF itera las líneas del pedido

        // Usar modelo configurado en Dolibarr o fallback a eratosthene
        $modeloPdf = !empty($objCommande->modelpdf)
            ? $objCommande->modelpdf
            : (!empty($conf->global->COMMANDE_ADDON_PDF) ? $conf->global->COMMANDE_ADDON_PDF : 'eratosthene');

        // Eliminar PDFs previos para asegurar que el adjunto incluye las firmas
        $dirPrevia = DOL_DATA_ROOT.'/commande/'.$objCommande->ref.'/';
        if (is_dir($dirPrevia)) {
            foreach ((glob($dirPrevia.'*.pdf') ?: array()) as $pdfViejo) {
                @unlink($pdfViejo);
            }
        }

        // Regenerar con el modelo configurado en Dolibarr
        $langs->loadLangs(array('main', 'bills', 'orders', 'products', 'companies'));
        $result = $objCommande->generateDocument($modeloPdf, $langs);
        if ($result > 0) {
            $pdfGenerado = true;
        } else {
            $pdfErrorMsg = is_array($objCommande->errors)
                ? implode(', ', $objCommande->errors)
                : ($objCommande->error ?: 'Error desconocido en generateDocument');
        }
    } else {
        $pdfErrorMsg = 'No se pudo cargar el pedido ID '.$parteId;
    }
} catch (Exception $e) {
    $pdfErrorMsg = 'Excepción al generar PDF: '.$e->getMessage();
}

if (!$pdfGenerado) {
    error_log('PartesTrabajo: error generando PDF para commande '.$parteId.': '.$pdfErrorMsg);
}

// ── Envío de correos ─────────────────────────────────────────────────────────
// Obtener datos del pedido y del cliente para los correos
$sqlMail  = "SELECT c.ref, c.fk_statut,";
$sqlMail .= "  ef.enviar_ok,";
$sqlMail .= "  ef.enviado,";
$sqlMail .= "  s.nom AS soc_nom, s.email AS soc_email";
$sqlMail .= " FROM ".MAIN_DB_PREFIX."commande c";
$sqlMail .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
$sqlMail .= " LEFT  JOIN ".MAIN_DB_PREFIX."commande_extrafields ef ON ef.fk_object = c.rowid";
$sqlMail .= " WHERE c.rowid = ".$parteId;
$resMail  = $db->query($sqlMail);
$objMail  = $resMail ? $db->fetch_object($resMail) : null;

if ($objMail && $objMail->enviado == 0) {
    $refAlbaran  = $objMail->ref;
    $enviarOk    = !empty($objMail->enviar_ok);
    $emailCliente = trim($objMail->soc_email ?? '');
    $nomCliente   = trim($objMail->soc_nom   ?? '');

    // Configuración de correo (usa la configuración SMTP de Dolibarr)
    $emailOrigen  = 'notificaciones@comercialoleza.com';
    $emailAdmin   = 'administracion@comercialoleza.com';

    // Firma corporativa HTML
    $firmaCorp  = '<br><br>';
    $firmaCorp .= '<b>Este correo se genera automáticamente desde nuestros servidores. ';
    $firmaCorp .= 'No se generará respuesta desde este correo. En caso de consulta o sugerencia, ';
    $firmaCorp .= 'no dude en contactarnos a través de nuestros correos de contacto habituales.</b>';
    $firmaCorp .= '<br><br>Un saludo.<br><br>';
    $firmaCorp .= 'Para proteger el medio ambiente, no imprima este email si no es necesario.<br><br>';
    $firmaCorp .= '<b>COMERCIAL FRIGORÍFICA OLEZA S.L.</b><br>';
    $firmaCorp .= 'C/Enrique Monsonis Domingo, Nave 6 - Pol. Ind. Virgen del Carmen<br>';
    $firmaCorp .= 'C.I.F.: B-03355419<br>';
    $firmaCorp .= 'Telf: 96 674 21 90<br>';
    $firmaCorp .= 'Email: <a href="mailto:administracion@comercialoleza.com">administracion@comercialoleza.com</a><br>';
    $firmaCorp .= '<a href="https://www.comercialoleza.com">www.comercialoleza.com</a>';

    require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

    // ── Buscar el PDF generado ────────────────────────────────────────────────
    // Dolibarr guarda los PDFs en DOL_DATA_ROOT/commande/{ref}/
    // También lo buscamos via getMultidirOutput si $objCommande está disponible
    $dirPdf  = DOL_DATA_ROOT.'/commande/'.$refAlbaran.'/';

    // Intentar obtener la ruta exacta del documento principal si lo tenemos
    if (isset($objCommande) && is_object($objCommande) && !empty($objCommande->last_main_doc)) {
        $candidato = DOL_DATA_ROOT.'/'.$objCommande->last_main_doc;
        if (file_exists($candidato)) $dirPdf = dirname($candidato).'/';
    }

    $pdfPath = '';
    $pdfName = '';

    if (is_dir($dirPdf)) {
        $archivos = glob($dirPdf.'*.pdf');
        if (!empty($archivos)) {
            usort($archivos, function($a, $b) { return filemtime($b) - filemtime($a); });
            $pdfPath = $archivos[0];
            $pdfName = basename($pdfPath);
        }
    }

    $tienePdf    = ($pdfPath !== '' && file_exists($pdfPath));
    $sinPdfAviso = !$tienePdf;

    // ── Función auxiliar para enviar usando CMailFile ─────────────────────────
    $enviarCorreo = function(
        $para, $asunto, $cuerpo,
        $adjuntoPath = '', $adjuntoNombre = '', $parteId
    ) use ($emailOrigen, $conf, $langs, $db) {
        $attachments = $adjuntoPath !== '' ? array($adjuntoPath) : array();
        $mimes       = $adjuntoPath !== '' ? array('application/pdf') : array();
        $nombres     = $adjuntoPath !== '' ? array($adjuntoNombre)   : array();

        $mail = new CMailFile(
            $asunto,
            $para,
            $emailOrigen,
            $cuerpo,
            $attachments,
            $mimes,
            $nombres,
            '',  // CC
            '',  // BCC
            0,   // delivery receipt
            1,   // ishtml
            '',  // errors to
            '',  // css
            '',  // trackid
            '',  // moreinheader
            'standard'
        );
        $result = $mail->sendfile();
        if (!$result) {
            error_log('PartesTrabajo: error enviando correo a '.$para.': '.$mail->error);
        }else{
			$sqlVerific  = "UPDATE ".MAIN_DB_PREFIX."commande_extrafields SET";
			$sqlVerific .= " enviado = 1";
			$sqlVerific .= " WHERE fk_object = ".$parteId;
			$db->query($sqlVerific);
		}
        return $result;
    };

    // ── Correo 1: SIEMPRE a administración (con PDF si existe) ───────────────
    $asuntoAdmin  = 'ALBARÁN '.$refAlbaran.' FIRMADO Y TERMINADO';
    $cuerpoAdmin  = '<p>El albarán <strong>'.$refAlbaran.'</strong> ha sido completado y firmado.</p>';
    $cuerpoAdmin .= '<ul>';
    $cuerpoAdmin .= '<li><strong>Cliente:</strong> '.$nomCliente.'</li>';
    $cuerpoAdmin .= '<li><strong>Firmante:</strong> '.$nombreCliente.' (DNI: '.$dniCliente.')</li>';
    $cuerpoAdmin .= '<li><strong>Técnico:</strong> '.$nombreTecnico.'</li>';
    /*$cuerpoAdmin .= '<li><strong>Hora inicio:</strong> '.$horaInicio.'</li>';
    $cuerpoAdmin .= '<li><strong>Hora fin:</strong> '.$horaFin.'</li>';*/
    $cuerpoAdmin .= '<li><strong>Fecha firma:</strong> '.$fechaFirma.'</li>';
    $cuerpoAdmin .= '</ul>';

    if ($sinPdfAviso) {
        $cuerpoAdmin .= '<p style="color:#c0392b;font-weight:bold;">⚠️ AVISO: No se pudo generar o encontrar el PDF del albarán '
            .$refAlbaran.'. No se ha podido adjuntar ni enviar al cliente.</p>';
        if (!empty($pdfErrorMsg)) {
            $cuerpoAdmin .= '<p><strong>Detalle del error:</strong> '.htmlspecialchars($pdfErrorMsg).'</p>';
        }
        $cuerpoAdmin .= '<p>Ruta buscada: '.htmlspecialchars($dirPdf).'</p>'
            .'<p>Por favor, genera el PDF manualmente desde Dolibarr y envíalo al cliente si procede.</p>';
    } else {
        $cuerpoAdmin .= '<p>✅ PDF adjunto: <strong>'.htmlspecialchars($pdfName).'</strong></p>';
    }

    $cuerpoAdmin .= $firmaCorp;
    $enviarCorreo($emailAdmin, $asuntoAdmin, $cuerpoAdmin, $tienePdf ? $pdfPath : '', $tienePdf ? $pdfName : '', $parteId);

    // ── Correo 2: al cliente SOLO si enviar_ok, tiene email y hay PDF ────────
    if ($enviarOk && $emailCliente !== '') {
        if ($tienePdf) {
            $asuntoCliente  = 'Albarán '.$refAlbaran.' firmado - Comercial Oleza';
            $cuerpoCliente  = '<p>Estimado/a '.$nomCliente.',</p>';
            $cuerpoCliente .= '<p>Le informamos que el albarán <strong>'.$refAlbaran.'</strong> ';
            $cuerpoCliente .= 'ha sido completado y firmado por <strong>'.$nombreCliente.'</strong> ';
            $cuerpoCliente .= 'el '.$fechaFirma.'.</p>';
            $cuerpoCliente .= '<p>Encontrará el albarán firmado adjunto a este correo.</p>';
            $cuerpoCliente .= '<p>Si tiene alguna duda, no dude en contactarnos.</p>';
            $cuerpoCliente .= $firmaCorp;
            $enviarCorreo($emailCliente, $asuntoCliente, $cuerpoCliente, $pdfPath, $pdfName, $parteId);
        } else {
            // Notificar a admin que NO se envió al cliente por falta de PDF
            $asuntoAviso  = '⚠️ ALBARÁN '.$refAlbaran.': NO enviado al cliente por falta de PDF';
            $cuerpoAviso  = '<p>El albarán <strong>'.$refAlbaran.'</strong> estaba marcado para envío al cliente '
                .'(<em>'.$emailCliente.'</em>), pero <strong>no se ha encontrado el PDF</strong> '
                .'en el servidor y por tanto <strong>no se ha enviado</strong>.</p>';
            $cuerpoAviso .= '<p>Por favor, genera el PDF manualmente desde Dolibarr y envíalo al cliente.</p>';
            $cuerpoAviso .= '<p>Ruta buscada: '.htmlspecialchars($dirPdf).'</p>';
            $cuerpoAviso .= $firmaCorp;
            $enviarCorreo($emailAdmin, $asuntoAviso, $cuerpoAviso, $parteId);
        }
    }
}

echo json_encode([
    'ok'             => true,
    'fecha_firma'    => $fechaFirma,
    'tecnico'        => $nombreTecnico,
    'pdf_generado'   => $pdfGenerado,
    'pdf_encontrado' => isset($tienePdf) ? $tienePdf   : false,
    'pdf_nombre'     => isset($pdfName)  ? $pdfName    : '',
    'correo_admin'   => isset($objMail),
    'correo_cliente' => isset($objMail) && isset($enviarOk) && $enviarOk
                        && isset($emailCliente) && $emailCliente !== ''
                        && isset($tienePdf) && $tienePdf,
    'ts'             => time(),
], JSON_UNESCAPED_UNICODE);

<?php
/**
 * inc/generar_ficha_pdf.php
 * Genera UN PDF por línea de producto (commandedet) con los datos de su
 * "ficha técnica" (llx_commandedet_extrafields), usando el motor TCPDF que
 * ya trae Dolibarr — no se añade ninguna librería nueva.
 *
 * Se llama una vez por línea desde api/terminar_parte.php cuando el técnico
 * cierra el albarán. Los PDF se guardan junto al PDF principal del pedido,
 * en la MISMA carpeta que el PDF principal del pedido (no en una subcarpeta
 * aparte), para que el técnico encuentre todo junto en un solo sitio.
 *
 * Devuelve un array con el resultado; nunca lanza excepción hacia fuera
 * (un fallo generando UNA ficha no debe romper el cierre del albarán).
 */

// Medidas de página (A4, márgenes de 15mm) reutilizadas en todo el archivo
define('PT_PDF_MARGEN', 15);
define('PT_PDF_ANCHO_UTIL', 180); // 210 - 15 - 15
define('PT_PDF_MARGEN_INFERIOR', 34); // deja hueco de sobra para el pie de página

/**
 * @param DoliDB $db
 * @param object $conf
 * @param object $langs
 * @param object $mysoc            Societe (empresa propia) ya cargada por Dolibarr, para la cabecera
 * @param int    $parteId          rowid de llx_commande
 * @param string $refAlbaran       ref del pedido (para la carpeta de documentos)
 * @param string $nombreTecnico    Nombre del técnico que cierra el parte (pie de página)
 * @param string $firmaTecnicoB64  dataURL base64 de la firma del técnico (pie de página)
 * @return array{ok: bool, generados: int, errores: array}
 */
function pt_generar_fichas_pdf_producto($db, $conf, $langs, $mysoc, $parteId, $refAlbaran, $nombreTecnico = '', $firmaTecnicoB64 = '')
{
    $resultado = array('ok' => true, 'generados' => 0, 'errores' => array());

    try {
        $camposLineaExtra = include __DIR__.'/campos_linea_extra.php';

        // Tipo de albarán → decide qué grupo de campos (pm_/man_) aplica
        $sqlTipo = "SELECT ef.tipo_albaran FROM ".MAIN_DB_PREFIX."commande_extrafields ef WHERE ef.fk_object = ".(int)$parteId;
        $resTipo = $db->query($sqlTipo);
        $tipoAlbaran = 0;
        if ($resTipo && $db->num_rows($resTipo) > 0) {
            $tipoAlbaran = (int)$db->fetch_object($resTipo)->tipo_albaran;
        }

        $grupos = array('Datos del producto' => $camposLineaExtra['global']);
        if ($tipoAlbaran === 2) {
            $grupos['Puesta en marcha'] = $camposLineaExtra['pm'];
        } elseif ($tipoAlbaran === 3 || $tipoAlbaran === 4) {
            $grupos['Avería / Mantenimiento'] = $camposLineaExtra['man'];
        }

        // Columnas a leer de commandedet_extrafields
        $cols = array();
        foreach ($grupos as $campos) {
            foreach ($campos as $campoDef) {
                if (isset($campoDef['name'])) $cols[] = $campoDef['name'];
            }
        }
        $selectCols = implode(', ', array_map(function ($nombreCol) { return 'cdef.'.$nombreCol; }, $cols));

        // Líneas del pedido con su ficha técnica ya rellena
        $sqlLn  = "SELECT cd.rowid, cd.label, cd.description, p.ref AS prod_ref, p.label AS prod_label";
        $sqlLn .= ($selectCols !== '' ? ", ".$selectCols : "");
        $sqlLn .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
        $sqlLn .= " LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product";
        $sqlLn .= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet_extrafields cdef ON cdef.fk_object = cd.rowid";
        $sqlLn .= " WHERE cd.fk_commande = ".(int)$parteId;
        $sqlLn .= " ORDER BY cd.rang ASC";
        $resLn = $db->query($sqlLn);
        if (!$resLn) {
            $resultado['ok'] = false;
            $resultado['errores'][] = 'No se pudieron leer las líneas del pedido';
            return $resultado;
        }

        $dirDestino = DOL_DATA_ROOT.'/commande/'.dol_sanitizeFileName($refAlbaran).'/';
        if (!is_dir($dirDestino)) {
            dol_mkdir($dirDestino);
        }

        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

        while ($ln = $db->fetch_object($resLn)) {
            // Solo generar ficha si hay al menos un campo relleno (no crear PDFs vacíos
            // para líneas donde el técnico no rellenó nada)
            $tieneAlgo = false;
            foreach ($cols as $nombreCol) {
                if (!empty($ln->$nombreCol) || $ln->$nombreCol === '0') { $tieneAlgo = true; break; }
            }
            if (!$tieneAlgo) continue;

            $labelProducto = trim(html_entity_decode(strip_tags($ln->label ?: $ln->prod_label ?: 'Producto'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            try {
                $pdf = pdf_getInstance();
                // Salto de página TOTALMENTE manual: no se usa el automático de
                // TCPDF en ningún momento (ver pt_forzar_salto_si_no_cabe más abajo).
                // Así, TODA página nueva pasa por pt_nueva_pagina(), que es la que
                // garantiza que la cabecera se repita en cada página sin excepción.
                $pdf->SetAutoPageBreak(false);
                $pdf->SetMargins(PT_PDF_MARGEN, PT_PDF_MARGEN, PT_PDF_MARGEN);
                pt_nueva_pagina($pdf, $mysoc, $conf);
                $pdf->SetFont('', '', 10);

                $pdf->SetFont('', 'B', 14);
                $pdf->MultiCell(0, 8, 'Ficha técnica', 0, 'L');
                $pdf->SetFont('', '', 10);
                $pdf->MultiCell(0, 6, 'Albarán: '.$refAlbaran, 0, 'L');
                $pdf->MultiCell(0, 6, 'Producto: '.($ln->prod_ref ? $ln->prod_ref.' — ' : '').$labelProducto, 0, 'L');
                $pdf->Ln(4);

                foreach ($grupos as $tituloGrupo => $campos) {
                    // ¿Tiene ALGO este grupo entero? (para no dibujar una caja vacía)
                    $grupoTieneAlgo = false;
                    foreach ($campos as $campo) {
                        if (!isset($campo['name'])) continue;
                        $v = $ln->{$campo['name']} ?? null;
                        if ($v !== null && $v !== '') { $grupoTieneAlgo = true; break; }
                    }
                    if (!$grupoTieneAlgo) continue;

                    // Trocear en sub-bloques: cada bloque empieza en un separador 'grupo'
                    // (o al principio, sin título). Así se puede comprobar si el bloque
                    // tiene algún dato ANTES de imprimir su título, y omitirlo si no.
                    $bloques = array();
                    $actual  = array('titulo' => null, 'campos' => array());
                    foreach ($campos as $campo) {
                        if (isset($campo['grupo'])) {
                            $bloques[] = $actual;
                            $actual = array('titulo' => $campo['grupo'], 'campos' => array());
                        } else {
                            $actual['campos'][] = $campo;
                        }
                    }
                    $bloques[] = $actual;

                    // ── Caja de la sección: se dibuja el contenido primero, y el borde
                    // se traza alrededor DESPUÉS (una vez se sabe la altura real que
                    // ha ocupado, ya con los textos ya ajustados). Si el contenido salta
                    // de página por sí solo, no se dibuja el borde (evita una caja rota
                    // partida entre dos páginas).
                    // Antes de nada, asegurar que hay sitio para el título + al menos una
                    // fila; si no cabe, saltar de página ya, para no dejar el título solo
                    // al final de una página con todo su contenido en la siguiente.
                    pt_forzar_salto_si_no_cabe($pdf, 20, $mysoc, $conf);

                    $pdf->Ln(2);
                    $xCaja = PT_PDF_MARGEN;
                    $paginaInicioCaja = $pdf->getPage();
                    $yCaja0 = $pdf->GetY();

                    $xContenido = $xCaja + 5;
                    $anchoContenido = PT_PDF_ANCHO_UTIL - 10;

                    $pdf->SetXY($xContenido, $yCaja0 + 4);
                    $pdf->SetFont('', 'B', 12);
                    $pdf->SetTextColor(30, 41, 59);
                    $pdf->MultiCell($anchoContenido, 6, $tituloGrupo, 0, 'L');
                    $pdf->SetTextColor(0, 0, 0);

                    foreach ($bloques as $bloque) {
                        $tieneValor = false;
                        foreach ($bloque['campos'] as $campo) {
                            $v = $ln->{$campo['name']} ?? null;
                            if ($v !== null && $v !== '') { $tieneValor = true; break; }
                        }
                        if (!$tieneValor) continue; // bloque vacío: no imprimir ni su título

                        if ($bloque['titulo']) {
                            pt_forzar_salto_si_no_cabe($pdf, 15, $mysoc, $conf);
                            $pdf->SetX($xContenido);
                            $pdf->SetFont('', 'B', 10);
                            $pdf->SetTextColor(124, 58, 237);
                            $pdf->Ln(2);
                            $pdf->SetX($xContenido);
                            $pdf->MultiCell($anchoContenido, 6, $bloque['titulo'], 0, 'L');
                            $pdf->SetTextColor(0, 0, 0);
                        }
                        foreach ($bloque['campos'] as $campo) {
                            $nombre = $campo['name'];
                            $valor  = $ln->$nombre ?? null;
                            if ($valor === null || $valor === '') continue;

                            $textoValor = pt_formatear_valor_ficha($campo, $valor);
                            pt_fila_campo_ficha($pdf, $campo['label'], $textoValor, $xContenido, $anchoContenido, $mysoc, $conf);
                        }
                    }

                    $pdf->Ln(3);

                    // Borde de la caja: solo si el contenido no saltó de página
                    if ($pdf->getPage() === $paginaInicioCaja) {
                        $yCajaFin = $pdf->GetY();
                        $pdf->SetDrawColor(226, 232, 240);
                        $pdf->RoundedRect($xCaja, $yCaja0, PT_PDF_ANCHO_UTIL, $yCajaFin - $yCaja0, 2.5, '1111', 'D');
                    }
                    $pdf->Ln(4);
                }

                // Pie de página (fecha, técnico, firma, nº de página) en TODAS las
                // páginas que haya ocupado esta ficha — se dibuja al final, cuando
                // ya se sabe cuántas páginas tiene el documento completo. El salto
                // de página automático ya está desactivado desde el principio (ver
                // más arriba), así que aquí no hace falta tocar nada más: recorremos
                // las páginas ya existentes a mano con setPage().
                $totalPaginas = $pdf->getNumPages();
                for ($nPag = 1; $nPag <= $totalPaginas; $nPag++) {
                    $pdf->setPage($nPag);
                    pt_dibujar_pie_pagina($pdf, $nombreTecnico, $firmaTecnicoB64, $nPag, $totalPaginas);
                }

                $nombreArchivo = 'ficha_'.$ln->rowid.'_'.dol_sanitizeFileName($ln->prod_ref ?: ('linea'.$ln->rowid)).'.pdf';
                $pdf->Output($dirDestino.$nombreArchivo, 'F');

                $resultado['generados']++;
            } catch (Throwable $ePdf) {
                $resultado['ok'] = false;
                $resultado['errores'][] = 'Línea '.$ln->rowid.': '.$ePdf->getMessage();
            }
        }
        $db->free($resLn);
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['errores'][] = $e->getMessage();
    }

    return $resultado;
}

/**
 * Crea una página nueva Y dibuja la cabecera de empresa en ella. TODA página
 * nueva del documento —la primera y cualquiera creada por un salto de
 * página— pasa por aquí, para que el encabezado (logo + datos de la
 * empresa) se repita siempre, en cada página, sin excepción.
 */
function pt_nueva_pagina($pdf, $mysoc, $conf)
{
    $pdf->AddPage();
    try {
        pt_dibujar_cabecera_empresa($pdf, $mysoc, $conf);
    } catch (Throwable $e) {
        // La cabecera es "mejor esfuerzo": si falla por lo que sea (p.ej. un
        // logo corrupto), seguimos sin ella en vez de perder toda la ficha.
    }
}

/**
 * Fuerza un salto de página SI hace falta (si el contenido de altura dada no
 * cabe ya en lo que queda de la página actual). No usa el checkPageBreak()
 * nativo de TCPDF porque en la versión que trae Dolibarr es un método
 * PROTEGIDO (llamarlo desde fuera de la clase da un error fatal) — se
 * calcula a mano con los mismos métodos públicos que usa el resto del archivo.
 * Cuando SÍ hace falta saltar, pasa por pt_nueva_pagina() (no por un
 * AddPage() suelto), para que la cabecera también se repita aquí.
 */
function pt_forzar_salto_si_no_cabe($pdf, $altoNecesario, $mysoc, $conf)
{
    if ($pdf->GetY() + $altoNecesario > $pdf->getPageHeight() - PT_PDF_MARGEN_INFERIOR) {
        pt_nueva_pagina($pdf, $mysoc, $conf);
    }
}

/**
 * Imprime una fila "etiqueta: valor" a dos columnas SIN que se puedan solapar
 * si alguna de las dos partes salta a una segunda línea. Mide con
 * getStringHeight() cuánto va a ocupar cada columna ANTES de dibujar nada,
 * y avanza el cursor esa altura ya calculada al terminar — no se puede fiar
 * uno de dónde deja la Y el propio MultiCell con ln=0 (ver nota más abajo).
 */
function pt_fila_campo_ficha($pdf, $label, $valorTexto, $xIzq, $anchoTotal, $mysoc, $conf)
{
    $anchoEtiqueta = 78;
    $anchoValor    = $anchoTotal - $anchoEtiqueta;
    $xValor        = $xIzq + $anchoEtiqueta;

    $pdf->SetFont('', 'B', 9);
    $altoEtiqueta = $pdf->getStringHeight($anchoEtiqueta, $label);
    $pdf->SetFont('', '', 9);
    $altoValor = $pdf->getStringHeight($anchoValor, $valorTexto);
    $altoFila = max($altoEtiqueta, $altoValor, 5); // mínimo 5mm por fila

    // Si la fila no cabe entera en lo que queda de página, saltar de página
    // AHORA, antes de dibujar nada. Si se dejara que TCPDF decidiera el salto
    // a su aire a mitad de las dos llamadas a MultiCell de abajo, la etiqueta
    // podía quedar en una página y el valor en la siguiente — superpuesto con
    // lo primero que hubiera al principio de esa página nueva. Con el salto
    // forzado aquí, las dos columnas de una misma fila SIEMPRE van juntas.
    pt_forzar_salto_si_no_cabe($pdf, $altoFila, $mysoc, $conf);

    $yInicio = $pdf->GetY();

    $pdf->SetXY($xIzq, $yInicio);
    $pdf->SetFont('', 'B', 9);
    $pdf->MultiCell($anchoEtiqueta, $altoFila, $label, 0, 'L', false, 0);

    $pdf->SetXY($xValor, $yInicio);
    $pdf->SetFont('', '', 9);
    $pdf->MultiCell($anchoValor, $altoFila, $valorTexto, 0, 'L', false, 0);

    // Avanzar el cursor la altura YA CALCULADA de la fila — no la que diga
    // GetY() tras el MultiCell, que aquí no es de fiar.
    $pdf->SetXY($xIzq, $yInicio + $altoFila);
}

/**
 * Dibuja la cabecera con el logo y los datos de la propia empresa (los mismos
 * que Dolibarr tiene configurados en Inicio > Configuración > Empresa), en la
 * página actual del PDF. Si no hay logo configurado o no se puede leer el
 * archivo, se omite sin más — nunca debe romper la generación del PDF.
 */
function pt_dibujar_cabecera_empresa($pdf, $mysoc, $conf)
{
    if (!is_object($mysoc)) return;

    $margenIzq = PT_PDF_MARGEN;
    $y0        = $pdf->GetY();
    $logoAncho = 0;

    if (!empty($mysoc->logo)) {
        $rutaLogo = rtrim($conf->mycompany->dir_output, '/').'/logos/'.$mysoc->logo;
        if (is_readable($rutaLogo)) {
            try {
                $pdf->Image($rutaLogo, $margenIzq, $y0, 28, 0, '', '', '', false, 300);
                $logoAncho = 33; // ancho reservado + margen antes del texto
            } catch (Throwable $e) {
                // Logo ilegible/corrupto: seguir sin él, no romper el PDF
                $logoAncho = 0;
            }
        }
    }

    $xTexto = $margenIzq + $logoAncho;
    $pdf->SetXY($xTexto, $y0);
    $pdf->SetFont('', 'B', 11);
    $pdf->MultiCell(180 - $logoAncho, 5, $mysoc->name ?: '', 0, 'L');

    $pdf->SetX($xTexto);
    $pdf->SetFont('', '', 8);
    $direccion = trim(($mysoc->address ?? '').' '.($mysoc->zip ?? '').' '.($mysoc->town ?? ''));
    if ($direccion !== '') {
        $pdf->SetX($xTexto);
        $pdf->MultiCell(180 - $logoAncho, 4, $direccion, 0, 'L');
    }

    $contacto = array_filter(array(
        !empty($mysoc->phone) ? 'Tel: '.$mysoc->phone : '',
        !empty($mysoc->email) ? $mysoc->email : '',
    ));
    if ($contacto) {
        $pdf->SetX($xTexto);
        $pdf->MultiCell(180 - $logoAncho, 4, implode('  ·  ', $contacto), 0, 'L');
    }

    $fiscal = !empty($mysoc->idprof1) ? $mysoc->idprof1 : (!empty($mysoc->tva_intra) ? $mysoc->tva_intra : '');
    if ($fiscal !== '') {
        $pdf->SetX($xTexto);
        $pdf->MultiCell(180 - $logoAncho, 4, 'NIF/CIF: '.$fiscal, 0, 'L');
    }

    // Asegurar que el cursor queda por debajo tanto del logo como del texto
    $yFinLogo   = $y0 + ($logoAncho > 0 ? 22 : 0);
    $yFinTexto  = $pdf->GetY();
    $pdf->SetY(max($yFinLogo, $yFinTexto) + 3);

    // Línea separadora antes del contenido de la ficha
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Line($margenIzq, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);
    $pdf->SetTextColor(0, 0, 0);
}

/**
 * Dibuja el pie de página en la página ACTUAL (hay que llamarlo una vez por
 * cada página, después de setPage($n)): fecha, nombre del técnico, su firma
 * y el número de página. Es "mejor esfuerzo": cualquier fallo puntual (p.ej.
 * firma con base64 corrupto) se ignora para no romper el PDF completo.
 *
 * IMPORTANTE: todo el texto se escribe con Text($x, $y, $texto) en vez de
 * MultiCell()/Cell(). Text() es un método de bajo nivel (heredado de FPDF)
 * que coloca una línea de texto en una coordenada exacta SIN pasar por
 * ninguna comprobación de salto de página — a diferencia de MultiCell/Cell,
 * que si el navegador anfitrión evalúa "no cabe" pueden disparar un salto
 * aunque SetAutoPageBreak esté a false. Esto es lo que hacía que cada
 * elemento del pie (fecha, técnico, firma...) acabara en su propia página:
 * cada MultiCell lo interpretaba por su cuenta. Con Text() eso no puede
 * pasar, así que el pie sale siempre en un solo bloque, en la misma página.
 */
function pt_dibujar_pie_pagina($pdf, $nombreTecnico, $firmaTecnicoB64, $nPagina, $totalPaginas)
{
    $margenIzq  = PT_PDF_MARGEN;
    $anchoUtil  = PT_PDF_ANCHO_UTIL;
    $yLinea     = $pdf->getPageHeight() - PT_PDF_MARGEN_INFERIOR + 5;

    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Line($margenIzq, $yLinea, $margenIzq + $anchoUtil, $yLinea);

    $yTexto = $yLinea + 3;

    // ── Columna izquierda: fecha (fila 1) y técnico (fila 2) ─────────────────
    $anchoIzq = 95; // deja hueco de sobra antes de la columna de la firma
    $pdf->SetFont('', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Text($margenIzq, $yTexto, pt_truncar_texto_ficha($pdf, 'Fecha: '.dol_print_date(dol_now(), 'day'), $anchoIzq));
    $pdf->Text($margenIzq, $yTexto + 4.5, pt_truncar_texto_ficha($pdf, 'Técnico: '.($nombreTecnico ?: '—'), $anchoIzq));

    // ── Columna derecha: firma del técnico + su etiqueta debajo ─────────────
    $anchoFirma = 32;
    $xFirma     = $margenIzq + $anchoUtil - $anchoFirma;
    if (!empty($firmaTecnicoB64)) {
        try {
            $datosImg = pt_decodificar_imagen_base64($firmaTecnicoB64);
            if ($datosImg !== null) {
                $pdf->Image('@'.$datosImg, $xFirma, $yTexto - 2, $anchoFirma, 10, '', '', '', false, 300);
            }
        } catch (Throwable $e) {
            // Firma no se pudo dibujar: seguir sin ella
        }
    }
    $ySeparadorFirma = $yTexto + 8.5;
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Line($xFirma, $ySeparadorFirma, $xFirma + $anchoFirma, $ySeparadorFirma);

    $pdf->SetFont('', '', 7);
    $pdf->SetTextColor(148, 163, 184);
    $etiquetaFirma     = 'Firma técnico';
    $anchoEtiquetaTxt  = $pdf->GetStringWidth($etiquetaFirma);
    $pdf->Text($xFirma + max(0, ($anchoFirma - $anchoEtiquetaTxt) / 2), $ySeparadorFirma + 3, $etiquetaFirma);

    // ── Página X de Y: en su propia fila, claramente por debajo de todo lo
    // anterior (columna izquierda Y derecha), alineada a la derecha a mano
    // calculando el ancho del texto (Text() no tiene alineación integrada).
    $pdf->SetFont('', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $textoPagina  = 'Página '.$nPagina.' de '.$totalPaginas;
    $anchoPagina  = $pdf->GetStringWidth($textoPagina);
    $pdf->Text($margenIzq + $anchoUtil - $anchoPagina, $yTexto + 15, $textoPagina);

    $pdf->SetTextColor(0, 0, 0);
}

/** Trunca (con "…") un texto si no cabe en el ancho dado, midiéndolo con GetStringWidth. */
function pt_truncar_texto_ficha($pdf, $texto, $anchoMaxMm)
{
    if ($pdf->GetStringWidth($texto) <= $anchoMaxMm) return $texto;
    while (function_exists('mb_strlen') ? mb_strlen($texto) > 1 : strlen($texto) > 1) {
        $texto = function_exists('mb_substr') ? mb_substr($texto, 0, -1) : substr($texto, 0, -1);
        if ($pdf->GetStringWidth($texto.'…') <= $anchoMaxMm) break;
    }
    return $texto.'…';
}

/** Decodifica una dataURL base64 (p.ej. "data:image/png;base64,AAAA...") a bytes crudos. */
function pt_decodificar_imagen_base64($dataUrl)
{
    if (strpos($dataUrl, 'base64,') === false) return null;
    $b64 = substr($dataUrl, strpos($dataUrl, 'base64,') + 7);
    $bin = base64_decode($b64, true);
    return ($bin === false || $bin === '') ? null : $bin;
}

/** Convierte el valor crudo de un campo a texto legible para el PDF, según su tipo. */
function pt_formatear_valor_ficha($campo, $valor)
{
    switch ($campo['type']) {
        case 'boolean':
            return !empty($valor) ? 'Sí' : 'No';
        case 'number':
            return rtrim(rtrim(number_format((float)$valor, 2, ',', '.'), '0'), ',');
        default:
            return (string)$valor;
    }
}

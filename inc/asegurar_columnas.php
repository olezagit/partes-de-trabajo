<?php
/**
 * inc/asegurar_columnas.php
 *
 * Comprueba (y crea si hace falta) TODAS las columnas y tablas internas que
 * el módulo necesita en las tablas de extrafields de Dolibarr:
 *   - llx_commande_extrafields:     horadeinicio, horadefinalizacion,
 *                                   foto_1..foto_10, enviar_ok (marca si el
 *                                   albarán debe enviarse por email al
 *                                   cliente al cerrarse), enviado (evita
 *                                   reenvíos), tec_firmante (técnico que
 *                                   firmó el cierre)
 *   - llx_societe_extrafields:      telefono_2, telefono_3, telefono_4
 *                                   (la tabla entera puede no existir si
 *                                   nunca se creó ningún campo extra de
 *                                   tercero desde la interfaz de Dolibarr)
 *   - llx_commandedet_extrafields:  tarea_realizada, pt_creado_por, y todos
 *                                   los campos de ficha técnica listados en
 *                                   inc/campos_linea_extra.php (marca,
 *                                   modelo, pm_*, man_*...)
 *   - llx_product_extrafields:      maquina (la tabla entera puede no
 *                                   existir si nunca se creó ningún campo
 *                                   extra de producto)
 *
 * Por qué existe este archivo:
 * Si cualquiera de estas columnas (o la tabla que las contiene) no existe
 * todavía en una instalación, la consulta SQL que las usa falla ENTERA — no
 * solo para ese campo — porque suelen ir mezcladas con otras en el mismo
 * SELECT. Esto es justo lo que causaba un 500 al abrir el detalle de
 * cualquier parte: la cabecera del pedido selecciona sef.telefono_2/3/4 y
 * ef.horadeinicio/foto_1..10 en la misma consulta, así que si falta UNA
 * sola de esas columnas, el pedido entero deja de poder abrirse.
 *
 * pt_asegurar_columnas_modulo($db) se ejecuta una sola vez por request (vía
 * caché estática) y, la primera vez que TODO el esquema ya está al día,
 * deja constancia en una constante de Dolibarr (llx_const) para no volver a
 * comprobar columna por columna en cada petición futura. Devuelve un mapa
 * [nombre_columna => disponible (bool)] para que el llamador pueda, si
 * hiciera falta, degradar con gracia una consulta cuando una columna no se
 * pudo crear (p.ej. por falta de permisos ALTER TABLE/CREATE TABLE del
 * usuario de BD) en vez de que la petición entera falle.
 *
 * NOTA sobre enviar_ok/enviado/tec_firmante: api/terminar_parte.php las
 * selecciona en UNA sola consulta junto con el resto de datos del pedido
 * para decidir si envía el correo de cierre al cliente. Antes de que este
 * archivo las gestionara, si esas tres columnas no existían todavía en una
 * instalación, esa consulta fallaba ENTERA y el bloque de envío de correo
 * se saltaba en silencio (sin error visible) — el cliente simplemente no
 * recibía nunca el aviso, y nadie se enteraba de por qué. Se incluyen aquí
 * por el mismo motivo que las demás: para que esa consulta nunca dependa de
 * que alguien haya creado antes esas columnas a mano.
 */

if (!function_exists('pt_tabla_existe')) {
    function pt_tabla_existe($db, $tabla)
    {
        $sql  = "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES";
        $sql .= " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$db->escape($tabla)."'";
        $res = $db->query($sql);
        if (!$res) return false;
        $obj = $db->fetch_object($res);
        $db->free($res);
        return $obj && (int) $obj->n > 0;
    }
}

if (!function_exists('pt_asegurar_tabla_extrafields')) {
    /**
     * Crea la tabla "<elemento>_extrafields" si todavía no existe, con la
     * misma estructura mínima que usa el propio Dolibarr cuando se crea el
     * primer campo extra de ese elemento desde la interfaz (rowid, tms,
     * fk_object único, import_key). Las columnas de datos en sí (telefono_2,
     * horadeinicio, maquina, etc.) se añaden después con pt_asegurar_columna().
     *
     * Casos reales que motivaron esto: ni llx_societe_extrafields (teléfonos
     * extra del cliente) ni llx_product_extrafields (campo "maquina") tienen
     * por qué existir en una instalación donde nunca se creó ningún campo
     * extra de tercero o de producto desde la interfaz de Dolibarr — a
     * diferencia de llx_commande_extrafields o llx_commandedet_extrafields,
     * que ya existen porque este módulo sí registra campos extra de pedido
     * (tipo_albaran, firma_cliente...) vía sql/create_extrafields.sql. Si la
     * tabla entera falta, un simple ALTER TABLE no sirve de nada: hay que
     * crearla antes.
     */
    function pt_asegurar_tabla_extrafields($db, $tabla)
    {
        if (pt_tabla_existe($db, $tabla)) return true;
        $sql = "CREATE TABLE ".$tabla." (";
        $sql .= "  rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
        $sql .= "  tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
        $sql .= "  fk_object INTEGER NOT NULL,";
        $sql .= "  import_key VARCHAR(14),";
        $sql .= "  UNIQUE KEY uk_".$tabla."_fk_object (fk_object)";
        $sql .= ") ENGINE=innodb";
        $ok = false;
        try {
            $ok = (bool) $db->query($sql);
        } catch (Throwable $e) {
            error_log('PartesTrabajo: no se pudo crear la tabla '.$tabla.': '.$e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            error_log('PartesTrabajo: no se pudo crear la tabla '.$tabla
                .'. Créala a mano o revisa los permisos CREATE TABLE del usuario de BD.');
        }
        return $ok;
    }
}

if (!function_exists('pt_columna_existe')) {
    function pt_columna_existe($db, $tabla, $columna)
    {
        $sql  = "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS";
        $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
        $sql .= "   AND TABLE_NAME = '".$db->escape($tabla)."'";
        $sql .= "   AND COLUMN_NAME = '".$db->escape($columna)."'";
        $res = $db->query($sql);
        if (!$res) return false;
        $obj = $db->fetch_object($res);
        $db->free($res);
        return $obj && (int) $obj->n > 0;
    }
}

if (!function_exists('pt_asegurar_columna')) {
    /** Crea la columna si la tabla existe (o se puede crear) y la columna
     *  todavía no. Devuelve true si, al terminar, la columna está disponible
     *  (ya estuviera o se acabe de crear); false si no se pudo (tabla
     *  inexistente y no se pudo crear, o sin permisos ALTER TABLE) — el
     *  llamador debe entonces registrar el fallo y seguir, nunca fallar la
     *  petición entera por esto. */
    function pt_asegurar_columna($db, $tabla, $columna, $definicionSql)
    {
        if (!pt_tabla_existe($db, $tabla) && !pt_asegurar_tabla_extrafields($db, $tabla)) {
            error_log('PartesTrabajo: la tabla '.$tabla.' no existe y no se pudo crear — no se puede crear '.$columna);
            return false;
        }
        if (pt_columna_existe($db, $tabla, $columna)) {
            return true;
        }
        $ok = false;
        try {
            $ok = (bool) $db->query("ALTER TABLE ".$tabla." ADD COLUMN ".$columna." ".$definicionSql);
        } catch (Throwable $e) {
            error_log('PartesTrabajo: no se pudo crear la columna '.$tabla.'.'.$columna.': '.$e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            error_log('PartesTrabajo: no se pudo crear la columna '.$tabla.'.'.$columna
                .'. Créala a mano (ver sql/create_extrafields.sql) o revisa los permisos ALTER TABLE del usuario de BD.');
        }
        return $ok;
    }
}

if (!function_exists('pt_tipo_campo_ficha_a_sql')) {
    /** Traduce el 'type' de inc/campos_linea_extra.php al tipo de columna
     *  SQL real. Única función que conoce esta correspondencia, para que
     *  nunca se desincronice con lo que lee/escribe el resto del módulo. */
    function pt_tipo_campo_ficha_a_sql($tipo)
    {
        switch ($tipo) {
            case 'boolean':     return 'TINYINT DEFAULT 0';
            case 'number':      return 'DOUBLE DEFAULT NULL';
            case 'multiselect': return 'VARCHAR(255) DEFAULT NULL';
            case 'select':      return 'VARCHAR(64) DEFAULT NULL';
            default:            return 'VARCHAR(128) DEFAULT NULL'; // text
        }
    }
}

if (!function_exists('pt_asegurar_columnas_modulo')) {
    /**
     * Punto de entrada único: llama a esto antes de cualquier consulta que
     * toque columnas de ficha técnica, horas, fotos, teléfonos extra,
     * "quién creó la línea" o "es una máquina". Segura de llamar en cada
     * request y desde varios archivos distintos: en cuanto el esquema está
     * completo queda marcado en llx_const y, además, cacheado en memoria
     * durante el resto del request, así que llamarla varias veces (p.ej.
     * una vez desde el dispatcher y otra vez dentro de parte_detalle.php)
     * no repite trabajo.
     *
     * @return array<string,bool> Mapa columna => disponible. Incluye al
     *         menos 'pt_creado_por' y 'maquina' (las dos que ya consultaba
     *         el código existente), más el resto de columnas que gestiona
     *         este archivo, por si algún llamador futuro las necesita.
     */
    function pt_asegurar_columnas_modulo($db)
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $conf;

        $pt_cols = array();

        // Incrementar este sufijo (V2, V3...) si en el futuro se añaden más
        // columnas que necesiten pasar otra vez por la comprobación.
        $flag = 'PARTES_TRABAJO_SCHEMA_V3_OK';
        if (!empty($conf->global->$flag)) {
            // Ya se comprobó en una petición anterior y todo estaba
            // disponible: no hace falta volver a tocar INFORMATION_SCHEMA.
            $cache = pt_mapa_columnas_gestionadas_todas_ok();
            return $cache;
        }

        $todoOk = true;

        // ── llx_commande_extrafields ──────────────────────────────────────────
        $pt_cols['horadeinicio']       = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'horadeinicio',       'VARCHAR(20) DEFAULT NULL');
        $pt_cols['horadefinalizacion'] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'horadefinalizacion', 'VARCHAR(20) DEFAULT NULL');
        for ($i = 1; $i <= 10; $i++) {
            $pt_cols['foto_'.$i] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'foto_'.$i, 'LONGTEXT DEFAULT NULL');
        }
        // Usadas por api/terminar_parte.php para decidir y registrar el envío
        // del correo de cierre al cliente (ver nota al principio del archivo).
        $pt_cols['enviar_ok']    = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'enviar_ok',    'TINYINT DEFAULT 0');
        $pt_cols['enviado']      = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'enviado',      'TINYINT DEFAULT 0');
        $pt_cols['tec_firmante'] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commande_extrafields', 'tec_firmante', 'INTEGER DEFAULT NULL');

        // ── llx_societe_extrafields (tabla puede no existir todavía) ──────────
        foreach (array('telefono_2', 'telefono_3', 'telefono_4') as $col) {
            $pt_cols[$col] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'societe_extrafields', $col, 'VARCHAR(32) DEFAULT NULL');
        }

        // ── llx_commandedet_extrafields ───────────────────────────────────────
        $pt_cols['tarea_realizada'] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commandedet_extrafields', 'tarea_realizada', 'TINYINT DEFAULT 0');
        $pt_cols['pt_creado_por']   = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commandedet_extrafields', 'pt_creado_por',   'INTEGER DEFAULT NULL');

        $camposLineaExtra = include __DIR__.'/campos_linea_extra.php';
        foreach (array('global', 'pm', 'man') as $grp) {
            foreach ($camposLineaExtra[$grp] as $campo) {
                if (!isset($campo['name'])) continue; // separador visual, no es columna
                $sqlType = pt_tipo_campo_ficha_a_sql($campo['type']);
                $pt_cols[$campo['name']] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'commandedet_extrafields', $campo['name'], $sqlType);
            }
        }

        // ── llx_product_extrafields (tabla puede no existir todavía) ──────────
        $pt_cols['maquina'] = pt_asegurar_columna($db, MAIN_DB_PREFIX.'product_extrafields', 'maquina', 'TINYINT DEFAULT 0');

        $todoOk = !in_array(false, $pt_cols, true);

        if ($todoOk && function_exists('dolibarr_set_const')) {
            dolibarr_set_const($db, $flag, '1', 'chaine', 0, '', $conf->entity);
        }

        $cache = $pt_cols;
        return $cache;
    }
}

if (!function_exists('pt_mapa_columnas_gestionadas_todas_ok')) {
    /** Lista de todas las columnas que gestiona este archivo, todas en
     *  true — usada como atajo cuando la constante llx_const ya confirmó en
     *  una petición anterior que el esquema completo está al día, para no
     *  repetir ~50 consultas a INFORMATION_SCHEMA en cada petición. */
    function pt_mapa_columnas_gestionadas_todas_ok()
    {
        $nombres = array('horadeinicio', 'horadefinalizacion', 'tarea_realizada', 'pt_creado_por', 'maquina', 'enviar_ok', 'enviado', 'tec_firmante');
        for ($i = 1; $i <= 10; $i++) $nombres[] = 'foto_'.$i;
        foreach (array('telefono_2', 'telefono_3', 'telefono_4') as $c) $nombres[] = $c;

        $camposLineaExtra = include __DIR__.'/campos_linea_extra.php';
        foreach (array('global', 'pm', 'man') as $grp) {
            foreach ($camposLineaExtra[$grp] as $campo) {
                if (isset($campo['name'])) $nombres[] = $campo['name'];
            }
        }

        return array_fill_keys($nombres, true);
    }
}

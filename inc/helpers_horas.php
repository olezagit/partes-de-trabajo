<?php
/**
 * inc/helpers_horas.php
 *
 * Calcula la duración en horas entre dos valores de tipo
 * <input type="datetime-local"> ("YYYY-MM-DDTHH:MM"), tal y como el módulo
 * guarda horadeinicio/horadefinalizacion en llx_commande_extrafields (ver
 * js/detalle.js). Compartido entre admin/estadisticas.php (resumen por
 * técnico) y api/estadisticas_tecnico.php (detalle por técnico) para no
 * duplicar esta lógica en dos sitios.
 *
 * FORMATO DE HORARIO (desde que el técnico introduce las horas a mano):
 *   - El técnico escribe SOLO la hora ("HH:MM") de inicio y de fin (obligatorias).
 *   - La FECHA no la escribe nadie: es la fecha en que el cliente firma el albarán
 *     (columna fecha_firma, "d/m/Y H:i"). Al guardar, el servidor une fecha + hora
 *     y almacena "YYYY-MM-DDTHH:MM" en horadeinicio/horadefinalizacion, que es el
 *     formato que ya entendían estas funciones y las estadísticas.
 *   - Mientras el cliente no haya firmado solo se guarda la hora ("HH:MM"); las
 *     funciones de abajo la tratan como "horario incompleto" (sin duración).
 */

if (!function_exists('pt_hora_hhmm')) {
    /**
     * Extrae la hora "HH:MM" (con cero a la izquierda) de "HH:MM",
     * "YYYY-MM-DDTHH:MM" o "d/m/Y H:i". Devuelve '' si no hay una hora válida.
     */
    function pt_hora_hhmm($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') return '';
        if (preg_match('/(?:^|[T\s])([01]?\d|2[0-3]):([0-5]\d)(?::\d{2})?$/', $valor, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
        }
        return '';
    }
}

if (!function_exists('pt_fecha_ymd')) {
    /** Extrae la fecha "YYYY-MM-DD" de "YYYY-MM-DD[...]" o "d/m/Y[...]". '' si no es válida. */
    function pt_fecha_ymd($valor)
    {
        $valor = trim((string) $valor);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $valor, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return '';
    }
}

if (!function_exists('pt_fecha_firma_dmyhi')) {
    /**
     * Convierte la fecha/hora de firma enviada por el navegador
     * ("YYYY-MM-DDTHH:MM") al formato que guarda fecha_firma ("d/m/Y H:i").
     * '' si no es una fecha válida.
     */
    function pt_fecha_firma_dmyhi($valor)
    {
        $ymd = pt_fecha_ymd($valor);
        if ($ymd === '') return '';
        $hm = pt_hora_hhmm($valor);
        if ($hm === '') $hm = '00:00';
        return substr($ymd, 8, 2).'/'.substr($ymd, 5, 2).'/'.substr($ymd, 0, 4).' '.$hm;
    }
}

if (!function_exists('pt_componer_fecha_hora')) {
    /**
     * Une la fecha de firma del cliente ("YYYY-MM-DD") con una hora "HH:MM".
     * Sin hora devuelve ''; sin fecha (cliente aún sin firmar) devuelve solo la hora.
     */
    function pt_componer_fecha_hora($ymd, $hhmm)
    {
        if ($hhmm === '') return '';
        return $ymd !== '' ? ($ymd.'T'.$hhmm) : $hhmm;
    }
}

if (!function_exists('pt_horas_entre')) {
    /**
     * @param  string|null $inicio Valor de horadeinicio
     * @param  string|null $fin    Valor de horadefinalizacion
     * @return float|null  Horas (2 decimales) o null si falta alguno de los
     *                     dos valores, no se pueden interpretar como fecha
     *                     válida, o el fin no es posterior al inicio (dato
     *                     inconsistente: se ignora en vez de mostrar un
     *                     número negativo o absurdo).
     */
    function pt_horas_entre($inicio, $fin)
    {
        $inicio = trim((string) $inicio);
        $fin    = trim((string) $fin);
        if ($inicio === '' || $fin === '') return null;
        // Solo "HH:MM" (cliente aún sin firmar, falta la fecha): sin duración fiable
        if (preg_match('/^\d{1,2}:\d{2}$/', $inicio) || preg_match('/^\d{1,2}:\d{2}$/', $fin)) return null;

        try {
            $dIni = new DateTime($inicio);
            $dFin = new DateTime($fin);
        } catch (Throwable $e) {
            return null;
        }

        $segundos = $dFin->getTimestamp() - $dIni->getTimestamp();
        if ($segundos <= 0) return null;

        return round($segundos / 3600, 2);
    }
}

if (!function_exists('pt_ts_o_null')) {
    /** Timestamp UNIX de un valor datetime-local, o null si está vacío o no es válido. */
    function pt_ts_o_null($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '' || preg_match('/^\d{1,2}:\d{2}$/', $valor)) return null;
        try {
            return (new DateTime($valor))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('pt_formatear_horas')) {
    /** Formatea un número de horas (float) como "Xh Ym" para mostrar en UI. */
    function pt_formatear_horas($horas)
    {
        if ($horas === null) return '—';
        $totalMin = (int) round($horas * 60);
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;
        return $m > 0 ? ($h.'h '.$m.'m') : ($h.'h');
    }
}

if (!function_exists('pt_formatear_fecha_hora_local')) {
    /** Formatea un valor datetime-local ("YYYY-MM-DDTHH:MM") como "dd/mm/YYYY HH:MM" para mostrar en UI. */
    function pt_formatear_fecha_hora_local($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') return '';
        if (preg_match('/^\d{1,2}:\d{2}$/', $valor)) return $valor; // solo hora (sin firma del cliente aún)
        try {
            $d = new DateTime($valor);
        } catch (Throwable $e) {
            return $valor;
        }
        return $d->format('d/m/Y H:i');
    }
}

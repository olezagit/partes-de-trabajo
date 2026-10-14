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
 */

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
        try {
            $d = new DateTime($valor);
        } catch (Throwable $e) {
            return $valor;
        }
        return $d->format('d/m/Y H:i');
    }
}

<?php
/**
 * inc/campos_linea_extra.php
 *
 * Metadatos de los campos de "ficha técnica" por producto (línea del albarán),
 * guardados en llx_commandedet_extrafields. Es la ÚNICA fuente de verdad para
 * las etiquetas/tipos/opciones de estos campos: tanto index.php (que los
 * inyecta como JSON para que detalle.js dibuje el formulario) como el
 * generador de PDF (api/generar_ficha_pdf.php) leen de aquí, para que nunca
 * se desincronicen entre sí.
 *
 * Convención de nombres de columna (ya existente en la tabla real):
 *   - Sin prefijo   → campo GLOBAL, se muestra en cualquier tipo de albarán.
 *   - Prefijo pm_   → solo para tipo_albaran = 2 (Puesta en marcha).
 *   - Prefijo man_  → solo para tipo_albaran = 3 o 4 (Avería / Mantenimiento).
 *
 * Tipos de campo soportados: 'text', 'number', 'boolean', 'select'.
 * Un elemento con 'grupo' en vez de 'name' es solo un separador visual
 * (no se guarda en BD), igual que los campos type='separate' de Dolibar.
 *
 * @return array{global: array, pm: array, man: array}
 */
return array(

    // Campos globales — se muestran siempre, sea cual sea el tipo de albarán
    'global' => array(
        array('name' => 'marca',    'label' => 'Marca',                'type' => 'text'),
        array('name' => 'modelo',   'label' => 'Modelo interior',      'type' => 'text'),
        array('name' => 'modex',    'label' => 'Modelo exterior',      'type' => 'text'),
        array('name' => 'nserie',   'label' => 'Nº de serie interior', 'type' => 'text'),
        array('name' => 'nseriex',  'label' => 'Nº de serie exterior', 'type' => 'text'),
        array('name' => 'ubicacin', 'label' => 'Ubicación',            'type' => 'text'),
    ),

    // Campos de Puesta en marcha (tipo_albaran = 2) — prefijo pm_
    'pm' => array(
        array('grupo' => 'Comprobaciones eléctricas'),
        array('name' => 'pm_okvolt',      'label' => 'Valores correctos Volt./Amp.',                                     'type' => 'boolean'),
        array('name' => 'pm_okconterson', 'label' => 'Se ha conexionado termostato, sondas, etc.',                       'type' => 'boolean'),
        array('name' => 'pm_okconcabelec','label' => 'Se ha conexionado cables eléctricos entre unidad interior y exterior', 'type' => 'boolean'),
        array('name' => 'pm_okconalielec','label' => 'Se ha conexionado la alimentación eléctrica a equipos',            'type' => 'boolean'),
        array('name' => 'pm_okprtodif',   'label' => 'Protección de línea diferencial',                                  'type' => 'boolean'),
        array('name' => 'pm_okprtoterm',  'label' => 'Protección de línea térmico',                                      'type' => 'boolean'),

        array('grupo' => 'Circuito frigorífico'),
        array('name' => 'pm_cargarefri', 'label' => 'Carga de refrigerante (Kg)',                    'type' => 'number'),
        array('name' => 'pm_tiporefri',  'label' => 'Tipo de refrigerante',                          'type' => 'text'),
        array('name' => 'pm_tubfri',     'label' => 'Tuberías frigoríficas (Ø)', 'type' => 'multiselect', 'options' => array(
            '1/4"', '3/8"', '1/2"', '5/8"', '3/4"', '7/8"', '1"', '1 1/8"', '1 3/8"', '1 5/8"',
        )),
        array('name' => 'pm_pruestanq',  'label' => 'Prueba de estanqueidad con nitrógeno a 38 Bar', 'type' => 'boolean'),
        array('name' => 'pm_insfrifin',  'label' => 'Instalación frigorífica finalizada',             'type' => 'boolean'),

        array('grupo' => 'Circuito hidráulico'),
        array('name' => 'pm_inshidra',       'label' => 'Instalación hidráulica finalizada y aislada correctamente',           'type' => 'boolean'),
        array('name' => 'pm_existoma',       'label' => 'Existe una toma de llenado para el circuito primario y/o acumulador', 'type' => 'boolean'),
        array('name' => 'pm_enjulimp',       'label' => 'Se ha realizado un enjuague y limpieza de la instalación',            'type' => 'boolean'),
        array('name' => 'pm_insfilret',      'label' => 'Se ha instalado un filtro en el retorno del equipo',                  'type' => 'boolean'),
        array('name' => 'pm_insllenpur',     'label' => 'La instalación se ha llenado a >1 bar y se ha purgado totalmente',    'type' => 'boolean'),
        array('name' => 'pm_volmin',         'label' => 'El volumen de agua mínimo se garantiza en todas las condiciones',     'type' => 'boolean'),
        array('name' => 'pm_volaprox',       'label' => 'Volumen aproximado de agua en el circuito primario (L)',             'type' => 'number'),
        array('name' => 'pm_pruestanqhidra', 'label' => 'Prueba de estanqueidad',                                             'type' => 'boolean'),
        array('name' => 'pm_compentcau',     'label' => 'Comprobada entrada de caudal nominal de agua a equipo',              'type' => 'boolean'),
        array('name' => 'pm_prothel',        'label' => 'Protección contra heladas',                                          'type' => 'boolean'),
        array('name' => 'pm_glicol',         'label' => 'Glicol',                                                             'type' => 'boolean'),
        array('name' => 'pm_limpcir',        'label' => 'Limpieza del circuito hidráulico realizada',                         'type' => 'boolean'),

        array('grupo' => 'Circuito de conductos'),
        array('name' => 'pm_difaire',      'label' => 'Difusión de aire por', 'type' => 'select', 'options' => array('Rejillas', 'Difusores', 'Toberas', 'Otros')),
        array('name' => 'pm_inster',       'label' => 'Instalación terminada',                    'type' => 'boolean'),
        array('name' => 'pm_distairok',    'label' => 'Distribución de aire correcta por rejilla', 'type' => 'boolean'),
        array('name' => 'pm_compins',      'label' => 'Compuertas de aire instaladas',             'type' => 'boolean'),
        array('name' => 'pm_tipfil',       'label' => 'Tipo de filtros', 'type' => 'select', 'options' => array('Filtrina', 'G4', 'F6', 'F7', 'F8', 'F9')),
        array('name' => 'pm_airemontlimp', 'label' => 'Filtro de aire montado y limpio',           'type' => 'boolean'),
    ),

    // Campos de Avería / Mantenimiento (tipo_albaran = 3 o 4) — prefijo man_
    'man' => array(
        array('name' => 'man_temp_termo',     'label' => 'Temp. termostato', 'type' => 'select', 'options' => array('AMBIENTE', 'RETORNO', 'IMPULSIÓN')),
        array('name' => 'man_rev_fug',        'label' => 'Revisión de fugas',                     'type' => 'boolean'),
        array('name' => 'man_des_ok',         'label' => 'Desagües correctos',                    'type' => 'boolean'),
        array('name' => 'man_temp_int',       'label' => 'Temp. interior comprobada',             'type' => 'boolean'),
        array('name' => 'man_temp_ext',       'label' => 'Temp. exterior comprobada',             'type' => 'boolean'),
        array('name' => 'man_rev_ruido',      'label' => 'Revisión de ruidos y vibraciones',       'type' => 'boolean'),
        array('name' => 'man_filtro_limpio',  'label' => 'Filtro de aire montado y limpio',        'type' => 'boolean'),
        array('name' => 'man_inst_conductos', 'label' => 'Instalación de conductos revisada',      'type' => 'boolean'),
        array('name' => 'man_rev_red',        'label' => 'Revisión de red de conductos',           'type' => 'boolean'),
    ),
);

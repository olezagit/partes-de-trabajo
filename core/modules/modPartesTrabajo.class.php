<?php
/**
 * Descriptor del módulo Partes de Trabajo para Dolibarr
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modPartesTrabajo extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // ── Identificación ────────────────────────────────────────────────────
        $this->numero          = 500001;
        $this->rights_class    = 'partes_trabajo';
        $this->family          = 'technic';
        $this->module_position = '50';
        $this->name            = preg_replace('/^mod/i', '', get_class($this));
        $this->description     = 'Gestión de Partes de Trabajo asignados al usuario';
        $this->version         = '2.8';
        $this->const_name      = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto           = 'wrench';

        $this->module_parts = array();
        $this->dirs         = array();
        $this->config_page_url = array(
            'partes_trabajo/admin/setup.php'
        );
        $this->hidden = false;

        // Sin dependencias obligatorias — solo módulos base de Dolibarr
        $this->depends      = array('modSociete');
        $this->requiredby   = array();
        $this->conflictwith = array();
        $this->phpmin                = array(7, 0);
        $this->need_dolibarr_version = array(13, 0);
        $this->langfiles = array('partes_trabajo@partes_trabajo');

        // ── Permisos ──────────────────────────────────────────────────────────
        // Formato: [id, descripción, ?, bydefault, permiso, subpermiso]
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;  // id único
        $this->rights[$r][1] = 'Ver mis partes de trabajo asignados';
        $this->rights[$r][2] = 'r';    // tipo lectura
        $this->rights[$r][3] = 1;      // 1 = activo por defecto para todos los usuarios
        $this->rights[$r][4] = 'leer'; // $user->rights->partes_trabajo->leer
        $r++;

        // ── Menú ──────────────────────────────────────────────────────────────
        $this->menu = array();
        $r = 0;

        $this->menu[$r] = array(
            'fk_menu'  => '',              // sin padre → menú de nivel superior
            'type'     => 'top',
            'titre'    => 'Mis Partes',
            'mainmenu' => 'partes_trabajo',
            'leftmenu' => '',
            'url'      => '/custom/partes_trabajo/index.php',
            'langs'    => 'partes_trabajo@partes_trabajo',
            'position' => 100,
            'enabled'  => 'isModEnabled("partes_trabajo")',
            'perms'    => '1',            // visible para todos los usuarios logueados
            'target'   => '',
            'user'     => 0,              // 0 = usuarios normales y admin
        );
        $r++;
    }
}

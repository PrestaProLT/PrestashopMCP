<?php
/**
 * 1.3.0: OAuth sign-in (tables, key columns, moduleRoutes hook, "Approve connection" tab, settings).
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ppmcp/autoload.php';

function upgrade_module_1_3_0($module)
{
    // PS 9 Module Manager passes the module with id = NULL.
    if (empty($module->id)) {
        $module->id = (int) Module::getModuleIdByName($module->name);
    }
    $installer = new PrestaPro\PpMcp\Install\Installer($module);
    if (!$installer->install(false)) {
        foreach ($installer->errors as $e) {
            PrestaShopLogger::addLog('ppmcp upgrade 1.3.0: ' . $e, 3, null, 'ppmcp');
        }

        return false;
    }

    return true;
}

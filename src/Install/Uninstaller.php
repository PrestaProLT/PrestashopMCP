<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Install;

/**
 * Destroys everything the module owns. Only called from uninstall().
 * The provider hook is kept: other modules may still be registered on it.
 */
class Uninstaller
{
    public static function run(): bool
    {
        $ok = true;
        foreach (['ppmcp_key', 'ppmcp_log', 'ppmcp_session', 'ppmcp_oauth_client', 'ppmcp_oauth_request'] as $table) {
            $ok = \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`') && $ok;
        }
        foreach (array_keys(Installer::DEFAULT_CONFIG) as $key) {
            \Configuration::deleteByName($key);
        }
        foreach (array_merge(array_keys(Installer::TABS), [Installer::PARENT_TAB]) as $class) {
            $id = (int) \Tab::getIdFromClassName($class);
            if ($id > 0) {
                $tab = new \Tab($id);
                $ok = $tab->delete() && $ok;
            }
        }

        return $ok;
    }
}

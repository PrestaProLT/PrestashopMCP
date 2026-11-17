<?php
/**
 * PrestaPro MCP — PSR-4 autoloader for PrestaPro\PpMcp\ => src/.
 *
 * Hand-written on purpose: the module has no Composer runtime dependencies and
 * registers no Symfony controllers/services, so no container scanning happens.
 * Works on PHP 7.1+ and PrestaShop 1.7 / 8 / 9.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (!defined('PPMCP_AUTOLOADER_REGISTERED')) {
    define('PPMCP_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(function ($class) {
        $prefix = 'PrestaPro\\PpMcp\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    require_once __DIR__ . '/classes/PpMcpApiKey.php';
    require_once __DIR__ . '/classes/PpMcpLog.php';
    require_once __DIR__ . '/integration/PpMcpToolProviderTrait.php';
}

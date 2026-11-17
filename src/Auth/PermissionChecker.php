<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Auth;

/**
 * Back-office permission check for the employee behind an API key.
 * Uses Access::isGranted() with ROLE_MOD_TAB_<CLASS>_<AUTH> slugs, available
 * unchanged from PrestaShop 1.7.0 to 9.x.
 */
class PermissionChecker
{
    const LEVELS = [
        'view' => 'READ',
        'read' => 'READ',
        'add' => 'CREATE',
        'create' => 'CREATE',
        'edit' => 'UPDATE',
        'update' => 'UPDATE',
        'delete' => 'DELETE',
    ];

    /** @var array cache */
    private $cache = [];

    /**
     * @param array $permissions list of [TabClass, level]; ANY grants access. Empty = allowed.
     */
    public function allows(\Employee $employee, array $permissions): bool
    {
        if (!$permissions) {
            return true;
        }
        foreach ($permissions as $perm) {
            if ($this->can($employee, (string) $perm[0], isset($perm[1]) ? (string) $perm[1] : 'view')) {
                return true;
            }
        }

        return false;
    }

    public function can(\Employee $employee, string $tabClass, string $level): bool
    {
        $idProfile = (int) $employee->id_profile;
        if ($idProfile === (int) _PS_ADMIN_PROFILE_) {
            return true;
        }
        $auth = isset(self::LEVELS[strtolower($level)]) ? self::LEVELS[strtolower($level)] : 'READ';
        $slug = 'ROLE_MOD_TAB_' . strtoupper($tabClass) . '_' . $auth;
        $key = $idProfile . '|' . $slug;
        if (!isset($this->cache[$key])) {
            try {
                $this->cache[$key] = (bool) \Access::isGranted($slug, $idProfile);
            } catch (\Exception $e) {
                $this->cache[$key] = false;
            }
        }

        return $this->cache[$key];
    }

    /**
     * Human readable form for error messages: "AdminProducts (edit)".
     */
    public static function describe(array $permissions): string
    {
        $parts = [];
        foreach ($permissions as $p) {
            $parts[] = $p[0] . ' (' . (isset($p[1]) ? $p[1] : 'view') . ')';
        }

        return implode(' or ', $parts);
    }
}

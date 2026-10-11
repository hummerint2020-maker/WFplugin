<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * The EWS role → permission matrix edited on the Roles & Permissions page. Pure: no WordPress calls.
 *
 * A permission that is off is *removed* from the role. Storing it as an explicit "false" (as
 * before 3.31.10) took the permission away from users who also had it through another role,
 * because WordPress lets the later role's "false" override the earlier role's "true".
 * The matrix itself is kept in an option so upgrades do not turn removed permissions back on.
 */
final class RolePermissions
{
    public const MANAGE_ROLES = 'ews_manage_roles';

    /**
     * @param mixed $posted roles[slug][cap] checkboxes
     * @param string[] $roles
     * @param string[] $caps
     * @return array<string, array<string, bool>>
     */
    public static function fromPost($posted, array $roles, array $caps): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach ($roles as $role) {
            foreach ($caps as $cap) $out[$role][$cap] = !empty($posted[$role][$cap]);
        }
        return $out;
    }

    /**
     * Desired state of one permission: the saved matrix wins; otherwise what the role already
     * holds (an explicit false from older versions counts as off); otherwise the role's default.
     * @param array<string, mixed>|null $saved   saved matrix row for this role
     * @param array<string, mixed>     $current the role's capabilities
     */
    public static function desired(?array $saved, array $current, string $cap, bool $default): bool
    {
        if ($saved !== null && array_key_exists($cap, $saved)) return (bool) $saved[$cap];
        if (array_key_exists($cap, $current)) return (bool) $current[$cap];
        return $default;
    }

    /**
     * A user who manages roles through EWS roles (not as a WordPress administrator) must not
     * remove that permission from every role they hold, or they lock themselves out.
     * @param array<string, array<string, bool>> $matrix
     * @param string[] $userRoles
     */
    public static function locksOut(array $matrix, array $userRoles, bool $isWpAdmin): bool
    {
        if ($isWpAdmin) return false;
        $held = array_values(array_intersect($userRoles, array_keys($matrix)));
        if (!$held) return false;
        foreach ($held as $role) if (!empty($matrix[$role][self::MANAGE_ROLES])) return false;
        return true;
    }
}

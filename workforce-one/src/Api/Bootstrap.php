<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * GET /me/bootstrap (3.31.113): what the app needs once someone is signed in. It is the /me document, unchanged, plus the
 * features, the screens of this user's phone menu and a few facts about the site. Pure: the plugin passes the facts; only the keys
 * listed here can ever be published.
 */
final class Bootstrap
{
    /**
     * @param array<string,mixed> $me the /me document (user, employee, roles, permissions, device)
     * @param array<string,bool> $features the same flags GET /meta publishes
     * @param list<array{key:string,label:string,order:int}> $screens the phone menu of this user
     * @param array{timezone:string,locale:string,today:string,working_days:list<int>} $site
     * @return array<string,mixed>
     */
    public static function document(array $me, array $features, array $screens, array $site): array
    {
        $flags = [];
        foreach (Meta::FEATURES as $name) $flags[$name] = !empty($features[$name]);
        $list = [];
        foreach ($screens as $s) $list[] = ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'order' => (int) $s['order']];
        return [
            'user' => $me['user'] ?? null,
            'employee' => $me['employee'] ?? null,
            'roles' => array_values((array) ($me['roles'] ?? [])),
            'permissions' => array_values((array) ($me['permissions'] ?? [])),
            'device' => $me['device'] ?? null,
            'features' => $flags,
            'screens' => $list,
            'site' => ['timezone' => (string) $site['timezone'], 'locale' => (string) $site['locale'], 'today' => (string) $site['today'],
                'working_days' => array_values(array_map('intval', $site['working_days']))],
            'limits' => ['schedule_max_days' => Period::MAX_DAYS],
        ];
    }
}

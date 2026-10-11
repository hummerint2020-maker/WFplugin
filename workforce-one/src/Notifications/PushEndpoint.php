<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * Which Web Push endpoints the server may POST to.
 *
 * Threat: a push endpoint is a URL a browser hands us, but any signed-in user can post one
 * (admin-post.php?action=ews_push_subscribe). The server later POSTs to it, so without checks a user
 * could make the server send requests into its own network (cloud metadata at 169.254.169.254, an
 * admin panel on 127.0.0.1, a database on 10.x) - server-side request forgery.
 *
 * Rule (no list of allowed providers, so any standard push service keeps working - FCM, Mozilla,
 * Apple, Windows):
 * - https only, on the default port (443), no user name or password in the URL;
 * - a real host name: not "localhost", not a single label, not a local-only name (.local,
 *   .internal, ...), not a disguised number (127.1, 0x7f.0.0.1, 2130706433);
 * - an IP address in the URL must be public; so must every address the host name resolves to
 *   (checked when subscribing and again just before each delivery, which then connects to exactly
 *   those addresses, so DNS cannot change between the check and the request).
 *
 * Pure: the caller resolves the host name (the network is not touched here).
 */
final class PushEndpoint
{
    public const BAD_URL = 'bad_url';
    public const NOT_HTTPS = 'not_https';
    public const BAD_PORT = 'bad_port';
    public const CREDENTIALS = 'credentials';
    public const LOCAL_HOST = 'local_host';
    public const PRIVATE_ADDRESS = 'private_address';
    public const UNRESOLVED = 'unresolved';

    /** IPv4 ranges that are not public Internet hosts (RFC 6890 and friends). */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges that are not public Internet hosts (the IPv4-in-IPv6 ranges are judged by their IPv4). */
    private const BLOCKED_V6 = [
        '::/96', '100::/64', '64:ff9b:1::/48', '2001::/23', '2001:db8::/32', '3fff::/20',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** Names that only mean something inside a network. */
    private const LOCAL_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'home.arpa', 'in-addr.arpa', 'ip6.arpa'];

    /**
     * Checks the URL itself. Returns [error code or null, host] - the host without brackets, lower case.
     * @return array{0:?string,1:string}
     */
    public static function check(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url)) return [self::BAD_URL, ''];
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return [self::BAD_URL, ''];
        if (strtolower($p['scheme']) !== 'https') return [self::NOT_HTTPS, ''];
        if (isset($p['user']) || isset($p['pass']) || strpos(substr($url, 8, strcspn($url, '/?#', 8)), '@') !== false) return [self::CREDENTIALS, ''];
        if (isset($p['port']) && (int) $p['port'] !== 443) return [self::BAD_PORT, ''];
        $host = strtolower(rtrim($p['host'], '.'));
        if ($host !== '' && $host[0] === '[') {
            $ip = substr($host, 1, -1);
            if (substr($host, -1) !== ']' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return [self::BAD_URL, ''];
            return [self::isPublicIp($ip) ? null : self::PRIVATE_ADDRESS, $ip];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return [self::isPublicIp($host) ? null : self::PRIVATE_ADDRESS, $host];
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || substr($host, -strlen($suffix) - 1) === '.' . $suffix) return [self::LOCAL_HOST, $host];
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) || strlen($host) > 253) return [self::BAD_URL, ''];
        $labels = explode('.', $host);
        $last = end($labels);
        // A top-level domain is never a number: "127.1", "0x7f.1" or "10.0.0.010" are IP addresses in disguise.
        if (preg_match('/^(?:\d+|0x[0-9a-f]*)$/', $last)) return [self::PRIVATE_ADDRESS, $host];
        return [null, $host];
    }

    /**
     * Checks the addresses a host name resolves to: there must be at least one, and all must be public.
     * @param array<int,string> $ips
     */
    public static function checkResolved(array $ips): ?string
    {
        if (!$ips) return self::UNRESOLVED;
        foreach ($ips as $ip) {
            if (!self::isPublicIp((string) $ip)) return self::PRIVATE_ADDRESS;
        }
        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        if (strlen($bin) === 4) return !self::inAny($bin, self::BLOCKED_V4);
        // IPv4 carried in IPv6 is judged by the IPv4: mapped (::ffff:a.b.c.d), NAT64 (64:ff9b::a.b.c.d), 6to4 (2002:aabb:ccdd::).
        if (self::inRange($bin, '::ffff:0:0/96') || self::inRange($bin, '64:ff9b::/96')) return self::isPublicIp((string) inet_ntop(substr($bin, 12, 4)));
        if (self::inRange($bin, '2002::/16')) return self::isPublicIp((string) inet_ntop(substr($bin, 2, 4)));
        return !self::inAny($bin, self::BLOCKED_V6);
    }

    /** @param array<int,string> $ranges */
    private static function inAny(string $bin, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($bin, $range)) return true;
        }
        return false;
    }

    private static function inRange(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = (string) inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) return false;
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** A short English reason for the log and the admin. */
    public static function message(string $code): string
    {
        $m = [
            self::BAD_URL => 'The push endpoint is not a valid URL.',
            self::NOT_HTTPS => 'Push endpoints must use HTTPS.',
            self::BAD_PORT => 'Push endpoints must use the standard HTTPS port.',
            self::CREDENTIALS => 'Push endpoints cannot contain a user name or password.',
            self::LOCAL_HOST => 'The push endpoint points to a local host name.',
            self::PRIVATE_ADDRESS => 'The push endpoint points to a private or reserved address.',
            self::UNRESOLVED => 'The push endpoint host name could not be resolved.',
        ];
        return $m[$code] ?? 'The push endpoint is not allowed.';
    }
}

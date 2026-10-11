<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Notifications\PushEndpoint as P;

final class PushEndpointTest extends TestCase
{
    public function testTheRealPushServicesAreAccepted(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/dXJ0ZXN0:APA91bH-abc_DEF',
            'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABk-xyz',
            'https://web.push.apple.com/QGuQyavXutnMH8Z6Hdx3J9V0Yd',
            'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB%2bAbC',
            'https://db5p.notify.windows.com/w/?token=AwYAAAA',
            'https://fcm.googleapis.com:443/wp/abc',
            'https://push.example-provider.co.uk/endpoint',
            'https://FCM.GoogleAPIs.com./fcm/send/x',
        ] as $url) {
            $this->assertSame(null, P::check($url)[0], $url);
        }
        $this->assertSame('fcm.googleapis.com', P::check('https://FCM.GoogleAPIs.com./fcm/send/x')[1]);
        $this->assertNull(P::checkResolved(['216.239.36.55', '2607:f8b0:4004:c1b::5f']));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function rejected(): array
    {
        return [
            'http' => ['http://fcm.googleapis.com/fcm/send/x', P::NOT_HTTPS],
            'ftp' => ['ftp://fcm.googleapis.com/x', P::NOT_HTTPS],
            'file' => ['file:///etc/passwd', P::BAD_URL],
            'gopher' => ['gopher://127.0.0.1:6379/_x', P::NOT_HTTPS],
            'no scheme' => ['//fcm.googleapis.com/x', P::BAD_URL],
            'empty' => ['', P::BAD_URL],
            'not a url' => ['not a url', P::BAD_URL],
            'spaces' => ['https://fcm.googleapis.com/a b', P::BAD_URL],
            'backslash trick' => ['https://fcm.googleapis.com\\@127.0.0.1/', P::BAD_URL],
            'user info' => ['https://user:pw@fcm.googleapis.com/x', P::CREDENTIALS],
            'user info to localhost' => ['https://fcm.googleapis.com@127.0.0.1/x', P::CREDENTIALS],
            'other port' => ['https://fcm.googleapis.com:8443/x', P::BAD_PORT],
            'port 80' => ['https://fcm.googleapis.com:80/x', P::BAD_PORT],
            'localhost' => ['https://localhost/x', P::LOCAL_HOST],
            'localhost with dot' => ['https://localhost./x', P::LOCAL_HOST],
            'sub.localhost' => ['https://push.localhost/x', P::LOCAL_HOST],
            '.local' => ['https://printer.local/x', P::LOCAL_HOST],
            '.internal' => ['https://metadata.google.internal/computeMetadata/v1/', P::LOCAL_HOST],
            'single label' => ['https://pushserver/x', P::BAD_URL],
            '127.0.0.1' => ['https://127.0.0.1/x', P::PRIVATE_ADDRESS],
            '127.1 shorthand' => ['https://127.1/x', P::PRIVATE_ADDRESS],
            'decimal 2130706433' => ['https://2130706433/x', P::BAD_URL],
            'hex 0x7f000001' => ['https://0x7f000001/x', P::BAD_URL],
            'hex dotted' => ['https://0x7f.0.0.1/x', P::PRIVATE_ADDRESS],
            'octal' => ['https://0177.0.0.1/x', P::PRIVATE_ADDRESS],
            '0.0.0.0' => ['https://0.0.0.0/x', P::PRIVATE_ADDRESS],
            '10/8' => ['https://10.1.2.3/x', P::PRIVATE_ADDRESS],
            '172.16/12' => ['https://172.20.0.5/x', P::PRIVATE_ADDRESS],
            '192.168/16' => ['https://192.168.1.10/x', P::PRIVATE_ADDRESS],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/', P::PRIVATE_ADDRESS],
            'carrier NAT' => ['https://100.64.0.1/x', P::PRIVATE_ADDRESS],
            'multicast' => ['https://224.0.0.1/x', P::PRIVATE_ADDRESS],
            'broadcast' => ['https://255.255.255.255/x', P::PRIVATE_ADDRESS],
            '::1' => ['https://[::1]/x', P::PRIVATE_ADDRESS],
            '::' => ['https://[::]/x', P::PRIVATE_ADDRESS],
            'mapped 127.0.0.1' => ['https://[::ffff:127.0.0.1]/x', P::PRIVATE_ADDRESS],
            'mapped 10.0.0.1 hex' => ['https://[::ffff:a00:1]/x', P::PRIVATE_ADDRESS],
            'unique local fd00' => ['https://[fd12:3456:789a::1]/x', P::PRIVATE_ADDRESS],
            'link local fe80' => ['https://[fe80::1]/x', P::PRIVATE_ADDRESS],
            'site local fec0' => ['https://[fec0::1]/x', P::PRIVATE_ADDRESS],
            'multicast ff02' => ['https://[ff02::1]/x', P::PRIVATE_ADDRESS],
            'documentation 2001:db8' => ['https://[2001:db8::1]/x', P::PRIVATE_ADDRESS],
            '6to4 of 192.168' => ['https://[2002:c0a8:0101::1]/x', P::PRIVATE_ADDRESS],
            'NAT64 of 127' => ['https://[64:ff9b::7f00:1]/x', P::PRIVATE_ADDRESS],
            'bad ipv6' => ['https://[::zz]/x', P::BAD_URL],
        ];
    }

    /** @dataProvider rejected */
    public function testUnsafeEndpointsAreRejected(string $url, string $code): void
    {
        $this->assertSame($code, P::check($url)[0], $url);
    }

    public function testResolvedAddresses(): void
    {
        $this->assertSame(P::UNRESOLVED, P::checkResolved([]));
        $this->assertSame(P::PRIVATE_ADDRESS, P::checkResolved(['216.239.36.55', '10.0.0.7']), 'one private address is enough to refuse (DNS rebinding)');
        $this->assertSame(P::PRIVATE_ADDRESS, P::checkResolved(['::1']));
        $this->assertSame(P::PRIVATE_ADDRESS, P::checkResolved(['garbage']));
        $this->assertTrue(P::isPublicIp('8.8.8.8'));
        $this->assertTrue(P::isPublicIp('2a00:1450:4001:80b::200a'));
        $this->assertTrue(P::isPublicIp('::ffff:8.8.8.8'), 'mapped public IPv4');
        $this->assertTrue(P::isPublicIp('172.32.0.1'), 'just outside 172.16/12');
        $this->assertFalse(P::isPublicIp('172.31.255.255'));
        $this->assertTrue(P::isPublicIp('100.128.0.1'), 'just outside 100.64/10');
        $this->assertNotSame('', P::message(P::PRIVATE_ADDRESS));
    }
}

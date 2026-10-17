<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\DeviceLabel;

final class DeviceLabelTest extends TestCase
{
    public function testCommonPhonesAndComputers(): void
    {
        $this->assertSame('iPhone · Safari', DeviceLabel::from('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'));
        $this->assertSame('iPhone · Safari', DeviceLabel::from('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'), 'the installed app (no Safari/ token)');
        $this->assertSame('Android (SM-A546E) · Samsung Internet', DeviceLabel::from('Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36'));
        $this->assertSame('Android · Chrome', DeviceLabel::from('Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'), 'Chrome hides the model');
        $this->assertSame('Windows · Edge', DeviceLabel::from('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'));
        $this->assertSame('Mac · Firefox', DeviceLabel::from('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertSame('', DeviceLabel::from(''));
    }
}

<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\ProfileService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** ProfileService (3.31.135): the three picture writes. */
final class ProfileServiceTest extends TestCase
{
    public function testTheThreeWritesAndTheNullKey(): void
    {
        $s = new OrgSet();
        $s->people->add(1, 'Mona', 11);
        $svc = new ProfileService($s->people);
        $this->assertSame(1, $svc->avatar(1, 'cat', 'https://x/cat.svg'));
        $this->assertSame(['avatar', 'https://x/cat.svg', 'cat'], [$s->people->rows[1]->profile_image_type, $s->people->rows[1]->profile_image_url, $s->people->rows[1]->avatar_key]);
        $this->assertSame(1, $svc->photo(1, 'https://x/me.jpg'));
        $this->assertSame(['photo', 'https://x/me.jpg', null], [$s->people->rows[1]->profile_image_type, $s->people->rows[1]->profile_image_url, $s->people->rows[1]->avatar_key], 'a photo clears the avatar key');
        $this->assertSame(1, $svc->reset(1));
        $this->assertSame(['initials', '', null], [$s->people->rows[1]->profile_image_type, $s->people->rows[1]->profile_image_url, $s->people->rows[1]->avatar_key]);
    }

    public function testADatabaseErrorIsFalse(): void
    {
        $s = new OrgSet();
        $s->people->add(1, 'Mona', 11);
        $s->w->fail['people.setPicture'] = true;
        $svc = new ProfileService($s->people);
        $this->assertFalse($svc->avatar(1, 'cat', 'u'));
        $this->assertFalse($svc->reset(1));
        $this->assertFalse($svc->photo(1, 'u'));
    }
}

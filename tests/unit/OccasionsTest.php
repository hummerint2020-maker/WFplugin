<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\Occasions;
use WorkforceOne\Settings\OccasionArt;

/** Occasions (3.31.145, docs/tasks/occasions.md): the settings, the form, who sees what, the words, the art. */
final class OccasionsTest extends TestCase
{
    private function cfg(array $over = []): array
    {
        return Occasions::config($over + ['active' => 'ramadan', 'started' => '2027-02-18 09:00:00']);
    }

    public function testDefaultsAreOff(): void
    {
        $c = Occasions::config(null);
        $this->assertSame('', $c['active']);
        $this->assertSame('bg', $c['mode']);
        $this->assertSame(1, $c['kiosk']);
        $this->assertSame(0, $c['birthdays']);
        $this->assertSame(1, $c['cake_self']);
        $this->assertSame(0, $c['cake_everyone']);
        $this->assertSame([], $c['texts']);
        $this->assertNull(Occasions::resolve($c, true, '2027-02-20', ['where' => 'app']), 'on with nothing chosen shows nothing');
    }

    public function testConfigCleans(): void
    {
        $c = Occasions::config(['active' => 'christmas', 'mode' => 'x', 'until' => '2027-02-30', 'people' => [3, '3', -1, 'a', 9], 'texts' => ['ramadan' => ['card_title' => ['ar' => " <b>رمضان</b>\n كريم ", 'en' => str_repeat('x', 61)]]]]);
        $this->assertSame('', $c['active']);
        $this->assertSame('bg', $c['mode']);
        $this->assertSame('', $c['until']);
        $this->assertSame([3, 9], $c['people']);
        $this->assertSame(['ramadan' => ['card_title' => ['ar' => 'رمضان  كريم']]], $c['texts'], 'tags and line breaks dropped; a word too long is not kept');
    }

    public function testFromPost(): void
    {
        $before = Occasions::config([]);
        $now = '2027-02-18 09:00:00';
        [$c, $e] = Occasions::fromPost(['occ_active' => 'nope'], $before, '2027-02-18', $now);
        $this->assertSame('bad_key', $e);
        [$c, $e] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_mode' => 'spin'], $before, '2027-02-18', $now);
        $this->assertSame('bad_mode', $e);
        [$c, $e] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_until' => '2027-02-31'], $before, '2027-02-18', $now);
        $this->assertSame('bad_date', $e);
        [$c, $e] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_until' => '2027-02-17'], $before, '2027-02-18', $now);
        $this->assertSame('until_past', $e);
        [$c, $e] = Occasions::fromPost(['occ_founded' => '2030-01-01'], $before, '2027-02-18', $now);
        $this->assertSame('founded_future', $e);
        [$c, $e] = Occasions::fromPost(['occ_text' => ['ramadan' => ['moment_title' => ['ar' => str_repeat('ر', 41)]]]], $before, '2027-02-18', $now);
        $this->assertSame('text_long', $e);
        [$c, $e] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_mode' => 'full', 'occ_until' => '2027-03-30', 'occ_kiosk' => 1, 'occ_birthdays' => 1, 'occ_people' => ['4'],
            'occ_cake_self' => 1, 'occ_text' => ['ramadan' => ['card_title' => ['ar' => 'رمضان كريم يا أبطال', 'en' => '']]]], $before, '2027-02-18', $now);
        $this->assertSame('', $e);
        $this->assertSame('ramadan', $c['active']);
        $this->assertSame('full', $c['mode']);
        $this->assertSame($now, $c['started'], 'a new occasion starts a new instance');
        $this->assertSame([4], $c['people']);
        $this->assertSame(0, $c['cake_everyone']);
        $this->assertSame(['ramadan' => ['card_title' => ['ar' => 'رمضان كريم يا أبطال']]], $c['texts']);
        [$c2] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_until' => '2027-03-30'], $c, '2027-02-20', '2027-02-20 10:00:00');
        $this->assertSame($now, $c2['started'], 'the same occasion keeps its instance');
        [$c3, $e] = Occasions::fromPost(['occ_active' => 'ramadan', 'occ_until' => '2027-03-30'], $c, '2027-04-01', '2027-04-01 10:00:00');
        $this->assertSame('', $e, 'a saved last day that has passed is kept (not refused)');
        $this->assertTrue(Occasions::ended($c3, '2027-04-01'));
        $this->assertStringContainsString('active=ramadan until 2027-03-30', Occasions::summary($c, true));
    }

    public function testResolve(): void
    {
        $c = $this->cfg(['mode' => 'full']);
        $this->assertNull(Occasions::resolve($c, false, '2027-02-20', ['where' => 'app']), 'off');
        $r = Occasions::resolve($c, true, '2027-02-20', ['where' => 'app']);
        $this->assertSame(['key' => 'ramadan', 'instance' => 'ramadan|2027-02-18 09:00:00', 'mode' => 'full', 'name' => '', 'moment' => 'occasion'], $r);
        $this->assertSame('bg', Occasions::resolve($c, true, '2027-02-20', ['where' => 'login'])['mode'], 'the login page: never full screen');
        $this->assertSame('', Occasions::resolve($c, true, '2027-02-20', ['where' => 'kiosk'])['moment'], 'no moment on a kiosk');
        $this->assertNull(Occasions::resolve($this->cfg(['kiosk' => 0]), true, '2027-02-20', ['where' => 'kiosk']), 'kiosks off');
        $this->assertNull(Occasions::resolve($c, true, '2027-02-20', ['where' => 'app', 'hidden' => 'ramadan|2027-02-18 09:00:00']), 'hidden');
        $this->assertNotNull(Occasions::resolve($c, true, '2027-02-20', ['where' => 'app', 'hidden' => 'ramadan|2026-03-01 09:00:00']), 'hidden last year: shows again');
        $until = $this->cfg(['until' => '2027-03-30']);
        $this->assertNotNull(Occasions::resolve($until, true, '2027-03-30', ['where' => 'app']), 'the last day itself');
        $this->assertNull(Occasions::resolve($until, true, '2027-03-31', ['where' => 'app']), 'after the last day');
        $this->assertSame('', Occasions::resolve($this->cfg(['active' => 'company_birthday']), true, '2027-02-20', ['where' => 'app'])['moment'], 'no moment for the company\'s birthday');
    }

    public function testBirthdays(): void
    {
        $c = $this->cfg(['birthdays' => 1, 'people' => [7]]);
        $mine = Occasions::resolve($c, true, '2027-02-20', ['where' => 'app', 'birthday' => '1990-02-20', 'self_id' => 3]);
        $this->assertSame('birthday', $mine['key']);
        $this->assertSame('cake', $mine['moment']);
        $this->assertSame('', $mine['name']);
        $this->assertSame('ramadan', Occasions::resolve($c, true, '2027-02-20', ['where' => 'login', 'birthday' => '1990-02-20'])['key'], 'never a birthday on the login page');
        $this->assertSame('ramadan', Occasions::resolve($c, true, '2027-02-20', ['where' => 'kiosk', 'birthday' => '1990-02-20'])['key'], 'nor on a kiosk');
        $this->assertSame('ramadan', Occasions::resolve($this->cfg(['birthdays' => 0]), true, '2027-02-20', ['where' => 'app', 'birthday' => '1990-02-20'])['key'], 'birthdays off');
        $leap = Occasions::resolve($c, true, '2027-02-28', ['where' => 'app', 'birthday' => '2000-02-29']);
        $this->assertSame('birthday', $leap['key'], '29 February on 28 February');
        $picked = Occasions::resolve($c, true, '2027-02-20', ['where' => 'app', 'birthday' => '', 'self_id' => 3, 'picked' => [['id' => 7, 'name' => 'Khaled Youssef', 'birthday' => '1970-02-20']]]);
        $this->assertSame(['key' => 'birthday', 'instance' => 'picked|7|2027', 'mode' => 'bg', 'name' => 'Khaled Youssef', 'moment' => ''], $picked, 'everyone sees a picked person\'s birthday; the cake only when switched on');
        $this->assertSame('cake', Occasions::resolve($this->cfg(['birthdays' => 1, 'people' => [7], 'cake_everyone' => 1]), true, '2027-02-20', ['where' => 'app', 'self_id' => 3, 'picked' => [['id' => 7, 'name' => 'K', 'birthday' => '1970-02-20']]])['moment']);
        $own = Occasions::resolve($c, true, '2027-02-20', ['where' => 'app', 'birthday' => '1970-02-20', 'self_id' => 7, 'picked' => [['id' => 7, 'name' => 'K', 'birthday' => '1970-02-20']]]);
        $this->assertSame('', $own['name'], 'the picked person sees their own birthday');
        $this->assertSame('', Occasions::resolve($this->cfg(['birthdays' => 1, 'cake_self' => 0]), true, '2027-02-20', ['where' => 'app', 'birthday' => '1990-02-20'])['moment'], 'the cake switched off');
    }

    public function testPreview(): void
    {
        $r = Occasions::resolve(Occasions::config([]), false, '2027-02-20', ['where' => 'app', 'preview' => 'halloween']);
        $this->assertSame('halloween', $r['key']);
        $this->assertSame('occasion', $r['moment']);
        $this->assertSame('preview|halloween', $r['instance']);
        $this->assertNull(Occasions::resolve(Occasions::config([]), false, '2027-02-20', ['where' => 'app', 'preview' => 'nope']));
    }

    public function testWordsAndNumbers(): void
    {
        $c = $this->cfg(['texts' => ['birthday' => ['card_title' => ['en' => 'Go {name}!']]]]);
        $this->assertSame('Go {name}!', Occasions::text($c, 'birthday', 'card_title', 'en'));
        $this->assertSame('', Occasions::text($c, 'birthday', 'card_title', 'ar'));
        $this->assertSame('Go Omar!', Occasions::fill('Go {name}!', 'Omar', 0));
        $this->assertSame('12 years', Occasions::fill('{years} years', '', 12));
        $this->assertSame(12, Occasions::years('2014-03-01', '2026-03-01'));
        $this->assertSame(11, Occasions::years('2014-03-01', '2026-02-28'));
        $this->assertSame(0, Occasions::years('', '2026-02-28'));
        $this->assertSame(2027, Occasions::newYear('2026-12-31'));
        $this->assertSame(2027, Occasions::newYear('2027-01-01'));
        $this->assertTrue(Occasions::isArabic('رمضان كريم'));
        $this->assertFalse(Occasions::isArabic('HAPPY NEW YEAR'));
    }

    public function testArtForEveryOccasion(): void
    {
        foreach (Occasions::TEXT_KEYS as $k) {
            $this->assertArrayHasKey($k, OccasionArt::VARS, $k);
            $this->assertStringContainsString('--wfo-occ-m1:url("data:image/svg+xml,', OccasionArt::VARS[$k]);
            $this->assertDoesNotMatchRegularExpression('/[<>]|expression\(|javascript:/i', OccasionArt::VARS[$k], 'no markup in the CSS');
        }
        foreach (array_merge(Occasions::MOMENTS, ['birthday']) as $k) {
            $this->assertArrayHasKey($k, OccasionArt::MOMENTS, $k);
            $this->assertStringNotContainsString('<script', OccasionArt::MOMENTS[$k][2]);
        }
        $this->assertStringContainsString('{{year}}', OccasionArt::MOMENTS['new_year'][2]);
    }
}

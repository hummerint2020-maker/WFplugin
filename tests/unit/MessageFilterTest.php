<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Messages\MessageFilter;

/** System messages (3.31.166): the gettext filter callbacks. */
final class MessageFilterTest extends TestCase
{
    /** @var int */
    private $site = 1;
    /** @var string */
    private $locale = 'ar';
    /** @var int */
    private $reads = 0;

    private function filter(array $maps): MessageFilter
    {
        return new MessageFilter(function () { return $this->site; }, function ($s) use ($maps) { $this->reads++; return $maps[$s] ?? null; },
            function () { return $this->locale; }, function () { return ['Verify Face' => true]; });
    }

    public function testReplacesOnlyEditedTextsOfTheLanguage(): void
    {
        $f = $this->filter([1 => ['ar' => ['Save' => 'احفظ', "short button label\x04Email" => 'إيميل'], 'en' => ['Save' => 'Keep']]]);
        $this->assertSame('احفظ', $f->gettext('حفظ', 'Save'));
        $this->assertSame('إلغاء', $f->gettext('إلغاء', 'Cancel'));
        $this->assertSame('إيميل', $f->gettextWithContext('بريد', 'Email', 'short button label'));
        $this->assertSame('بريد', $f->gettextWithContext('بريد', 'Email', 'other'));
        $this->locale = 'en_US';
        $f->forgetLocale();
        $this->assertSame('Keep', $f->gettext('Save', 'Save'));
        $this->assertSame(1, $this->reads, 'one read per site');
    }

    public function testEachSiteHasItsOwnEdits(): void
    {
        $f = $this->filter([1 => ['ar' => ['Save' => 'احفظ']], 2 => null]);
        $this->assertSame('احفظ', $f->gettext('حفظ', 'Save'));
        $this->site = 2;
        $this->assertSame('حفظ', $f->gettext('حفظ', 'Save'));
    }

    public function testScriptTranslations(): void
    {
        $f = $this->filter([1 => ['ar' => ['Verify Face' => 'اتحقق'], 'en' => ['Verify Face' => 'Check my face']]]);
        $json = json_encode(['locale_data' => ['messages' => ['' => ['domain' => 'messages'], 'Verify Face' => ['تحقق من الوجه'], 'Not enrolled' => ['غير مسجّل']]]]);
        $out = json_decode((string) $f->scriptTranslations($json, 'f.json', 'workforce-one', 'workforce-one'), true);
        $this->assertSame(['اتحقق'], $out['locale_data']['messages']['Verify Face']);
        $this->assertSame(['غير مسجّل'], $out['locale_data']['messages']['Not enrolled']);
        $this->assertSame($json, $f->scriptTranslations($json, 'f.json', 'other-plugin', 'other'));
        // An edited script text the file has no translation for is added.
        $bare = json_encode(['locale_data' => ['messages' => ['' => ['domain' => 'messages']]]]);
        $out = json_decode((string) $f->scriptTranslations($bare, 'f.json', 'workforce-one', 'workforce-one'), true);
        $this->assertSame(['اتحقق'], $out['locale_data']['messages']['Verify Face']);
        $this->assertNull($f->preScriptTranslations(null, 'f.json', 'workforce-one', 'workforce-one'), 'Arabic: the shipped file is used');
        $this->locale = 'en_US';
        $f->forgetLocale();
        $en = json_decode((string) $f->preScriptTranslations(null, 'f.json', 'workforce-one', 'workforce-one'), true);
        $this->assertSame(['Check my face'], $en['locale_data']['messages']['Verify Face']);
        $this->assertNull($f->preScriptTranslations(null, 'f.json', 'jquery', 'default'));
    }
}

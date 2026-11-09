<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\HrLetters\LetterFacts;
use WorkforceOne\HrLetters\LetterRules;
use WorkforceOne\HrLetters\LetterSettings;
use WorkforceOne\HrLetters\LetterTemplate;

/** HR letters HL3 (docs/tasks/hr-letter-hl3.md): settings, the request rules, templates and the placeholder values. */
final class HrLetterRulesTest extends TestCase
{
    public function testSettingsAndTypes(): void
    {
        $s = LetterSettings::settings(null);
        $this->assertSame(['ar', 'en'], $s['languages']);
        $this->assertSame('إلى من يهمه الأمر', $s['addressee_ar']);
        $this->assertSame([1, 1, 'none'], [$s['other'], $s['one_open'], $s['new_type_delivery']]);
        $this->assertSame(['en'], LetterSettings::settings(['languages' => ['en', 'fr']])['languages']);
        $this->assertSame(0, LetterSettings::settings(['other' => 0])['other']);
        $types = LetterSettings::types(null);
        $this->assertSame(['employment', 'salary', 'experience', 'embassy', 'bank'], array_column($types, 'key'), 'the five starting types (Q4)');
        $this->assertSame(['pdf', 'pdf', 'upload', 'upload', 'none'], array_column($types, 'delivery'), 'as the approved mockup (decision 11)');
        $this->assertTrue(LetterTemplate::usesSalary($types[1]['tpl_ar']));
        $this->assertFalse(LetterTemplate::usesSalary($types[0]['tpl_ar']));
        foreach ($types as $t) $this->assertSame([], array_merge(LetterTemplate::validate($t['tpl_ar']), LetterTemplate::validate($t['tpl_en'])), 'the starting templates use known placeholders');
        $this->assertSame(['other_x'], array_column(LetterSettings::types([['key' => 'Other X', 'name_en' => 'x'], ['key' => 'other'], ['key' => 'other x']]), 'key'), 'keys cleaned, "other" reserved, no duplicates');
        $this->assertSame('', LetterSettings::letterhead(['logo_key' => '../etc/passwd'])['logo_key']);
    }

    public function testSaveType(): void
    {
        $types = LetterSettings::types(null);
        $r = LetterSettings::saveType($types, '', ['name_en' => 'Visa letter', 'name_ar' => 'خطاب تأشيرة', 'active' => 1], 'none');
        $this->assertSame(['', 'visa_letter', 'none'], [$r['error'], $r['key'], end($r['types'])['delivery']], 'a new type is request only by default (Q1)');
        $r2 = LetterSettings::saveType($r['types'], '', ['name_en' => 'Visa letter'], 'none');
        $this->assertSame('visa_letter_2', $r2['key']);
        $bad = LetterSettings::saveType($types, 'employment', ['name_en' => 'Employment', 'delivery' => 'pdf', 'tpl_en' => 'Net {salary_net} and {g:his|her}'], 'none');
        $this->assertSame(['template_tokens', ['{salary_net}']], [$bad['error'], $bad['unknown']]);
        $this->assertSame('template_missing', LetterSettings::saveType($types, 'bank', ['name_en' => 'Bank', 'delivery' => 'pdf'], 'none')['error']);
        $this->assertSame('name', LetterSettings::saveType($types, 'bank', ['delivery' => 'none'], 'none')['error']);
    }

    public function testTemplate(): void
    {
        $paras = LetterTemplate::render("Dear {title_mr_ms} {employee_name},\nyou {g:are his|are her} {unknown}.\n\n\nSecond   paragraph.", ['title_mr_ms' => 'Ms.', 'employee_name' => 'Mona'], 'female');
        $this->assertSame(['Dear Ms. Mona, you are her {unknown}.', 'Second   paragraph.'], $paras, 'a line end inside a paragraph is a space; unknown tokens stay');
        $this->assertSame(['يعمل/تعمل لديها'], LetterTemplate::render('{g:يعمل|تعمل} لديها', [], ''), 'gender not set: both forms');
        $this->assertSame(['يعمل'], LetterTemplate::render('{g:يعمل|تعمل}', [], 'male'));
        $this->assertSame(['{nope}'], LetterTemplate::validate('{employee_name} {nope} {g:a|b}'));
    }

    public function testRequestCheck(): void
    {
        $types = LetterSettings::types(null);
        $s = LetterSettings::settings(null);
        $ok = LetterRules::check(['type' => 'salary', 'language' => 'ar', 'addressee' => '', 'note' => 'قبل الخميس'], $types, $s);
        $this->assertSame(['', 'pdf', 1, 'إلى من يهمه الأمر', 'شهادة مرتب'], [$ok['error'], $ok['delivery'], $ok['with_salary'], $ok['addressee'], $ok['type_label']]);
        $other = LetterRules::check(['type' => 'other', 'other_text' => 'Letter for the club', 'language' => 'en'], $types, $s);
        $this->assertSame(['', 'upload', 'Other', 'Letter for the club'], [$other['error'], $other['delivery'], $other['type_label'], $other['other_text']], '"Other" is uploaded by HR (Q3)');
        $this->assertSame('other_text', LetterRules::check(['type' => 'other', 'language' => 'en'], $types, $s)['error']);
        $this->assertSame('other_off', LetterRules::check(['type' => 'other', 'other_text' => 'x', 'language' => 'en'], $types, LetterSettings::settings(['other' => 0]))['error']);
        $this->assertSame('type', LetterRules::check(['type' => 'nope', 'language' => 'en'], $types, $s)['error']);
        $types[0]['active'] = 0;
        $this->assertSame('type', LetterRules::check(['type' => 'employment', 'language' => 'en'], $types, $s)['error'], 'a type switched off');
        $types[1]['tpl_en'] = '';
        $this->assertSame('language', LetterRules::check(['type' => 'salary', 'language' => 'en'], $types, $s)['error'], 'a PDF type without a template in that language');
        $this->assertSame(['ar'], LetterRules::languagesFor($types[1], $s));
        $this->assertSame('language', LetterRules::check(['type' => 'bank', 'language' => 'en'], $types, LetterSettings::settings(['languages' => ['ar']]))['error']);
        $this->assertSame('note_long', LetterRules::check(['type' => 'bank', 'language' => 'ar', 'note' => str_repeat('ن', 501)], $types, $s)['error']);
    }

    public function testOpenDownloadAndStates(): void
    {
        $this->assertSame('HR-2026-000123', LetterRules::reference(123, '2026-11-09 10:00:00'));
        $this->assertSame('7:salary:ar', LetterRules::openKey(7, 'salary', 'ar', true));
        $this->assertNull(LetterRules::openKey(7, 'other', 'ar', true), '"Other" is exempt (decision 10)');
        $this->assertNull(LetterRules::openKey(7, 'salary', 'ar', false));
        $issued = (object) ['status' => 'issued', 'file_key' => str_repeat('a', 32) . '.pdf', 'employee_id' => 7, 'delivery' => 'pdf'];
        $this->assertTrue(LetterRules::mayDownload($issued, 7, false, true), 'the employee');
        $this->assertFalse(LetterRules::mayDownload($issued, 7, false, false), '...not while letters are off');
        $this->assertFalse(LetterRules::mayDownload($issued, 8, false, true), 'another employee (an approver too)');
        $this->assertTrue(LetterRules::mayDownload($issued, 0, true, false), 'HR, even while off');
        $this->assertFalse(LetterRules::mayDownload((object) ['status' => 'approved', 'file_key' => '', 'employee_id' => 7], 7, true, true));
        $approved = (object) ['status' => 'approved', 'file_key' => null, 'employee_id' => 7, 'delivery' => 'none'];
        $this->assertSame([true, true, true, false], [LetterRules::mayCancel($approved), LetterRules::mayMarkReady($approved), LetterRules::mayUpload($approved), LetterRules::mayRegenerate($approved)]);
        $this->assertFalse(LetterRules::mayCancel($issued), 'not once a file is attached');
        $this->assertTrue(LetterRules::mayWithdraw((object) ['status' => 'pending', 'employee_id' => 7], 7));
        $this->assertFalse(LetterRules::mayWithdraw((object) ['status' => 'pending', 'employee_id' => 7], 8));
        $this->assertTrue(LetterRules::isOpen($approved), 'open until issued, ready, rejected or withdrawn (decision 10)');
        $this->assertFalse(LetterRules::isOpen($issued));
        $this->assertSame(['approved', 'ready'], array_values(LetterRules::stateFor($issued)));
        $this->assertSame(['approved', 'preparing_collect'], array_values(LetterRules::stateFor($approved)));
        $this->assertSame(['rejected', 'cancelled'], array_values(LetterRules::stateFor((object) ['status' => 'cancelled', 'delivery' => 'none'])));
        $this->assertTrue(LetterRules::isPdf("%PDF-1.4\n"));
        $this->assertFalse(LetterRules::isPdf('<html>'));
    }

    public function testFacts(): void
    {
        $letter = (object) ['id' => 123, 'requested_at' => '2026-11-07 09:00:00', 'language' => 'ar', 'addressee' => 'بنك مصر', 'type_label' => 'شهادة مرتب', 'other_text' => ''];
        $emp = (object) ['name' => 'Ahmed Samir', 'name_ar' => 'أحمد سمير', 'gender' => 'male', 'hire_date' => '2021-03-01', 'created_at' => '2023-01-12 08:00:00', 'job_title' => 'Senior Accountant', 'department_name' => 'Accounts'];
        $v = LetterFacts::values($letter, $emp, ['basic' => 15000, 'allowances' => 3500.5, 'total' => 18500.5], LetterSettings::letterhead(['company_ar' => 'شركة النيل']), LetterSettings::settings(['currency_ar' => 'جنيه مصري']), 'EGP', 'Site', '2026-11-09');
        $this->assertSame('أحمد سمير', $v['employee_name']);
        $this->assertSame('السيد', $v['title_mr_ms']);
        $this->assertSame('١ مارس ٢٠٢١', $v['hire_date']);
        $this->assertSame('٩ نوفمبر ٢٠٢٦', $v['date']);
        $this->assertSame('HR-2026-000123', $v['ref_no'], 'Western digits (decision 8)');
        $this->assertSame('١٨٬٥٠٠٫٥٠', $v['salary_total']);
        $this->assertSame('ثمانية عشر ألفًا وخمسمائة و٥٠/١٠٠', $v['salary_in_words']);
        $this->assertSame(['جنيه مصري', 'شركة النيل'], [$v['currency'], $v['company_name']]);
        $this->assertStringContainsString('هـ', $v['date_hijri']);
        $en = LetterFacts::values((object) (['language' => 'en'] + (array) $letter), (object) (['name_ar' => '', 'gender' => '', 'hire_date' => null] + (array) $emp), null, LetterSettings::letterhead([]), LetterSettings::settings(null), 'EGP', 'Nile Site', '2026-11-09');
        $this->assertSame(['Ahmed Samir', 'Mr./Ms.', '12 January 2023', 'Nile Site', 'EGP', ''], [$en['employee_name'], $en['title_mr_ms'], $en['hire_date'], $en['company_name'], $en['currency'], $en['salary_total']],
            'falls back: the name, a title for anyone, the day added, the site name, the currency code (decision 3, 4)');
        $this->assertSame('eighteen thousand five hundred', LetterFacts::words(18500, 'en'));
        $this->assertSame('one thousand and 5/100', LetterFacts::words(1000.05, 'en'));
    }
}

<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Messages\MessageCatalog;

/** System messages (3.31.165): the list the page shows. */
final class MessageCatalogTest extends TestCase
{
    private function catalog(): MessageCatalog
    {
        $row = function ($m, $k, $n = 1, $c = '', $p = 0, $j = 0) { return ['m' => $m, 'r' => ['x.php:1'], 'n' => $n, 'c' => $c, 'p' => $p, 'j' => $j, 'k' => $k]; };
        return new MessageCatalog([
            'Correction rejected' => $row('attendance', 'notification'),
            'Save' => $row('platform', 'ui', 7),
            "closed week banner\x04This week is closed." => $row('schedule', 'ui'),
            'This week is closed.' => $row('schedule', 'ui'),
            '%d day' => $row('leave', 'ui', 1, '', 1),
            'The shift swap request was rejected.' => $row('schedule', 'error'),
        ], ['Correction rejected' => 'رُفض التصحيح', 'Save' => 'حفظ']);
    }

    public function testDefaultsAndKinds(): void
    {
        $c = $this->catalog();
        $this->assertSame('رُفض التصحيح', $c->defaultText('ar', 'Correction rejected'));
        $this->assertSame('Correction rejected', $c->defaultText('en', 'Correction rejected'));
        $this->assertSame('This week is closed.', $c->defaultText('ar', "closed week banner\x04This week is closed."));
        // ImportantMessages overrides the guess.
        $this->assertSame('banner', $c->kind('The shift swap request was rejected.'));
        $this->assertSame('notification', $c->kind('Correction rejected'));
        $this->assertTrue($c->plural('%d day'));
        $this->assertSame("closed week banner\x04This week is closed.", $c->byHash(md5("closed week banner\x04This week is closed.")));
        $this->assertNull($c->byHash(md5('nope')));
    }

    public function testListingFiltersSearchesAndPutsEditedFirst(): void
    {
        $c = $this->catalog();
        $edits = ['Save' => 'احفظ'];
        $all = $c->listing(['lang' => 'ar'], $edits, 1);
        $this->assertSame(6, $all['total']);
        $this->assertSame('Save', $all['rows'][0]['key']);
        $this->assertTrue($all['rows'][0]['edited']);
        $this->assertSame('احفظ', $all['rows'][0]['current']);
        $this->assertSame('حفظ', $all['rows'][0]['default']);
        $this->assertSame(1, $c->listing(['lang' => 'ar', 'edited' => true], $edits, 1)['total']);
        $this->assertSame(1, $c->listing(['lang' => 'ar', 'kind' => 'notification'], $edits, 1)['total']);
        $this->assertSame(3, $c->listing(['lang' => 'ar', 'module' => 'schedule'], $edits, 1)['total']);
        // Search: the original, the shipped Arabic and the edit.
        $this->assertSame(1, $c->listing(['lang' => 'ar', 'q' => 'التصحيح'], $edits, 1)['total']);
        $this->assertSame(1, $c->listing(['lang' => 'ar', 'q' => 'احفظ'], $edits, 1)['total']);
        $this->assertSame(2, $c->listing(['lang' => 'ar', 'q' => 'WEEK IS'], $edits, 1)['total']);
        // The context makes its own row.
        $ctx = $c->listing(['lang' => 'en', 'q' => 'week is'], [], 1)['rows'];
        $this->assertSame(['closed week banner', ''], [$ctx[0]['context'] === '' ? $ctx[1]['context'] : $ctx[0]['context'], $ctx[0]['context'] === '' ? $ctx[0]['context'] : $ctx[1]['context']]);
        $page = $c->listing(['lang' => 'en'], [], 2, 4);
        $this->assertSame([2, 2, 2], [$page['page'], $page['pages'], count($page['rows'])]);
        $this->assertSame(2, $c->listing(['lang' => 'en'], [], 9, 4)['page']);
    }

    public function testAPluralIsNotEditedAndNeedsReviewListsUnknownKeys(): void
    {
        $c = $this->catalog();
        $row = $c->row('en', '%d day', ['%d day' => 'x']);
        $this->assertFalse($row['edited']);
        $this->assertSame(7, $c->row('en', 'Save', [])['places']);
        $r = $c->needsReview(['ar' => ['Save' => 'احفظ', 'Old text' => 'قديم'], 'en' => ['Gone' => 'x']]);
        $this->assertSame([['ar', 'Old text'], ['en', 'Gone']], array_map(function ($x) { return [$x['lang'], $x['key']]; }, $r));
        $this->assertSame(['Save'], $c->keysOfModule('platform'));
    }
}

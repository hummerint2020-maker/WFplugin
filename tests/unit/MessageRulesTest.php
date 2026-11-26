<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Messages\MessageRules as R;

/** System messages (3.31.165): what an edited text may be. */
final class MessageRulesTest extends TestCase
{
    public function testKeysAndBuckets(): void
    {
        $this->assertSame('Save', R::key('', 'Save'));
        $this->assertSame("closed week banner\x04Hi", R::key('closed week banner', 'Hi'));
        $this->assertSame(['closed week banner', 'Hi'], R::split("closed week banner\x04Hi"));
        $this->assertSame(['', 'Save'], R::split('Save'));
        $this->assertSame('ar', R::bucket('ar'));
        $this->assertSame('ar', R::bucket('ar_EG'));
        $this->assertSame('en', R::bucket('en_US'));
        $this->assertSame('en', R::bucket(''));
    }

    public function testPlaceholdersAreCountedByNumberAndType(): void
    {
        $this->assertSame(['1s', '2s'], R::placeholders('%s on %s'));
        $this->assertSame(['1d', '2s'], R::placeholders('%2$s, %1$d'));
        $this->assertSame(['1s'], R::placeholders('%1$s and %1$s again'));
        $this->assertSame([], R::placeholders('100%% sure'));
        $this->assertNull(R::placeholders('100% sure'));
        $this->assertNull(R::placeholders('The % sign'));
        $this->assertNull(R::placeholders('%s and %2$s'));
        $this->assertSame('%1$s on %2$d', R::numbered('%s on %d'));
        $this->assertSame('%2$s %1$s', R::numbered('%2$s %1$s'));
    }

    public function testAGoodEditIsKeptAndNumbered(): void
    {
        $this->assertSame(['Correction turned down', ''], R::check('Correction rejected', 'Correction turned down', 'notification'));
        // Moved placeholders of a plain original come back numbered, so they stay in their place.
        $this->assertSame(['Day %2$s: %1$s', ''], R::check('Your correction for %s was rejected: %s', 'Day %2$s: %1$s', 'notification'));
        $this->assertSame(['On %1$s: %2$s', ''], R::check('Your correction for %s was rejected: %s', 'On %s: %s', 'notification'));
        $this->assertSame(['رُفض تصحيح يوم %1$s: %2$s', ''], R::check('Your correction for %1$s was rejected: %2$s', 'رُفض تصحيح يوم %1$s: %2$s', 'notification'));
    }

    public function testPlaceholderMistakesAreRefused(): void
    {
        $o = 'Your correction for %1$s was rejected: %2$s';
        $this->assertSame('placeholders', R::check($o, 'Rejected: %2$s', 'notification')[1]);
        $this->assertSame('placeholders', R::check($o, '%1$s %2$s %3$s', 'notification')[1]);
        $this->assertSame('placeholders', R::check($o, '%1$d %2$s', 'notification')[1]);
        $this->assertSame('placeholders', R::check($o, '%s %2$s', 'notification')[1]);
        $this->assertSame('percent', R::check($o, '%1$s %2$s 100%', 'notification')[1]);
        $this->assertSame('placeholders', R::check('Save', 'Save %s', 'ui')[1]);
        $this->assertSame('percent', R::check('Save', 'Save 100%%', 'ui')[1]);
        $this->assertSame('', R::check('%d%% of requests', '%d%% من الطلبات', 'ui')[1]);
        // An original that is no printf text (a lone %) never goes through sprintf.
        $this->assertSame('', R::check('Up to 100% now', 'Up to 100% today', 'ui')[1]);
        $this->assertSame('empty', R::check('Save', "  \n ", 'ui')[1]);
    }

    public function testTagsQuotesAndLength(): void
    {
        $o = 'Your schedule for <strong>%1$s</strong> is now <strong>%2$s</strong>.';
        $this->assertSame('', R::check($o, 'جدولك يوم <strong>%1$s</strong> أصبح <strong>%2$s</strong>', 'notification')[1]);
        $this->assertSame('html', R::check($o, 'Your schedule for %1$s is now %2$s', 'notification')[1]);
        $this->assertSame('html', R::check('Save', '<script>x</script>', 'ui')[1]);
        $this->assertSame('html', R::check('Save', 'a < b', 'ui')[1]);
        $this->assertSame('html', R::check($o, '<strong>%1$s</strong> <em>%2$s</em>', 'notification')[1]);
        $this->assertSame(['You’re done ”now”', ''], R::check('Done', 'You\'re done "now"', 'ui'));
        $this->assertSame(['Don\'t', ''], R::check('Don\'t', 'Don\'t', 'ui'));
        $this->assertSame('chars', R::check('Save', 'a\\b', 'ui')[1]);
        $this->assertSame('too_long', R::check('Title', str_repeat('x', 81), 'notification_title')[1]);
        $this->assertSame('', R::check('Title', str_repeat('ع', 80), 'notification_title')[1]);
        $this->assertSame(60, R::limit('ui', 'Save'));
        $this->assertSame(300, R::limit('banner', 'x'));
        $this->assertSame(500, R::limit('ui', str_repeat('x', 400)));
    }

    public function testLabelsComeFromTheTranslatorsComment(): void
    {
        $this->assertSame(['%1$s' => 'day', '%2$s' => 'the manager\'s note'], R::labels('translators: 1: day, 2: the manager\'s note', 'Your correction for %1$s was rejected: %2$s'));
        $this->assertSame(['%1$s' => 'employee name'], R::labels('translators: %s: employee name', '%s asked for a salary advance.'));
        $this->assertSame(['%1$s' => '#1', '%2$d' => '#2'], R::labels('', '%s and %d'));
        $this->assertSame([], R::labels('', 'Save'));
    }
}

<?php
/**
 * Schedule change requests (3.31.167): the days of a request, each person · day · old → new, with a warning line.
 * Included by templates/app/approvals.php (the HR card) and templates/app/requests.php (the days not applied).
 * @var list<array{name:string,day:string,old:string,new:string,warn:string}> $sc_days
 * @var string $sc_class is-card | is-mini
 * @var bool $sc_change show old → new
 */
if (!defined('ABSPATH')) exit;
?>
<ul class="wfo-sc-list <?php echo esc_attr($sc_class); ?>">
<?php foreach ($sc_days as $sc_d): ?>
    <li class="<?php echo $sc_d['warn'] !== '' ? 'is-warn' : ''; ?>"><b><?php echo esc_html($sc_d['name']); ?></b><span><?php echo esc_html($sc_d['day']); ?></span><?php if ($sc_change): ?><span class="wfo-sc-change"><?php echo esc_html($sc_d['old']); ?><em><?php echo \WorkforceOne\Ui\Icons::svg('arrow', 14, 2.2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></em><?php echo esc_html($sc_d['new']); ?></span><?php endif; ?><?php if ($sc_d['warn'] !== ''): ?><small class="wfo-sc-since"><?php echo \WorkforceOne\Ui\Icons::svg('alert', 13, 2.2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php echo esc_html($sc_d['warn']); ?></small><?php endif; ?></li>
<?php endforeach; ?>
</ul>

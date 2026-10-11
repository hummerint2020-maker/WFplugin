<?php
/**
 * Page links under a long people list (Ui\ListPage, 3.31.71): "51–100 of 3,000" and Previous / Next.
 * Links keep the week, search and team (add_query_arg on the current address).
 *
 * @var array{rows:object[],paged:bool,page:int,pages:int,total:int,all:int,q:string,team:string} $list
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\ListPage;
if (!empty($list['paged'])):
$from = $list['total'] ? ($list['page'] - 1) * ListPage::PER + 1 : 0;
$to = min($list['total'], $list['page'] * ListPage::PER);
?>
<nav class="wfo-list-pager ews-no-print" aria-label="<?php esc_attr_e('Pages', 'workforce-one'); ?>">
    <span class="wfo-list-pager-count"><?php echo esc_html(sprintf(/* translators: 1: first row, 2: last row, 3: number of people */ __('%1$d–%2$d of %3$d', 'workforce-one'), $from, $to, $list['total'])); ?></span>
    <?php if ($list['page'] > 1): ?><a class="ews-btn secondary wfo-btn-ghost" href="<?php echo esc_url(add_query_arg('pg', $list['page'] - 1)); ?>" rel="prev"><?php esc_html_e('Previous', 'workforce-one'); ?></a><?php endif; ?>
    <span class="wfo-list-pager-page"><?php echo esc_html(sprintf(/* translators: 1: page, 2: number of pages */ __('Page %1$d of %2$d', 'workforce-one'), $list['page'], $list['pages'])); ?></span>
    <?php if ($list['page'] < $list['pages']): ?><a class="ews-btn secondary wfo-btn-ghost" href="<?php echo esc_url(add_query_arg('pg', $list['page'] + 1)); ?>" rel="next"><?php esc_html_e('Next', 'workforce-one'); ?></a><?php endif; ?>
</nav>
<?php endif;

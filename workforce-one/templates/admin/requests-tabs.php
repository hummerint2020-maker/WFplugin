<?php
/**
 * The Requests Hub's two tabs (3.31.159, only with Requests Hub reports on).
 *
 * @var list<array{key:string,label:string,url:string,count:int,current:bool}> $tabs
 */
if (!defined('ABSPATH')) exit;
?>
<nav class="nav-tab-wrapper wp-clearfix ews-hr-tabs" aria-label="<?php esc_attr_e('Requests Hub', 'workforce-one'); ?>">
<?php foreach ($tabs as $t): ?>
    <a href="<?php echo esc_url($t['url']); ?>" class="nav-tab<?php echo $t['current'] ? ' nav-tab-active' : ''; ?>"<?php echo $t['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html($t['label']); ?><?php if ($t['count'] > 0): ?> <span class="ews-hr-count"><?php echo (int) $t['count']; ?></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>

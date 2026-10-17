<?php
/**
 * wp-admin → Settings Overview: every Workforce One setting with its current value and default.
 * Read-only; the only form is the export. Rows come from src/Settings/Options.php.
 *
 * @var array<int,array<string,mixed>> $rows  name, group, label, current, default_text, changed, status, page
 * @var array<string,string> $groups          group key => title
 * @var int $changed_count
 * @var int $total
 * @var bool $only_changed
 * @var string[] $unregistered                stored ews_* options this version does not know
 * @var string $page_url
 */
if (!defined('ABSPATH')) exit;

$by_group = [];
foreach ($rows as $r) $by_group[$r['group']][] = $r;
?>
<div class="wrap ews-settings-overview"><h1>Settings Overview</h1>
<p class="ews-so-intro">Every Workforce One setting on this site, with its current value and the value used when nothing is saved. Settings are changed on their own pages (the Edit links); this page changes nothing.</p>

<div class="ews-so-bar">
    <p class="ews-so-summary"><strong><?php echo (int) $total; ?></strong> settings · <strong><?php echo (int) $changed_count; ?> changed</strong> from their defaults</p>
    <p class="ews-so-filter">
        <?php if ($only_changed): ?>
            <a class="button" href="<?php echo esc_url($page_url); ?>">Show all</a>
        <?php else: ?>
            <a class="button" href="<?php echo esc_url(add_query_arg('changed', 1, $page_url)); ?>">Only changed</a>
        <?php endif; ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-so-export">
        <input type="hidden" name="action" value="ews_settings_export">
        <?php wp_nonce_field('ews_settings_export'); ?>
        <button type="submit" class="button button-primary">Export settings (JSON)</button>
        <span class="description">For support. Secrets and employee data are left out.</span>
    </form>
</div>

<?php if (!$rows): ?><p>No setting differs from its default.</p><?php endif; ?>

<?php foreach ($groups as $key => $title): if (empty($by_group[$key])) continue; ?>
<h2><?php echo esc_html($title); ?></h2>
<table class="widefat striped ews-so-table">
    <thead><tr><th class="ews-so-name">Setting</th><th>Current</th><th>Default</th><th class="ews-so-status">Status</th><th class="ews-so-edit"></th></tr></thead>
    <tbody>
    <?php foreach ($by_group[$key] as $r): ?>
        <tr data-option="<?php echo esc_attr($r['name']); ?>"<?php echo $r['changed'] ? ' class="is-changed"' : ''; ?>>
            <td class="ews-so-name">
                <strong><?php echo esc_html($r['label']); ?></strong><br><code><?php echo esc_html($r['name']); ?></code>
            </td>
            <td data-label="Current"><?php echo esc_html($r['current']); ?></td>
            <td data-label="Default"><?php echo esc_html($r['default_text']); ?></td>
            <td class="ews-so-status" data-label="Status">
                <?php if ($r['status'] === 'changed'): ?><span class="ews-so-badge is-changed">Changed</span>
                <?php elseif ($r['status'] === 'default'): ?><span class="ews-so-badge">Default</span>
                <?php elseif ($r['status'] === 'internal'): ?><span class="ews-so-badge is-unset">Set by the plugin</span>
                <?php elseif ($r['status'] === 'legacy'): ?><span class="ews-so-badge is-unset">From older versions</span>
                <?php elseif ($r['status'] === 'secret'): ?><span class="ews-so-badge is-unset">Hidden</span>
                <?php else: ?><span class="ews-so-badge is-unset" title="Nothing saved yet; the default is used">Default (not saved)</span><?php endif; ?>
            </td>
            <td class="ews-so-edit"><?php if (!empty($r['page'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page=' . $r['page'])); ?>">Edit</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endforeach; ?>

<?php if ($unregistered): ?>
<h2>Other stored settings</h2>
<p>Saved on this site with a Workforce One name, but not used by this version (left by an older version or added by hand). Their values are not shown.</p>
<ul class="ews-so-unknown">
    <?php foreach ($unregistered as $name): ?><li><code><?php echo esc_html($name); ?></code></li><?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

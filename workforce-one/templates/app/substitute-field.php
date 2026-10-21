<?php
/**
 * A manager's leave form → "Who decides for you while you are away?" (3.31.107, mockup
 * docs/mockups/substitutes/, screen 1). Shared by the Leave page and My requests → New request.
 * The substitute starts when the leave is approved and stops if it is cancelled.
 *
 * @var array{required:bool,people:list<array{id:int,name:string,sub:string,suggested:bool}>}|null $sub_q  EWS_Substitutes_Trait::substitute_leave_question()
 * @var string $sub_id   the select's id
 * (optional) $sub_class: the field wrapper class of the form it is in
 */
if (!defined('ABSPATH')) exit;
if (empty($sub_q)) return;
$suggested = array_values(array_filter($sub_q['people'], static function ($p) { return $p['suggested']; }));
$others = array_values(array_filter($sub_q['people'], static function ($p) { return !$p['suggested']; }));
?>
<div class="<?php echo esc_attr((isset($sub_class) && is_string($sub_class) ? $sub_class : 'wfo-rq-field') . ' wfo-sub-field'); ?>" data-substitute-field>
    <label for="<?php echo esc_attr($sub_id); ?>"><?php esc_html_e('Who decides for you while you are away?', 'workforce-one'); ?><?php if (!$sub_q['required']): ?> <span class="wfo-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span><?php endif; ?></label>
    <select id="<?php echo esc_attr($sub_id); ?>" name="substitute_employee_id"<?php echo $sub_q['required'] ? ' required' : ''; ?>>
        <option value=""><?php echo $sub_q['required'] ? esc_html__('Pick someone', 'workforce-one') : esc_html__('No substitute: requests wait for me', 'workforce-one'); ?></option>
        <?php if ($suggested): ?><optgroup label="<?php esc_attr_e('Suggested', 'workforce-one'); ?>"><?php foreach ($suggested as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name'] . ($p['sub'] !== '' ? ' · ' . $p['sub'] : '')); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <?php if ($others): ?><optgroup label="<?php esc_attr_e('Everyone else', 'workforce-one'); ?>"><?php foreach ($others as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
    </select>
    <small class="wfo-help"><?php esc_html_e('Requests from your team on these days go to them, marked "for" you. It starts when the leave is approved.', 'workforce-one'); ?></small>
</div>

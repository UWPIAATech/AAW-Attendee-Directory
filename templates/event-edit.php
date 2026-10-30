<?php
if (!defined('ABSPATH')) exit;

$requested = sanitize_key(wp_unslash($_GET['event'] ?? ''));
$errors = array();
$not_found = false;

// After a failed save, show what was typed along with the errors.
$form_key = 'idloom_event_form_' . get_current_user_id();
$pending = isset($_GET['error']) ? get_transient($form_key) : false;
delete_transient($form_key);

if ($pending) {
    $event = $pending['event'];
    $original = $pending['original'];
    $errors = $pending['errors'];
} elseif ($requested !== '' && ($saved_event = IdloomEvents::get($requested))) {
    $event = $saved_event;
    $original = $requested;
} else {
    $not_found = $requested !== '';
    $event = IdloomEvents::blank();
    $original = '';
}

$rules = $event['rules'];

$render_column_row = function($index, $column) {
    $name = 'event[columns][' . $index . ']';
    ?>
    <tr class="idloom-column-row">
        <td class="idloom-column-order">
            <button type="button" class="button-link idloom-move-up" aria-label="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
            <button type="button" class="button-link idloom-move-down" aria-label="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
        </td>
        <td><input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($column['label']); ?>" placeholder="Shown as" class="regular-text"></td>
        <td><input type="text" name="<?php echo esc_attr($name); ?>[field]" value="<?php echo esc_attr($column['field']); ?>" placeholder="free_field12" list="idloom-field-list"></td>
        <td><input type="checkbox" name="<?php echo esc_attr($name); ?>[sortable]" value="1" <?php checked(!empty($column['sortable'])); ?>></td>
        <td><input type="checkbox" name="<?php echo esc_attr($name); ?>[searchable]" value="1" <?php checked(!empty($column['searchable'])); ?>></td>
        <td><button type="button" class="button-link idloom-remove-column">Remove</button></td>
    </tr>
    <?php
};
?>
<div class="wrap">
    <h1><?php echo $original !== '' ? esc_html('Edit ' . $event['label']) : 'Add Event'; ?></h1>

    <?php if ($not_found): ?>
        <div class="notice notice-error"><p>That event no longer exists. You can add it again below.</p></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error">
            <p>The event was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo esc_html($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <datalist id="idloom-field-list"></datalist>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="idloom-event-form">
        <input type="hidden" name="action" value="idloom_save_event">
        <input type="hidden" name="original_shortcode" value="<?php echo esc_attr($original); ?>">
        <?php wp_nonce_field('idloom_save_event'); ?>

        <h2>Event</h2>
        <table class="form-table">
            <tr>
                <th><label for="idloom-label">Name</label></th>
                <td>
                    <input type="text" id="idloom-label" name="event[label]" value="<?php echo esc_attr($event['label']); ?>" class="regular-text">
                    <p class="description">For your reference in admin only.</p>
                </td>
            </tr>
            <tr>
                <th><label for="idloom-shortcode">Shortcode</label></th>
                <td>
                    [<input type="text" id="idloom-shortcode" name="event[shortcode]" value="<?php echo esc_attr($event['shortcode']); ?>" placeholder="tucson27" pattern="[a-z][a-z0-9_]{1,39}">]
                    <p class="description">Put this on a page to show the list. Lowercase letters, numbers and underscores.
                        <?php if ($original !== ''): ?>
                            <strong>Changing it breaks pages that use [<?php echo esc_html($original); ?>].</strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="idloom-event-uid">Idloom Event ID</label></th>
                <td>
                    <input type="text" id="idloom-event-uid" name="event[event_uid]" value="<?php echo esc_attr($event['event_uid']); ?>" class="regular-text">
                    <button type="button" class="button" id="idloom-load-fields">Load fields</button>
                    <p class="description">"Load fields" looks at recent registrations and lists the fields this event's form has, so you can pick them below.</p>
                    <div id="idloom-fields-result" hidden></div>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <label><input type="checkbox" name="event[enabled]" value="1" <?php checked(!empty($event['enabled'])); ?>> Enabled</label>
                    <p class="description">When disabled, the shortcode shows nothing.</p>
                </td>
            </tr>
        </table>

        <h2>Who appears on the list</h2>
        <table class="form-table">
            <tr>
                <th>Registration</th>
                <td>
                    <label><input type="checkbox" name="event[rules][require_completed]" value="1" <?php checked(!empty($rules['require_completed'])); ?>> Only completed registrations</label>
                </td>
            </tr>
            <tr>
                <th><label for="idloom-payment">Payment</label></th>
                <td>
                    <select id="idloom-payment" name="event[rules][payment]">
                        <option value="none" <?php selected($rules['payment'], 'none'); ?>>Not required</option>
                        <option value="paid" <?php selected($rules['payment'], 'paid'); ?>>Must be paid</option>
                        <option value="paid_or_exception" <?php selected($rules['payment'], 'paid_or_exception'); ?>>Paid, or exception field filled in</option>
                    </select>
                    <p id="idloom-exception-row">
                        <label>Exception field
                            <input type="text" name="event[rules][exception_field]" value="<?php echo esc_attr($rules['exception_field']); ?>" placeholder="free_field1" list="idloom-field-list">
                        </label>
                        <span class="description">e.g. a coupon code. Anyone with this filled in is shown even if unpaid.</span>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="idloom-optin">Opt-in field</label></th>
                <td>
                    <input type="text" id="idloom-optin" name="event[rules][optin_field]" value="<?php echo esc_attr($rules['optin_field']); ?>" placeholder="free_field56" list="idloom-field-list">
                    <p class="description">The "show me on the registered list" question. Only people who answered yes are shown.</p>
                    <p id="idloom-optin-warning" class="notice notice-warning inline" hidden>
                        No opt-in field: <strong>everyone</strong> who passes the rules above will be listed publicly.
                    </p>
                </td>
            </tr>
        </table>

        <h2>Columns</h2>
        <table class="widefat idloom-columns">
            <thead>
                <tr>
                    <th style="width:4em">Order</th>
                    <th>Label</th>
                    <th>Field</th>
                    <th style="width:6em">Sortable</th>
                    <th style="width:6em">Searchable</th>
                    <th style="width:6em"></th>
                </tr>
            </thead>
            <tbody id="idloom-columns-body">
                <?php foreach ($event['columns'] as $index => $column) {
                    $render_column_row($index, $column);
                } ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="idloom-add-column">Add column</button></p>

        <template id="idloom-column-template">
            <?php $render_column_row('__i__', array('label' => '', 'field' => '', 'sortable' => true, 'searchable' => true)); ?>
        </template>

        <table class="form-table">
            <tr>
                <th><label for="idloom-default-sort">Default sort</label></th>
                <td>
                    <input type="text" id="idloom-default-sort" name="event[default_sort]" value="<?php echo esc_attr($event['default_sort']); ?>" placeholder="lastname" list="idloom-field-list">
                    <p class="description">Field of a sortable column. Leave blank to sort by the first sortable column.</p>
                </td>
            </tr>
        </table>

        <?php submit_button($original !== '' ? 'Save Event' : 'Add Event'); ?>
    </form>

    <?php if ($original !== '' && empty($errors)): ?>
        <h2>Test</h2>
        <p>Loads this event fresh from Idloom using the saved settings and shows how many people would be listed.</p>
        <p>
            <button type="button" class="button" id="idloom-test-event" data-event="<?php echo esc_attr($original); ?>">Test event</button>
        </p>
        <div id="idloom-test-result"></div>
    <?php endif; ?>
</div>

<?php
if (!defined('ABSPATH')) exit;

$events = IdloomEvents::get_all();

$notice = '';
if (isset($_GET['saved'])) {
    $notice = 'Saved [' . sanitize_key(wp_unslash($_GET['saved'])) . '].';
} elseif (isset($_GET['deleted'])) {
    $notice = 'Deleted [' . sanitize_key(wp_unslash($_GET['deleted'])) . '].';
} elseif (isset($_GET['cleared'])) {
    $cleared = sanitize_key(wp_unslash($_GET['cleared']));
    $notice = $cleared === 'all' ? 'Cleared the cache for all events.' : "Cleared the cache for [{$cleared}].";
}

$format_expiry = function($timestamp) {
    return $timestamp ? wp_date('Y-m-d H:i:s', (int) $timestamp) : 'N/A';
};
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Idloom Events</h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=' . IdloomAdmin::EDIT_PAGE)); ?>" class="page-title-action">Add Event</a>
    <hr class="wp-header-end">

    <?php if ($notice): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <h2>API Key</h2>
    <p>Shared by all events.</p>
    <form method="post" action="options.php">
        <?php settings_fields('idloom-settings-group'); ?>
        <table class="form-table">
            <tr>
                <th><label for="idloom_api_key">API Key</label></th>
                <td><input type="text" id="idloom_api_key" name="idloom_api_key" value="<?php echo esc_attr(get_option('idloom_api_key')); ?>" class="regular-text"></td>
            </tr>
        </table>
        <?php submit_button('Save API Key'); ?>
    </form>

    <h2>Events</h2>

    <?php if (empty($events)): ?>
        <p>No events yet. <a href="<?php echo esc_url(admin_url('admin.php?page=' . IdloomAdmin::EDIT_PAGE)); ?>">Add your first event</a>.</p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Shortcode</th>
                    <th>Idloom Event ID</th>
                    <th>Status</th>
                    <th>Cache (5 min)</th>
                    <th>Backup Cache (1 hour)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $shortcode => $event): ?>
                    <?php
                    $cache_info = $this->api_handler->get_cache_info($event);
                    $edit_url = admin_url('admin.php?page=' . IdloomAdmin::EDIT_PAGE . '&event=' . rawurlencode($shortcode));
                    $clear_url = wp_nonce_url(
                        admin_url('admin-post.php?action=clear_idloom_cache&event=' . rawurlencode($shortcode)),
                        'clear_idloom_cache_nonce'
                    );
                    $delete_url = wp_nonce_url(
                        admin_url('admin-post.php?action=idloom_delete_event&event=' . rawurlencode($shortcode)),
                        'idloom_delete_event_' . $shortcode
                    );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($event['label']); ?></a></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url($edit_url); ?>">Edit</a> | </span>
                                <span><a href="<?php echo esc_url($clear_url); ?>">Clear cache</a> | </span>
                                <span class="trash">
                                    <a href="<?php echo esc_url($delete_url); ?>" class="idloom-confirm" data-confirm="<?php echo esc_attr("Delete [{$shortcode}]? Pages using this shortcode will show nothing."); ?>">Delete</a>
                                </span>
                            </div>
                        </td>
                        <td>
                            <input type="text" readonly class="idloom-copy" value="<?php echo esc_attr('[' . $shortcode . ']'); ?>" size="<?php echo esc_attr(strlen($shortcode) + 2); ?>">
                        </td>
                        <td><code><?php echo esc_html($event['event_uid']); ?></code></td>
                        <td><?php echo !empty($event['enabled']) ? 'Enabled' : '<em>Disabled</em>'; ?></td>
                        <td>
                            <?php if (is_array($cache_info['primary_cache'])): ?>
                                <?php echo esc_html(count($cache_info['primary_cache'])); ?> shown<br>
                                <small>until <?php echo esc_html($format_expiry($cache_info['primary_expiry'])); ?></small>
                            <?php else: ?>
                                <span style="color:#b32d2e">Empty</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($cache_info['backup_cache'])): ?>
                                <?php echo esc_html(count($cache_info['backup_cache'])); ?> shown<br>
                                <small>until <?php echo esc_html($format_expiry($cache_info['backup_expiry'])); ?></small>
                            <?php else: ?>
                                <span style="color:#b32d2e">Empty</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=clear_idloom_cache&event=all'), 'clear_idloom_cache_nonce')); ?>" class="button">Clear all caches</a>
        </p>
    <?php endif; ?>
</div>

<?php
class IdloomAdmin {
    const LIST_PAGE = 'idloom-settings';
    const EDIT_PAGE = 'idloom-event';

    private $api_handler;

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_post_idloom_save_event', array($this, 'save_event'));
        add_action('admin_post_idloom_delete_event', array($this, 'delete_event'));
        add_action('admin_post_clear_idloom_cache', array($this, 'clear_cache'));
        add_action('wp_ajax_idloom_load_fields', array($this, 'ajax_load_fields'));
        add_action('wp_ajax_idloom_test_event', array($this, 'ajax_test_event'));
    }

    public function register_settings() {
        register_setting('idloom-settings-group', 'idloom_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Idloom Events',
            'Idloom Events',
            'manage_options',
            self::LIST_PAGE,
            array($this, 'events_page')
        );

        add_submenu_page(
            self::LIST_PAGE,
            'Idloom Events',
            'All Events',
            'manage_options',
            self::LIST_PAGE,
            array($this, 'events_page')
        );

        add_submenu_page(
            self::LIST_PAGE,
            'Edit Event',
            'Add Event',
            'manage_options',
            self::EDIT_PAGE,
            array($this, 'edit_page')
        );
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, self::EDIT_PAGE) === false && strpos($hook, self::LIST_PAGE) === false) {
            return;
        }

        wp_enqueue_script(
            'idloom-admin-script',
            plugins_url('../assets/js/admin.js', __FILE__),
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . '../assets/js/admin.js'),
            true
        );

        wp_localize_script('idloom-admin-script', 'IdloomAdminData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('idloom_admin'),
        ));
    }

    public function events_page() {
        require plugin_dir_path(__FILE__) . '../templates/admin-page.php';
    }

    public function edit_page() {
        require plugin_dir_path(__FILE__) . '../templates/event-edit.php';
    }

    private function list_url($args = array()) {
        return add_query_arg(array_merge(array('page' => self::LIST_PAGE), $args), admin_url('admin.php'));
    }

    private function edit_url($args = array()) {
        return add_query_arg(array_merge(array('page' => self::EDIT_PAGE), $args), admin_url('admin.php'));
    }

    private function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    }

    public function save_event() {
        $this->require_admin();
        check_admin_referer('idloom_save_event');

        $input = wp_unslash($_POST['event'] ?? array());
        $original = sanitize_key(wp_unslash($_POST['original_shortcode'] ?? ''));
        $events = IdloomEvents::get_all();

        if (!isset($events[$original])) {
            $original = '';
        }

        list($event, $errors) = IdloomEvents::from_input(is_array($input) ? $input : array(), $original);

        if (!empty($errors)) {
            // Keep what was typed so the form can be shown again with the errors.
            set_transient('idloom_event_form_' . get_current_user_id(), array(
                'event' => $event,
                'original' => $original,
                'errors' => $errors,
            ), 5 * MINUTE_IN_SECONDS);

            wp_redirect($this->edit_url(array_filter(array('event' => $original, 'error' => '1'))));
            exit;
        }

        if ($original !== '') {
            $this->api_handler->clear_cache($events[$original]);
        }

        // Replace in place so a renamed event keeps its position in the list.
        $saved = array();
        foreach ($events as $shortcode => $existing) {
            if ($shortcode === $original) {
                $saved[$event['shortcode']] = $event;
            } else {
                $saved[$shortcode] = $existing;
            }
        }
        $saved[$event['shortcode']] = $event; // New events go at the end.

        IdloomEvents::save_all($saved);
        $this->api_handler->clear_cache($event);

        wp_redirect($this->list_url(array('saved' => $event['shortcode'])));
        exit;
    }

    public function delete_event() {
        $this->require_admin();

        $shortcode = sanitize_key(wp_unslash($_GET['event'] ?? ''));
        check_admin_referer('idloom_delete_event_' . $shortcode);

        $events = IdloomEvents::get_all();
        if (isset($events[$shortcode])) {
            $this->api_handler->clear_cache($events[$shortcode]);
            unset($events[$shortcode]);
            IdloomEvents::save_all($events);
        }

        wp_redirect($this->list_url(array('deleted' => $shortcode)));
        exit;
    }

    public function clear_cache() {
        $this->require_admin();
        check_admin_referer('clear_idloom_cache_nonce');

        $shortcode = sanitize_key(wp_unslash($_REQUEST['event'] ?? ''));
        $events = IdloomEvents::get_all();

        foreach ($events as $key => $event) {
            if ($shortcode === 'all' || $shortcode === $key) {
                $this->api_handler->clear_cache($event);
            }
        }

        wp_redirect($this->list_url(array('cleared' => $shortcode)));
        exit;
    }

    /**
     * List the fields an event's registrations contain, with a sample value
     * for each, so the admin can map rules and columns without guessing.
     */
    public function ajax_load_fields() {
        check_ajax_referer('idloom_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $event_uid = sanitize_text_field(wp_unslash($_POST['event_uid'] ?? ''));
        if ($event_uid === '') {
            wp_send_json_error('Enter the Idloom event ID first.');
        }

        $sample = $this->api_handler->fetch_sample($event_uid);
        if ($sample === false) {
            wp_send_json_error('Could not load registrations. Check the API key and event ID.');
        }
        if (empty($sample)) {
            wp_send_json_error('This event has no registrations yet, so there are no fields to show.');
        }

        // First non-empty value seen for each field.
        $fields = array();
        foreach ($sample as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            foreach ($attendee as $field => $value) {
                if (!isset($fields[$field]) || $fields[$field] === '') {
                    $fields[$field] = mb_substr(IdloomDisplay::format_value($value), 0, 80);
                }
            }
        }

        uksort($fields, 'strnatcmp');

        $result = array();
        foreach ($fields as $field => $value) {
            $result[] = array('field' => (string) $field, 'sample' => $value);
        }

        wp_send_json_success(array(
            'fields' => $result,
            'checked' => count($sample),
        ));
    }

    /**
     * Fetch a saved event fresh from Idloom and report how many registrations
     * pass its rules, and why the others were left out. Refreshes the cache.
     */
    public function ajax_test_event() {
        check_ajax_referer('idloom_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $event = IdloomEvents::get(sanitize_key(wp_unslash($_POST['event'] ?? '')));
        if (!$event) {
            wp_send_json_error('Save the event before testing it.');
        }

        $all_attendees = $this->api_handler->fetch_all_raw($event['event_uid']);
        if ($all_attendees === false) {
            wp_send_json_error('Could not load registrations. Check the API key and event ID.');
        }

        $counts = array(
            'total' => count($all_attendees),
            'shown' => 0,
            'not_completed' => 0,
            'not_paid' => 0,
            'not_opted_in' => 0,
            'invalid' => 0,
        );
        $shown = array();

        foreach ($all_attendees as $attendee) {
            $result = $this->api_handler->check_rules($attendee, $event['rules']);
            if ($result === true) {
                $shown[] = $attendee;
                $counts['shown']++;
            } else {
                $counts[$result]++;
            }
        }

        $this->api_handler->update_caches($event, $shown);

        wp_send_json_success($counts);
    }
}

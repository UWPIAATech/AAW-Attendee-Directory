<?php
/**
 * Stores and validates the per-event configuration.
 *
 * Events are kept in a single option, keyed by shortcode name:
 *
 *   'tucson27' => array(
 *       'shortcode'    => 'tucson27',
 *       'label'        => 'Tucson 2027',
 *       'event_uid'    => '...',
 *       'enabled'      => true,
 *       'rules'        => array(
 *           'require_completed' => true,
 *           'payment'           => 'none' | 'paid' | 'paid_or_exception',
 *           'exception_field'   => 'free_field1',
 *           'optin_field'       => 'free_field56', // '' = no opt-in required
 *       ),
 *       'columns'      => array(
 *           array('label' => 'First Name', 'field' => 'firstname', 'sortable' => true, 'searchable' => true),
 *       ),
 *       'default_sort' => 'lastname',
 *   )
 */
class IdloomEvents {
    const OPTION = 'idloom_events';

    const PAYMENT_MODES = array('none', 'paid', 'paid_or_exception');

    public static function get_all() {
        $events = get_option(self::OPTION, array());
        return is_array($events) ? $events : array();
    }

    public static function get($shortcode) {
        $events = self::get_all();
        return isset($events[$shortcode]) ? $events[$shortcode] : null;
    }

    public static function save_all($events) {
        update_option(self::OPTION, $events);
    }

    /**
     * Settings for a brand new event.
     */
    public static function blank() {
        return array(
            'shortcode' => '',
            'label' => '',
            'event_uid' => '',
            'enabled' => true,
            'rules' => array(
                'require_completed' => true,
                'payment' => 'none',
                'exception_field' => '',
                'optin_field' => '',
            ),
            'columns' => array(
                array('label' => 'First Name', 'field' => 'firstname', 'sortable' => true, 'searchable' => true),
                array('label' => 'Last Name', 'field' => 'lastname', 'sortable' => true, 'searchable' => true),
            ),
            'default_sort' => 'lastname',
        );
    }

    /**
     * One-time move from the single-event settings (idloom_event_id) to the
     * events list. The old event keeps the [display_attendees] shortcode and
     * the exact rules and columns that were hardcoded before, so existing
     * pages are unchanged.
     */
    public static function maybe_migrate() {
        if (get_option(self::OPTION, false) !== false) {
            return;
        }

        $events = array();
        $old_event_uid = get_option('idloom_event_id');

        if (!empty($old_event_uid)) {
            $events['display_attendees'] = array(
                'shortcode' => 'display_attendees',
                'label' => 'Original event',
                'event_uid' => $old_event_uid,
                'enabled' => true,
                'rules' => array(
                    'require_completed' => true,
                    'payment' => 'paid_or_exception',
                    'exception_field' => 'free_field1',
                    'optin_field' => 'free_field56',
                ),
                'columns' => array(
                    array('label' => 'First Name', 'field' => 'firstname', 'sortable' => true, 'searchable' => true),
                    array('label' => 'Last Name', 'field' => 'lastname', 'sortable' => true, 'searchable' => true),
                    array('label' => 'Primary Cast', 'field' => 'cpy_name', 'sortable' => true, 'searchable' => true),
                    array('label' => 'Other Casts', 'field' => 'free_field40', 'sortable' => false, 'searchable' => true),
                    array('label' => 'Country', 'field' => 'cpy_country', 'sortable' => true, 'searchable' => true),
                ),
                'default_sort' => 'lastname',
            );
        }

        add_option(self::OPTION, $events);
    }

    /**
     * Build an event from submitted form data and validate it.
     *
     * @param array  $input              Raw (unslashed) form data.
     * @param string $original_shortcode Shortcode the event had before editing, '' for new events.
     * @return array array($event, $errors)
     */
    public static function from_input($input, $original_shortcode = '') {
        $errors = array();
        $events = self::get_all();

        $shortcode = strtolower(trim((string) ($input['shortcode'] ?? '')));
        $field_name = function($value) {
            return preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        };

        $payment = (string) ($input['rules']['payment'] ?? 'none');
        if (!in_array($payment, self::PAYMENT_MODES, true)) {
            $payment = 'none';
        }

        $event = array(
            'shortcode' => $shortcode,
            'label' => sanitize_text_field($input['label'] ?? ''),
            'event_uid' => sanitize_text_field($input['event_uid'] ?? ''),
            'enabled' => !empty($input['enabled']),
            'rules' => array(
                'require_completed' => !empty($input['rules']['require_completed']),
                'payment' => $payment,
                'exception_field' => $field_name($input['rules']['exception_field'] ?? ''),
                'optin_field' => $field_name($input['rules']['optin_field'] ?? ''),
            ),
            'columns' => array(),
            'default_sort' => $field_name($input['default_sort'] ?? ''),
        );

        foreach ((array) ($input['columns'] ?? array()) as $column) {
            $field = $field_name($column['field'] ?? '');
            if ($field === '') {
                continue;
            }

            $label = sanitize_text_field($column['label'] ?? '');

            $event['columns'][] = array(
                'label' => $label !== '' ? $label : $field,
                'field' => $field,
                'sortable' => !empty($column['sortable']),
                'searchable' => !empty($column['searchable']),
            );
        }

        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $shortcode)) {
            $errors[] = 'Shortcode must start with a letter and use only lowercase letters, numbers and underscores (2–40 characters).';
        } elseif ($shortcode === 'all') {
            $errors[] = '"all" is reserved. Choose another shortcode.';
        } elseif ($shortcode !== $original_shortcode && isset($events[$shortcode])) {
            $errors[] = "Another event already uses the shortcode [{$shortcode}].";
        } elseif (!isset($events[$shortcode]) && shortcode_exists($shortcode)) {
            $errors[] = "[{$shortcode}] is already used by another plugin or theme on this site.";
        }

        if ($event['label'] === '') {
            $event['label'] = $shortcode;
        }

        if ($event['event_uid'] === '') {
            $errors[] = 'Idloom event ID is required.';
        }

        if ($payment === 'paid_or_exception' && $event['rules']['exception_field'] === '') {
            $errors[] = 'Choose the exception field for "Paid or exception field filled in".';
        }

        if (empty($event['columns'])) {
            $errors[] = 'Add at least one column.';
        }

        $sortable = array_column(array_filter($event['columns'], function($c) {
            return $c['sortable'];
        }), 'field');

        if ($event['default_sort'] !== '' && !in_array($event['default_sort'], $sortable, true)) {
            $errors[] = 'Default sort must be one of the sortable columns.';
        }

        return array($event, $errors);
    }
}

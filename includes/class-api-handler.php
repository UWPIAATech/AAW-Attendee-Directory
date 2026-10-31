<?php
class IdloomAPIHandler {
    private $api_base_url = 'https://idloom.events/api/v4';
    private $page_size = 200;
    private $cache_expiration = 300;
    private $backup_expiration = HOUR_IN_SECONDS;
    private $debug;

    public function __construct($debug = true) {
        $this->debug = $debug;
    }

    private function log_message($message) {
        if ($this->debug && WP_DEBUG) {
            error_log('Idloom: ' . $message);
        }
    }

    /**
     * Cache keys are per event, so two events never share a cached list.
     */
    public function cache_key($event) {
        return 'idloom_attendees_' . md5($event['shortcode'] . '|' . $event['event_uid']);
    }

    /**
     * GET an API path and return the decoded JSON, or false on failure.
     */
    private function request($path, $query) {
        $api_key = get_option('idloom_api_key');

        $url = $this->api_base_url . $path . '?' . http_build_query($query);

        $response = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $api_key),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $this->log_message("API request error: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['data'])) {
            $this->log_message("Invalid data received for {$path}");
            return false;
        }

        return $data;
    }

    private function fetch_page($event_uid, $page = 1) {
        $this->log_message("Making API request for event {$event_uid} page {$page}");

        return $this->request('/attendees', [
            'event_uid' => $event_uid,
            'page' => $page,
            'ignore_fields_mapping' => 1,
            'page_size' => $this->page_size
        ]);
    }

    /**
     * Fetch every registration for an event, unfiltered.
     *
     * @return array|false False when the first request fails.
     */
    public function fetch_all_raw($event_uid) {
        $response = $this->fetch_page($event_uid, 1);
        if (!$response) {
            return false;
        }

        $all_attendees = $response['data'];

        if (isset($response['meta']) && isset($response['meta']['total'])) {
            $total_records = $response['meta']['total'];
        } else {
            $total_records = count($response['data']); // Fallback
        }

        $total_pages = ceil($total_records / $this->page_size);
        $this->log_message("Found total of {$total_records} records across {$total_pages} pages");

        for ($page = 2; $page <= $total_pages; $page++) {
            $page_data = $this->fetch_page($event_uid, $page);
            if ($page_data && isset($page_data['data'])) {
                $all_attendees = array_merge($all_attendees, $page_data['data']);
            }
        }

        $this->log_message("Retrieved total of " . count($all_attendees) . " records");

        return $all_attendees;
    }

    /**
     * Attendees for an event that pass its rules, cached.
     */
    public function fetch_attendees($event) {
        $cache_key = $this->cache_key($event);

        try {
            $cached_data = get_transient($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }

            $all_attendees = $this->fetch_all_raw($event['event_uid']);
            if ($all_attendees === false) {
                return $this->get_backup_cache($event);
            }

            $filtered_attendees = $this->filter_attendees($all_attendees, $event['rules']);
            $this->update_caches($event, $filtered_attendees);

            return $filtered_attendees;

        } catch (Exception $e) {
            $this->log_message("Error: " . $e->getMessage());
            return $this->get_backup_cache($event);
        }
    }

    /**
     * The fields on an event's registration form, with their labels, in form
     * order: array('free_field40' => 'Other Casts', ...). Fields with
     * subfields (such as an address) are listed by subfield.
     * Cached for an hour; pass $refresh to reload.
     *
     * @return array|false
     */
    public function fetch_fields($event_uid, $refresh = false) {
        $cache_key = 'idloom_fields_' . md5($event_uid);

        if (!$refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = $this->request('/events/fields', ['event_uid' => $event_uid]);
        if (!$response || !is_array($response['data'])) {
            return false;
        }

        $clean = function($label, $name) {
            $label = trim(html_entity_decode(wp_strip_all_tags((string) $label), ENT_QUOTES));
            return $label !== '' ? $label : $name;
        };

        $fields = [];
        foreach ($response['data'] as $field) {
            if (empty($field['name'])) {
                continue;
            }

            $label = $clean($field['label'] ?? '', $field['name']);

            if (!empty($field['fields']) && is_array($field['fields'])) {
                foreach ($field['fields'] as $subfield) {
                    if (!empty($subfield['name'])) {
                        $fields[$subfield['name']] = $label . ': ' . $clean($subfield['label'] ?? '', $subfield['name']);
                    }
                }
            } else {
                $fields[$field['name']] = $label;
            }
        }

        set_transient($cache_key, $fields, HOUR_IN_SECONDS);

        return $fields;
    }

    /**
     * Check one registration against an event's rules.
     *
     * @return true|string True if it passes, otherwise the name of the first rule it fails.
     */
    public function check_rules($attendee, $rules) {
        if (!is_array($attendee)) {
            return 'invalid';
        }

        // Must be fully registered
        if (!empty($rules['require_completed'])
            && ($attendee['registration_status'] ?? null) !== 'Form Completed') {
            return 'not_completed';
        }

        // Must be paid, or (optionally) have an exception such as a coupon
        $payment = $rules['payment'] ?? 'none';
        if ($payment !== 'none') {
            $isPaid = ($attendee['payment_status'] ?? null) === 'Paid';
            $hasException = $payment === 'paid_or_exception'
                && !empty($rules['exception_field'])
                && !empty($attendee[$rules['exception_field']]);

            if (!$isPaid && !$hasException) {
                return 'not_paid';
            }
        }

        // Must have opted in to the list — accept multiple truthy representations
        // because Idloom may return this as bool true, "true", 1, "1", or "yes".
        if (!empty($rules['optin_field'])) {
            $optIn = $attendee[$rules['optin_field']] ?? null;
            $hasOptedIn = ($optIn === true
                || $optIn === 1
                || (is_string($optIn) && in_array(strtolower($optIn), ['true', '1', 'yes'], true)));

            if (!$hasOptedIn) {
                return 'not_opted_in';
            }
        }

        return true;
    }

    public function filter_attendees($attendees, $rules) {
        if (!is_array($attendees)) {
            return [];
        }

        $filtered = array_filter($attendees, function($attendee) use ($rules) {
            return $this->check_rules($attendee, $rules) === true;
        });

        $this->log_message("Filtered " . count($attendees) . " attendees down to " . count($filtered));
        return array_values($filtered);
    }

    private function get_backup_cache($event) {
        return get_transient($this->cache_key($event) . '_backup') ?: [];
    }

    public function update_caches($event, $data) {
        $cache_key = $this->cache_key($event);
        set_transient($cache_key, $data, $this->cache_expiration);

        // Don't let an empty result replace a good backup.
        if (!empty($data)) {
            set_transient($cache_key . '_backup', $data, $this->backup_expiration);
        }
    }

    public function get_cache_info($event) {
        $cache_key = $this->cache_key($event);

        return array(
            'primary_cache' => get_transient($cache_key),
            'backup_cache' => get_transient($cache_key . '_backup'),
            'primary_expiry' => get_option('_transient_timeout_' . $cache_key),
            'backup_expiry' => get_option('_transient_timeout_' . $cache_key . '_backup'),
            'cache_key' => $cache_key
        );
    }

    public function clear_cache($event) {
        $cache_key = $this->cache_key($event);
        delete_transient($cache_key);
        delete_transient($cache_key . '_backup');
    }
}

<?php
/**
 * Class responsible for displaying the Idloom attendee list via shortcode.
 */
class IdloomDisplay {
    private $api_handler;
    private $attendees_per_page = 20;

    /**
     * Constructor.
     *
     * @param object $api_handler Instance of the API handler class.
     */
    public function __construct($api_handler) {
        $this->api_handler = $api_handler;

        // Every configured event gets its own shortcode, e.g. [tucson27].
        // Disabled events are registered too so the raw tag never shows on a page.
        foreach (IdloomEvents::get_all() as $shortcode => $event) {
            if (!shortcode_exists($shortcode)) {
                add_shortcode($shortcode, array($this, 'display_attendees_shortcode'));
            }
        }

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Handles the output of every event shortcode.
     *
     * @param array|string $atts    Shortcode attributes (unused).
     * @param string|null  $content Enclosed content (unused).
     * @param string       $tag     The shortcode name, which identifies the event.
     * @return string HTML output for the attendee list.
     */
    public function display_attendees_shortcode($atts = array(), $content = null, $tag = '') {
        $event = IdloomEvents::get($tag);

        if (!$event || empty($event['enabled'])) {
            return '';
        }

        $columns = $event['columns'];

        $attendees = $this->api_handler->fetch_attendees($event);

        if (!$attendees || !is_array($attendees)) {
            return '<p>No attendees found.</p>';
        }

        /*
         * Search ONLY the visible columns marked searchable.
         */
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        $search_fields = array_column(array_filter($columns, function($column) {
            return !empty($column['searchable']);
        }), 'field');

        if ($search !== '' && !empty($search_fields)) {
            $attendees = array_filter($attendees, function($attendee) use ($search, $search_fields) {
                if (!is_array($attendee)) {
                    return false;
                }

                $searchable = '';

                foreach ($search_fields as $field) {
                    if (!array_key_exists($field, $attendee)) {
                        continue;
                    }

                    $value = $attendee[$field];

                    if (is_array($value)) {
                        $flat_values = array();

                        array_walk_recursive($value, function($item) use (&$flat_values) {
                            if (is_scalar($item)) {
                                $flat_values[] = (string) $item;
                            }
                        });

                        $searchable .= ' ' . implode(' ', $flat_values);
                    } elseif (is_scalar($value)) {
                        $searchable .= ' ' . (string) $value;
                    }
                }

                return stripos($searchable, $search) !== false;
            });

            $attendees = array_values($attendees);
        }

        /*
         * Sort ONLY visible columns marked sortable.
         */
        $allowed_sort_columns = array_column(array_filter($columns, function($column) {
            return !empty($column['sortable']);
        }), 'field');

        $default_sort = in_array($event['default_sort'] ?? '', $allowed_sort_columns, true)
            ? $event['default_sort']
            : ($allowed_sort_columns[0] ?? '');

        $sort_column = isset($_GET['sort']) ? sanitize_text_field(wp_unslash($_GET['sort'])) : $default_sort;

        if (!in_array($sort_column, $allowed_sort_columns, true)) {
            $sort_column = $default_sort;
        }

        $sort_direction = isset($_GET['order']) ? strtolower(sanitize_text_field(wp_unslash($_GET['order']))) : 'asc';

        if (!in_array($sort_direction, array('asc', 'desc'), true)) {
            $sort_direction = 'asc';
        }

        usort($attendees, function($a, $b) use ($sort_column, $sort_direction) {
            $a_val = strtolower(trim(self::format_value($a[$sort_column] ?? '')));
            $b_val = strtolower(trim(self::format_value($b[$sort_column] ?? '')));

            if ($a_val === $b_val) {
                $a_lastname = strtolower(trim((string) ($a['lastname'] ?? '')));
                $b_lastname = strtolower(trim((string) ($b['lastname'] ?? '')));

                $last_cmp = strcmp($a_lastname, $b_lastname);

                if ($last_cmp !== 0) {
                    return $sort_direction === 'asc' ? $last_cmp : -$last_cmp;
                }

                $a_firstname = strtolower(trim((string) ($a['firstname'] ?? '')));
                $b_firstname = strtolower(trim((string) ($b['firstname'] ?? '')));

                $first_cmp = strcmp($a_firstname, $b_firstname);

                return $sort_direction === 'asc' ? $first_cmp : -$first_cmp;
            }

            $cmp = strcmp($a_val, $b_val);

            return $sort_direction === 'asc' ? $cmp : -$cmp;
        });

        /*
         * Pagination after searching and sorting.
         */
        $total_items = count($attendees);

        if ($total_items === 0 && $search !== '') {
            return '<div class="attendee-list">' .
                '<input type="text" id="attendee-search" class="attendee-search" placeholder="Search attendees (minimum 3 characters)..." value="' . esc_attr($search) . '">' .
                '<p>No attendees found matching your search.</p>' .
                '</div>';
        }

        if ($total_items === 0) {
            return '<p>No attendees found.</p>';
        }

        $current_page = isset($_GET['aidloom_page']) ? max(1, (int) $_GET['aidloom_page']) : 1;
        $total_pages = (int) ceil($total_items / $this->attendees_per_page);
        $current_page = min($current_page, $total_pages);
        $offset = ($current_page - 1) * $this->attendees_per_page;

        $paged_attendees = array_slice($attendees, $offset, $this->attendees_per_page);

        $sort_info = array(
            'column' => $sort_column,
            'direction' => $sort_direction,
        );

        $search_term = $search;

        ob_start();

        $template_path = plugin_dir_path(__FILE__) . '../templates/attendee-list.php';

        if (file_exists($template_path)) {
            require $template_path;
        } else {
            echo '<p>Error: Attendee list template file not found.</p>';
        }

        return ob_get_clean();
    }

    /**
     * Turn a field value into display text. Multi-value fields (such as
     * checkbox lists) are joined with commas.
     *
     * @param mixed $value Raw attendee field value.
     * @return string
     */
    public static function format_value($value) {
        if (is_array($value)) {
            $flat_values = array();

            array_walk_recursive($value, function($item) use (&$flat_values) {
                if (is_scalar($item) && (string) $item !== '') {
                    $flat_values[] = (string) $item;
                }
            });

            return implode(', ', $flat_values);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Enqueues the necessary CSS and JavaScript files for the attendee list display.
     */
    public function enqueue_scripts() {
        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'idloom-attendees-style',
            plugins_url('../assets/css/style.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . '../assets/css/style.css')
        );

        wp_enqueue_script(
            'idloom-attendees-script',
            plugins_url('../assets/js/script.js', __FILE__),
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . '../assets/js/script.js'),
            true
        );
    }
}

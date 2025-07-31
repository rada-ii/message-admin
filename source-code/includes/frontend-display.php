<?php

/**
 * Message Admin Frontend Display Class
 * Handles message display on the frontend with proper targeting and caching
 */

if (!defined('ABSPATH')) {
    exit;
}

class MessageAdminFrontend
{
    private static $message_cache = array();
    private static $displayed_messages = array();
    private static $is_processing = false;

    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
        add_filter('the_content', array($this, 'add_messages_to_content'));
        add_action('wp_head', array($this, 'add_messages_to_head'));
        add_action('wp_footer', array($this, 'add_messages_to_footer'));
    }

    /**
     * Enqueue frontend styles and scripts
     */
    public function enqueue_frontend_styles()
    {
        wp_enqueue_style(
            'message-admin-frontend',
            MESSAGE_ADMIN_PLUGIN_URL . 'assets/frontend-style.css',
            array(),
            MESSAGE_ADMIN_VERSION
        );

        wp_enqueue_script(
            'message-admin-frontend-js',
            MESSAGE_ADMIN_PLUGIN_URL . 'assets/frontend-script.js',
            array('jquery'),
            MESSAGE_ADMIN_VERSION,
            true
        );

        wp_localize_script('message-admin-frontend-js', 'messageAdminFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('message_admin_nonce')
        ));
    }

    /**
     * Add messages to post/page content
     */
    public function add_messages_to_content($content)
    {
        if (!is_singular() || self::$is_processing) {
            return $content;
        }

        self::$is_processing = true;

        $before_messages = $this->get_messages_by_position('before_content');
        $after_messages = $this->get_messages_by_position('after_content');

        $before_content = '';
        foreach ($before_messages as $message) {
            if ($this->should_display_message($message) && !$this->is_already_displayed($message->id)) {
                $before_content .= $this->render_message($message, 'before_content');
                $this->mark_as_displayed($message->id);
            }
        }

        $after_content = '';
        foreach ($after_messages as $message) {
            if ($this->should_display_message($message) && !$this->is_already_displayed($message->id)) {
                $after_content .= $this->render_message($message, 'after_content');
                $this->mark_as_displayed($message->id);
            }
        }

        self::$is_processing = false;
        return $before_content . $content . $after_content;
    }

    /**
     * Add messages to header
     */
    public function add_messages_to_head()
    {
        if (self::$is_processing) return;

        self::$is_processing = true;
        $messages = $this->get_messages_by_position('header');
        self::$is_processing = false;

        $this->render_position_messages($messages, 'header', 'message-admin-header-container');
    }

    /**
     * Add messages to footer
     */
    public function add_messages_to_footer()
    {
        if (self::$is_processing) return;

        self::$is_processing = true;
        $messages = $this->get_messages_by_position('footer');
        self::$is_processing = false;

        $this->render_position_messages($messages, 'footer', 'message-admin-footer-container');
    }

    /**
     * Render messages for specific positions
     */
    private function render_position_messages($messages, $position, $container_class)
    {
        $valid_messages = array();
        foreach ($messages as $message) {
            if ($this->should_display_message($message) && !$this->is_already_displayed($message->id)) {
                $valid_messages[] = $message;
                $this->mark_as_displayed($message->id);
            }
        }

        if (!empty($valid_messages)) {
            echo '<div class="' . $container_class . '">';
            foreach ($valid_messages as $message) {
                echo $this->render_message($message, $position);
            }
            echo '</div>';
        }
    }

    /**
     * Check if message is already displayed
     */
    private function is_already_displayed($message_id)
    {
        return in_array($message_id, self::$displayed_messages);
    }

    /**
     * Mark message as displayed
     */
    private function mark_as_displayed($message_id)
    {
        if (!in_array($message_id, self::$displayed_messages)) {
            self::$displayed_messages[] = $message_id;
        }
    }

    /**
     * Render individual message HTML
     */
    private function render_message($message, $position)
    {
        $classes = array(
            'message-admin-display',
            'message-admin-position-' . $position,
            'message-admin-id-' . $message->id
        );

        if (!empty($message->message_type)) {
            $classes[] = 'message-type-' . $message->message_type;
        }

        // Check if dismissible field exists
        $is_dismissible = isset($message->dismissible) ? $message->dismissible : 0;
        if ($is_dismissible) {
            $classes[] = 'message-admin-dismissible';
        }

        $html = '<div class="' . implode(' ', $classes) . '" data-message-id="' . $message->id . '">';

        // Add dismiss button if message is dismissible
        if ($is_dismissible) {
            $html .= '<button class="message-admin-dismiss" aria-label="Close message" title="Close message">&times;</button>';
        }

        $html .= '<div class="message-admin-content">';
        $html .= wp_kses_post($message->content);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if message should be displayed
     */
    private function should_display_message($message)
    {
        if ($message->status !== 'active' || is_admin()) {
            return false;
        }

        // Check if message is dismissed
        $is_dismissible = isset($message->dismissible) ? $message->dismissible : 0;
        if ($is_dismissible && $this->is_message_dismissed($message->id)) {
            return false;
        }

        return $this->check_user_role_targeting($message) &&
            $this->check_page_targeting($message) &&
            $this->check_date_targeting($message);
    }

    /**
     * Check if message is dismissed
     */
    private function is_message_dismissed($message_id)
    {
        if (!isset($_COOKIE['dismissed_messages'])) {
            return false;
        }

        $dismissed_messages = json_decode(stripslashes($_COOKIE['dismissed_messages']), true);

        if (!is_array($dismissed_messages)) {
            return false;
        }

        return in_array($message_id, $dismissed_messages);
    }

    /**
     * Check user role targeting
     */
    private function check_user_role_targeting($message)
    {
        if (empty($message->user_roles)) {
            return true;
        }

        $target_roles = json_decode($message->user_roles, true);
        if (!is_array($target_roles) || in_array('all', $target_roles)) {
            return true;
        }

        if (!is_user_logged_in()) {
            return in_array('guest', $target_roles);
        }

        $current_user = wp_get_current_user();
        $user_roles = $current_user->roles;

        foreach ($user_roles as $role) {
            if (in_array($role, $target_roles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check page targeting
     */
    private function check_page_targeting($message)
    {
        if (empty($message->display_pages)) {
            return true;
        }

        $target_pages = json_decode($message->display_pages, true);
        if (!is_array($target_pages) || in_array('all', $target_pages)) {
            return true;
        }

        if (is_front_page() && in_array('home', $target_pages)) {
            return true;
        }

        if (is_home() && in_array('blog', $target_pages)) {
            return true;
        }

        if (is_page()) {
            $page_id = get_the_ID();
            if (in_array('page_' . $page_id, $target_pages)) {
                return true;
            }
        }

        if (is_single()) {
            $post_id = get_the_ID();
            if (in_array('post_' . $post_id, $target_pages)) {
                return true;
            }
        }

        if (is_category()) {
            $cat_id = get_queried_object_id();
            if (in_array('category_' . $cat_id, $target_pages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check date targeting
     */
    private function check_date_targeting($message)
    {
        $now = current_time('timestamp');

        if (!empty($message->start_date)) {
            $start_time = strtotime($message->start_date);
            if ($now < $start_time) {
                return false;
            }
        }

        if (!empty($message->end_date)) {
            $end_time = strtotime($message->end_date);
            if ($now > $end_time) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get messages by position with caching
     */
    private function get_messages_by_position($position)
    {
        $cache_key = 'messages_' . $position;

        if (isset(self::$message_cache[$cache_key])) {
            return self::$message_cache[$cache_key];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE position = %s AND status = %s ORDER BY id ASC",
            $position,
            'active'
        ));

        self::$message_cache[$cache_key] = $messages ? $messages : array();
        return self::$message_cache[$cache_key];
    }

    /**
     * Get single message by ID (for shortcodes)
     */
    public static function get_message_by_id($message_id, $force_display = false)
    {
        $cache_key = 'message_' . $message_id;

        if (isset(self::$message_cache[$cache_key]) && !$force_display) {
            return self::$message_cache[$cache_key];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND status = %s",
            $message_id,
            'active'
        ));

        if (!$message) {
            self::$message_cache[$cache_key] = '';
            return '';
        }

        $frontend = new self();

        if (!$frontend->should_display_message($message)) {
            self::$message_cache[$cache_key] = '';
            return '';
        }

        if (!$force_display && $frontend->is_already_displayed($message->id)) {
            self::$message_cache[$cache_key] = '';
            return '';
        }

        // Mark as displayed
        $frontend->mark_as_displayed($message->id);

        // For shortcode/widget - always display
        $output = $frontend->render_message($message, 'shortcode');
        self::$message_cache[$cache_key] = $output;
        return $output;
    }

    /**
     * Static method to get messages by position
     */
    public static function get_messages_by_position_static($position)
    {
        $frontend = new self();
        return $frontend->get_messages_by_position($position);
    }

    /**
     * AJAX handler for loading message
     */
    public function ajax_load_message()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'message_admin_nonce')) {
            wp_die('Security check failed');
        }

        $message_id = intval($_POST['message_id']);
        $message_html = self::get_message_by_id($message_id, true);

        if ($message_html) {
            wp_send_json_success($message_html);
        } else {
            wp_send_json_error('Message not found or not allowed to display');
        }
    }

    /**
     * Reset displayed messages (useful for debugging)
     */
    public static function reset_displayed_messages()
    {
        self::$displayed_messages = array();
        self::$message_cache = array();
    }

    /**
     * Get list of displayed messages (for debugging)
     */
    public static function get_displayed_messages()
    {
        return self::$displayed_messages;
    }

    /**
     * Debug message targeting (admin only)
     */
    public function debug_message_targeting($message_id)
    {
        if (!current_user_can('manage_options')) {
            return 'Access denied';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $message_id
        ));

        if (!$message) {
            return 'Message not found';
        }

        $dismissible = isset($message->dismissible) ? $message->dismissible : 0;

        $debug_info = array(
            'message_id' => $message->id,
            'title' => $message->title,
            'status' => $message->status,
            'position' => $message->position,
            'dismissible' => $dismissible,
            'is_dismissed' => $dismissible ? $this->is_message_dismissed($message->id) : false,
            'already_displayed' => $this->is_already_displayed($message->id),
            'displayed_messages_list' => self::$displayed_messages,
            'current_page' => array(
                'is_front_page' => is_front_page(),
                'is_home' => is_home(),
                'is_page' => is_page(),
                'is_single' => is_single(),
                'page_id' => get_the_ID(),
                'post_type' => get_post_type()
            ),
            'user_info' => array(
                'is_logged_in' => is_user_logged_in(),
                'user_roles' => is_user_logged_in() ? wp_get_current_user()->roles : array('guest')
            ),
            'targeting' => array(
                'display_pages' => json_decode($message->display_pages, true),
                'user_roles' => json_decode($message->user_roles, true),
                'start_date' => $message->start_date,
                'end_date' => $message->end_date
            ),
            'should_display' => $this->should_display_message($message),
            'targeting_checks' => array(
                'user_role_check' => $this->check_user_role_targeting($message),
                'page_check' => $this->check_page_targeting($message),
                'date_check' => $this->check_date_targeting($message)
            )
        );

        return '<div class="debug-info"><h3>🔍 Debug Information for Message #' . $message->id . '</h3><pre>' . print_r($debug_info, true) . '</pre></div>';
    }
}

// Initialize the frontend class
new MessageAdminFrontend();

<?php

/**
 * Message Admin Widget Class
 * Displays custom messages in sidebar, footer, or any widget area
 */

if (!defined('ABSPATH')) {
    exit;
}

class MessageAdminWidget extends WP_Widget
{
    /**
     * Widget constructor
     */
    public function __construct()
    {
        parent::__construct(
            'message_admin_widget',
            __('Message Admin Widget', 'message-admin'),
            array(
                'description' => __('Display custom messages in sidebar, footer, or any widget area.', 'message-admin'),
                'customize_selective_refresh' => true,
            )
        );
    }

    /**
     * Display widget on frontend
     */
    public function widget($args, $instance)
    {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $message_id = !empty($instance['message_id']) ? intval($instance['message_id']) : 0;
        $show_title = !empty($instance['show_title']) ? $instance['show_title'] : false;

        if (empty($message_id)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND status = %s",
            $message_id,
            'active'
        ));

        if (!$message || !$this->should_display_message($message)) {
            return;
        }

        echo $args['before_widget'];

        if ($show_title && !empty($title)) {
            echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        }

        $this->render_message($message);

        echo $args['after_widget'];
    }

    /**
     * Render message HTML
     */
    private function render_message($message)
    {
        $dismissible = isset($message->dismissible) ? $message->dismissible : 0;
        $classes = array(
            'message-admin-display',
            'message-admin-widget',
            'message-admin-id-' . $message->id
        );

        if (!empty($message->message_type)) {
            $classes[] = 'message-type-' . $message->message_type;
        }

        if ($dismissible) {
            $classes[] = 'message-admin-dismissible';
        }

        echo '<div class="message-admin-widget-content">';
        echo '<div class="' . implode(' ', $classes) . '" data-message-id="' . $message->id . '">';

        if ($dismissible) {
            echo '<button class="message-admin-dismiss" aria-label="Close message" title="Close message">&times;</button>';
        }

        echo '<div class="message-admin-content">';
        echo wp_kses_post($message->content);
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Check if message should be displayed
     */
    private function should_display_message($message)
    {
        if ($message->status !== 'active' || is_admin()) {
            return false;
        }

        // Check date targeting
        if (!$this->check_date_targeting($message)) {
            return false;
        }

        // Check dismissal
        $dismissible = isset($message->dismissible) ? $message->dismissible : 0;
        if ($dismissible && $this->is_message_dismissed($message->id)) {
            return false;
        }

        // Check user roles and pages
        return $this->check_user_role_targeting($message) && $this->check_page_targeting($message);
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
        return is_array($dismissed_messages) && in_array($message_id, $dismissed_messages);
    }

    /**
     * Check date targeting
     */
    private function check_date_targeting($message)
    {
        $now = current_time('timestamp');

        if (!empty($message->start_date) && $now < strtotime($message->start_date)) {
            return false;
        }

        if (!empty($message->end_date) && $now > strtotime($message->end_date)) {
            return false;
        }

        return true;
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
        return !empty(array_intersect($current_user->roles, $target_roles));
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

        // Check various page types
        if (is_front_page() && in_array('home', $target_pages)) {
            return true;
        }

        if (is_home() && in_array('blog', $target_pages)) {
            return true;
        }

        if (is_page() || is_single()) {
            $page_id = get_the_ID();
            return in_array('page_' . $page_id, $target_pages) || in_array('post_' . $page_id, $target_pages);
        }

        return false;
    }

    /**
     * Widget admin form
     */
    public function form($instance)
    {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $message_id = !empty($instance['message_id']) ? intval($instance['message_id']) : 0;
        $show_title = !empty($instance['show_title']) ? $instance['show_title'] : false;

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo '<p style="color: red;"><strong>Error:</strong> Message Admin table not found. Please deactivate and reactivate the plugin.</p>';
            return;
        }

        $messages = $wpdb->get_results("SELECT id, title FROM $table_name WHERE status = 'active' ORDER BY title ASC");

        if ($wpdb->last_error) {
            echo '<p style="color: red;"><strong>Database Error:</strong> ' . esc_html($wpdb->last_error) . '</p>';
            return;
        }
?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Widget Title (optional):', 'message-admin'); ?>
            </label>
            <input class="widefat"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                type="text"
                value="<?php echo esc_attr($title); ?>" />
        </p>

        <p>
            <input class="checkbox"
                type="checkbox"
                <?php checked($show_title); ?>
                id="<?php echo esc_attr($this->get_field_id('show_title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_title')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('show_title')); ?>">
                <?php _e('Show widget title', 'message-admin'); ?>
            </label>
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('message_id')); ?>">
                <?php _e('Select Message:', 'message-admin'); ?>
            </label>
            <select class="widefat"
                id="<?php echo esc_attr($this->get_field_id('message_id')); ?>"
                name="<?php echo esc_attr($this->get_field_name('message_id')); ?>">
                <option value="0"><?php _e('-- Select Message --', 'message-admin'); ?></option>
                <?php if (!empty($messages)): ?>
                    <?php foreach ($messages as $message): ?>
                        <option value="<?php echo esc_attr($message->id); ?>"
                            <?php selected($message_id, $message->id); ?>>
                            <?php echo esc_html($message->title); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </p>

        <p style="font-size: 11px; color: #666; font-style: italic;">
            <strong><?php _e('Note:', 'message-admin'); ?></strong>
            <?php _e('Widget will respect targeting rules (pages, user roles) defined for the selected message.', 'message-admin'); ?>
        </p>

        <?php if (empty($messages)): ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin: 10px 0;">
                <p style="margin: 0; color: #856404; font-weight: bold;">
                    ⚠️ <?php _e('No active messages found!', 'message-admin'); ?>
                </p>
                <p style="margin: 5px 0 0 0;">
                    <a href="<?php echo admin_url('admin.php?page=message-admin-add'); ?>" target="_blank">
                        <?php _e('Create new message', 'message-admin'); ?> →
                    </a>
                </p>
            </div>
        <?php else: ?>
            <p style="font-size: 11px; color: #28a745;">
                ✅ <?php printf(__('%d active messages available', 'message-admin'), count($messages)); ?>
            </p>
        <?php endif; ?>

<?php
    }

    /**
     * Update widget instance
     */
    public function update($new_instance, $old_instance)
    {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        $instance['message_id'] = (!empty($new_instance['message_id'])) ? intval($new_instance['message_id']) : 0;
        $instance['show_title'] = (!empty($new_instance['show_title'])) ? 1 : 0;

        return $instance;
    }
}

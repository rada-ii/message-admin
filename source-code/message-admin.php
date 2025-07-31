<?php
/*
Plugin Name: Message Admin Pro
Plugin URI: https://github.com/your-username/message-admin-pro
Description: Advanced solution for creating and displaying custom messages anywhere on the site. Includes targeting by pages, user roles, and time frames.
Version: 2.1.0
Author: Rada Ivanković
Author URI: https://portfolio-v2-topaz-pi.vercel.app/
License: GPL2
Text Domain: message-admin
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MESSAGE_ADMIN_VERSION', '2.1.0');
define('MESSAGE_ADMIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MESSAGE_ADMIN_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MESSAGE_ADMIN_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
class MessageAdmin
{
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('MessageAdmin', 'uninstall'));

        // Load classes immediately
        add_action('plugins_loaded', array($this, 'load_all_classes'), 5);

        // Initialize plugin
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'check_version'));

        // Register widget properly - FIXED: use correct path to includes folder
        add_action('widgets_init', array($this, 'register_widget'));
    }

    /**
     * Load all required class files
     */
    public function load_all_classes()
    {
        // FIXED: Load widget first with correct includes path
        $widget_file = MESSAGE_ADMIN_PLUGIN_PATH . 'includes/message-widget.php';
        if (file_exists($widget_file)) {
            require_once $widget_file;
            $this->debug_log("Loaded widget file");
        } else {
            $this->debug_log("Widget file not found: {$widget_file}", 'error');
        }

        // Load frontend display
        $frontend_file = MESSAGE_ADMIN_PLUGIN_PATH . 'includes/frontend-display.php';
        if (file_exists($frontend_file)) {
            require_once $frontend_file;
            $this->debug_log("Loaded frontend display file");
        } else {
            $this->debug_log("Frontend display file not found: {$frontend_file}", 'error');
        }

        // Load admin panel only in admin area
        if (is_admin()) {
            $admin_file = MESSAGE_ADMIN_PLUGIN_PATH . 'includes/admin-panel.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
                $this->debug_log("Loaded admin panel");
            } else {
                $this->debug_log("Admin panel file not found: {$admin_file}", 'error');
            }
        }
    }

    /**
     * Register widget properly
     */
    public function register_widget()
    {
        if (class_exists('MessageAdminWidget')) {
            register_widget('MessageAdminWidget');
            $this->debug_log("Widget registered successfully");
        } else {
            $this->debug_log("Widget class not found - loading widget file again", 'error');

            // Try to load widget file again if class doesn't exist
            $widget_file = MESSAGE_ADMIN_PLUGIN_PATH . 'includes/message-widget.php';
            if (file_exists($widget_file)) {
                require_once $widget_file;
                if (class_exists('MessageAdminWidget')) {
                    register_widget('MessageAdminWidget');
                    $this->debug_log("Widget loaded and registered on second attempt");
                } else {
                    $this->debug_log("Widget class still not found after loading file", 'error');
                }
            }
        }
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Load text domain for translations
        load_plugin_textdomain('message-admin', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Ensure database is up to date
        $this->ensure_database_updated();

        // Initialize admin and frontend
        if (is_admin()) {
            $this->init_admin();
        }
        $this->init_frontend();

        // Register shortcode and AJAX handlers
        add_shortcode('message_admin', array($this, 'shortcode_handler'));
        add_action('wp_ajax_dismiss_message_admin', array($this, 'ajax_dismiss_message'));
        add_action('wp_ajax_nopriv_dismiss_message_admin', array($this, 'ajax_dismiss_message'));
        add_action('wp_ajax_reset_message_ids', array($this, 'ajax_reset_message_ids'));
    }

    /**
     * Initialize admin functionality
     */
    private function init_admin()
    {
        if (class_exists('MessageAdminPanel')) {
            new MessageAdminPanel();
        } else {
            $this->debug_log("MessageAdminPanel class not found", 'error');
        }
    }

    /**
     * Initialize frontend functionality
     */
    private function init_frontend()
    {
        if (class_exists('MessageAdminFrontend')) {
            new MessageAdminFrontend();
        } else {
            $this->debug_log("MessageAdminFrontend class not found", 'error');
        }
    }

    /**
     * Handle shortcode display
     */
    public function shortcode_handler($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 1,
            'debug' => false
        ), $atts);

        $message_id = intval($atts['id']);
        $debug = filter_var($atts['debug'], FILTER_VALIDATE_BOOLEAN);

        // Debug mode for administrators
        if ($debug && current_user_can('manage_options')) {
            if (class_exists('MessageAdminFrontend')) {
                $frontend = new MessageAdminFrontend();
                return $frontend->debug_message_targeting($message_id);
            }
        }

        // Regular message display
        if (class_exists('MessageAdminFrontend')) {
            return MessageAdminFrontend::get_message_by_id($message_id);
        }

        return '';
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        $this->create_database_table();
        $this->upgrade_database();
        $this->insert_default_message();
        update_option('message_admin_version', MESSAGE_ADMIN_VERSION);
        $this->create_capabilities();
        flush_rewrite_rules();

        $this->debug_log("Plugin activated successfully");
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        flush_rewrite_rules();
        $this->debug_log("Plugin deactivated");
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall()
    {
        global $wpdb;

        // Remove database table
        $table_name = $wpdb->prefix . 'message_admin';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");

        // Remove options
        delete_option('message_admin_version');

        // Remove capabilities
        self::remove_capabilities();

        self::debug_log("Plugin uninstalled completely");
    }

    /**
     * Check and update plugin version
     */
    public function check_version()
    {
        $installed_version = get_option('message_admin_version', '0');

        if (version_compare($installed_version, MESSAGE_ADMIN_VERSION, '<')) {
            $this->upgrade_database();
            update_option('message_admin_version', MESSAGE_ADMIN_VERSION);
            $this->debug_log("Plugin upgraded from {$installed_version} to " . MESSAGE_ADMIN_VERSION);
        }
    }

    /**
     * Ensure database is updated
     */
    private function ensure_database_updated()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_database_table();
            return;
        }

        // Check if dismissible column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name");

        if (!in_array('dismissible', $columns)) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN dismissible TINYINT(1) DEFAULT 0");

            if ($result !== false) {
                $this->debug_log("Dismissible column added successfully");
            } else {
                $this->debug_log("Error adding dismissible column: " . $wpdb->last_error, 'error');
            }
        }
    }

    /**
     * Create database table
     */
    private function create_database_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'message_admin';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            content longtext NOT NULL,
            position varchar(50) DEFAULT 'manual',
            status varchar(20) DEFAULT 'active',
            display_pages longtext DEFAULT NULL,
            user_roles text DEFAULT NULL,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            message_type varchar(20) DEFAULT 'info',
            dismissible tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY position (position),
            KEY message_type (message_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $this->debug_log("Database table created/updated");
    }

    /**
     * Upgrade database structure
     */
    public function upgrade_database()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_database_table();
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name");

        // Add missing columns
        $new_columns = array(
            'display_pages' => "ALTER TABLE $table_name ADD COLUMN display_pages LONGTEXT DEFAULT NULL",
            'user_roles' => "ALTER TABLE $table_name ADD COLUMN user_roles TEXT DEFAULT NULL",
            'start_date' => "ALTER TABLE $table_name ADD COLUMN start_date DATETIME DEFAULT NULL",
            'end_date' => "ALTER TABLE $table_name ADD COLUMN end_date DATETIME DEFAULT NULL",
            'message_type' => "ALTER TABLE $table_name ADD COLUMN message_type VARCHAR(20) DEFAULT 'info'",
            'dismissible' => "ALTER TABLE $table_name ADD COLUMN dismissible TINYINT(1) DEFAULT 0",
            'updated_at' => "ALTER TABLE $table_name ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        );

        foreach ($new_columns as $column => $sql) {
            if (!in_array($column, $columns)) {
                $wpdb->query($sql);
                $this->debug_log("Added column: {$column}");
            }
        }

        // Add missing indexes
        $indexes = array(
            'status' => "ALTER TABLE $table_name ADD KEY status (status)",
            'position' => "ALTER TABLE $table_name ADD KEY position (position)",
            'message_type' => "ALTER TABLE $table_name ADD KEY message_type (message_type)"
        );

        foreach ($indexes as $index => $sql) {
            $existing_indexes = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = '$index'");
            if (empty($existing_indexes)) {
                $wpdb->query($sql);
                $this->debug_log("Added index: {$index}");
            }
        }

        // Modify content column to LONGTEXT
        $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN content LONGTEXT NOT NULL");
    }

    /**
     * Insert default welcome message
     */
    private function insert_default_message()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        if ($count == 0) {
            $wpdb->insert(
                $table_name,
                array(
                    'title' => 'Welcome to our website!',
                    'content' => '<h3>🎉 Welcome!</h3><p>This is a demo message created by <strong>Message Admin Pro</strong> plugin. You can edit or delete it from the admin panel.</p><p><a href="' . admin_url('admin.php?page=message-admin') . '">Manage messages →</a></p>',
                    'position' => 'before_content',
                    'status' => 'active',
                    'message_type' => 'info',
                    'dismissible' => 1,
                    'display_pages' => json_encode(array('all')),
                    'user_roles' => json_encode(array('all'))
                ),
                array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            $this->debug_log("Default message inserted");
        }
    }

    /**
     * Create user capabilities
     */
    private function create_capabilities()
    {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_message_admin');
            $admin_role->add_cap('edit_messages');
            $admin_role->add_cap('delete_messages');
        }

        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_role->add_cap('edit_messages');
        }

        $this->debug_log("User capabilities created");
    }

    /**
     * Remove user capabilities
     */
    private static function remove_capabilities()
    {
        $roles = array('administrator', 'editor');
        $capabilities = array('manage_message_admin', 'edit_messages', 'delete_messages');

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }

        self::debug_log("User capabilities removed");
    }

    /**
     * AJAX handler for dismissing messages
     */
    public function ajax_dismiss_message()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'message_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $message_id = intval($_POST['message_id']);

        if (empty($message_id)) {
            wp_send_json_error('Invalid message ID');
        }

        // Store dismissed message in cookie for 30 days
        $dismissed_messages = isset($_COOKIE['dismissed_messages']) ?
            json_decode(stripslashes($_COOKIE['dismissed_messages']), true) : array();

        if (!is_array($dismissed_messages)) {
            $dismissed_messages = array();
        }

        $dismissed_messages[] = $message_id;
        $dismissed_messages = array_unique($dismissed_messages);

        // Set cookie for 30 days
        setcookie(
            'dismissed_messages',
            json_encode($dismissed_messages),
            time() + (30 * 24 * 60 * 60),
            COOKIEPATH,
            COOKIE_DOMAIN
        );

        $this->debug_log("Message {$message_id} dismissed by user");
        wp_send_json_success('Message dismissed');
    }

    /**
     * AJAX handler for resetting message IDs
     */
    public function ajax_reset_message_ids()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'message_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        // Check if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        if ($count > 0) {
            wp_send_json_error('Cannot reset IDs while messages exist. Delete all messages first.');
        }

        // Reset AUTO_INCREMENT to 1
        $result = $wpdb->query("ALTER TABLE $table_name AUTO_INCREMENT = 1");

        if ($result !== false) {
            $this->debug_log("Message IDs reset successfully");
            wp_send_json_success('Message IDs reset successfully');
        } else {
            wp_send_json_error('Failed to reset message IDs');
        }
    }

    /**
     * Get all active messages
     */
    public static function get_all_active_messages()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = %s ORDER BY created_at DESC",
            'active'
        ));
    }

    /**
     * Get single message by ID
     */
    public static function get_message($message_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            intval($message_id)
        ));
    }

    /**
     * Debug logging function
     */
    private function debug_log($message, $type = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Message Admin {$type}] " . print_r($message, true));
        }
    }

    /**
     * Static debug logging function
     */
    public static function log($message, $type = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Message Admin {$type}] " . print_r($message, true));
        }
    }
}

/**
 * Initialize plugin
 */
function message_admin_init()
{
    return MessageAdmin::get_instance();
}

// Initialize plugin
add_action('plugins_loaded', 'message_admin_init', 1);

/**
 * Helper function to display message by ID
 */
if (!function_exists('message_admin_display')) {
    function message_admin_display($message_id, $echo = true)
    {
        $output = do_shortcode('[message_admin id="' . intval($message_id) . '"]');

        if ($echo) {
            echo $output;
        } else {
            return $output;
        }
    }
}

/**
 * Helper function to get messages by position
 */
if (!function_exists('message_admin_get_by_position')) {
    function message_admin_get_by_position($position)
    {
        if (class_exists('MessageAdminFrontend')) {
            return MessageAdminFrontend::get_messages_by_position_static($position);
        }
        return array();
    }
}

/**
 * Helper function to reset message IDs
 */
if (!function_exists('message_admin_reset_ids')) {
    function message_admin_reset_ids()
    {
        $instance = message_admin_init();
        return $instance->ajax_reset_message_ids();
    }
}

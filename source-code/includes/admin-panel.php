<?php

if (!defined('ABSPATH')) {
    exit;
}

class MessageAdminPanel
{
    private $cache_group = 'message_admin';
    private $cache_expiry = 3600; // 1 hour

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_save_message_admin', array($this, 'save_message'));
        add_action('admin_post_delete_message_admin', array($this, 'delete_message'));
        add_action('admin_post_bulk_message_admin', array($this, 'handle_bulk_actions'));
        add_action('admin_post_export_messages', array($this, 'export_messages'));
        add_action('admin_post_import_messages', array($this, 'import_messages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('wp_ajax_get_pages_list', array($this, 'ajax_get_pages_list'));
        add_action('wp_ajax_toggle_message_status', array($this, 'ajax_toggle_status'));
        add_action('wp_ajax_reset_message_ids', array($this, 'ajax_reset_message_ids'));


        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Clear cache on message changes
        add_action('admin_post_save_message_admin', array($this, 'clear_cache'));
        add_action('admin_post_delete_message_admin', array($this, 'clear_cache'));
    }

    /**
     * Optimize database with proper indexes
     */
    public function optimize_database()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        try {
            // Add composite indexes for better performance
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_status_position (status, position)");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_dates (start_date, end_date)");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_dismissible_status (dismissible, status)");

            $this->log_message('Database indexes optimized successfully', 'info');
            return true;
        } catch (Exception $e) {
            $this->log_message('Database optimization failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Enhanced error logging
     */
    private function log_message($message, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Message Admin {$level}] " . $message);
        }

        // Store critical errors in options for admin notice
        if ($level === 'error') {
            $errors = get_option('message_admin_errors', array());
            $errors[] = array(
                'message' => $message,
                'time' => current_time('mysql'),
                'level' => $level
            );

            // Keep only last 10 errors
            $errors = array_slice($errors, -10);
            update_option('message_admin_errors', $errors);
        }
    }

    /**
     * Clear all caches
     */
    public function clear_cache()
    {
        delete_transient('message_admin_analytics');
        delete_transient('message_admin_dashboard_stats');
        delete_transient('message_admin_all_messages');

        // Clear WordPress object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        }

        $this->log_message('Cache cleared successfully', 'info');
    }

    public function register_settings()
    {
        register_setting('message_admin_settings', 'message_admin_default_type');
        register_setting('message_admin_settings', 'message_admin_auto_dismiss');
        register_setting('message_admin_settings', 'message_admin_enable_analytics');
        register_setting('message_admin_settings', 'message_admin_cache_duration');
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Message Admin',
            'Message Admin',
            'manage_options',
            'message-admin',
            array($this, 'admin_page'),
            'dashicons-email-alt',
            30
        );

        add_submenu_page(
            'message-admin',
            'All Messages',
            'All Messages',
            'manage_options',
            'message-admin',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'message-admin',
            'Add New Message',
            'Add New',
            'manage_options',
            'message-admin-add',
            array($this, 'add_message_page')
        );

        add_submenu_page(
            'message-admin',
            'Analytics',
            'Analytics',
            'manage_options',
            'message-admin-analytics',
            array($this, 'analytics_page')
        );

        add_submenu_page(
            'message-admin',
            'Import/Export',
            'Import/Export',
            'manage_options',
            'message-admin-import-export',
            array($this, 'import_export_page')
        );

        add_submenu_page(
            'message-admin',
            'Settings',
            'Settings',
            'manage_options',
            'message-admin-settings',
            array($this, 'settings_page')
        );
    }

    public function enqueue_admin_styles($hook)
    {
        if (strpos($hook, 'message-admin') !== false) {
            wp_enqueue_style(
                'message-admin-style',
                MESSAGE_ADMIN_PLUGIN_URL . 'assets/admin-style.css',
                array(),
                MESSAGE_ADMIN_VERSION
            );

            wp_enqueue_script(
                'message-admin-js',
                MESSAGE_ADMIN_PLUGIN_URL . 'assets/admin-script.js',
                array('jquery'),
                MESSAGE_ADMIN_VERSION,
                true
            );

            wp_localize_script('message-admin-js', 'messageAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('message_admin_nonce'),
                'strings' => array(
                    'confirm_delete' => __('Are you sure you want to delete this message?', 'message-admin'),
                    'confirm_bulk_delete' => __('Are you sure you want to delete selected messages?', 'message-admin'),
                    'no_action_selected' => __('Please select an action.', 'message-admin'),
                    'no_messages_selected' => __('Please select at least one message.', 'message-admin'),
                    'loading' => __('Loading...', 'message-admin'),
                    'error_occurred' => __('An error occurred. Please try again.', 'message-admin')
                )
            ));


            // Enqueue Chart.js for analytics with responsive config
            if ($hook === 'message-admin_page_message-admin-analytics') {
                wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
            }
        }
    }

    public function admin_page()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        // Handle bulk actions
        if (isset($_GET['bulk_action']) && isset($_GET['message_ids'])) {
            $this->process_bulk_action();
        }

        // Get cached messages or fetch from database
        $messages = $this->get_all_messages_cached();
        $stats = $this->get_dashboard_stats_cached();
?>
        <div class="wrap">
            <div class="message-admin-header">
                <h1 class="wp-heading-inline">Message Admin Dashboard</h1>
                <a href="<?php echo admin_url('admin.php?page=message-admin-add'); ?>" class="page-title-action">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <span class="button-text">Add New Message</span>
                </a>
                <button class="button button-secondary clear-cache-btn" onclick="clearCache()">
                    <span class="dashicons dashicons-update"></span>
                    <span class="button-text">Clear Cache</span>
                </button>
            </div>

            <?php $this->show_admin_notices(); ?>

            <!-- Responsive Dashboard Statistics -->
            <div class="message-admin-stats">
                <div class="message-admin-stat-box">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="message-admin-stat-number"><?php echo $stats['total']; ?></div>
                        <div class="message-admin-stat-label">Total Messages</div>
                    </div>
                </div>
                <div class="message-admin-stat-box">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="message-admin-stat-number"><?php echo $stats['active']; ?></div>
                        <div class="message-admin-stat-label">Active</div>
                    </div>
                </div>
                <div class="message-admin-stat-box">
                    <div class="stat-icon">❌</div>
                    <div class="stat-content">
                        <div class="message-admin-stat-number"><?php echo $stats['inactive']; ?></div>
                        <div class="message-admin-stat-label">Inactive</div>
                    </div>
                </div>
                <div class="message-admin-stat-box">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-content">
                        <div class="message-admin-stat-number"><?php echo $stats['scheduled']; ?></div>
                        <div class="message-admin-stat-label">Scheduled</div>
                    </div>
                </div>
            </div>

            <!-- Enhanced Mobile-Friendly Bulk Actions -->
            <form method="get" action="" class="bulk-actions-form">
                <input type="hidden" name="page" value="message-admin">
                <?php wp_nonce_field('bulk_messages'); ?>

                <div class="message-admin-bulk-actions">
                    <div class="bulk-actions-left">
                        <select name="bulk_action" id="bulk-action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="activate">✅ Activate</option>
                            <option value="deactivate">❌ Deactivate</option>
                            <option value="delete">🗑️ Delete</option>
                        </select>
                        <button type="submit" class="button apply-bulk-btn" onclick="return confirmBulkAction();">
                            Apply
                        </button>
                    </div>

                    <div class="bulk-actions-right">
                        <input type="text" id="search-messages" placeholder="🔍 Search messages..." class="search-input">
                        <select id="filter-status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- Responsive Table Wrapper -->
                <div class="table-responsive">
                    <table class="wp-list-table widefat fixed striped" id="messages-table">
                        <thead>
                            <tr>
                                <td class="check-column">
                                    <input type="checkbox" id="select-all-messages">
                                </td>
                                <th scope="col" class="column-id">ID</th>
                                <th scope="col" class="column-title">Title</th>
                                <!-- <th scope="col" class="column-content hide-mobile">Content Preview</th> -->
                                <th scope="col" class="column-position hide-tablet">Position</th>
                                <th scope="col" class="column-pages hide-mobile">Display On</th>
                                <th scope="col" class="column-roles hide-mobile">User Roles</th>
                                <th scope="col" class="column-status">Status</th>
                                <th scope="col" class="column-dismissible hide-tablet">Dismissible</th>
                                <th scope="col" class="column-schedule hide-mobile">Schedule</th>
                                <th scope="col" class="column-shortcode hide-tablet">Shortcode</th>
                                <th scope="col" class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($messages)): ?>
                                <tr class="no-items">
                                    <td colspan="11" class="no-messages-found">
                                        <div class="empty-state">
                                            <span class="empty-icon">📝</span>
                                            <h3>No messages found</h3>
                                            <p>Create your first message to get started!</p>
                                            <a href="<?php echo admin_url('admin.php?page=message-admin-add'); ?>" class="button button-primary">
                                                Create First Message
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <tr class="message-row" data-status="<?php echo esc_attr($message->status); ?>" data-title="<?php echo esc_attr(strtolower($message->title)); ?>">
                                        <th class="check-column">
                                            <input type="checkbox" name="message_ids[]" value="<?php echo $message->id; ?>">
                                        </th>
                                        <td class="column-id" data-label="ID">
                                            <strong>#<?php echo $message->id; ?></strong>
                                        </td>
                                        <td class="column-title" data-label="Title">
                                            <strong class="message-title"><?php echo esc_html($message->title); ?></strong>
                                        </td>
                                        <!-- <td class="column-content hide-mobile" data-label="Content">
                                            <div class="content-preview">
                                                <?php
                                                $content_preview = wp_strip_all_tags($message->content);
                                                echo esc_html(substr($content_preview, 0, 50)) . (strlen($content_preview) > 50 ? '...' : '');
                                                ?>
                                            </div>
                                        </td> -->
                                        <td class="column-position hide-tablet" data-label="Position">
                                            <span class="position-badge position-<?php echo esc_attr($message->position); ?>">
                                                <?php echo esc_html($message->position); ?>
                                            </span>
                                        </td>
                                        <td class="column-pages hide-mobile" data-label="Display On">
                                            <?php echo $this->format_display_pages($message->display_pages); ?>
                                        </td>
                                        <td class="column-roles hide-mobile" data-label="User Roles">
                                            <?php echo $this->format_user_roles($message->user_roles); ?>
                                        </td>
                                        <td class="column-status" data-label="Status">
                                            <button class="status-toggle status-<?php echo $message->status; ?>"
                                                data-message-id="<?php echo $message->id; ?>"
                                                data-current-status="<?php echo $message->status; ?>"
                                                title="Click to toggle status">
                                                <span class="status-icon"></span>
                                                <span class="status-text"><?php echo ucfirst($message->status); ?></span>
                                            </button>
                                        </td>
                                        <td class="column-dismissible hide-tablet" data-label="Dismissible">
                                            <?php
                                            $dismissible = isset($message->dismissible) ? $message->dismissible : 0;
                                            echo $dismissible ? '<span class="dismissible-yes">✓ Yes</span>' : '<span class="dismissible-no">✗ No</span>';
                                            ?>
                                        </td>
                                        <td class="column-schedule hide-mobile" data-label="Schedule">
                                            <div class="schedule-info">
                                                <?php echo $this->format_schedule($message); ?>
                                            </div>
                                        </td>
                                        <td class="column-shortcode hide-tablet" data-label="Shortcode">
                                            <code class="shortcode-copy" title="Click to copy" data-shortcode="[message_admin id=&quot;<?php echo $message->id; ?>&quot;]">
                                                [message_admin id="<?php echo $message->id; ?>"]
                                            </code>
                                        </td>
                                        <td class="column-actions" data-label="Actions">
                                            <div class="action-buttons">
                                                <a href="<?php echo admin_url('admin.php?page=message-admin-add&edit=' . $message->id); ?>"
                                                    class="button button-small edit-btn" title="Edit message">
                                                    <span class="dashicons dashicons-edit"></span>
                                                    <span class="button-text">Edit</span>
                                                </a>

                                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_message_admin&id=' . $message->id), 'delete_message_' . $message->id); ?>"
                                                    class="button button-small button-link-delete delete-btn"
                                                    onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this message?', 'message-admin')); ?>')"
                                                    title="Delete message">
                                                    <span class="dashicons dashicons-trash"></span>
                                                    <span class="button-text">Delete</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>


            <!-- Quick Stats and Tips -->
            <div class="message-admin-help">
                <h3>💡 Pro Tips for Message Admin:</h3>
                <div class="tips-grid">
                    <div class="tip-item">
                        <span class="tip-icon">⚡</span>
                        <div class="tip-content">
                            <strong>Quick Toggle:</strong> Click status buttons to activate/deactivate messages instantly
                        </div>
                    </div>
                    <div class="tip-item">
                        <span class="tip-icon">📦</span>
                        <div class="tip-content">
                            <strong>Bulk Operations:</strong> Select multiple messages for batch actions
                        </div>
                    </div>

                    <div class="tip-item">
                        <span class="tip-icon">⏰</span>
                        <div class="tip-content">
                            <strong>Scheduling:</strong> Set start/end dates for time-based campaigns
                        </div>
                    </div>
                    <div class="tip-item">
                        <span class="tip-icon">🎯</span>
                        <div class="tip-content">
                            <strong>Targeting:</strong> Show messages to specific pages and user roles
                        </div>
                    </div>
                    <div class="tip-item">
                        <span class="tip-icon">📊</span>
                        <div class="tip-content">
                            <strong>Analytics:</strong> Track performance in the Analytics section
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Enhanced mobile-friendly functionality
                const MessageAdmin = {
                    init: function() {
                        this.bindEvents();
                        this.initSearch();
                        this.initFilters();

                    },

                    bindEvents: function() {
                        // Select all functionality
                        $('#select-all-messages').change(function() {
                            $('input[name="message_ids[]"]:visible').prop('checked', this.checked);
                        });

                        // Status toggle with loading state
                        $('.status-toggle').click(function() {
                            const $button = $(this);
                            const messageId = $button.data('message-id');
                            const currentStatus = $button.data('current-status');
                            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

                            $button.addClass('loading').prop('disabled', true);

                            $.ajax({
                                url: messageAdmin.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'toggle_message_status',
                                    message_id: messageId,
                                    new_status: newStatus,
                                    nonce: messageAdmin.nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $button.removeClass('status-' + currentStatus).addClass('status-' + newStatus);
                                        $button.find('.status-text').text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                                        $button.data('current-status', newStatus);
                                        $button.closest('tr').attr('data-status', newStatus);
                                    } else {
                                        alert(messageAdmin.strings.error_occurred);
                                    }
                                },
                                error: function() {
                                    alert(messageAdmin.strings.error_occurred);
                                },
                                complete: function() {
                                    $button.removeClass('loading').prop('disabled', false);
                                }
                            });
                        });

                        // Enhanced shortcode copy
                        $('.shortcode-copy').click(function(e) {
                            e.preventDefault();
                            const shortcode = $(this).data('shortcode');
                            navigator.clipboard.writeText(shortcode).then(() => {
                                this.showToast('Shortcode copied to clipboard!', 'success');
                            }).catch(() => {
                                this.showToast('Failed to copy shortcode', 'error');
                            });
                        }.bind(this));
                    },

                    initSearch: function() {
                        $('#search-messages').on('input', function() {
                            const searchTerm = $(this).val().toLowerCase();
                            $('.message-row').each(function() {
                                const title = $(this).data('title');
                                const visible = title.includes(searchTerm);
                                $(this).toggle(visible);
                            });
                        });
                    },

                    initFilters: function() {
                        $('#filter-status').change(function() {
                            const status = $(this).val();
                            $('.message-row').each(function() {
                                const rowStatus = $(this).data('status');
                                const visible = !status || rowStatus === status;
                                $(this).toggle(visible);
                            });
                        });
                    },






                };

                // Initialize
                MessageAdmin.init();

                // Global functions
                window.confirmBulkAction = function() {
                    const action = document.getElementById('bulk-action').value;
                    const checked = document.querySelectorAll('input[name="message_ids[]"]:checked');

                    if (!action) {
                        alert(messageAdmin.strings.no_action_selected);
                        return false;
                    }

                    if (checked.length === 0) {
                        alert(messageAdmin.strings.no_messages_selected);
                        return false;
                    }

                    if (action === 'delete') {
                        return confirm(messageAdmin.strings.confirm_bulk_delete.replace('%d', checked.length));
                    }

                    return confirm(`Are you sure you want to ${action} ${checked.length} message(s)?`);
                };

                window.clearCache = function() {
                    if (confirm('Clear all Message Admin cache?')) {
                        $.post(messageAdmin.ajaxurl, {
                            action: 'clear_message_admin_cache',
                            nonce: messageAdmin.nonce
                        }, function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        });
                    }
                };
            });
        </script>
    <?php
    }

    private function get_dashboard_stats_cached()
    {
        $cache_key = 'message_admin_dashboard_stats';
        $stats = get_transient($cache_key);

        if (false === $stats) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'message_admin';

            $stats = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
                'active' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active'"),
                'inactive' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'inactive'"),
                'dismissible' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE dismissible = 1"),
                'scheduled' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE start_date IS NOT NULL OR end_date IS NOT NULL")
            );

            // Cache for 1 hour
            set_transient($cache_key, $stats, $this->cache_expiry);
            $this->log_message('Dashboard stats cached', 'info');
        }

        return $stats;
    }

    /**
     * Get cached analytics data
     */
    private function get_analytics_data_cached()
    {
        $cache_key = 'message_admin_analytics';
        $analytics = get_transient($cache_key);

        if (false === $analytics) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'message_admin';

            $positions = $wpdb->get_results("SELECT position, COUNT(*) as count FROM $table_name GROUP BY position", ARRAY_A);
            $position_data = array();
            foreach ($positions as $pos) {
                $position_data[$pos['position']] = $pos['count'];
            }

            $popular_position = $wpdb->get_var("SELECT position FROM $table_name GROUP BY position ORDER BY COUNT(*) DESC LIMIT 1");

            $analytics = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
                'active' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active'"),
                'inactive' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'inactive'"),
                'dismissible' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE dismissible = 1"),
                'scheduled' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE start_date IS NOT NULL OR end_date IS NOT NULL"),
                'positions' => $position_data,
                'popular_position' => $popular_position ?: 'manual'
            );

            // Cache for 1 hour
            set_transient($cache_key, $analytics, $this->cache_expiry);
            $this->log_message('Analytics data cached', 'info');
        }

        return $analytics;
    }

    /**
     * Get cached messages
     */
    private function get_all_messages_cached()
    {
        $cache_key = 'message_admin_all_messages';
        $messages = get_transient($cache_key);

        if (false === $messages) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'message_admin';
            $messages = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

            // Cache for 30 minutes
            set_transient($cache_key, $messages, 1800);
            $this->log_message('Messages cached', 'info');
        }

        return $messages;
    }

    /**
     * Enhanced Analytics Page with caching
     */
    public function analytics_page()
    {
        $analytics = $this->get_analytics_data_cached();
        $cache_info = $this->get_cache_info();
    ?>
        <div class="wrap">
            <div class="analytics-header">
                <h1>📊 Message Analytics</h1>
                <div class="analytics-actions">
                    <button class="button refresh-analytics" onclick="refreshAnalytics()">
                        <span class="dashicons dashicons-update"></span> Refresh Data
                    </button>
                    <small class="cache-info">Last updated: <?php echo $cache_info['analytics']; ?></small>
                </div>
            </div>

            <div class="message-admin-analytics">
                <div class="analytics-overview">
                    <div class="overview-card">
                        <div class="card-icon">📈</div>
                        <div class="card-content">
                            <h3>Performance Overview</h3>
                            <div class="metrics">
                                <div class="metric">
                                    <span class="metric-value"><?php echo $analytics['total']; ?></span>
                                    <span class="metric-label">Total Messages</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-value"><?php echo round(($analytics['active'] / max($analytics['total'], 1)) * 100); ?>%</span>
                                    <span class="metric-label">Active Rate</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="analytics-charts">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Messages by Status</h3>
                            <div class="chart-controls">
                                <button class="chart-download" onclick="downloadChart('statusChart')">📥 Download</button>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Messages by Position</h3>
                            <div class="chart-controls">
                                <button class="chart-download" onclick="downloadChart('positionChart')">📥 Download</button>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="positionChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="analytics-details">
                    <div class="details-section">
                        <h3>📊 Detailed Statistics</h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-icon">📝</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo $analytics['total']; ?></span>
                                    <span class="stat-label">Total Messages</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">✅</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo $analytics['active']; ?></span>
                                    <span class="stat-label">Active Messages</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">⏰</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo $analytics['scheduled']; ?></span>
                                    <span class="stat-label">Scheduled Messages</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">❌</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo $analytics['dismissible']; ?></span>
                                    <span class="stat-label">Dismissible Messages</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">📍</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo $analytics['popular_position']; ?></span>
                                    <span class="stat-label">Most Used Position</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="insights-section">
                        <h3>💡 Insights & Recommendations</h3>
                        <div class="insights-list">
                            <?php if ($analytics['inactive'] > $analytics['active']): ?>
                                <div class="insight warning">
                                    <span class="insight-icon">⚠️</span>
                                    <div class="insight-content">
                                        <strong>Too many inactive messages</strong>
                                        <p>You have more inactive than active messages. Consider activating relevant messages or deleting unused ones.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($analytics['dismissible'] < ($analytics['total'] * 0.3)): ?>
                                <div class="insight info">
                                    <span class="insight-icon">💡</span>
                                    <div class="insight-content">
                                        <strong>Consider making more messages dismissible</strong>
                                        <p>Dismissible messages provide better user experience. Consider enabling this for non-critical messages.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="insight success">
                                <span class="insight-icon">✅</span>
                                <div class="insight-content">
                                    <strong>Performance Tips</strong>
                                    <p>Use caching and scheduled messages for better performance. Current cache hit rate: <strong>Active</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Enhanced responsive charts
                const chartOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: window.innerWidth < 768 ? 'bottom' : 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#333',
                            borderWidth: 1
                        }
                    }
                };

                // Status Chart
                const statusCtx = document.getElementById('statusChart').getContext('2d');
                window.statusChart = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Active', 'Inactive'],
                        datasets: [{
                            data: [<?php echo $analytics['active']; ?>, <?php echo $analytics['inactive']; ?>],
                            backgroundColor: [
                                'rgba(70, 180, 80, 0.8)',
                                'rgba(220, 50, 50, 0.8)'
                            ],
                            borderColor: [
                                'rgba(70, 180, 80, 1)',
                                'rgba(220, 50, 50, 1)'
                            ],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        ...chartOptions,
                        cutout: '60%'
                    }
                });

                // Position Chart
                const positionCtx = document.getElementById('positionChart').getContext('2d');
                window.positionChart = new Chart(positionCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_keys($analytics['positions'])); ?>,
                        datasets: [{
                            label: 'Messages',
                            data: <?php echo json_encode(array_values($analytics['positions'])); ?>,
                            backgroundColor: 'rgba(0, 115, 170, 0.8)',
                            borderColor: 'rgba(0, 115, 170, 1)',
                            borderWidth: 2,
                            borderRadius: 4,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        ...chartOptions,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            },
                            x: {
                                ticks: {
                                    maxRotation: window.innerWidth < 768 ? 45 : 0
                                }
                            }
                        }
                    }
                });

                // Responsive chart updates
                window.addEventListener('resize', function() {
                    if (window.statusChart && window.positionChart) {
                        window.statusChart.options.plugins.legend.position = window.innerWidth < 768 ? 'bottom' : 'right';
                        window.positionChart.options.scales.x.ticks.maxRotation = window.innerWidth < 768 ? 45 : 0;

                        window.statusChart.update();
                        window.positionChart.update();
                    }
                });
            });

            // Global functions
            function refreshAnalytics() {
                // Clear analytics cache and reload
                jQuery.post(ajaxurl, {
                    action: 'clear_analytics_cache',
                    nonce: '<?php echo wp_create_nonce('clear_analytics_cache'); ?>'
                }, function() {
                    location.reload();
                });
            }

            function downloadChart(chartId) {
                const chart = window[chartId];
                if (chart) {
                    const url = chart.toBase64Image();
                    const link = document.createElement('a');
                    link.download = chartId + '-' + new Date().getTime() + '.png';
                    link.href = url;
                    link.click();
                }
            }
        </script>
    <?php
    }

    /**
     * Enhanced Settings Page with cache controls
     */
    public function settings_page()
    {
        $cache_info = $this->get_cache_info();
    ?>
        <div class="wrap">
            <h1>⚙️ Message Admin Settings</h1>

            <form method="post" action="options.php" class="settings-form">
                <?php
                settings_fields('message_admin_settings');
                do_settings_sections('message_admin_settings');
                ?>

                <div class="settings-sections">
                    <div class="settings-section">
                        <h2>🎯 General Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Default Message Type</th>
                                <td>
                                    <select name="message_admin_default_type" class="regular-text">
                                        <option value="info" <?php selected(get_option('message_admin_default_type', 'info'), 'info'); ?>>ℹ️ Info</option>
                                        <option value="success" <?php selected(get_option('message_admin_default_type', 'info'), 'success'); ?>>✅ Success</option>
                                        <option value="warning" <?php selected(get_option('message_admin_default_type', 'info'), 'warning'); ?>>⚠️ Warning</option>
                                        <option value="error" <?php selected(get_option('message_admin_default_type', 'info'), 'error'); ?>>❌ Error</option>
                                    </select>
                                    <p class="description">Default message type for new messages</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Auto-dismiss Time</th>
                                <td>
                                    <input type="number" name="message_admin_auto_dismiss"
                                        value="<?php echo get_option('message_admin_auto_dismiss', 0); ?>"
                                        min="0" max="300" class="small-text">
                                    <span class="unit">seconds</span>
                                    <p class="description">Set to 0 to disable auto-dismiss. Maximum 300 seconds (5 minutes)</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="settings-section">
                        <h2>📊 Performance & Caching</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Analytics</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="message_admin_enable_analytics"
                                            value="1" <?php checked(get_option('message_admin_enable_analytics', 1)); ?>>
                                        Track message views and interactions
                                    </label>
                                    <p class="description">Collect analytics data for better insights</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Duration</th>
                                <td>
                                    <select name="message_admin_cache_duration" class="regular-text">
                                        <option value="900" <?php selected(get_option('message_admin_cache_duration', 3600), 900); ?>>15 minutes</option>
                                        <option value="1800" <?php selected(get_option('message_admin_cache_duration', 3600), 1800); ?>>30 minutes</option>
                                        <option value="3600" <?php selected(get_option('message_admin_cache_duration', 3600), 3600); ?>>1 hour</option>
                                        <option value="7200" <?php selected(get_option('message_admin_cache_duration', 3600), 7200); ?>>2 hours</option>
                                        <option value="21600" <?php selected(get_option('message_admin_cache_duration', 3600), 21600); ?>>6 hours</option>
                                    </select>
                                    <p class="description">How long to cache data for better performance</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="settings-section">
                        <h2>🔧 Cache Management</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cache Status</th>
                                <td>
                                    <div class="cache-status">
                                        <div class="cache-item">
                                            <span class="cache-label">Dashboard:</span>
                                            <span class="cache-value"><?php echo $cache_info['dashboard']; ?></span>
                                        </div>
                                        <div class="cache-item">
                                            <span class="cache-label">Analytics:</span>
                                            <span class="cache-value"><?php echo $cache_info['analytics']; ?></span>
                                        </div>
                                        <div class="cache-item">
                                            <span class="cache-label">Messages:</span>
                                            <span class="cache-value"><?php echo $cache_info['messages']; ?></span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Actions</th>
                                <td>
                                    <div class="cache-actions">
                                        <button type="button" class="button" onclick="clearSpecificCache('dashboard')">
                                            🔄 Clear Dashboard Cache
                                        </button>
                                        <button type="button" class="button" onclick="clearSpecificCache('analytics')">
                                            📊 Clear Analytics Cache
                                        </button>
                                        <button type="button" class="button button-secondary" onclick="clearAllCache()">
                                            🗑️ Clear All Cache
                                        </button>
                                    </div>
                                    <p class="description">Clear specific caches or all cached data</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button('💾 Save Settings', 'primary', 'submit', true, array('class' => 'save-settings-btn')); ?>
            </form>

            <!-- Database Optimization -->
            <div class="settings-section">
                <h2>🗄️ Database Optimization</h2>
                <div class="optimization-info">
                    <p>Optimize your database for better performance. This will add indexes to speed up queries.</p>
                    <button type="button" class="button button-secondary" onclick="optimizeDatabase()">
                        ⚡ Optimize Database
                    </button>
                    <div id="optimization-result"></div>
                </div>
            </div>
        </div>

        <script>
            function clearSpecificCache(type) {
                jQuery.post(ajaxurl, {
                    action: 'clear_specific_cache',
                    cache_type: type,
                    nonce: '<?php echo wp_create_nonce('clear_cache'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to clear cache');
                    }
                });
            }

            function clearAllCache() {
                if (confirm('Clear all Message Admin cache?')) {
                    jQuery.post(ajaxurl, {
                        action: 'clear_all_cache',
                        nonce: '<?php echo wp_create_nonce('clear_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Failed to clear cache');
                        }
                    });
                }
            }

            function optimizeDatabase() {
                const button = event.target;
                const result = document.getElementById('optimization-result');

                button.disabled = true;
                button.textContent = 'Optimizing...';

                jQuery.post(ajaxurl, {
                    action: 'optimize_message_database',
                    nonce: '<?php echo wp_create_nonce('optimize_db'); ?>'
                }, function(response) {
                    if (response.success) {
                        result.innerHTML = '<p style="color: green;">✅ Database optimized successfully!</p>';
                    } else {
                        result.innerHTML = '<p style="color: red;">❌ Optimization failed: ' + response.data + '</p>';
                    }

                    button.disabled = false;
                    button.textContent = '⚡ Optimize Database';
                });
            }
        </script>
    <?php
    }

    /**
     * Get cache information
     */
    private function get_cache_info()
    {
        return array(
            'dashboard' => get_transient('message_admin_dashboard_stats') ? 'Cached' : 'Not cached',
            'analytics' => get_transient('message_admin_analytics') ? 'Cached' : 'Not cached',
            'messages' => get_transient('message_admin_all_messages') ? 'Cached' : 'Not cached'
        );
    }

    // Continue with remaining methods...
    // (The file is getting long, so I'll continue with the essential remaining methods)

    // Helper methods remain the same as before
    private function format_schedule($message)
    {
        if (empty($message->start_date) && empty($message->end_date)) {
            return '<span class="schedule-always">Always Active</span>';
        }

        $schedule = '';
        if (!empty($message->start_date)) {
            $schedule .= '<span class="schedule-start">From: ' . date('M j, Y', strtotime($message->start_date)) . '</span><br>';
        }
        if (!empty($message->end_date)) {
            $schedule .= '<span class="schedule-end">Until: ' . date('M j, Y', strtotime($message->end_date)) . '</span>';
        }

        return $schedule;
    }

    private function get_page_targeting_type($message)
    {
        if (!$message || empty($message->display_pages)) return 'all';
        $pages = json_decode($message->display_pages, true);
        return (is_array($pages) && in_array('all', $pages)) ? 'all' : 'specific';
    }

    private function get_selected_pages($message)
    {
        if (!$message || empty($message->display_pages)) return array();
        $pages = json_decode($message->display_pages, true);
        return is_array($pages) ? $pages : array();
    }

    private function get_selected_roles($message)
    {
        if (!$message || empty($message->user_roles)) return array('all');
        $roles = json_decode($message->user_roles, true);
        return is_array($roles) ? $roles : array('all');
    }

    private function format_display_pages($display_pages_json)
    {
        if (empty($display_pages_json)) return '<span class="pages-all">All pages</span>';
        $pages = json_decode($display_pages_json, true);
        if (!is_array($pages) || in_array('all', $pages)) return '<span class="pages-all">All pages</span>';
        return '<span class="pages-specific">' . count($pages) . ' specific page(s)</span>';
    }

    private function format_user_roles($user_roles_json)
    {
        if (empty($user_roles_json)) return '<span class="roles-all">All users</span>';
        $roles = json_decode($user_roles_json, true);
        if (!is_array($roles) || in_array('all', $roles)) return '<span class="roles-all">All users</span>';
        return '<span class="roles-specific">' . implode(', ', $roles) . '</span>';
    }

    // AJAX Methods
    public function ajax_toggle_status()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'message_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $message_id = intval($_POST['message_id']);
        $new_status = sanitize_text_field($_POST['new_status']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        try {
            $result = $wpdb->update(
                $table_name,
                array('status' => $new_status),
                array('id' => $message_id),
                array('%s'),
                array('%d')
            );

            if ($result !== false) {
                // Clear cache when status changes
                $this->clear_cache();
                wp_send_json_success('Status updated successfully');
            } else {
                throw new Exception('Database update failed');
            }
        } catch (Exception $e) {
            $this->log_message('Status toggle failed: ' . $e->getMessage(), 'error');
            wp_send_json_error('Update failed');
        }
    }

    /**
     * Show admin notices
     */
    private function show_admin_notices()
    {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Message saved successfully!</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Message updated successfully!</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Message deleted successfully!</p></div>';
        }
        if (isset($_GET['imported'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ' . intval($_GET['imported']) . ' messages imported successfully!</p></div>';
        }
        if (isset($_GET['bulk_activate'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ' . intval($_GET['bulk_activate']) . ' messages activated!</p></div>';
        }
        if (isset($_GET['bulk_deactivate'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ' . intval($_GET['bulk_deactivate']) . ' messages deactivated!</p></div>';
        }
        if (isset($_GET['bulk_delete'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ' . intval($_GET['bulk_delete']) . ' messages deleted!</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            switch ($error) {
                case 'upload_failed':
                    echo '<div class="notice notice-error is-dismissible"><p>❌ File upload failed!</p></div>';
                    break;
                case 'invalid_file':
                    echo '<div class="notice notice-error is-dismissible"><p>❌ Invalid import file!</p></div>';
                    break;
                case 'no_selection':
                    echo '<div class="notice notice-error is-dismissible"><p>❌ No messages selected for bulk action!</p></div>';
                    break;
            }
        }
    }

    /**
     * Bulk Actions Handler
     */
    public function process_bulk_action()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bulk_messages')) {
            wp_die('Unauthorized access');
        }

        $action = sanitize_text_field($_GET['bulk_action']);
        $message_ids = array_map('intval', $_GET['message_ids'] ?? array());

        if (empty($action) || empty($message_ids)) {
            wp_redirect(admin_url('admin.php?page=message-admin&error=no_selection'));
            exit;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';
        $count = 0;

        foreach ($message_ids as $id) {
            switch ($action) {
                case 'activate':
                    $result = $wpdb->update($table_name, array('status' => 'active'), array('id' => $id), array('%s'), array('%d'));
                    break;
                case 'deactivate':
                    $result = $wpdb->update($table_name, array('status' => 'inactive'), array('id' => $id), array('%s'), array('%d'));
                    break;
                case 'delete':
                    $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
                    break;
            }
            if ($result !== false) $count++;
        }

        // Clear cache after bulk operations
        $this->clear_cache();

        wp_redirect(admin_url("admin.php?page=message-admin&bulk_{$action}={$count}"));
        exit;
    }

    /**
     * Export messages
     */
    public function export_messages()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';
        $messages = $wpdb->get_results("SELECT * FROM $table_name");

        $export_data = array(
            'plugin_version' => MESSAGE_ADMIN_VERSION,
            'export_date' => current_time('mysql'),
            'messages' => $messages
        );

        $filename = 'message-admin-export-' . date('Y-m-d-H-i-s') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen(json_encode($export_data)));

        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Import messages
     */
    public function import_messages()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'import_messages')) {
            wp_die('Unauthorized access');
        }

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(admin_url('admin.php?page=message-admin-import-export&error=upload_failed'));
            exit;
        }

        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);

        if (!$import_data || !isset($import_data['messages'])) {
            wp_redirect(admin_url('admin.php?page=message-admin-import-export&error=invalid_file'));
            exit;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';
        $imported = 0;

        foreach ($import_data['messages'] as $message) {
            unset($message['id']); // Remove ID to create new messages

            $result = $wpdb->insert($table_name, (array)$message);
            if ($result !== false) {
                $imported++;
            }
        }

        // Clear cache after import
        $this->clear_cache();

        wp_redirect(admin_url("admin.php?page=message-admin-import-export&imported={$imported}"));
        exit;
    }

    /**
     * Save message
     */
    public function save_message()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        if (!wp_verify_nonce($_POST['message_admin_nonce'], 'save_message_admin')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name");
        $has_dismissible = in_array('dismissible', $columns);

        $title = sanitize_text_field($_POST['message_title']);
        $content = wp_kses_post($_POST['message_content']);
        $position = sanitize_text_field($_POST['message_position']);
        $status = sanitize_text_field($_POST['message_status']);

        $start_date = !empty($_POST['message_start_date']) ? $_POST['message_start_date'] : null;
        $end_date = !empty($_POST['message_end_date']) ? $_POST['message_end_date'] : null;

        $display_pages = array('all');
        if (isset($_POST['page_targeting_type']) && $_POST['page_targeting_type'] === 'specific') {
            if (isset($_POST['message_pages']) && is_array($_POST['message_pages'])) {
                $display_pages = array_map('sanitize_text_field', $_POST['message_pages']);
            }
        }

        $user_roles = isset($_POST['message_user_roles']) && is_array($_POST['message_user_roles'])
            ? array_map('sanitize_text_field', $_POST['message_user_roles'])
            : array('all');

        $data = array(
            'title' => $title,
            'content' => $content,
            'position' => $position,
            'status' => $status,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'display_pages' => json_encode($display_pages),
            'user_roles' => json_encode($user_roles),
            'message_type' => 'info'
        );

        $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        if ($has_dismissible) {
            $dismissible = isset($_POST['message_dismissible']) ? 1 : 0;
            $data['dismissible'] = $dismissible;
            $format[] = '%d';
        }

        try {
            if (isset($_POST['message_id']) && is_numeric($_POST['message_id'])) {
                $result = $wpdb->update(
                    $table_name,
                    $data,
                    array('id' => intval($_POST['message_id'])),
                    $format,
                    array('%d')
                );
                $redirect_url = admin_url('admin.php?page=message-admin&updated=1');
            } else {
                $result = $wpdb->insert($table_name, $data, $format);
                $redirect_url = admin_url('admin.php?page=message-admin&saved=1');
            }

            if ($result === false) {
                throw new Exception('Database operation failed: ' . $wpdb->last_error);
            }

            // Clear cache after save
            $this->clear_cache();

            wp_redirect($redirect_url);
            exit;
        } catch (Exception $e) {
            $this->log_message('Save message failed: ' . $e->getMessage(), 'error');
            wp_die('Something went wrong. Please try again.');
        }
    }

    /**
     * Delete message
     */
    public function delete_message()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        $message_id = intval($_GET['id']);
        if (empty($message_id)) {
            wp_die('Invalid message ID');
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_message_' . $message_id)) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';

        try {
            $result = $wpdb->delete(
                $table_name,
                array('id' => $message_id),
                array('%d')
            );

            if ($result === false) {
                throw new Exception('Database delete failed: ' . $wpdb->last_error);
            }

            // Clear cache after delete
            $this->clear_cache();

            wp_redirect(admin_url('admin.php?page=message-admin&deleted=1'));
            exit;
        } catch (Exception $e) {
            $this->log_message('Delete message failed: ' . $e->getMessage(), 'error');
            wp_die('Something went wrong. Please try again.');
        }
    }

    /**
     * Handle bulk actions - alias for backwards compatibility
     */
    public function handle_bulk_actions()
    {
        $this->process_bulk_action();
    }

    /**
     * Get pages list via AJAX
     */
    public function ajax_get_pages_list()
    {
        check_ajax_referer('message_admin_nonce', 'nonce');
        $pages = get_pages();
        $posts = get_posts(array('numberposts' => 10));
        wp_send_json_success(array('pages' => $pages, 'posts' => $posts));
    }




    public function add_message_page()
    {
        $editing = false;
        $message = null;

        if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
            $editing = true;
            global $wpdb;
            $table_name = $wpdb->prefix . 'message_admin';
            $message = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['edit'])));

            if (!$message) {
                wp_die('Message not found');
            }
        }

        // Get all pages and posts for targeting
        $pages = get_pages(array('post_status' => 'publish'));
        $posts = get_posts(array('numberposts' => 50, 'post_status' => 'publish'));
        $categories = get_categories();

        // Get WordPress roles
        global $wp_roles;
        $roles = $wp_roles->roles;
    ?>
        <div class="wrap">
            <h1><?php echo $editing ? 'Edit Message' : 'Add New Message'; ?></h1>

            <?php $this->show_admin_notices(); ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="message-form">
                <input type="hidden" name="action" value="save_message_admin">
                <?php wp_nonce_field('save_message_admin', 'message_admin_nonce'); ?>

                <?php if ($editing): ?>
                    <input type="hidden" name="message_id" value="<?php echo $message->id; ?>">
                <?php endif; ?>

                <div class="form-container">
                    <div class="form-main">
                        <!-- Message Title -->
                        <div class="form-field">
                            <label for="message_title">Message Title *</label>
                            <input type="text"
                                id="message_title"
                                name="message_title"
                                value="<?php echo $editing ? esc_attr($message->title) : ''; ?>"
                                required
                                class="large-text">
                            <p class="description">Enter a descriptive title for your message</p>
                        </div>

                        <!-- Message Content -->
                        <div class="form-field">
                            <label for="message_content">Message Content *</label>
                            <?php
                            $content = $editing ? $message->content : '';
                            wp_editor($content, 'message_content', array(
                                'textarea_rows' => 8,
                                'media_buttons' => true,
                                'teeny' => false,
                                'tinymce' => array(
                                    'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,blockquote,alignleft,aligncenter,alignright,undo,redo',
                                    'toolbar2' => 'formatselect,forecolor,backcolor,removeformat,charmap,outdent,indent,wp_adv',
                                )
                            ));
                            ?>
                            <p class="description">Create your message content. HTML and shortcodes are supported.</p>
                        </div>
                    </div>

                    <div class="form-sidebar">
                        <!-- Publish Box -->
                        <div class="postbox">
                            <h3><span>📤 Publish</span></h3>
                            <div class="inside">
                                <div class="submitbox">
                                    <div class="misc-pub-section">
                                        <label for="message_status">Status:</label>
                                        <select name="message_status" id="message_status">
                                            <option value="active" <?php echo ($editing && $message->status === 'active') ? 'selected' : ''; ?>>✅ Active</option>
                                            <option value="inactive" <?php echo ($editing && $message->status === 'inactive') ? 'selected' : ''; ?>>❌ Inactive</option>
                                        </select>
                                    </div>

                                    <div class="misc-pub-section">
                                        <label for="message_position">Position:</label>
                                        <select name="message_position" id="message_position">
                                            <option value="manual" <?php echo ($editing && $message->position === 'manual') ? 'selected' : ''; ?>>📝 Manual (Shortcode)</option>
                                            <option value="before_content" <?php echo ($editing && $message->position === 'before_content') ? 'selected' : ''; ?>>⬆️ Before Content</option>
                                            <option value="after_content" <?php echo ($editing && $message->position === 'after_content') ? 'selected' : ''; ?>>⬇️ After Content</option>
                                            <option value="header" <?php echo ($editing && $message->position === 'header') ? 'selected' : ''; ?>>🔝 Header</option>
                                            <option value="footer" <?php echo ($editing && $message->position === 'footer') ? 'selected' : ''; ?>>🔻 Footer</option>
                                        </select>
                                    </div>

                                    <div class="misc-pub-section">
                                        <label>
                                            <input type="checkbox"
                                                name="message_dismissible"
                                                value="1"
                                                <?php echo ($editing && isset($message->dismissible) && $message->dismissible) ? 'checked' : ''; ?>>
                                            ❌ Dismissible
                                        </label>
                                    </div>

                                    <div class="major-publishing-actions">
                                        <div class="publishing-action">
                                            <input type="submit"
                                                class="button button-primary button-large"
                                                value="<?php echo $editing ? '💾 Update Message' : '🚀 Publish Message'; ?>">
                                        </div>
                                        <div class="clear"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Page Targeting -->
                        <div class="postbox">
                            <h3><span>🎯 Page Targeting</span></h3>
                            <div class="inside">
                                <p>
                                    <label>
                                        <input type="radio"
                                            name="page_targeting_type"
                                            value="all"
                                            <?php echo ($editing && $this->get_page_targeting_type($message) === 'all') ? 'checked' : 'checked'; ?>>
                                        Show on all pages
                                    </label>
                                </p>
                                <p>
                                    <label>
                                        <input type="radio"
                                            name="page_targeting_type"
                                            value="specific"
                                            <?php echo ($editing && $this->get_page_targeting_type($message) === 'specific') ? 'checked' : ''; ?>>
                                        Show on specific pages only
                                    </label>
                                </p>

                                <div id="specific-pages" style="<?php echo ($editing && $this->get_page_targeting_type($message) === 'specific') ? 'display:block;' : 'display:none;'; ?>">
                                    <h4>Select Pages:</h4>
                                    <div class="pages-checklist" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                        <?php
                                        $selected_pages = $editing ? $this->get_selected_pages($message) : array();

                                        echo '<label><input type="checkbox" name="message_pages[]" value="home" ' . (in_array('home', $selected_pages) ? 'checked' : '') . '> 🏠 Front Page</label><br>';
                                        echo '<label><input type="checkbox" name="message_pages[]" value="blog" ' . (in_array('blog', $selected_pages) ? 'checked' : '') . '> 📝 Blog Page</label><br>';

                                        foreach ($pages as $page) {
                                            $checked = in_array('page_' . $page->ID, $selected_pages) ? 'checked' : '';
                                            echo '<label><input type="checkbox" name="message_pages[]" value="page_' . $page->ID . '" ' . $checked . '> 📄 ' . esc_html($page->post_title) . '</label><br>';
                                        }

                                        if (!empty($posts)) {
                                            echo '<h5>Recent Posts:</h5>';
                                            foreach ($posts as $post) {
                                                $checked = in_array('post_' . $post->ID, $selected_pages) ? 'checked' : '';
                                                echo '<label><input type="checkbox" name="message_pages[]" value="post_' . $post->ID . '" ' . $checked . '> 📝 ' . esc_html($post->post_title) . '</label><br>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- User Role Targeting -->
                        <div class="postbox">
                            <h3><span>👥 User Role Targeting</span></h3>
                            <div class="inside">
                                <?php
                                $selected_roles = $editing ? $this->get_selected_roles($message) : array('all');
                                ?>
                                <label>
                                    <input type="checkbox"
                                        name="message_user_roles[]"
                                        value="all"
                                        <?php echo in_array('all', $selected_roles) ? 'checked' : ''; ?>>
                                    👨‍👩‍👧‍👦 All Users
                                </label><br>

                                <label>
                                    <input type="checkbox"
                                        name="message_user_roles[]"
                                        value="guest"
                                        <?php echo in_array('guest', $selected_roles) ? 'checked' : ''; ?>>
                                    👤 Guests (Not Logged In)
                                </label><br>

                                <?php foreach ($roles as $role_key => $role_info): ?>
                                    <label>
                                        <input type="checkbox"
                                            name="message_user_roles[]"
                                            value="<?php echo esc_attr($role_key); ?>"
                                            <?php echo in_array($role_key, $selected_roles) ? 'checked' : ''; ?>>
                                        🔑 <?php echo esc_html($role_info['name']); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Schedule -->
                        <div class="postbox">
                            <h3><span>⏰ Schedule (Optional)</span></h3>
                            <div class="inside">
                                <p>
                                    <label for="message_start_date">Start Date:</label><br>
                                    <input type="datetime-local"
                                        id="message_start_date"
                                        name="message_start_date"
                                        value="<?php echo $editing && $message->start_date ? date('Y-m-d\TH:i', strtotime($message->start_date)) : ''; ?>"
                                        class="regular-text"
                                        style="width: 100%; max-width: 250px; box-sizing: border-box;">
                                </p>
                                <p>
                                    <label for="message_end_date">End Date:</label><br>
                                    <input type="datetime-local"
                                        id="message_end_date"
                                        name="message_end_date"
                                        value="<?php echo $editing && $message->end_date ? date('Y-m-d\TH:i', strtotime($message->end_date)) : ''; ?>"
                                        class="regular-text"
                                        style="width: 100%; max-width: 250px; box-sizing: border-box;">
                                </p>
                                <p class="description">Leave empty for always active message</p>
                            </div>
                        </div>


                    </div>
                </div>
            </form>

            <!-- Back Link -->
            <p>
                <a href="<?php echo admin_url('admin.php?page=message-admin'); ?>" class="button">
                    ← Back to All Messages
                </a>
            </p>
        </div>

        <style>
            .form-container {
                display: flex;
                gap: 20px;
                margin-top: 20px;
            }

            .form-main {
                flex: 1;
                min-width: 0;
            }

            .form-sidebar {
                width: 280px;
                flex-shrink: 0;
            }

            .form-field {
                margin-bottom: 20px;
            }

            .form-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }

            .pages-checklist {
                max-height: 200px;
                overflow-y: auto;
            }

            .pages-checklist label {
                display: block;
                padding: 2px 0;
                font-weight: normal;
            }

            @media (max-width: 782px) {
                .form-container {
                    flex-direction: column;
                }

                .form-sidebar {
                    width: 100%;
                }
            }
        </style>


    <?php
    }

    /**
     * Import/Export Page
     */
    public function import_export_page()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'message_admin';
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    ?>
        <div class="wrap">
            <h1>📦 Import/Export Messages</h1>

            <?php $this->show_admin_notices(); ?>

            <div class="import-export-container">
                <!-- Export Section -->
                <div class="export-section">
                    <div class="postbox">
                        <h2><span>📤 Export Messages</span></h2>
                        <div class="inside">
                            <p>Export all your messages to a JSON file for backup or migration purposes.</p>

                            <div class="export-stats">
                                <p><strong>Total Messages:</strong> <?php echo $total_messages; ?></p>
                                <p><strong>Export includes:</strong> All message content, settings, targeting rules, and schedules</p>
                            </div>

                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                <input type="hidden" name="action" value="export_messages">
                                <?php wp_nonce_field('export_messages'); ?>

                                <p>
                                    <input type="submit"
                                        class="button button-primary"
                                        value="📥 Download Export File"
                                        <?php echo $total_messages == 0 ? 'disabled' : ''; ?>>
                                </p>

                                <?php if ($total_messages == 0): ?>
                                    <p class="description" style="color: #dc3232;">
                                        No messages available to export. <a href="<?php echo admin_url('admin.php?page=message-admin-add'); ?>">Create your first message</a>
                                    </p>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Import Section -->
                <div class="import-section">
                    <div class="postbox">
                        <h2><span>📥 Import Messages</span></h2>
                        <div class="inside">
                            <p>Import messages from a previously exported JSON file.</p>

                            <div class="import-warning">
                                <p><strong>⚠️ Important:</strong></p>
                                <ul>
                                    <li>Only import files exported from Message Admin plugin</li>
                                    <li>Imported messages will be added as new messages (duplicates possible)</li>
                                    <li>All imported messages will be set to inactive by default</li>
                                    <li>Large files may take time to process</li>
                                </ul>
                            </div>

                            <form method="post"
                                action="<?php echo admin_url('admin-post.php'); ?>"
                                enctype="multipart/form-data"
                                class="import-form">
                                <input type="hidden" name="action" value="import_messages">
                                <?php wp_nonce_field('import_messages'); ?>

                                <p>
                                    <label for="import_file">Select JSON file to import:</label><br>
                                    <input type="file"
                                        id="import_file"
                                        name="import_file"
                                        accept=".json"
                                        required>
                                </p>

                                <p>
                                    <label>
                                        <input type="checkbox" name="activate_imported" value="1">
                                        Activate imported messages immediately
                                    </label>
                                </p>

                                <p>
                                    <input type="submit"
                                        class="button button-secondary"
                                        value="🚀 Import Messages"
                                        onclick="return confirm('Are you sure you want to import these messages? This action cannot be undone.');">
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sample Export Format -->
            <div class="export-format-info">
                <h3>📋 Export File Format</h3>
                <p>The export file contains the following structure:</p>
                <pre style="background: #f1f1f1; padding: 15px; border-radius: 5px; overflow-x: auto;"><code>{
    "plugin_version": "2.0.0",
    "export_date": "2024-01-15 10:30:00",
    "messages": [
        {
            "title": "Welcome Message",
            "content": "Welcome to our website!",
            "position": "before_content",
            "status": "active",
            "display_pages": "[\"all\"]",
            "user_roles": "[\"all\"]",
            "start_date": null,
            "end_date": null,
            "message_type": "info",
            "dismissible": 1
        }
    ]
}</code></pre>
            </div>

            <!-- Tips -->
            <div class="import-export-tips">
                <h3>💡 Tips & Best Practices</h3>
                <div class="tips-grid">
                    <div class="tip-item">
                        <h4>🔒 Backup Regularly</h4>
                        <p>Export your messages regularly as part of your site backup routine.</p>
                    </div>
                    <div class="tip-item">
                        <h4>🔄 Migration</h4>
                        <p>Use export/import to move messages between development, staging, and production sites.</p>
                    </div>
                    <div class="tip-item">
                        <h4>📝 Version Control</h4>
                        <p>Keep exported files in version control to track changes over time.</p>
                    </div>
                    <div class="tip-item">
                        <h4>🧪 Testing</h4>
                        <p>Import messages as inactive first, then review and activate them manually.</p>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .import-export-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin: 20px 0;
            }

            .export-section,
            .import-section {
                background: #fff;
            }

            .export-stats {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
            }

            .import-warning {
                background: #fff3cd;
                border: 1px solid #ffc107;
                border-radius: 5px;
                padding: 15px;
                margin: 15px 0;
            }

            .import-warning ul {
                margin: 10px 0 0 20px;
            }

            .export-format-info {
                margin: 30px 0;
                background: #fff;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .tips-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .tip-item {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #0073aa;
            }

            .tip-item h4 {
                margin: 0 0 10px 0;
                color: #0073aa;
            }

            .tip-item p {
                margin: 0;
                color: #555;
            }

            @media (max-width: 768px) {
                .import-export-container {
                    grid-template-columns: 1fr;
                }
            }
        </style>
<?php
    }

    /**
     * AJAX handler for clearing specific cache
     */
    public function ajax_clear_specific_cache()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'clear_cache') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $cache_type = sanitize_text_field($_POST['cache_type']);

        switch ($cache_type) {
            case 'dashboard':
                delete_transient('message_admin_dashboard_stats');
                break;
            case 'analytics':
                delete_transient('message_admin_analytics');
                break;
            case 'messages':
                delete_transient('message_admin_all_messages');
                break;
            default:
                wp_send_json_error('Invalid cache type');
        }

        wp_send_json_success('Cache cleared successfully');
    }

    /**
     * AJAX handler for clearing all cache
     */
    public function ajax_clear_all_cache()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'clear_cache') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $this->clear_cache();
        wp_send_json_success('All cache cleared successfully');
    }

    /**
     * AJAX handler for database optimization
     */
    public function ajax_optimize_database()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'optimize_db') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->optimize_database();

        if ($result) {
            wp_send_json_success('Database optimized successfully');
        } else {
            wp_send_json_error('Database optimization failed');
        }
    }

    /**
     * Register AJAX handlers
     */
    public function register_ajax_handlers()
    {
        add_action('wp_ajax_clear_specific_cache', array($this, 'ajax_clear_specific_cache'));
        add_action('wp_ajax_clear_all_cache', array($this, 'ajax_clear_all_cache'));
        add_action('wp_ajax_optimize_message_database', array($this, 'ajax_optimize_database'));
        add_action('wp_ajax_clear_message_admin_cache', array($this, 'ajax_clear_all_cache'));
        add_action('wp_ajax_clear_analytics_cache', array($this, 'ajax_clear_analytics_cache'));
    }

    /**
     * AJAX handler for clearing analytics cache specifically
     */
    public function ajax_clear_analytics_cache()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'clear_analytics_cache') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        delete_transient('message_admin_analytics');
        wp_send_json_success('Analytics cache cleared');
    }
}

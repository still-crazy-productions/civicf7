<?php
/**
 * Plugin Name: CF7 CiviCRM Integration
 * Plugin URI: https://github.com/still-crazy-productions/civicf7
 * Description: Integrates Contact Form 7 with CiviCRM API v4
 * Version: 1.0.0
 * Author: Ramon Dailey
 * Author URI: https://github.com/still-crazy-productions
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-civicrm-integration
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Enable debugging
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

// Define plugin constants
define('CF7_CIVICRM_VERSION', '1.0.0');
define('CF7_CIVICRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF7_CIVICRM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Error logging function
function cf7_civicrm_log_error($message) {
    if (is_wp_error($message)) {
        $message = $message->get_error_message();
    }
    error_log('CF7 CiviCRM Integration Error: ' . $message);
}

// Include required files
try {
    if (!file_exists(CF7_CIVICRM_PLUGIN_DIR . 'includes/class-cf7-civicrm-admin.php')) {
        throw new Exception('Admin class file not found');
    }
    require_once CF7_CIVICRM_PLUGIN_DIR . 'includes/class-cf7-civicrm-admin.php';

    if (!file_exists(CF7_CIVICRM_PLUGIN_DIR . 'includes/class-cf7-civicrm-form-handler.php')) {
        throw new Exception('Form handler class file not found');
    }
    require_once CF7_CIVICRM_PLUGIN_DIR . 'includes/class-cf7-civicrm-form-handler.php';
} catch (Exception $e) {
    cf7_civicrm_log_error($e->getMessage());
    return;
}

// Initialize the plugin
function cf7_civicrm_init() {
    try {
        // Check if Contact Form 7 is active
        if (!class_exists('WPCF7')) {
            throw new Exception('Contact Form 7 is not installed or activated');
        }

        // Check if CiviCRM is active
        if (!function_exists('civicrm_initialize')) {
            throw new Exception('CiviCRM is not installed or activated');
        }

        // Initialize admin first
        new CF7_Civicrm_Admin();
        
        // Initialize form handler
        new CF7_Civicrm_Form_Handler();

        // Add filter to modify default Contact Form 7 template
        add_filter('wpcf7_default_template', function($template) {
            return '[contact-form-7 id="new" title="Contact Form"]' . "\n\n" .
                '<p>' . "\n" .
                '    [text* first_name "First Name"]' . "\n" .
                '</p>' . "\n\n" .
                '<p>' . "\n" .
                '    [text* last_name "Last Name"]' . "\n" .
                '</p>' . "\n\n" .
                '<p>' . "\n" .
                '    [email* email "Email Address"]' . "\n" .
                '</p>' . "\n\n" .
                '<p>' . "\n" .
                '    [text phone "Phone Number"]' . "\n" .
                '</p>' . "\n\n" .
                '<p>' . "\n" .
                '    [textarea message "Your Message"]' . "\n" .
                '</p>' . "\n\n" .
                '<p>' . "\n" .
                '    [submit "Send Message"]' . "\n" .
                '</p>';
        });

        // Add filter to modify default mail template
        add_filter('wpcf7_mail_template', function($template) {
            $template['subject'] = 'New Contact Form Submission from [first_name] [last_name]';
            $template['body'] = "From: [first_name] [last_name] <[email]>\n\n" .
                "Phone: [phone]\n\n" .
                "Message:\n[message]\n\n" .
                "-- \nThis e-mail was sent from a contact form on " . get_bloginfo('name') . " (" . get_bloginfo('url') . ")";
            return $template;
        });

    } catch (Exception $e) {
        cf7_civicrm_log_error($e->getMessage());
        add_action('admin_notices', function() use ($e) {
            ?>
            <div class="notice notice-error">
                <p><?php printf(__('CF7 CiviCRM Integration Error: %s', 'cf7-civicrm-integration'), esc_html($e->getMessage())); ?></p>
            </div>
            <?php
        });
    }
}
add_action('plugins_loaded', 'cf7_civicrm_init');

// Initialize CiviCRM after WordPress is fully loaded
function cf7_civicrm_initialize_civicrm() {
    try {
        if (function_exists('civicrm_initialize')) {
            civicrm_initialize();
            
            // Check if API v4 is available
            if (!function_exists('civicrm_api4')) {
                throw new Exception('CiviCRM API v4 is not available');
            }
        }
    } catch (Exception $e) {
        cf7_civicrm_log_error($e->getMessage());
    }
}
add_action('init', 'cf7_civicrm_initialize_civicrm', 1);

// Activation hook
register_activation_hook(__FILE__, 'cf7_civicrm_activate');
function cf7_civicrm_activate() {
    try {
        // Check if Contact Form 7 is active
        if (!class_exists('WPCF7')) {
            throw new Exception('Contact Form 7 is not installed or activated');
        }

        // Check if CiviCRM is active
        if (!function_exists('civicrm_initialize')) {
            throw new Exception('CiviCRM is not installed or activated');
        }

        // Add default options
        add_option('cf7_civicrm_settings', array(
            'civicrm_url' => '',
            'api_key' => '',
            'site_key' => '',
        ));

    } catch (Exception $e) {
        cf7_civicrm_log_error('Activation Error: ' . $e->getMessage());
        // Deactivate the plugin
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die($e->getMessage(), 'Plugin Activation Error', array('back_link' => true));
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'cf7_civicrm_deactivate');
function cf7_civicrm_deactivate() {
    // Delete all plugin options
    delete_option('cf7_civicrm_settings');
    
    // Clear any transients that might be storing API credentials
    delete_transient('cf7_civicrm_api_credentials');
    
    // Clear any cached API responses
    delete_transient('cf7_civicrm_api_test');
}

// Uninstall hook - this runs when the plugin is deleted
register_uninstall_hook(__FILE__, 'cf7_civicrm_uninstall');
function cf7_civicrm_uninstall() {
    // Delete all plugin options
    delete_option('cf7_civicrm_settings');
    
    // Clear any transients
    delete_transient('cf7_civicrm_api_credentials');
    delete_transient('cf7_civicrm_api_test');
    
    // Delete any post meta data for all forms
    global $wpdb;
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_cf7_civicrm_settings'));
} 
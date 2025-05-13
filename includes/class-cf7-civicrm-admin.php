<?php

class CF7_Civicrm_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wpcf7_admin_misc_pub_section', array($this, 'add_civicrm_tab'));
        add_action('wpcf7_save_contact_form', array($this, 'save_civicrm_settings'), 10, 1);
        add_action('wpcf7_editor_panels', array($this, 'add_civicrm_panel'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
    }

    private function test_civicrm_connection($settings) {
        if (!isset($settings['civicrm_url']) || !isset($settings['api_key']) || !isset($settings['site_key'])) {
            add_settings_error(
                'cf7_civicrm_settings',
                'missing_credentials',
                'Please provide all required CiviCRM credentials.'
            );
            return $settings;
        }

        // Initialize CiviCRM if not already initialized
        if (!defined('CIVICRM_INITIALIZED')) {
            civicrm_initialize();
        }

        // Check if API v4 is available
        if (!function_exists('civicrm_api4')) {
            add_settings_error(
                'cf7_civicrm_settings',
                'api_not_available',
                'CiviCRM API v4 is not available.'
            );
            return $settings;
        }

        try {
            // Test the connection by trying to get a single contact
            $result = civicrm_api4('Contact', 'get', [
                'limit' => 1,
                'checkPermissions' => false
            ]);

            // Check if the result is valid
            if (!is_object($result)) {
                throw new Exception('Invalid response from CiviCRM API');
            }

            // If we get here, the connection is successful
            add_settings_error(
                'cf7_civicrm_settings',
                'connection_success',
                'Successfully connected to CiviCRM.',
                'success'
            );

        } catch (Exception $e) {
            add_settings_error(
                'cf7_civicrm_settings',
                'connection_failed',
                'Failed to connect to CiviCRM: ' . $e->getMessage()
            );
        }

        return $settings;
    }

    public function register_settings() {
        register_setting('cf7_civicrm_settings', 'cf7_civicrm_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }

    public function sanitize_settings($input) {
        $test_result = $this->test_civicrm_connection($input);
        
        // Store the test result in a transient
        set_transient('cf7_civicrm_connection_test', $test_result, 45);
        
        return $input;
    }

    public function display_admin_notices() {
        // Get any settings errors
        $settings_errors = get_settings_errors('cf7_civicrm_settings');
        
        if (!empty($settings_errors)) {
            foreach ($settings_errors as $error) {
                $class = ($error['type'] === 'success') ? 'notice-success' : 'notice-error';
                ?>
                <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
                    <p><?php echo esc_html($error['message']); ?></p>
                </div>
                <?php
            }
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('CF7 CiviCRM Settings', 'cf7-civicrm-integration'),
            __('CF7 CiviCRM', 'cf7-civicrm-integration'),
            'manage_options',
            'cf7-civicrm-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('cf7_civicrm_settings');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('cf7_civicrm_settings');
                do_settings_sections('cf7_civicrm_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="civicrm_url"><?php _e('CiviCRM API v4 URL', 'cf7-civicrm-integration'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="civicrm_url" name="cf7_civicrm_settings[civicrm_url]" 
                                value="<?php echo esc_attr($settings['civicrm_url'] ?? ''); ?>" class="regular-text" required>
                            <p class="description">
                                <?php _e('The URL should end with: /wp-content/plugins/civicrm/civicrm/extern/api4.php', 'cf7-civicrm-integration'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php _e('API Key', 'cf7-civicrm-integration'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="api_key" name="cf7_civicrm_settings[api_key]" 
                                value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" required>
                            <p class="description">
                                <?php _e('You can find this in CiviCRM under Administer > System Settings > API Keys', 'cf7-civicrm-integration'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="site_key"><?php _e('Site Key', 'cf7-civicrm-integration'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="site_key" name="cf7_civicrm_settings[site_key]" 
                                value="<?php echo esc_attr($settings['site_key'] ?? ''); ?>" class="regular-text" required>
                            <p class="description">
                                <?php _e('You can find this in CiviCRM under Administer > System Settings > API Keys', 'cf7-civicrm-integration'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings & Test Connection', 'cf7-civicrm-integration')); ?>
            </form>
        </div>
        <?php
    }

    public function add_civicrm_tab($contact_form) {
        // Handle both post ID and Contact Form 7 object
        $post_id = is_object($contact_form) ? $contact_form->id() : $contact_form;
        $civicrm_settings = get_post_meta($post_id, '_cf7_civicrm_settings', true);
        ?>
        <div class="misc-pub-section">
            <label>
                <input type="checkbox" name="cf7_civicrm_enabled" value="1" 
                    <?php checked(isset($civicrm_settings['enabled']) && $civicrm_settings['enabled']); ?>>
                <?php _e('Enable CiviCRM Integration', 'cf7-civicrm-integration'); ?>
            </label>
        </div>
        <?php
    }

    public function add_civicrm_panel($panels) {
        $panels['civicrm-panel'] = array(
            'title' => __('CiviCRM Integration', 'cf7-civicrm-integration'),
            'callback' => array($this, 'render_civicrm_panel')
        );
        return $panels;
    }

    public function render_civicrm_panel($contact_form) {
        // Handle both post ID and Contact Form 7 object
        $post_id = is_object($contact_form) ? $contact_form->id() : $contact_form;
        $civicrm_settings = get_post_meta($post_id, '_cf7_civicrm_settings', true);
        ?>
        <div class="cf7-civicrm-panel">
            <h2><?php _e('CiviCRM API v4 Settings', 'cf7-civicrm-integration'); ?></h2>
            
            <fieldset>
                <legend><?php _e('API Action', 'cf7-civicrm-integration'); ?></legend>
                <select name="cf7_civicrm_settings[action]">
                    <option value="Contact.create" <?php selected(isset($civicrm_settings['action']) && $civicrm_settings['action'] === 'Contact.create'); ?>>
                        <?php _e('Create Contact', 'cf7-civicrm-integration'); ?>
                    </option>
                </select>
            </fieldset>

            <fieldset>
                <legend><?php _e('Field Mapping', 'cf7-civicrm-integration'); ?></legend>
                <p class="description">
                    <?php _e('Map your form fields to CiviCRM fields. The following fields are required for creating a contact:', 'cf7-civicrm-integration'); ?>
                </p>
                <ul class="description" style="list-style-type: disc; margin-left: 20px;">
                    <li><?php _e('First Name (first_name)', 'cf7-civicrm-integration'); ?></li>
                    <li><?php _e('Last Name (last_name)', 'cf7-civicrm-integration'); ?></li>
                    <li><?php _e('Email (email)', 'cf7-civicrm-integration'); ?></li>
                </ul>
                <p class="description">
                    <?php _e('Example mapping (one per line):', 'cf7-civicrm-integration'); ?>
                </p>
                <pre class="description" style="background: #f0f0f0; padding: 10px; margin: 10px 0;">
first_name = first_name
last_name = last_name
email = email
contact_type = Individual</pre>
                <textarea name="cf7_civicrm_settings[field_mapping]" rows="5" class="large-text"><?php 
                    echo esc_textarea($civicrm_settings['field_mapping'] ?? "first_name = first_name\nlast_name = last_name\nemail = email\ncontact_type = Individual"); 
                ?></textarea>
            </fieldset>
        </div>
        <?php
    }

    public function save_civicrm_settings($contact_form) {
        // Handle both post ID and Contact Form 7 object
        $post_id = is_object($contact_form) ? $contact_form->id() : $contact_form;
        
        $civicrm_settings = array(
            'enabled' => isset($_POST['cf7_civicrm_enabled']),
            'action' => sanitize_text_field($_POST['cf7_civicrm_settings']['action'] ?? ''),
            'field_mapping' => sanitize_textarea_field($_POST['cf7_civicrm_settings']['field_mapping'] ?? '')
        );

        update_post_meta($post_id, '_cf7_civicrm_settings', $civicrm_settings);
    }
} 
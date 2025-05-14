<?php

class CF7_Civicrm_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wpcf7_save_contact_form', array($this, 'save_civicrm_settings'), 10, 1);
        add_action('wpcf7_editor_panels', array($this, 'add_civicrm_panel'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
        add_action('admin_init', array($this, 'handle_clear_credentials'));
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

        error_log('CF7 CiviCRM Integration: Testing connection with credentials - Site Key: ' . $settings['site_key'] . ', API Key: ' . $settings['api_key']);

        try {
            // Use the REST API endpoint
            $endpoint = str_replace('api4.php', 'rest.php', $settings['civicrm_url']);
            error_log('CF7 CiviCRM Integration: Testing endpoint: ' . $endpoint);

            $payload = [
                'entity' => 'Contact',
                'action' => 'get',
                'params' => [
                    'select' => ['id'],
                    'where' => [
                        ['contact_type', '=', 'Individual'],
                        ['is_deleted', '=', 0]
                    ],
                    'limit' => 1
                ],
                'api_key' => $settings['api_key'],
                'key' => $settings['site_key']
            ];

            error_log('CF7 CiviCRM Integration: Sending request with payload: ' . print_r($payload, true));

            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'body' => json_encode($payload),
                'timeout' => 10,
                'sslverify' => false // Temporarily disable SSL verification for testing
            ]);

            if (is_wp_error($response)) {
                error_log('CF7 CiviCRM Integration: WP Error: ' . $response->get_error_message());
                throw new Exception('HTTP error: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);
            
            error_log('CF7 CiviCRM Integration: Response code: ' . $response_code);
            error_log('CF7 CiviCRM Integration: Response headers: ' . print_r($response_headers, true));
            error_log('CF7 CiviCRM Integration: Raw response: ' . $response_body);

            // Check if response is empty
            if (empty($response_body)) {
                throw new Exception('Empty response from CiviCRM API.');
            }

            // Check content type to determine if response is XML
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if (strpos($content_type, 'xml') !== false) {
                // Parse XML response
                $xml = simplexml_load_string($response_body);
                if ($xml === false) {
                    throw new Exception('Failed to parse XML response from CiviCRM');
                }
                
                // Check for error in XML response
                if (isset($xml->Result->is_error) && (string)$xml->Result->is_error === '1') {
                    throw new Exception('CiviCRM API error: ' . (string)$xml->Result->error_message);
                }
            } else {
                // Try to decode as JSON
                $body = json_decode($response_body);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('CF7 CiviCRM Integration: JSON decode error: ' . json_last_error_msg());
                    throw new Exception('Invalid JSON response from CiviCRM: ' . json_last_error_msg());
                }

                error_log('CF7 CiviCRM Integration: Decoded response: ' . print_r($body, true));

                // Check for API error response
                if (isset($body->is_error) && $body->is_error) {
                    throw new Exception('CiviCRM API error: ' . ($body->error_message ?? 'Unknown error'));
                }
            }

            // Success!
            add_settings_error(
                'cf7_civicrm_settings',
                'connection_success',
                'Successfully connected to CiviCRM via API credentials.',
                'success'
            );

        } catch (Exception $e) {
            error_log('CF7 CiviCRM Integration: Connection test failed - ' . $e->getMessage());
            error_log('CF7 CiviCRM Integration: Full error details: ' . print_r($e, true));
            
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

        // Add action for test connection button
        add_action('admin_init', array($this, 'handle_test_connection'));
    }

    public function handle_test_connection() {
        if (isset($_POST['test_connection'])) {
            // Get the submitted values instead of stored settings
            $settings = array(
                'civicrm_url' => sanitize_text_field($_POST['cf7_civicrm_settings']['civicrm_url'] ?? ''),
                'api_key' => sanitize_text_field($_POST['cf7_civicrm_settings']['api_key'] ?? ''),
                'site_key' => sanitize_text_field($_POST['cf7_civicrm_settings']['site_key'] ?? '')
            );
            
            error_log('CF7 CiviCRM Integration: Testing connection with submitted credentials - Site Key: ' . $settings['site_key'] . ', API Key: ' . $settings['api_key']);
            
            $this->test_civicrm_connection($settings);
        }
    }

    public function sanitize_settings($input) {
        // Only test connection if the test button was clicked
        if (isset($_POST['test_connection'])) {
            return $this->test_civicrm_connection($input);
        }
        
        // Otherwise just return the sanitized input
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
                <?php 
                submit_button(__('Save Settings', 'cf7-civicrm-integration'), 'primary', 'submit', true, array('id' => 'submit'));
                submit_button(__('Test Connection', 'cf7-civicrm-integration'), 'secondary', 'test_connection', false, array('id' => 'test_connection'));
                ?>
            </form>

            <hr>

            <h2><?php _e('Clear Credentials', 'cf7-civicrm-integration'); ?></h2>
            <p class="description">
                <?php _e('Use this button to clear all stored CiviCRM credentials. This will stop the integration from working until new credentials are entered.', 'cf7-civicrm-integration'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('cf7_civicrm_clear_credentials'); ?>
                <p class="submit">
                    <input type="submit" name="clear_credentials" class="button button-secondary" 
                        value="<?php esc_attr_e('Clear Credentials', 'cf7-civicrm-integration'); ?>"
                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all CiviCRM credentials? This will stop the integration from working until new credentials are entered.', 'cf7-civicrm-integration'); ?>');">
                </p>
            </form>
        </div>
        <?php
    }

    public function add_civicrm_panel($panels) {
        $panels['civicrm-panel'] = array(
            'title' => __('CiviCRM', 'cf7-civicrm-integration'),
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
                <legend><?php _e('Enable Integration', 'cf7-civicrm-integration'); ?></legend>
                <label>
                    <input type="checkbox" name="cf7_civicrm_enabled" value="1" 
                        <?php checked(isset($civicrm_settings['enabled']) && $civicrm_settings['enabled']); ?>>
                    <?php _e('Enable CiviCRM Integration for this form', 'cf7-civicrm-integration'); ?>
                </label>
            </fieldset>

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
        
        error_log('CF7 CiviCRM Integration: Saving settings for form ID: ' . $post_id);
        error_log('CF7 CiviCRM Integration: POST data: ' . print_r($_POST, true));
        
        $civicrm_settings = array(
            'enabled' => isset($_POST['cf7_civicrm_enabled']),
            'action' => sanitize_text_field($_POST['cf7_civicrm_settings']['action'] ?? ''),
            'field_mapping' => sanitize_textarea_field($_POST['cf7_civicrm_settings']['field_mapping'] ?? '')
        );

        error_log('CF7 CiviCRM Integration: Saving settings: ' . print_r($civicrm_settings, true));
        
        update_post_meta($post_id, '_cf7_civicrm_settings', $civicrm_settings);
        
        // Verify the settings were saved
        $saved_settings = get_post_meta($post_id, '_cf7_civicrm_settings', true);
        error_log('CF7 CiviCRM Integration: Verified saved settings: ' . print_r($saved_settings, true));
    }

    public function save_settings() {
        if (!isset($_POST['cf7_civicrm_settings_nonce']) || 
            !wp_verify_nonce($_POST['cf7_civicrm_settings_nonce'], 'cf7_civicrm_settings')) {
            return;
        }

        error_log('CF7 CiviCRM Integration: Saving settings - POST data: ' . print_r($_POST, true));

        $settings = array(
            'civicrm_url' => sanitize_text_field($_POST['civicrm_url']),
            'api_key' => sanitize_text_field($_POST['api_key']),
            'site_key' => sanitize_text_field($_POST['site_key'])
        );

        error_log('CF7 CiviCRM Integration: Sanitized settings to save: ' . print_r($settings, true));

        // Clear any cached credentials
        delete_transient('cf7_civicrm_api_credentials');
        delete_transient('cf7_civicrm_api_test');
        
        error_log('CF7 CiviCRM Integration: Cleared credential transients');

        update_option('cf7_civicrm_settings', $settings);
        
        error_log('CF7 CiviCRM Integration: Settings saved to database');
        
        // Verify the settings were saved correctly
        $saved_settings = get_option('cf7_civicrm_settings');
        error_log('CF7 CiviCRM Integration: Verified saved settings: ' . print_r($saved_settings, true));
    }

    public function handle_clear_credentials() {
        if (isset($_POST['clear_credentials']) && check_admin_referer('cf7_civicrm_clear_credentials')) {
            // Delete the settings
            delete_option('cf7_civicrm_settings');
            
            // Clear any cached credentials
            delete_transient('cf7_civicrm_api_credentials');
            delete_transient('cf7_civicrm_api_test');
            
            error_log('CF7 CiviCRM Integration: Cleared all stored credentials and transients');
            
            // Add success message
            add_settings_error(
                'cf7_civicrm_settings',
                'credentials_cleared',
                'All CiviCRM credentials have been cleared.',
                'success'
            );
            
            // Redirect to remove the POST data
            wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
            exit;
        }
    }
} 
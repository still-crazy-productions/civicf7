<?php

class CF7_Civicrm_Form_Handler {
    public function __construct() {
        add_action('wpcf7_mail_sent', array($this, 'handle_form_submission'));
    }

    public function handle_form_submission($contact_form) {
        try {
            $post_id = $contact_form->id();
            $civicrm_settings = get_post_meta($post_id, '_cf7_civicrm_settings', true);

            error_log('CF7 CiviCRM Integration: Form submission started for form ID: ' . $post_id);
            error_log('CF7 CiviCRM Integration: Settings: ' . print_r($civicrm_settings, true));

            // Check if CiviCRM integration is enabled for this form
            if (!isset($civicrm_settings['enabled']) || !$civicrm_settings['enabled']) {
                error_log('CF7 CiviCRM Integration: Integration not enabled for this form');
                return;
            }

            // Get form submission data
            $submission = WPCF7_Submission::get_instance();
            if (!$submission) {
                throw new Exception('Could not get form submission data');
            }

            $data = $submission->get_posted_data();
            error_log('CF7 CiviCRM Integration: Form data: ' . print_r($data, true));
            
            // Parse field mapping
            $field_mapping = $this->parse_field_mapping($civicrm_settings['field_mapping']);
            error_log('CF7 CiviCRM Integration: Field mapping: ' . print_r($field_mapping, true));
            
            // Prepare data for CiviCRM API
            $civicrm_data = $this->prepare_civicrm_data($data, $field_mapping);
            error_log('CF7 CiviCRM Integration: Prepared CiviCRM data: ' . print_r($civicrm_data, true));
            
            if ($civicrm_data === false) {
                throw new Exception('Required fields are missing');
            }
            
            // Make API call
            $result = $this->call_civicrm_api($civicrm_settings['action'], $civicrm_data);
            error_log('CF7 CiviCRM Integration: API call result: ' . print_r($result, true));
            
            if ($result === false) {
                throw new Exception('Failed to create CiviCRM contact');
            }
            
        } catch (Exception $e) {
            error_log('CF7 CiviCRM Integration Error: ' . $e->getMessage());
            // You might want to add a filter here to allow other plugins to handle the error
            do_action('cf7_civicrm_error', $e->getMessage(), $contact_form);
        }
    }

    private function parse_field_mapping($mapping_text) {
        $mapping = array();
        $lines = explode("\n", $mapping_text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $parts = explode('=', $line);
            if (count($parts) === 2) {
                $form_field = trim($parts[0]);
                $civicrm_field = trim($parts[1]);
                $mapping[$form_field] = $civicrm_field;
            }
        }
        
        return $mapping;
    }

    private function prepare_civicrm_data($form_data, $field_mapping) {
        $civicrm_data = array();
        
        // Always set contact type to Individual
        $civicrm_data['contact_type'] = 'Individual';
        
        error_log('CF7 CiviCRM Integration: Preparing data with mapping: ' . print_r($field_mapping, true));
        error_log('CF7 CiviCRM Integration: Form data to map: ' . print_r($form_data, true));
        
        foreach ($field_mapping as $form_field => $civicrm_field) {
            error_log("CF7 CiviCRM Integration: Processing field mapping - Form field: $form_field, CiviCRM field: $civicrm_field");
            if (isset($form_data[$form_field])) {
                // Special handling for email field
                if ($civicrm_field === 'email') {
                    $civicrm_data['email'] = array(
                        array(
                            'email' => $form_data[$form_field],
                            'is_primary' => 1,
                            'location_type_id' => 1 // Main location type
                        )
                    );
                    error_log("CF7 CiviCRM Integration: Added email: " . $form_data[$form_field]);
                } else {
                    $civicrm_data[$civicrm_field] = $form_data[$form_field];
                    error_log("CF7 CiviCRM Integration: Added field $civicrm_field: " . $form_data[$form_field]);
                }
            } else {
                error_log("CF7 CiviCRM Integration: Form field not found: $form_field");
            }
        }
        
        // Validate required fields
        if (empty($civicrm_data['first_name']) || empty($civicrm_data['last_name']) || empty($civicrm_data['email'])) {
            error_log('CF7 CiviCRM Integration: Missing required fields for contact creation');
            error_log('CF7 CiviCRM Integration: Current data: ' . print_r($civicrm_data, true));
            return false;
        }
        
        return $civicrm_data;
    }

    private function call_civicrm_api($action, $data) {
        // Check if CiviCRM is initialized
        if (!function_exists('civicrm_initialize')) {
            error_log('CF7 CiviCRM Integration: CiviCRM is not initialized');
            return false;
        }
        
        try {
            // Get the CiviCRM settings
            $settings = get_option('cf7_civicrm_settings');
            error_log('CF7 CiviCRM Integration: Raw settings from database: ' . print_r($settings, true));
            
            // Check if we're in a Docker environment
            $is_docker = file_exists('/.dockerenv');
            error_log('CF7 CiviCRM Integration: Running in Docker: ' . ($is_docker ? 'Yes' : 'No'));
            
            // Check if CiviCRM is already initialized
            error_log('CF7 CiviCRM Integration: CIVICRM_INITIALIZED defined: ' . (defined('CIVICRM_INITIALIZED') ? 'Yes' : 'No'));
            
            // Log the current request parameters before modification
            error_log('CF7 CiviCRM Integration: Original request parameters: ' . print_r($_REQUEST, true));
            
            // Store the current request parameters
            $current_key = isset($_REQUEST['key']) ? $_REQUEST['key'] : null;
            $current_api_key = isset($_REQUEST['api_key']) ? $_REQUEST['api_key'] : null;
            
            error_log('CF7 CiviCRM Integration: Current request parameters - key: ' . $current_key . ', api_key: ' . $current_api_key);

            // Set the API credentials
            $_REQUEST['key'] = $settings['site_key'];
            $_REQUEST['api_key'] = $settings['api_key'];
            
            error_log('CF7 CiviCRM Integration: Set request parameters - key: ' . $_REQUEST['key'] . ', api_key: ' . $_REQUEST['api_key']);
            
            // Initialize CiviCRM if not already initialized
            if (!defined('CIVICRM_INITIALIZED')) {
                error_log('CF7 CiviCRM Integration: Initializing CiviCRM');
                civicrm_initialize();
                error_log('CF7 CiviCRM Integration: CiviCRM initialized');
            } else {
                error_log('CF7 CiviCRM Integration: CiviCRM already initialized');
            }
            
            // Check if API v4 is available
            if (!function_exists('civicrm_api4')) {
                throw new Exception('CiviCRM API v4 is not available');
            }
            
            // Parse the action (e.g., "Contact.create")
            list($entity, $operation) = explode('.', $action);
            
            error_log('CF7 CiviCRM Integration: Making API call - Entity: ' . $entity . ', Operation: ' . $operation);
            error_log('CF7 CiviCRM Integration: API call data: ' . print_r($data, true));
            
            // Make the API call using CiviCRM API v4
            $result = civicrm_api4($entity, $operation, [
                'values' => $data,
                'checkPermissions' => false
            ]);
            
            error_log('CF7 CiviCRM Integration: API call result: ' . print_r($result, true));
            
            // Check if the result is valid
            if (!is_object($result)) {
                error_log('CF7 Civicrm Integration API Error: Invalid response from API');
                return false;
            }
            
            // For create operations, we expect a result with the created entity
            if ($operation === 'create' && !$result->first()) {
                error_log('CF7 Civicrm Integration API Error: Failed to create entity');
                return false;
            }
            
            // Restore original request parameters
            $_REQUEST['key'] = $current_key;
            $_REQUEST['api_key'] = $current_api_key;
            
            error_log('CF7 CiviCRM Integration: Restored request parameters - key: ' . $_REQUEST['key'] . ', api_key: ' . $_REQUEST['api_key']);
            
            return $result;
            
        } catch (Exception $e) {
            // Restore original request parameters
            $_REQUEST['key'] = $current_key;
            $_REQUEST['api_key'] = $current_api_key;

            error_log('CF7 Civicrm Integration Exception: ' . $e->getMessage());
            return false;
        }
    }
} 
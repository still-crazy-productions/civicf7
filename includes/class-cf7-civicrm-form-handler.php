<?php

class CF7_Civicrm_Form_Handler {
    public function __construct() {
        add_action('wpcf7_mail_sent', array($this, 'handle_form_submission'));
    }

    public function handle_form_submission($contact_form) {
        try {
            $post_id = $contact_form->id();
            $civicrm_settings = get_post_meta($post_id, '_cf7_civicrm_settings', true);

            // Check if CiviCRM integration is enabled for this form
            if (!isset($civicrm_settings['enabled']) || !$civicrm_settings['enabled']) {
                return;
            }

            // Get form submission data
            $submission = WPCF7_Submission::get_instance();
            if (!$submission) {
                throw new Exception('Could not get form submission data');
            }

            $data = $submission->get_posted_data();
            
            // Parse field mapping
            $field_mapping = $this->parse_field_mapping($civicrm_settings['field_mapping']);
            
            // Prepare data for CiviCRM API
            $civicrm_data = $this->prepare_civicrm_data($data, $field_mapping);
            
            if ($civicrm_data === false) {
                throw new Exception('Required fields are missing');
            }
            
            // Make API call
            $result = $this->call_civicrm_api($civicrm_settings['action'], $civicrm_data);
            
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
        
        foreach ($field_mapping as $form_field => $civicrm_field) {
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
                } else {
                    $civicrm_data[$civicrm_field] = $form_data[$form_field];
                }
            }
        }
        
        // Validate required fields
        if (empty($civicrm_data['first_name']) || empty($civicrm_data['last_name']) || empty($civicrm_data['email'])) {
            error_log('CF7 CiviCRM Integration: Missing required fields for contact creation');
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
            // Initialize CiviCRM if not already initialized
            if (!defined('CIVICRM_INITIALIZED')) {
                civicrm_initialize();
            }
            
            // Check if API v4 is available
            if (!function_exists('civicrm_api4')) {
                throw new Exception('CiviCRM API v4 is not available');
            }
            
            // Parse the action (e.g., "Contact.create")
            list($entity, $operation) = explode('.', $action);
            
            // Make the API call using CiviCRM API v4
            $result = civicrm_api4($entity, $operation, [
                'values' => $data,
                'checkPermissions' => false
            ]);
            
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

            // If contact was created successfully, add to Pending Applications group
            if ($operation === 'create' && $entity === 'Contact') {
                $contact_id = $result->first()['id'];
                
                // Add contact to Pending Applications group
                $group_result = civicrm_api4('GroupContact', 'create', [
                    'values' => [
                        'contact_id' => $contact_id,
                        'group_id' => 'Pending Applications', // This should be the name or ID of your Pending Applications group
                        'status' => 'Added'
                    ],
                    'checkPermissions' => false
                ]);

                if (!is_object($group_result)) {
                    error_log('CF7 Civicrm Integration: Failed to add contact to Pending Applications group');
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log('CF7 Civicrm Integration Exception: ' . $e->getMessage());
            return false;
        }
    }
} 
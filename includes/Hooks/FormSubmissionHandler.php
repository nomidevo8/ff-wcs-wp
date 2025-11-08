<?php
namespace FFWCS\Hooks;

if ( ! defined( 'ABSPATH' ) ) exit;

use FFWCS\Helpers\UserHelper;
use FFWCS\Services\SubscriptionCreator;

/**
 * Handles Fluent Forms submission events
 */
class FormSubmissionHandler {

    public function __construct() {
        add_action('fluentform/submission_inserted', [ $this, 'handle_submission' ], 10, 3);
        add_filter('fluentform/validate_input_item_billing_start_date', [ $this, 'validate_billing_start_date' ], 10, 4);
    }

    public function handle_submission($insertId, $insertData, $form) {
        error_log('FFWCS: Handling submission for form ID ' . intval($form->id));
        error_log("FFWCS: Submission ID: " . $insertId);
        error_log("FFWCS: Submission data: " . print_r($insertData, true));

        // load plugin settings
        $opts = get_option('ffwcs_settings', []);
        $form_id = isset($opts['form_id']) ? absint($opts['form_id']) : 0;
        if (!$form_id || intval($form->id) !== intval($form_id)) {
            return;
        }

        $map = isset($opts['field_map']) ? json_decode($opts['field_map'], true) : [];

        $get = function($key) use ($insertData) {
            if (!$key) return '';
            if (strpos($key, '.') === false) {
                return $insertData[$key] ?? '';
            }
            $parts = explode('.', $key);
            $cur = $insertData;
            foreach ($parts as $p) {
                if (is_array($cur) && isset($cur[$p])) {
                    $cur = $cur[$p];
                } elseif (is_object($cur) && isset($cur->{$p})) {
                    $cur = $cur->{$p};
                } else {
                    return '';
                }
            }
            return $cur;
        };

        // Check if user is already logged in
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            // User not logged in, get/create from form data
            $email = $get($map['email'] ?? '');
            if (empty($email)) return; // email required

            $first_name = $get($map['first_name'] ?? '');
            $last_name  = $get($map['last_name'] ?? '');

            // create or fetch user
            $userHelper = new UserHelper();
            $user_id = $userHelper->get_or_create_user($email, $first_name, $last_name);
        } else {
            error_log('FFWCS: Using logged-in user ID: ' . $user_id);
        }

        // Get or create appropriate product based on donation type
        $frequency = trim(strtolower($insertData[$map['billing_period'] ?? 'frequency'] ?? ''));
        
        // Get or create reusable products
        $product_id = $this->get_or_create_product($frequency);
        
        if (!$product_id) {
            error_log('FFWCS: Failed to get or create product.');
            return;
        }

        // Create subscriptions or orders
        $creator = new SubscriptionCreator();
        $checkout_url = $creator->create_from_submission($user_id, $product_id, $insertData, $map);
        
        // Redirect to checkout if URL is returned
        // if ($checkout_url) {
        //     wp_redirect($checkout_url);
        //     exit;
        // }
    }

    /**
     * Get or create reusable products (only 2 products total: one simple, one subscription)
     * 
     * @param string $frequency 'one time' or 'regularly'
     * @return int Product ID
     */
    private function get_or_create_product($frequency) {
        $option_key = ($frequency === 'one time') 
            ? 'ffwcs_onetime_product_id' 
            : 'ffwcs_subscription_product_id';
        
        // Check if product already exists in options
        $product_id = get_option($option_key, 0);
        
        // Verify product still exists
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                error_log('FFWCS: Using existing product. ID: ' . $product_id);
                return $product_id;
            }
        }
        
        // Create new product if doesn't exist
        if ($frequency === 'one time') {
            // Create simple product for one-time donations
            $product = new \WC_Product_Simple();
            $product->set_name('One-Time Donation');
            $product->set_status('publish');
            $product->set_regular_price(0); 
            $product->set_virtual(true);
            $product->set_downloadable(false);
            $product->set_sold_individually(true);
            $product_id = $product->save();
            error_log('FFWCS: Created simple product for one-time donations. ID: ' . $product_id);
        } else {
            // Create subscription product for recurring donations
            if (!class_exists('WC_Product_Subscription')) {
                error_log('FFWCS: WC_Product_Subscription class not found. Please install WooCommerce Subscriptions.');
                return 0;
            }
            
            $product = new \WC_Product_Subscription();
            $product->set_name('Recurring Donation Subscription');
            $product->set_status('publish');
            $product->set_regular_price(0);
            $product->set_virtual(true);
            $product->set_downloadable(false);
            $product->set_sold_individually(true);
            
            // Set default subscription parameters (will be overridden per donation)
            $product->update_meta_data('_subscription_period', 'month');
            $product->update_meta_data('_subscription_period_interval', '1');
            $product->update_meta_data('_subscription_length', '0'); // Never expires
            
            $product_id = $product->save();
            error_log('FFWCS: Created subscription product for recurring donations. ID: ' . $product_id);
        }
        
        // Save product ID to options for reuse
        update_option($option_key, $product_id);
        
        return $product_id;
    }

    public function validate_billing_start_date($error, $field, $formData, $form) {
        return null;
    }

}
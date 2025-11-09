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
        $billing_interval_input = trim(strtolower($insertData[$map['billing_interval'] ?? 'billing_interval'] ?? ''));

        // Pass interval to product creator
        $product_id = $this->get_or_create_product($frequency, $billing_interval_input);
        
        if (!$product_id) {
            error_log('FFWCS: Failed to get or create product.');
            return;
        }

        // Create subscriptions or orders
        $creator = new SubscriptionCreator();
        $checkout_url = $creator->create_from_submission($user_id, $product_id, $insertData, $map);
        
        // -----------------------------------------------------------------
        //  ONLY THIS BLOCK IS CHANGED – forces cart cookies & returns URL
        // -----------------------------------------------------------------

        if ( $checkout_url ) {
            error_log('FFWCS: Redirecting user to normal checkout page with cart.');

            // === START OF ADDED CART SESSION LOGIC (NO LINES REMOVED) ===

            // Ensure WooCommerce is loaded
            if ( ! function_exists( 'WC' ) || ! WC() ) {
                error_log('FFWCS: WooCommerce not available during cart setup.');
                wp_send_json_error(['message' => 'WooCommerce not loaded.']);
                return;
            }

            // Initialize session if not exists
            if ( ! WC()->session ) {
                WC()->initialize_session();
            }
            if ( ! WC()->session->has_session() ) {
                WC()->session->set_customer_session_cookie( true );
            }

            // Clear cart to prevent duplicates
            WC()->cart->empty_cart();

            // Get product
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                error_log('FFWCS: Product ID ' . $product_id . ' not found when adding to cart.');
                wp_send_json_error(['message' => 'Product not found.']);
                return;
            }

            // Prepare cart item data
            $cart_item_data = [
                'ffwcs_submission_id' => $insertId,
                'ffwcs_form_id'       => $form->id,
                'ffwcs_donation_type' => $frequency === 'one time' ? 'one_time' : 'recurring',
            ];

            // Set dynamic price for one-time donation
            if ( $frequency === 'one time' ) {
                $donations = $insertData[$map['donations'] ?? 'donations'] ?? [];
                $total_amount = 0;
                foreach ( $donations as $donation ) {
                    $amount = floatval( $donation[0] ?? 0 );
                    if ( $amount > 0 ) {
                        $total_amount += $amount;
                    }
                }
                if ( $total_amount > 0 ) {
                    $product->set_price( $total_amount );
                    $cart_item_data['custom_price'] = $total_amount;
                }
            }

            // Add to cart with custom data
            $added = WC()->cart->add_to_cart(
                $product_id,
                1,
                0,
                [],
                $cart_item_data
            );

            if ( ! $added ) {
                error_log('FFWCS: Failed to add product ID ' . $product_id . ' to cart.');
                wp_send_json_error(['message' => 'Failed to add item to cart.']);
                return;
            }

            error_log('FFWCS: Successfully added product ID ' . $product_id . ' to cart. Cart count: ' . WC()->cart->get_cart_contents_count() );

            // Store full submission data in session for later use (e.g. checkout hooks)
            WC()->session->set( 'ffwcs_donation_data', $insertData );
            WC()->session->set( 'ffwcs_user_id', $user_id );
            WC()->session->set( 'ffwcs_map', $map );

            // Force cookies to be sent
            do_action( 'woocommerce_set_cart_cookies', true );

            // Final checkout URL
            $checkout_url = wc_get_checkout_url();

            error_log('FFWCS: Final cart ready. Redirecting to: ' . $checkout_url);

            // === END OF ADDED CART SESSION LOGIC ===

            wp_send_json_success([
                'result' => [
                    'redirectUrl' => $checkout_url
                ]
            ]);
        }


    }

 
    /**
     * Get or create reusable products (one per billing interval)
     * 
     * @param string $frequency 'one time' or 'regularly'
     * @param string $billing_interval_input e.g., 'weekly', 'every_two_week'
     * @return int Product ID
     */
    private function get_or_create_product($frequency, $billing_interval_input = '') {
        if ($frequency === 'one time') {
            $option_key = 'ffwcs_onetime_product_id';
        } else {
            $option_key = 'ffwcs_subscription_product_' . $billing_interval_input;
        }

        $product_id = get_option($option_key, 0);

        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                error_log('FFWCS: Reusing existing product. ID: ' . $product_id . ' for ' . $option_key);
                return $product_id;
            }
        }

        // Create new product
        if ($frequency === 'one time') {
            $product = new \WC_Product_Simple();
            $product->set_name('One-Time Donation');
            $product->set_regular_price(0);
        } else {
            if (!class_exists('WC_Product_Subscription')) {
                error_log('FFWCS: WC_Product_Subscription class not found.');
                return 0;
            }
            $product = new \WC_Product_Subscription();
            $product->set_name('Recurring Donation - ' . ucfirst(str_replace('_', ' ', $billing_interval_input)));
            $product->set_regular_price(0);

            // DO NOT SET _subscription_period or _interval here
            // Let SubscriptionCreator set it dynamically
            $product->update_meta_data('_subscription_length', 0);
        }

        $product->set_status('publish');
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_sold_individually(true);

        $product_id = $product->save();
        update_option($option_key, $product_id);
        error_log('FFWCS: Created new product. ID: ' . $product_id . ' for ' . $option_key);

        return $product_id;
    }

    public function validate_billing_start_date($error, $field, $formData, $form) {
        return null;
    }

}
<?php
namespace FFWCS\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Responsible for creating WooCommerce orders and subscriptions
 */
class SubscriptionCreator {

    /**
     * Create subscription based on form submission.
     *
     * @param int $user_id
     * @param int $product_id (a subscription product for recurring, simple product for one-time)
     * @param array $insertData
     * @param array $map
     * @return string|null Checkout URL or null
     */
    public function create_from_submission($user_id, $product_id, $insertData = [], $map = []) {
        // Check WooCommerce availability
        if (!class_exists('WC_Product')) {
            error_log('FFWCS: WC_Product not available.');
            return null;
        }

        $donations = $insertData[$map['donations'] ?? 'donations'] ?? [];
        if (empty($donations)) {
            error_log('FFWCS: No donations found in submission.');
            return null;
        }

        // Map form interval to WooCommerce billing periods (exact WooCommerce allowed values)
        $billing_period_map = [
            'daily'          => 'day',
            'weekly'         => 'week',
            'every_two_week' => 'week',
            'monthly'        => 'month',
            'yearly'         => 'year',
        ];

        $billing_interval_map = [
            'daily'          => 1,
            'weekly'         => 1,
            'every_two_week' => 2,
            'monthly'        => 1,
            'yearly'         => 1,
        ];

        // Determine frequency once (same for all donations)
        $frequency = trim(strtolower($insertData[$map['billing_period'] ?? 'frequency'] ?? ''));
        error_log('FFWCS: Donation frequency: ' . $frequency);

        $checkout_order = null;

        foreach ($donations as $donation) {
            $amount = floatval($donation[0] ?? 0);
            $fund   = $donation[1] ?? '';

            if ($amount <= 0) {
                error_log('FFWCS: Skipping donation with zero or invalid amount.');
                continue;
            }

            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                error_log('FFWCS: Invalid product ID ' . $product_id);
                continue;
            }
            error_log('FFWCS: Product loaded. Product ID: ' . $product_id . ', Type: ' . $product->get_type());

            if ($frequency === 'one time') {
                $order = $this->create_one_time_order($user_id, $product, $amount, $fund, $insertData, $map);
            } else {
                $billing_interval_input = trim(strtolower($insertData[$map['billing_interval'] ?? 'billing_interval'] ?? ''));
                $billing_period = $billing_period_map[$billing_interval_input] ?? '';
                $billing_interval = $billing_interval_map[$billing_interval_input] ?? 1;

                if (empty($billing_period)) {
                    error_log('FFWCS: Invalid billing period. Subscription creation skipped.');
                    continue;
                }

            $billing_start_date = $insertData[$map['billing_start_date'] ?? 'billing_start_date'] ?? '';
            $order = $this->create_recurring_subscription(
                $user_id, $product, $amount, $fund,
                $billing_period, $billing_interval, $insertData, $map, $donations, $billing_start_date
            );
            }

            if ($order && !$checkout_order) {
                $checkout_order = $order;
            }
        }

        // -----------------------------------------------------------------
        //  ONLY THIS BLOCK IS CHANGED – session + cookies + safe URL
        // -----------------------------------------------------------------
        if ( $checkout_order ) {
            // Start session
            if ( ! WC()->session ) {
                WC()->initialize_session();
            }
            if ( ! WC()->session->has_session() ) {
                WC()->session->set_customer_session_cookie( true );
            }

            // Clear cart
            WC()->cart->empty_cart();

            // Link order
            WC()->session->set( 'order_awaiting_payment', $checkout_order->get_id() );

            // Ensure order key
            if ( ! $checkout_order->get_order_key() ) {
                $checkout_order->set_order_key( wc_generate_order_key() );
                $checkout_order->save();
            }

            // Let WC set cookies properly
            do_action( 'woocommerce_set_cart_cookies', true );

            // Use canonical pay-for-order URL
            $checkout_url = wc_get_checkout_url() . 'order-pay/' . $checkout_order->get_id() . '/?pay_for_order=true&key=' . $checkout_order->get_order_key();

            error_log('FFWCS: Redirecting to pay-for-order: ' . $checkout_url);
            return $checkout_url;
        }

        // Fallback: return the standard checkout URL (may be empty if cart is empty)
        error_log('FFWCS: No order to return payment URL for; returning null.');
        return null;
    }


    /**
     * Create a one-time donation order
     * 
     * @return WC_Order|null
     */
    private function create_one_time_order($user_id, $product, $amount, $fund, $insertData, $map) {
        // Create WooCommerce order (pending payment)
        $order = wc_create_order(['status' => 'pending']);
        if (!$order || is_wp_error($order)) {
            error_log('FFWCS: Failed to create one-time order: ' . ($order->get_error_message() ?? 'unknown error'));
            return null;
        }
        error_log('FFWCS: One-time order created successfully. Order ID: ' . $order->get_id());

        // Add product with custom amount
        $order->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);
        
        // Set customer
        if ($user_id) {
            $order->set_customer_id($user_id);
        }

        // Set billing details
        $order->set_billing_email($insertData[$map['email'] ?? 'donor_email'] ?? '');
        $order->set_billing_first_name($insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        $order->set_billing_last_name($insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');

        // Save metadata
        update_post_meta($order->get_id(), '_ffwcs_donor_email', $insertData[$map['email'] ?? 'donor_email'] ?? '');
        update_post_meta($order->get_id(), '_ffwcs_first_name', $insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        update_post_meta($order->get_id(), '_ffwcs_last_name', $insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');
        update_post_meta($order->get_id(), '_ffwcs_blog_id', $insertData[$map['blog_id'] ?? 'blog_id'] ?? 0);
        update_post_meta($order->get_id(), '_ffwcs_donation_fund', $fund);
        update_post_meta($order->get_id(), '_ffwcs_donation_type', 'one_time');

        // Calculate totals
        $order->calculate_totals();
        $order->save();
        
        // Add order note
        $order->add_order_note('One-time donation of $' . $amount . ' to ' . $fund . '. Awaiting payment.');
        
        error_log('FFWCS: One-time order created and awaiting payment. Order ID: ' . $order->get_id());
        
        return $order;
    }

    /**
     * Create a recurring subscription
     * 
     * @return WC_Order|null Returns the parent order for checkout
     */

    private function create_recurring_subscription(
        $user_id, $product, $amount, $fund, 
        $billing_period, $billing_interval, $insertData, $map, $donations, 
        $start_date_input = ''  // ← ADD THIS
    ) {
        // Check WooCommerce Subscriptions availability
        if (!function_exists('wcs_create_subscription')) {
            error_log('FFWCS: WooCommerce Subscriptions not available.');
            return null;
        }

        // Verify product is a subscription type
        if (!in_array($product->get_type(), ['subscription', 'subscription_variation', 'variable-subscription'])) {
            error_log('FFWCS: Product ID ' . $product->get_id() . ' is not a subscription product. Type: ' . $product->get_type());
            return null;
        }

        // --------------------------------------------------------------
        // FIX: DYNAMICALLY SET SUBSCRIPTION PERIOD & INTERVAL ON PRODUCT
        // --------------------------------------------------------------
        $product->update_meta_data('_subscription_period', $billing_period);           // e.g., 'week'
        $product->update_meta_data('_subscription_period_interval', $billing_interval); // e.g., 1 or 2
        $product->update_meta_data('_subscription_length', 0); // never expires
        $product->save();

        error_log("FFWCS: Updated product {$product->get_id()} → {$billing_interval} {$billing_period}(s)");

        // Create parent order first (pending payment)
        $order = wc_create_order(['status' => 'pending']);
        if (!$order || is_wp_error($order)) {
            error_log('FFWCS: Failed to create order: ' . ($order->get_error_message() ?? 'unknown error'));
            return null;
        }
        error_log('FFWCS: Parent order created successfully. Order ID: ' . $order->get_id());

        // Add product to order
        $order->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);
        
        // Set customer
        if ($user_id) {
            $order->set_customer_id($user_id);
        }

        // Set billing details
        $order->set_billing_email($insertData[$map['email'] ?? 'donor_email'] ?? '');
        $order->set_billing_first_name($insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        $order->set_billing_last_name($insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');

        $order->calculate_totals();
        $order->save();

        // Create subscription
        $args = [
            'order_id'         => $order->get_id(),
            'customer_id'      => $user_id,
            'billing_period'   => $billing_period,
            'billing_interval' => $billing_interval,
            'status'           => 'pending',
        ];

        if (!empty($start_date_input)) {
            $start_timestamp = strtotime($start_date_input);
            $today_timestamp = strtotime('today');

            if ($start_timestamp && $start_timestamp >= $today_timestamp) {
                $start_date_mysql = date('Y-m-d 00:00:00', $start_timestamp);
                $first_payment_mysql = $start_date_mysql;

                $next_payment_timestamp = strtotime("+{$billing_interval} {$billing_period}", $start_timestamp);
                $next_payment_mysql = $next_payment_timestamp ? date('Y-m-d 00:00:00', $next_payment_timestamp) : $first_payment_mysql;

                $args['start_date']     = $start_date_mysql;
                $args['next_payment']   = $first_payment_mysql;
                $args['trial_end']      = $first_payment_mysql;
                $args['date_created']   = $start_date_mysql;

                error_log("FFWCS: Setting start & first payment at creation: {$first_payment_mysql}");
            } else {
                error_log('FFWCS: Invalid start date: ' . $start_date_input);
            }
        }

        $subscription = wcs_create_subscription($args);

        if (!$subscription || is_wp_error($subscription)) {
            error_log('FFWCS: Failed to create subscription: ' . ($subscription->get_error_message() ?? 'unknown error'));
            return null;
        }
        error_log('FFWCS: Subscription created. ID: ' . $subscription->get_id());

        // Add product to subscription
        $subscription->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);
        error_log('FFWCS: Product added to subscription.');

        // Set billing details on subscription
        $subscription->set_billing_email($insertData[$map['email'] ?? 'donor_email'] ?? '');
        $subscription->set_billing_first_name($insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        $subscription->set_billing_last_name($insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');

        // Save donor & blog info
        update_post_meta($subscription->get_id(), '_ffwcs_donor_email', $insertData[$map['email'] ?? 'donor_email'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_first_name', $insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_last_name', $insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_blog_id', $insertData[$map['blog_id'] ?? 'blog_id'] ?? 0);
        update_post_meta($subscription->get_id(), '_ffwcs_donation_fund', $fund);
        update_post_meta($subscription->get_id(), '_ffwcs_all_donations', $donations);
        update_post_meta($subscription->get_id(), '_ffwcs_donation_type', 'recurring');

        // Finalize
        $subscription->calculate_totals();
        $subscription->save();
        error_log('FFWCS: Subscription totals calculated and saved.');

        $order->add_order_note('Subscription created (ID: ' . $subscription->get_id() . '). Awaiting payment to activate.');
        error_log('FFWCS: Subscription created successfully for user ID ' . $user_id . ', subscription ID ' . $subscription->get_id() . '. Awaiting payment.');
        
        return $order;
        
    }
}
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

        // Map interval for special cases
        $billing_interval_map = [
            'daily'          => 1,
            'weekly'         => 1,
            'every_two_week' => 2, // interval 2 = every 2 weeks
            'monthly'        => 1,
            'yearly'         => 1,
        ];

        // Determine frequency once (same for all donations)
        $frequency = trim(strtolower($insertData[$map['billing_period'] ?? 'frequency'] ?? ''));
        error_log('FFWCS: Donation frequency: ' . $frequency);

        // We'll create ONE order/subscription for the first donation and redirect to checkout
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

            // Handle based on frequency
            if ($frequency === 'one time') {
                // ONE-TIME DONATION: Create simple order
                $order = $this->create_one_time_order($user_id, $product, $amount, $fund, $insertData, $map);
                if ($order && !$checkout_order) {
                    $checkout_order = $order;
                }
            } else {
                // RECURRING DONATION: Create subscription
                $billing_interval_input = trim(strtolower($insertData[$map['billing_interval'] ?? 'billing_interval'] ?? ''));
                error_log('FFWCS: Billing interval input: ' . $billing_interval_input);

                $billing_period = $billing_period_map[$billing_interval_input] ?? '';
                $billing_interval = $billing_interval_map[$billing_interval_input] ?? 1;

                error_log('FFWCS: Mapped billing period: ' . $billing_period);
                error_log('FFWCS: Mapped billing interval: ' . $billing_interval);

                if (empty($billing_period)) {
                    error_log('FFWCS: Invalid billing period. Subscription creation skipped.');
                    continue;
                }

                $order = $this->create_recurring_subscription($user_id, $product, $amount, $fund, $billing_period, $billing_interval, $insertData, $map, $donations);
                if ($order && !$checkout_order) {
                    $checkout_order = $order;
                }
            }
        }

        // Return checkout URL if order was created
        if ($checkout_order) {
            $checkout_url = $checkout_order->get_checkout_payment_url();
            error_log('FFWCS: Redirecting to checkout: ' . $checkout_url);
            return $checkout_url;
        }

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
    private function create_recurring_subscription($user_id, $product, $amount, $fund, $billing_period, $billing_interval, $insertData, $map, $donations) {
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

        // Create subscription with proper arguments (status: pending initially)
        $subscription = wcs_create_subscription([
            'order_id'        => $order->get_id(),
            'customer_id'     => $user_id,
            'billing_period'  => $billing_period,
            'billing_interval'=> $billing_interval,
            'start_date'      => current_time('mysql'),
            'status'          => 'pending', // Will activate after payment
        ]);

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

        // Optional: set billing day
        if (isset($insertData[$map['billing_day'] ?? 'billing_day'])) {
            update_post_meta($subscription->get_id(), '_billing_day', $insertData[$map['billing_day']] ?? '');
        }

        // Save donor & blog info
        update_post_meta($subscription->get_id(), '_ffwcs_donor_email', $insertData[$map['email'] ?? 'donor_email'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_first_name', $insertData[$map['first_name'] ?? 'donpr_names.first_name'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_last_name', $insertData[$map['last_name'] ?? 'donpr_names.last_name'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_blog_id', $insertData[$map['blog_id'] ?? 'blog_id'] ?? 0);
        update_post_meta($subscription->get_id(), '_ffwcs_donation_fund', $fund);
        update_post_meta($subscription->get_id(), '_ffwcs_card_number', $insertData[$map['card_number'] ?? 'donor_card_number'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_expiry_date', $insertData[$map['expiry_date'] ?? 'donor_expiry_date'] ?? '');
        update_post_meta($subscription->get_id(), '_ffwcs_security_code', $insertData[$map['security_code'] ?? 'donor_security_code'] ?? '');

        // Save full donations array in subscription meta
        update_post_meta($subscription->get_id(), '_ffwcs_all_donations', $donations);
        update_post_meta($subscription->get_id(), '_ffwcs_donation_type', 'recurring');

        // Finalize subscription
        $subscription->calculate_totals();
        $subscription->save();
        error_log('FFWCS: Subscription totals calculated and saved.');

        // Update order note
        $order->add_order_note('Subscription created (ID: ' . $subscription->get_id() . '). Awaiting payment to activate.');

        error_log('FFWCS: Subscription created successfully for user ID ' . $user_id . ', subscription ID ' . $subscription->get_id() . '. Awaiting payment.');
        
        return $order;
    }
}
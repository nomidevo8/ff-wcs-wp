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
     * @param int $product_id (a subscription product)
     * @param array $insertData
     * @param array $map
     */
    public function create_from_submission($user_id, $product_id, $insertData = [], $map = []) {
        // Check WooCommerce Subscriptions availability
        if (!function_exists('wcs_create_subscription') || !class_exists('WC_Product')) {
            error_log('FFWCS: WooCommerce Subscriptions or WC_Product not available.');
            return;
        }

        $donations = $insertData[$map['donations'] ?? 'donations'] ?? [];
        if (empty($donations)) {
            error_log('FFWCS: No donations found in submission.');
            return;
        }

        // Determine frequency
        $frequency = $insertData[$map['billing_period'] ?? 'frequency'] ?? '';
        $frequency = trim($frequency);

        // Map your form interval to WooCommerce billing periods
        $frequency_map = [
            'One Time' => '',        // no subscription
            'Regularly' => 'week',   // default to weekly, can adjust based on interval
        ];

        $billing_period = $frequency_map[$frequency] ?? '';

        // Map interval
        $interval_map = [
            'Weekly' => 1,
            'twice monthly' => 2,
            'every other week' => 2,
        ];
        $billing_interval = $interval_map[$insertData[$map['billing_interval'] ?? 'billing_interval'] ?? ''] ?? 1;

        foreach ($donations as $donation) {
            $amount = floatval($donation[0] ?? 0);
            $fund   = $donation[1] ?? '';

            if ($amount <= 0) {
                error_log('FFWCS: Skipping donation with zero or invalid amount.');
                continue;
            }

            // Create WooCommerce order
            $order = wc_create_order(['status' => 'on-hold']);
            if (!$order || is_wp_error($order)) {
                error_log('FFWCS: Failed to create order: ' . ($order->get_error_message() ?? 'unknown error'));
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                error_log('FFWCS: Invalid product ID ' . $product_id);
                continue;
            }

            $order->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);
            if ($user_id) $order->set_customer_id($user_id);
            $order->calculate_totals();
            $order->save();

            // If frequency is "One Time", just finalize the order
            if ($frequency === 'One Time') {
                $order->update_status('completed', 'One-time donation completed.');
                $order->add_order_note('One-time donation of $' . $amount . ' to ' . $fund);
                continue;
            }

            // Create subscription for regular donations
            $subscription = wcs_create_subscription([
                'order_id'    => $order->get_id(),
                'customer_id' => $user_id,
                'start_date'  => current_time('mysql'),
            ]);

            if (!$subscription || is_wp_error($subscription)) {
                error_log('FFWCS: Failed to create subscription: ' . ($subscription->get_error_message() ?? 'unknown error'));
                continue;
            }

            // Add product to subscription
            $subscription->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);

            // Set billing period and interval
            $subscription->set_billing_period($billing_period);
            $subscription->set_billing_interval($billing_interval);

            if (isset($insertData[$map['billing_day'] ?? 'billing_day'])) {
                update_post_meta($subscription->get_id(), '_billing_day', $insertData[$map['billing_day']]);
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

            // Finalize subscription
            $subscription->calculate_totals();
            $subscription->save();

            // Update order note
            $order->add_order_note('Subscription created programmatically (ID: ' . $subscription->get_id() . ')');
            $order->update_status('completed', 'Subscription created.');

            // Save full donations array in subscription meta
            update_post_meta($subscription->get_id(), '_ffwcs_all_donations', $donations);

            error_log('FFWCS: Subscription created successfully for user ID ' . $user_id . ', subscription ID ' . $subscription->get_id());
        }
    }
}

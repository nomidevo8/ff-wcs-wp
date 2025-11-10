<?php
namespace FFWCS\Services;

if (!defined('ABSPATH')) exit;

class ProductCreator {

    /**
     * Create a product representing the donation submission.
     *
     * @param array $donations Array of ['amount' => float, 'title' => string]
     * @param string $frequency 'one time' or 'regularly' etc.
     * @param string $billing_interval 'weekly', 'monthly', 'yearly', 'every_two_week', etc.
     * @param string $start_date optional start date in Y-m-d format
     * @param int|null $user_id optional user for meta or ownership
     * @param array $extra_meta Optional extra meta from submission (site, form, card, etc.)
     * @return \WC_Product|WP_Error
     */
    public function create_product_from_donations(array $donations, $frequency = 'one time', $billing_interval = 'monthly', $start_date = '', $user_id = null, $extra_meta = []) {
        if (!class_exists('\WC_Product')) {
            return new \WP_Error('woocommerce_missing', 'WooCommerce not active');
        }

        // compute total & title list
        $total = 0;
        $titles = [];
        foreach ($donations as $d) {
            $amt = floatval($d['amount'] ?? 0);
            if ($amt <= 0) continue;
            $total += $amt;
            $titles[] = sanitize_text_field($d['title'] ?? 'Donation');
        }

        if ($total <= 0) {
            return new \WP_Error('invalid_amount', 'Total donation amount must be greater than zero.');
        }

        // product name
        $name = 'Donation - ' . implode(', ', array_slice($titles, 0, 3));
        if (count($titles) > 3) $name .= ' + ' . (count($titles) - 3) . ' more';

        // decide product type
        $is_recurring = (strtolower($frequency) !== 'one time' && strtolower($frequency) !== 'one-time' && strtolower($frequency) !== 'onetime');

        if ($is_recurring && class_exists('\WC_Product_Subscription')) {
            $product = new \WC_Product_Subscription();
            $product_type = 'subscription';
        } else {
            $product = new \WC_Product_Simple();
            $product_type = 'simple';
        }

        // Basic product properties
        $product->set_name($name);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_sku('ffwcs-' . uniqid());
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_regular_price(wc_format_decimal($total, wc_get_price_decimals()));

        // Standard donation meta
        $product->update_meta_data('ffwcs_donation_items', maybe_serialize($donations));
        $product->update_meta_data('ffwcs_created', current_time('mysql'));
        if ($user_id) {
            $product->update_meta_data('ffwcs_user_id', intval($user_id));
        }

        // Extra meta (site, blog, form, card, etc.)
        foreach ($extra_meta as $key => $value) {
            $product->update_meta_data(sanitize_key($key), maybe_serialize($value));
        }

        // Handle subscription meta if recurring
        if ($is_recurring) {
            $period_map = [
                'daily' => 'day',
                'weekly' => 'week',
                'every_two_week' => 'week',
                'every_two_weeks' => 'week',
                'biweekly' => 'week',
                'monthly' => 'month',
                'yearly' => 'year',
                'annually' => 'year',
            ];
            $interval_map = [
                'daily' => 1,
                'weekly' => 1,
                'every_two_week' => 2,
                'every_two_weeks' => 2,
                'biweekly' => 2,
                'monthly' => 1,
                'yearly' => 1,
            ];

            $period_key = $billing_interval ?: 'monthly';
            $period = $period_map[$period_key] ?? 'month';
            $interval = $interval_map[$period_key] ?? 1;

            $product->update_meta_data('_subscription_period', $period);
            $product->update_meta_data('_subscription_period_interval', $interval);
            $product->update_meta_data('_subscription_length', 0);

            // trial
            if ($start_date) {
                $today = new \DateTime(current_time('Y-m-d'));
                $start = new \DateTime($start_date);
                $diff = $today->diff($start);
                $trial_days = max(0, (int) $diff->format('%a'));
                if ($trial_days > 0) {
                    $product->update_meta_data('_subscription_trial_length', $trial_days);
                    $product->update_meta_data('_subscription_trial_period', 'day');
                }
            }
        }

        // Save product
        try {
            $product->save();
            wp_set_object_terms($product->get_id(), $product_type, 'product_type', false);
        } catch (\Exception $ex) {
            return new \WP_Error('product_save_failed', $ex->getMessage());
        }

        return $product;
    }
}

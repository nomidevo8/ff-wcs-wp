<?php
namespace FFWCS\Hooks;

if (!defined('ABSPATH'))
    exit;

use FFWCS\Helpers\UserHelper;
use FFWCS\Services\ProductCreator;

class FormSubmissionHandler
{

    public function __construct()
    {
        add_action('fluentform/submission_inserted', [$this, 'handle_submission'], 10, 3);

        // Orders: line item meta
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_donation_meta_to_order_item'], 10, 4);

        // Subscriptions: copy meta from order
        add_action('woocommerce_checkout_subscription_created', [$this, 'add_donation_meta_to_subscription'], 10, 2);

        // Admin display
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_details_to_admin_order_page']);
        add_action('woocommerce_admin_subscription_data_after_order_details', [$this, 'add_details_to_admin_subscription_page']);

        // Hide donation meta from cart/checkout
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_donation_meta_on_cart_checkout']);
        // Hide ffwcs_ meta on frontend (cart, checkout, thank you, emails)
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'hide_donation_meta_on_front'], 10, 2);
        add_filter('woocommerce_checkout_get_value', [$this, 'prefill_checkout_fields'], 10, 2);
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_checkout_fields']);

    }

    /**
     * Hide donation & site meta from cart/checkout pages
     */
    public function hide_donation_meta_on_cart_checkout($hidden_meta)
    {
        $donation_meta_keys = [
            'ffwcs_form_id',
            'ffwcs_blog_id',
            'ffwcs_blog_url',
            'ffwcs_user_id',
            'ffwcs_user_ip',
            'ffwcs_raw_submission',
        ];

        return array_merge($hidden_meta, $donation_meta_keys);
    }

    public function handle_submission($insertId, $insertData, $form)
    {
        try {
            error_log('FFWCS: Handling submission for form ID ' . intval($form->id));
            error_log("FFWCS: Submission ID: " . $insertId);
            error_log("FFWCS: Submission data: " . print_r($insertData, true));

            // get plugin options (map, form id)
            $opts = get_option('ffwcs_settings', []);
            $configured_form_id = intval($opts['form_id'] ?? 0);

            if ($configured_form_id && $configured_form_id !== intval($form->id)) {
                error_log('FFWCS: Form ID mismatch — skipping.');
                return;
            }

            // $insertData may be object or array
            $data = is_object($insertData) ? (array) $insertData : (array) $insertData;

            // Helper to fetch nested values safely
            $get = function ($key, $default = '') use ($data) {
                if (!$key)
                    return $default;
                // support dot notation or simple key
                if (strpos($key, '.') === false) {
                    return $data[$key] ?? $default;
                }
                $parts = explode('.', $key);
                $cur = $data;
                foreach ($parts as $p) {
                    if (is_array($cur) && array_key_exists($p, $cur)) {
                        $cur = $cur[$p];
                    } elseif (is_object($cur) && isset($cur->{$p})) {
                        $cur = $cur->{$p};
                    } else {
                        return $default;
                    }
                }
                return $cur;
            };

            // Map field keys: if you use plugin settings map, decode it, else fallback to common field names
            $map = isset($opts['field_map']) ? json_decode($opts['field_map'], true) : [];
            // fallback sensible keys:
            $donations_key = $map['donations'] ?? 'donations';
            $email_key = $map['email'] ?? 'donor_email';
            $first_key = $map['first_name'] ?? 'donpr_names.first_name';
            $last_key = $map['last_name'] ?? 'donpr_names.last_name';
            $frequency_key = $map['billing_period'] ?? 'frequency';
            $interval_key = $map['billing_interval'] ?? 'billing_interval';
            $start_date_key = $map['billing_start_date'] ?? 'billing_start_date';
            $first_name = '';
            $last_name = '';
            // get or create user
            $user_id = get_current_user_id();
            if (!$user_id) {
                $email = $get($email_key, '');
                if (empty($email)) {
                    error_log('FFWCS: No email provided — abort.');
                    return;
                }

                $first_name = $get($first_key, '');
                $last_name = $get($last_key, '');

                $userHelper = new UserHelper();
                $user_id = $userHelper->get_or_create_user($email, $first_name, $last_name);

                // sign them in for this request so WC cart attaches to this user
                if ($user_id && !is_wp_error($user_id)) {
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);
                }
            } else {
                error_log('FFWCS: Using logged-in user ID: ' . $user_id);
            }

            // gather donation rows (support array-of-arrays or array-of-assoc)
            $donations_raw = $get($donations_key, []);
            // If Fluent Form stores donations as nested arrays, ensure we cast to array
            $donations = [];
            if (is_string($donations_raw)) {
                // maybe JSON?
                $decoded = json_decode($donations_raw, true);
                if (is_array($decoded))
                    $donations_raw = $decoded;
            }
            if (is_array($donations_raw)) {
                foreach ($donations_raw as $row) {
                    if (is_array($row)) {
                        if (isset($row[0])) {
                            $amount = floatval($row[0]);
                            $title = isset($row[1]) ? sanitize_text_field($row[1]) : 'Donation';
                        } else {
                            $amount = floatval($row['amount'] ?? ($row['value'] ?? 0));
                            $title = sanitize_text_field($row['title'] ?? $row['label'] ?? 'Donation');
                        }
                        if ($amount > 0) {
                            $donations[] = [
                                'amount' => $amount,
                                'title' => $title
                            ];
                        }
                    } elseif (is_numeric($row)) {
                        $donations[] = [
                            'amount' => floatval($row),
                            'title' => 'Donation'
                        ];
                    }
                }
            }

            if (empty($donations)) {
                error_log('FFWCS: No donation rows found — abort.');
                return;
            }

            $frequency = strtolower(trim($get($frequency_key, 'one time')));
            $billing_interval = strtolower(trim($get($interval_key, 'monthly')));
            $start_date = trim($get($start_date_key, ''));

            error_log('FFWCS: Frequency: ' . $frequency . '; Interval: ' . $billing_interval . '; Start: ' . $start_date);
            error_log('FFWCS: Donations parsed: ' . print_r($donations, true));

            // === EXTRA META FOR STORAGE ===
            $extra_meta = [
                'ffwcs_form_id' => intval($form->id),
                'ffwcs_blog_id' => get_current_blog_id(),
                'ffwcs_blog_url' => get_site_url(),
                'ffwcs_user_id' => $user_id,
                'ffwcs_user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'ffwcs_raw_submission' => $data,
            ];

            // create product (single product representing the submission)
            $productCreator = new ProductCreator();
            $product = $productCreator->create_product_from_donations($donations, $frequency, $billing_interval, $start_date, $user_id, $extra_meta);

            if (is_wp_error($product) || !$product || !$product->get_id()) {
                error_log('FFWCS: Product creation failed: ' . (is_wp_error($product) ? $product->get_error_message() : 'unknown'));
                return;
            }

            // ensure WC is available
            if (!function_exists('WC') || !WC()->session) {
                if (class_exists('\WC_Session_Handler')) {
                    WC()->session = new \WC_Session_Handler();
                    WC()->session->init();
                }
            }

            // Clear cart and add product
            if (WC()->cart) {
                WC()->cart->empty_cart(true);
            }

            $added = WC()->cart->add_to_cart($product->get_id(), 1);
            if (!$added) {
                error_log('FFWCS: Failed to add product to cart: ' . $product->get_id());
            }

            // store donation metadata in session so you can render on checkout if needed
            WC()->session->set('ffwcs_last_submission', [
                'product_id' => $product->get_id(),
                'donations' => $donations,
                'frequency' => $frequency,
                'interval' => $billing_interval,
                'start_date' => $start_date,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
            ]);

            // Redirect user to WC checkout
            $checkout_url = wc_get_checkout_url();

            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_send_json_success([
                    'result' => [
                        'redirectUrl' => $checkout_url
                    ]
                ]);
                return;
            }

            wp_safe_redirect($checkout_url);
            exit;

        } catch (\Throwable $e) {
            error_log('FFWCS: Exception in handle_submission: ' . $e->getMessage());
            return;
        }
    }

    public function add_donation_meta_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['data']) && is_callable([$values['data'], 'get_meta'])) {
            // Grab the product
            $product = $values['data'];

            // Get stored meta from product
            $extra_meta_keys = [
                'ffwcs_form_id',
                'ffwcs_blog_id',
                'ffwcs_blog_url',
                'ffwcs_user_id',
                'ffwcs_user_ip',
                'ffwcs_raw_submission',
            ];

            foreach ($extra_meta_keys as $key) {
                $value = maybe_unserialize($product->get_meta($key, true));
                if ($value) {
                    $item->add_meta_data($key, $value, true);
                }
            }
        }
    }

    public function add_donation_meta_to_subscription($subscription, $order)
    {
        foreach ($order->get_items() as $item_id => $item) {
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'ffwcs_') === 0) {
                    $subscription->add_meta_data($meta->key, $meta->value, true);
                }
            }
        }
        $subscription->save();
    }

    public function add_details_to_admin_order_page($order)
    {
        echo '<h3>Donation & Site Info</h3>';
        foreach ($order->get_items() as $item) {
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'ffwcs_') === 0) {
                    echo '<p><strong>' . esc_html($meta->key) . ':</strong> ' . esc_html(is_array($meta->value) ? json_encode($meta->value) : $meta->value) . '</p>';
                }
            }
        }
    }
    public function add_details_to_admin_subscription_page($subscription)
    {
        echo '<h3>Donation & Site Info</h3>';
        foreach ($subscription->get_items() as $item) {
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'ffwcs_') === 0) {
                    echo '<p><strong>' . esc_html($meta->key) . ':</strong> ' . esc_html(is_array($meta->value) ? json_encode($meta->value) : $meta->value) . '</p>';
                }
            }
        }
    }
    /**
     * Hide donation & site meta from frontend display (cart, checkout, thank you, emails)
     */
    public function hide_donation_meta_on_front($formatted_meta, $item)
    {
        foreach ($formatted_meta as $key => $meta) {
            if (strpos($meta->key, 'ffwcs_') === 0) {
                unset($formatted_meta[$key]);
            }
        }
        return $formatted_meta;
    }

    public function prefill_checkout_fields($value, $input)
    {
        $last_submission = WC()->session->get('ffwcs_last_submission', []);

        if (!$last_submission) {
            return $value; // fallback if nothing in session
        }

        switch ($input) {
            case 'billing_first_name':
                return $last_submission['first_name'] ?? $value;
            case 'billing_last_name':
                return $last_submission['last_name'] ?? $value;
            case 'billing_email':
                return $last_submission['email'] ?? $value;
            default:
                return $value;
        }
    }

    /**
     * Remove unnecessary checkout fields
     */
    public function simplify_checkout_fields($fields)
    {
        $keep = ['billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone'];

        foreach ($fields['billing'] as $key => $field) {
            if (!in_array($key, $keep)) {
                unset($fields['billing'][$key]);
            }
        }

        // Remove shipping fields
        $fields['shipping'] = [];

        // Remove account creation fields
        if (isset($fields['account'])) {
            $fields['account'] = [];
        }

        return $fields;
    }

}

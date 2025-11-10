<?php
namespace FFWCS\Hooks;

if (!defined('ABSPATH')) exit;

use FFWCS\Helpers\UserHelper;
use FFWCS\Services\ProductCreator;

class FormSubmissionHandler {

    public function __construct() {
        // Fluent Forms: fired after submission is inserted
        add_action('fluentform/submission_inserted', [$this, 'handle_submission'], 10, 3);
    }

    /**
     * Handle the Fluent Form submission.
     *
     * @param int $insertId
     * @param array|object $insertData
     * @param object $form
     */
    public function handle_submission($insertId, $insertData, $form) {
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
            $get = function($key, $default = '') use ($data) {
                if (!$key) return $default;
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
                if (is_array($decoded)) $donations_raw = $decoded;
            }
            if (is_array($donations_raw)) {
                // Some forms: donations => [ 0 => [0 => "160.00", 1 => "Benevolence Offering"] ]
                foreach ($donations_raw as $row) {
                    if (is_array($row)) {
                        // detect numeric-indexed
                        if (isset($row[0])) {
                            $amount = floatval($row[0]);
                            $title = isset($row[1]) ? sanitize_text_field($row[1]) : 'Donation';
                        } else {
                            // keyed array
                            $amount = floatval($row['amount'] ?? ($row['value'] ?? 0));
                            $title = sanitize_text_field($row['title'] ?? $row['label'] ?? 'Donation');
                        }
                        if ($amount > 0) {
                            $donations[] = [
                                'amount' => $amount,
                                'title'  => $title
                            ];
                        }
                    } elseif (is_numeric($row)) {
                        $donations[] = [
                            'amount' => floatval($row),
                            'title'  => 'Donation'
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

            // create product (single product representing the submission)
            $productCreator = new ProductCreator();
            $product = $productCreator->create_product_from_donations($donations, $frequency, $billing_interval, $start_date, $user_id);

            if (is_wp_error($product) || !$product || !$product->get_id()) {
                error_log('FFWCS: Product creation failed: ' . (is_wp_error($product) ? $product->get_error_message() : 'unknown'));
                return;
            }

            // ensure WC is available
            if (!function_exists('WC') || !WC()->session) {
                // try initialize session
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
                'donations'  => $donations,
                'frequency'  => $frequency,
                'interval'   => $billing_interval,
                'start_date' => $start_date,
            ]);

            // Redirect user to WC checkout
            $checkout_url = wc_get_checkout_url();

            // If this is an AJAX context, send JSON response
            if (defined('DOING_AJAX') && DOING_AJAX) {
                
                wp_send_json_success([
                    'result' => [
                        'redirectUrl' => $checkout_url
                    ]
                ]);
                return;
            }

            // Fallback (non-AJAX)
            wp_safe_redirect($checkout_url);
            exit;

        } catch (\Throwable $e) {
            error_log('FFWCS: Exception in handle_submission: ' . $e->getMessage());
            return;
        }
    }
}

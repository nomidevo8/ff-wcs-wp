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

        $email = $get($map['email'] ?? '');
        if (empty($email)) return; // email required

        $first_name = $get($map['first_name'] ?? '');
        $last_name  = $get($map['last_name'] ?? '');

        // create or fetch user
        $userHelper = new UserHelper();
        $user_id = $userHelper->get_or_create_user($email, $first_name, $last_name);

        // create subscriptions

        $product_id = isset($opts['product_id']) ? absint($opts['product_id']) : 0;

        if (!$product_id) {
            // create a simple subscription product programmatically
            $product = new \WC_Product_Simple();
            $product->set_name('Donation Subscription');
            $product->set_status('publish');
            $product->set_regular_price(0); 
            $product->set_virtual(true);
            $product->set_downloadable(false);

            $product_id = $product->save(); 
        }

        $creator = new SubscriptionCreator();
        $creator->create_from_submission($user_id, $product_id, $insertData, $map);
    }

}

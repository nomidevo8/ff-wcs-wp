<?php
/**
 * Plugin Name: FFWCS – Checkout Handler
 * Description: Reads the signed payload on /checkout/?payload=… and creates cart + order/subscription.
 * Version: 1.2
 */

namespace FFWCS\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use FFWCS\Helpers\UserHelper;
use FFWCS\Services\SubscriptionCreator;

class CheckoutHandler {

    private $creator;
    private $user_helper;

    public function __construct() {
        $this->creator     = new SubscriptionCreator();
        $this->user_helper = new UserHelper();

        // Hook when checkout is processed (after cart is ready)
        add_action( 'woocommerce_checkout_process', [ $this, 'process_payload' ], 5 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_custom_prices' ], 10, 1 );
    }

    /**
     * Main entry – runs on checkout page if payload exists
     */
    public function process_payload() {
        if ( ! isset( $_GET['payload'] ) ) {
            return;
        }

        $payload_b64 = sanitize_text_field( $_GET['payload'] );
        $payload_json = base64_decode( $payload_b64, true );

        if ( ! $payload_json ) {
            wc_add_notice( __( 'Invalid donation data.', 'ffwcs' ), 'error' );
            return;
        }

        $data = json_decode( $payload_json, true );
        if ( ! $data || empty( $data['signature'] ) ) {
            wc_add_notice( __( 'Corrupted donation data.', 'ffwcs' ), 'error' );
            return;
        }

        $signature = $data['signature'];
        unset( $data['signature'] );

        $json_for_sig = wp_json_encode( $data );
        $expected     = hash_hmac( 'sha256', $json_for_sig, wp_salt( 'nonce' ) );

        if ( ! hash_equals( $expected, $signature ) ) {
            wc_add_notice( __( 'Invalid donation signature.', 'ffwcs' ), 'error' );
            return;
        }

        $insertData = $data['insertData'];
        $map        = $data['map'];

        // -----------------------------------------------------------------
        // 1. User
        // -----------------------------------------------------------------
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            $email      = $this->get( $map['email'] ?? '', $insertData );
            $first_name = $this->get( $map['first_name'] ?? '', $insertData );
            $last_name  = $this->get( $map['last_name'] ?? '', $insertData );
            $user_id    = $this->user_helper->get_or_create_user( $email, $first_name, $last_name );
        }

        if ( ! $user_id ) {
            wc_add_notice( __( 'Could not create user account.', 'ffwcs' ), 'error' );
            return;
        }

        // -----------------------------------------------------------------
        // 2. Product
        // -----------------------------------------------------------------
        $frequency  = trim( strtolower( $insertData[ $map['billing_period'] ?? 'frequency' ] ?? '' ) );
        $product_id = $this->get_or_create_product( $frequency );

        if ( ! $product_id ) {
            wc_add_notice( __( 'Donation product not available.', 'ffwcs' ), 'error' );
            return;
        }

        // -----------------------------------------------------------------
        // 3. Build Cart
        // -----------------------------------------------------------------
        WC()->cart->empty_cart();

        $donations = $insertData[ $map['donations'] ?? 'donations' ] ?? [];
        if ( empty( $donations ) ) {
            wc_add_notice( __( 'No donation amounts found.', 'ffwcs' ), 'error' );
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wc_add_notice( __( 'Invalid product.', 'ffwcs' ), 'error' );
            return;
        }

        foreach ( $donations as $donation ) {
            $amount = floatval( $donation[0] ?? 0 );
            $fund   = $donation[1] ?? '';

            if ( $amount <= 0 ) {
                continue;
            }

            WC()->cart->add_to_cart(
                $product_id,
                1,
                0,
                [],
                [
                    'ffwcs_custom_price' => $amount,
                    'ffwcs_fund'         => $fund,
                    'ffwcs_donation'     => true,
                ]
            );
        }

        // Store data for order creation
        WC()->session->set( 'ffwcs_form_data', [
            'insertData' => $insertData,
            'map'        => $map,
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'frequency'  => $frequency,
        ] );

        // Remove payload from URL to avoid re-processing
        $clean_url = remove_query_arg( 'payload', wc_get_checkout_url() );
        wp_safe_redirect( $clean_url );
        exit;
    }

    /**
     * Apply custom price to cart items
     */
    public function apply_custom_prices( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['ffwcs_custom_price'] ) ) {
                $item['data']->set_price( (float) $item['ffwcs_custom_price'] );
            }
        }
    }

    // -----------------------------------------------------------------
    // Helper: $get
    // -----------------------------------------------------------------
    private function get( $key, $data ) {
        if ( ! $key ) return '';
        if ( strpos( $key, '.' ) === false ) {
            return $data[ $key ] ?? '';
        }
        $parts = explode( '.', $key );
        $cur   = $data;
        foreach ( $parts as $p ) {
            if ( is_array( $cur ) && isset( $cur[ $p ] ) ) {
                $cur = $cur[ $p ];
            } elseif ( is_object( $cur ) && isset( $cur->{$p} ) ) {
                $cur = $cur->{$p};
            } else {
                return '';
            }
        }
        return $cur;
    }

    // -----------------------------------------------------------------
    // Product creation (exact copy)
    // -----------------------------------------------------------------
    private function get_or_create_product( $frequency ) {
        $option_key = ( $frequency === 'one time' )
            ? 'ffwcs_onetime_product_id'
            : 'ffwcs_subscription_product_id';

        $product_id = get_option( $option_key, 0 );
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) return $product_id;
        }

        if ( $frequency === 'one time' ) {
            $product = new \WC_Product_Simple();
            $product->set_name( 'One-Time Donation' );
            $product->set_status( 'publish' );
            $product->set_regular_price( 0 );
            $product->set_virtual( true );
            $product->set_downloadable( false );
            $product->set_sold_individually( true );
        } else {
            if ( ! class_exists( 'WC_Product_Subscription' ) ) return 0;
            $product = new \WC_Product_Subscription();
            $product->set_name( 'Recurring Donation Subscription' );
            $product->set_status( 'publish' );
            $product->set_regular_price( 0 );
            $product->set_virtual( true );
            $product->set_downloadable( false );
            $product->set_sold_individually( true );
            $product->update_meta_data( '_subscription_period', 'month' );
            $product->update_meta_data( '_subscription_period_interval', '1' );
            $product->update_meta_data( '_subscription_length', '0' );
        }

        $product_id = $product->save();
        update_option( $option_key, $product_id );
        return $product_id;
    }
}

// -----------------------------------------------------------------
// Hook into order creation – uses your existing SubscriptionCreator
// -----------------------------------------------------------------
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    $form_data = WC()->session->get( 'ffwcs_form_data' );
    if ( ! $form_data ) {
        return;
    }

    $creator = new \FFWCS\Services\SubscriptionCreator();

    // Re-use the exact same method you already have
    $creator->create_from_submission(
        $form_data['user_id'],
        $form_data['product_id'],
        $form_data['insertData'],
        $form_data['map']
    );

    // Clean up
    WC()->session->set( 'ffwcs_form_data', null );
}, 10, 2 );

new CheckoutHandler();
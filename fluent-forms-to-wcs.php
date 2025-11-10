<?php
/**
 * Plugin Name: Fluent Forms → WooCommerce Subscriptions
 * Description: Create WooCommerce Subscriptions from Fluent Forms submissions. No Composer — uses a lightweight autoloader and modular OOP structure.
 * Version: 1.0.0
 * Author: SERVTECH
 * Text Domain: ff-wcs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('FFWCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FFWCS_PLUGIN_FILE', __FILE__);

require_once FFWCS_PLUGIN_DIR . 'autoloader.php';

// Boot the plugin
add_action('plugins_loaded', function() {
    \FFWCS\Plugin::instance();
}, 5);


// Enqeue the Styling 
add_action('wp_enqueue_scripts', 'ffwcs_enqueue_styles');
function ffwcs_enqueue_styles() {
    wp_enqueue_style('ffwcs-styles', plugins_url('assets/css/dev-style.css', __FILE__));
}

// Enqeue the JavaScript
add_action('wp_enqueue_scripts', 'ffwcs_enqueue_scripts');
function ffwcs_enqueue_scripts() {
    wp_enqueue_script('ffwcs-scripts', plugins_url('assets/js/dev-script.js', __FILE__), array('jquery'), '1.0.034', true);
}
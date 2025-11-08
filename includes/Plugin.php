<?php
namespace FFWCS;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main plugin bootstrap
 */
class Plugin {

    private static $instance = null;
    public $option_name = 'ffwcs_settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // load text domain if needed
        // add_action('init', [ $this, 'load_textdomain' ]);

        // Admin pages (placed under WooCommerce menu)
        add_action('admin_menu', [ $this, 'admin_menu' ]);

        // Register settings
        add_action('admin_init', [ $this, 'register_settings' ]);

        // Hook Fluent Forms submission handler
        new Hooks\FormSubmissionHandler();

        // Admin requirement checks
        add_action('admin_notices', [ $this, 'admin_requirements_notice' ]);
    }

    public function admin_menu() {
        // Place under WooCommerce for convenience if WooCommerce exists, otherwise under Settings
        if ( class_exists('WooCommerce') ) {
            add_submenu_page('woocommerce', 'FF Subscriptions', 'FF Subscriptions', 'manage_options', 'ffwcs-settings', [ $this, 'render_settings_page' ] );
        } else {
            add_options_page('FF Subscriptions', 'FF Subscriptions', 'manage_options', 'ffwcs-settings', [ $this, 'render_settings_page' ] );
        }
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [ $this, 'sanitize_settings' ]);
        add_settings_section('ffwcs_main', 'Main settings', '__return_false', $this->option_name);
        add_settings_field('form_id', 'Fluent Form ID', [ $this, 'field_form_id' ], $this->option_name, 'ffwcs_main');
        add_settings_field('product_id', 'Subscription Product ID', [ $this, 'field_product_id' ], $this->option_name, 'ffwcs_main');
        add_settings_field('field_map', 'Field mapping (JSON)', [ $this, 'field_map' ], $this->option_name, 'ffwcs_main');
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['form_id'] = isset($input['form_id']) ? absint($input['form_id']) : 0;
        $out['product_id'] = isset($input['product_id']) ? absint($input['product_id']) : 0;
        $map = isset($input['field_map']) ? $input['field_map'] : '{}';
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $out['field_map'] = wp_json_encode($decoded);
            } else {
                $out['field_map'] = '{}';
            }
        } else {
            $out['field_map'] = '{}';
        }
        return $out;
    }

    public function field_form_id() {
        $opts = get_option($this->option_name, []);
        $val = isset($opts['form_id']) ? $opts['form_id'] : '';
        printf('<input type="number" name="%1$s[form_id]" value="%2$s" class="regular-text" />', esc_attr($this->option_name), esc_attr($val));
        echo '<p class="description">Fluent Form numeric ID to listen for.</p>';
    }

    public function field_product_id() {
        $opts = get_option($this->option_name, []);
        $val = isset($opts['product_id']) ? $opts['product_id'] : '';
        printf('<input type="number" name="%1$s[product_id]" value="%2$s" class="regular-text" />', esc_attr($this->option_name), esc_attr($val));
        echo '<p class="description">WooCommerce Subscription product ID to create subscriptions for.</p>';
    }

    public function field_map() {
        $opts = get_option($this->option_name, []);
        $val = isset($opts['field_map']) ? $opts['field_map'] : '{}';
        printf('<textarea name="%1$s[field_map]" rows="6" cols="70" class="large-text code">%2$s</textarea>', esc_attr($this->option_name), esc_textarea($val));
        echo '<p class="description">JSON map of form fields to keys: email, first_name, last_name, amount, billing_period, billing_interval. Example:<br><code>{ "email":"email", "first_name":"names.first_name", "last_name":"names.last_name", "amount":"amount" }</code></p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Fluent Forms → WooCommerce Subscriptions</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections($this->option_name);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function admin_requirements_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ✅ Fluent Forms check
        if ( ! class_exists( '\FluentForm\App\Modules\Form\Form' ) && ! defined( 'FLUENTFORM' ) ) {
            echo '<div class="notice notice-error"><p><strong>Fluent Forms</strong> not detected. Please activate the Fluent Forms plugin.</p></div>';
        }

        // ✅ WooCommerce Subscriptions check
        if ( ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_create_subscription' ) ) {
            echo '<div class="notice notice-warning"><p><strong>WooCommerce Subscriptions</strong> not detected. The plugin requires WooCommerce Subscriptions to create recurring subscriptions.</p></div>';
        }
    }


}


Fluent Forms → WooCommerce Subscriptions (No Composer)
----------------------------------------------------

Drop this plugin into wp-content/plugins and activate.
Settings are available under WooCommerce → FF Subscriptions (or Settings → FF Subscriptions if WooCommerce not present).

Configuration:
- Fluent Form ID: numeric Fluent Form to listen for
- Subscription Product ID: a WooCommerce subscription product ID
- Field mapping (JSON): map form fields to keys used by the plugin
  Example mapping:
  {
    "email": "email",
    "first_name": "names.first_name",
    "last_name": "names.last_name",
    "amount": "amount",
    "billing_period": "frequency_period",
    "billing_interval": "frequency_interval"
  }

Notes:
- Requires Fluent Forms plugin active (hook: fluentform/submission_inserted)
- Requires WooCommerce + WooCommerce Subscriptions to create subscription records
- This plugin creates an order then a subscription; to charge immediately you must integrate with payment gateway tokens / saved payment methods (not included).
- The autoloader maps FFWCS\\ classes to includes/ directory.

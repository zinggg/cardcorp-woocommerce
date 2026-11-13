<?php

/**
 * Plugin Name: CardCorp Gateway
 * Plugin URI: https://cardcorp.com
 * Author: CardCorp
 * Author URI: https://cardcorp.com
 * Description: WooCommerce plugin for accepting payments through the CardCorp gateway.
 * Version: 1.7.4
 * Tested up to: 6.8.3
 * WC requires at least: 3.0
 * WC tested up to: 10.2.2
 */

include_once(dirname(__FILE__) . '/includes/cardcorp_additional.php');

add_action('before_woocommerce_init', function () {
	if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('plugins_loaded', 'init_woocommerce_cardcorp', 0);

// For upgrading customers, update old Zing tokens to Cardcorp on plugin activation
function update_gateway_id_on_activation() {
    global $wpdb;

    // Query to find tokens with gateway ID 'zing'
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = 'zing'");

    // If a token with gateway ID 'zing' exists, update it to 'cardcorp'
    if (!empty($results)) {
        $wpdb->update(
            "{$wpdb->prefix}woocommerce_payment_tokens",
            array('gateway_id' => 'cardcorp'),
            array('gateway_id' => 'zing')
        );
    }
}

// Hook the function to the plugin activation hook
register_activation_hook(__FILE__, 'update_gateway_id_on_activation');

/**
 * Init payment gateway
 */

function init_woocommerce_cardcorp()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}
	class woocommerce_cardcorp extends WC_Payment_Gateway
	{

		protected $version = '2.0';

		/**
		 * Construct Main Functions
		 */
		public function __construct()
		{
			global $woocommerce;

			$this->id				= 'cardcorp';
			$this->method_title 	= 'CardCorp Payments';
			$this->method_description = 'CardCorp Payments';

			$icon 				= plugins_url('/assets/images/general/cardcorp-dark.svg', __FILE__);
			$icon_html			= $icon_html = $this->get_icon();
			$this->icon			= $icon_html;
			$this->screen 		= plugins_url('screen.png', __FILE__);
			$this->has_fields 	= false;
			$this->init_form_fields(); // Load the form fields.
			$this->init_settings(); // Load the settings.

			/* Define user set variables */
			$this->title			= 'Card Payments';
			$this->description		= $this->settings['description'];
			$this->operation		= $this->settings['operation_mode'];
			$this->supports			= array('refunds');
			$this->basket			= $this->settings['basket'] == 'yes';
			$this->merchant_url		= $this->get_option('merchant_url');

			if ($this->operation == 'live') {
				$this->cardcorp_url			= "https://eu-prod.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['access_token'];
				$this->ENTITY_ID		= $this->settings['entity_id'];
			} else {
				$this->cardcorp_url	= "https://eu-test.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['test_access_token'];
				$this->ENTITY_ID		= $this->settings['test_entity_id'];
			}
			$this->paymentType			= 'DB';

			if ($this->settings['card_supported'] !== NULL) {
				$this->cards = implode(' ', $this->settings['card_supported']);
			}

			$this->cards_supported			= $this->get_option('card_supported');
			$this->woocommerce_version 		= $woocommerce->version;
			$this->return_url   			= add_query_arg('wc-api', 'cardcorp_payment', home_url('/'));


			if ($this->settings['dob'] == 'yes' && $this->settings['enabled'] == 'yes') {
				add_action('woocommerce_review_order_before_submit', 'check_client_age_field');
			}

			/* Actions */
			add_action('init', array($this, 'cardcorp_process'));
			add_action('woocommerce_api_cardcorp_payment', array($this, 'cardcorp_process'));
			add_action('woocommerce_receipt_cardcorp', array($this, 'receipt_page'));
			add_action('woocommerce_order_action_cardcorp_capture', array($this, 'capture_payment'));
			add_action('woocommerce_order_action_cardcorp_reverse', array($this, 'reverse_payment'));

			/* add_action to parse values to thankyou*/
			add_action('woocommerce_thankyou', array($this, 'report_payment'));
			//add_action('woocommerce_thankyou', array($this, 'parse_value_cardcorp_success_page'));

			/* add_action to parse values when error */
			add_action('woocommerce_before_checkout_form', array($this, 'parse_value_cardcorp_error'), 10);

			/* Lets check for SSL */
			add_action('admin_notices', array($this, 'do_ssl_check'));
			wp_enqueue_style('cardcorp_style', plugin_dir_url(__FILE__) . 'assets/css/cardcorp-style.css', array(), $this->version);


			$tab = isset($_GET['cardcorp_tab']) ? $_GET['cardcorp_tab'] : null;

			if ($tab !== null) {
				$GLOBALS['hide_save_button'] = true;
			} else {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			}
		}

		public function do_ssl_check()
		{
			if ($this->enabled == "yes") {
				if (get_option('woocommerce_force_ssl_checkout') == "no") { ?>
<div class="error">
    <p>
        <?= sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=advanced')); ?>

    </p>
</div>
<?php } ?>
<?php }
		}


		/**
		 * Woocommerce Admin Panel Option Manage CardCorp Settings here.
		 *
		 * @return void
		 */
		public function admin_options()
		{

			$default_tab = null;
			$tab = isset($_GET['cardcorp_tab']) ? $_GET['cardcorp_tab'] : $default_tab; ?>

<nav class="nav-tab-wrapper">

    <a href="?page=wc-settings&tab=checkout&section=cardcorp"
        class="nav-tab <?php if ($tab === null) { ?> nav-tab-active <?php } ?>">General Settings</a>

    <a href="?page=wc-settings&tab=checkout&section=cardcorp&cardcorp_tab=requires"
        class="nav-tab <?php if ($tab === "requires") { ?> nav-tab-active <?php } ?>">Requires</a>

    <a href="?page=wc-settings&tab=checkout&section=cardcorp&cardcorp_tab=logs"
        class="nav-tab <?php if ($tab === "logs") { ?> nav-tab-active <?php } ?>">Logs</a>

</nav>

<div class="tab-content">
    <?php
				switch ($tab):
					case 'requires':
						$this->requiresTab();
						break;
					case 'logs':
						$this->logsTab();
						break;
					default:
						$this->generalSettingsTab();
						break;
				endswitch;
				?>
</div>
<?php
		}

		public function generalSettingsTab()
		{
		?>
<h2>CardCorp Payment Gateway</h2>
<p>CardCorp Configuration Settings</p>
<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table>
<?php wc_enqueue_js("jQuery( function( $ ) {
				var cardcorp_test_fields = '#woocommerce_cardcorp_test_entity_id, #woocommerce_cardcorp_test_access_token'; 
				var cardcorp_live_fields = '#woocommerce_cardcorp_entity_id, #woocommerce_cardcorp_access_token'; 
				$( '#woocommerce_cardcorp_operation_mode' ).change(function(){ 
					$( cardcorp_test_fields + ',' + cardcorp_live_fields ).closest( 'tr' ).hide();
					if ( 'live' === $( this ).val() ) { 
						$( '#woocommerce_cardcorp_live_credentials, #woocommerce_cardcorp_live_credentials + p' ).show();
						$( '#woocommerce_cardcorp_test_credentials, #woocommerce_cardcorp_test_credentials + p' ).hide(); 
						$( cardcorp_live_fields ).closest( 'tr' ).show();
					} else { 
						$( '#woocommerce_cardcorp_live_credentials, #woocommerce_cardcorp_live_credentials + p' ).hide();
						$( '#woocommerce_cardcorp_test_credentials, #woocommerce_cardcorp_test_credentials + p' ).show();
						$( cardcorp_test_fields ).closest( 'tr' ).show(); 
					} 
				}).change();

				function toggleGooglePayField() {
					var selectedCards = $('input[name=\"woocommerce_cardcorp_card_supported[]\"]:checked').map(function() {
						return $(this).val();
					}).get();
					var operationMode = $('#woocommerce_cardcorp_operation_mode').val();

					if (selectedCards.indexOf('GOOGLEPAY') !== -1 && operationMode === 'live') {
						$('#woocommerce_cardcorp_googlepay_mid').closest('tr').show();
					} else {
						$('#woocommerce_cardcorp_googlepay_mid').closest('tr').hide();
					}
				}

				$('input[name=\"woocommerce_cardcorp_card_supported[]\"]').on('change', toggleGooglePayField);
				$('#woocommerce_cardcorp_operation_mode').on('change', toggleGooglePayField);
				toggleGooglePayField(); 
			});");
		}

		public function requiresTab()
		{
			global $wp_version;
		?>

<h3>Requires</h3>
<table class="requires-table">
    <tbody>
        <tr>
            <td>PHP</td>
            <td><strong><?= phpversion(); ?></strong></td>
            <td>
                <?php if (phpversion() >= '7.0.0') { ?>
                <span class="dashicons dashicons-yes cardcorp-success-text"></span>
                <?php } else if (phpversion() >= '5.2.0') { ?>
                <span class="dashicons dashicons-info cardcorp-warning-text"></span>
                <?php } else { ?>
                <span class="dashicons dashicons-no cardcorp-error-text"></span>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <td>WordPress</td>
            <td><?= $wp_version; ?></td>
            <td>
                <?php if ($wp_version >= '5.4.0') { ?>
                <span class="dashicons dashicons-yes cardcorp-success-text"></span>
                <?php } else if ($wp_version >= '5.0.0') { ?>
                <span class="dashicons dashicons-info cardcorp-warning-text"></span>
                <?php } else { ?>
                <span class="dashicons dashicons-no cardcorp-error-text"></span>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <td>WooCommerce</td>
            <td><?= WC_VERSION; ?></td>
            <td>
                <?php if (WC_VERSION >= '4.2.2') { ?>
                <span class="dashicons dashicons-yes cardcorp-success-text"></span>
                <?php } else if (WC_VERSION >= '3.0') { ?>
                <span class="dashicons dashicons-info cardcorp-warning-text"></span>
                <?php } else { ?>
                <span class="dashicons dashicons-no cardcorp-error-text"></span>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <td>Force SSL</td>
            <td><?php if (get_option('woocommerce_force_ssl_checkout') == "no") { ?> No <?php } else { ?> Yes <?php } ?>
            </td>
            <td>
                <?php if (get_option('woocommerce_force_ssl_checkout') == "no") { ?>
                <span class="dashicons dashicons-no cardcorp-error-text"></span>
                <?php } else { ?>
                <span class="dashicons dashicons-yes cardcorp-success-text"></span>
                <?php } ?>
            </td>
        </tr>
    </tbody>
</table>

<?php
		}

		public function logsTab()
		{
		?>
<h3>Logs</h3>

<textarea class="large-text logs_textarea" disabled="" rows="30"><?= esc_textarea(wp_check_invalid_utf8(get_cardcorp_logs(), true)); ?></textarea>

<?php
		}

		/**
		 * Initialise CardCorp Woo Plugin Settings Form Fields
		 *
		 * @return void
		 */
		function init_form_fields()
		{

			$this->form_fields = array(
				'enabled' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable CardCorp',
					'default' 			=> 'yes'
				),
				'force3ds' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Force 3DS Challenge',
					'default' 			=> 'yes'
				),
				'operation_mode' 	=> array(
					'title' 			=> 'Operation Mode',
					'default' 			=> 'Payments processed by CardCorp',
					'description' 		=> 'You can switch between different environments, by selecting the corresponding operation mode',
					'type' 				=> 'select',
					'class'				=> 'cardcorp_mode',
					'options' 			=> 	array(
						'test' 				=>  'Test Mode',
						'live' 				=>  'Live Mode',
					)
				),
				'description' 		=> array(
					'title' 			=> 'Description',
					'type' 				=> 'text',
					'description' 		=> 'This controls the description which the user sees during checkout',
					'default' 			=> 'Payments processed by CardCorp',
					'desc_tip'    		=> true
				),
				'test_credentials' 	=> array(
					'title'       		=> 'API Test Credentials',
					'type'        		=> 'title',
					'description' 		=> 'Enter your CardCorp Test API Credentials to process transactions via CardCorp. 
										You can get your CardCorp Test Credentials via 
										<a href="mailto:support@cardcorp.com">CardCorp Support</a>',
				),
				'test_entity_id' 	=> array(
					'title' 			=> 'Test Entity ID',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your CardCorp Test Entity ID. This is needed in order to take the payment',
					'default'			=> '',
					'desc_tip'    		=> true
				),
				'test_access_token' => array(
					'title' 			=> 'Test Access Token',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your CardCorp Test Access Token. This is needed in order to take the payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'live_credentials' 	=> array(
					'title'       		=> 'API LIVE Credentials',
					'type'        		=> 'title',
					'description' 		=> 'Enter your CardCorp Live API Credentials to process transactions via CardCorp. You can get your CardCorp Live Credentials via 
										<a href="mailto:support@cardcorp.com">CardCorp Support</a>',
				),
				'entity_id' 		=> array(
					'title' 			=> 'Entity ID',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your CardCorp Entity ID. This is needed in order to the take payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'access_token' 		=> array(
					'title' 			=> 'Access Token',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your CardCorp Access Token. This is needed in order to take the payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'googlepay_mid' 		=> array(
					'title' 			=> 'Google Pay MID',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your approved Google merchant identifier.',
					'default' 			=> '',
					'desc_tip'    		=> true,
				),
				'merchant_url' 		=> array(
					'title' 			=> 'Merchant URL',
					'type' 				=> 'text',
					'description' 		=> 'Please enter the merchant website URL sent to CardCorp with each transaction. Leave blank to omit it from the request.',
					'default' 			=> '',
					'desc_tip'    		=> true,
				),
				'hr' 				=> array(
					'title' 			=> '<hr>',
					'type' 				=> 'title',
				),
				'dob' 				=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable Over 18s Date of Birth Field',
					'default' 			=> 'no'
				),
				'basket' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable Cart/Basket above checkout form.',
					'default' 			=> 'no'
				),

				'card_supported' 	=> array(
					'title' 			=> 'Accepted Payment Methods',
					'default' 			=>  array(
						'VISA',
						'MASTER',
						'MAESTRO',
						'APPLEPAY'
					),
					'type' 				=> 'cardcorp_checkbox_list',
					'options' 		=> array(
						'VISA' 			=> 'VISA',
						'MASTER' 		=> 'MASTER',
						'MAESTRO' 		=> 'MAESTRO',
						'APPLEPAY' 		=> 'APPLEPAY',
						'GOOGLEPAY' 	=> 'GOOGLEPAY',
					)
				)
			);
		}

		public function generate_cardcorp_checkbox_list_html($key, $data)
		{
			$defaults = array(
				'title'       => '',
				'disabled'    => false,
				'class'       => '',
				'css'         => '',
				'desc_tip'    => false,
				'description' => '',
				'options'     => array(),
			);

			$data = wp_parse_args($data, $defaults);
			$field_key = $this->get_field_key($key);
			$value = $this->get_option($key, $data['default']);
			$value = is_array($value) ? $value : array_filter(array($value));

			$disabled = !empty($data['disabled']) ? 'disabled="disabled"' : '';

			ob_start();
			?>
<tr valign="top" id="<?php echo esc_attr($field_key); ?>_tr">
    <th scope="row" class="titledesc">
        <?php echo $this->get_tooltip_html($data); ?>
        <label><?php echo wp_kses_post($data['title']); ?></label>
    </th>
    <td class="forminp forminp-cardcorp-checkbox-list">
        <fieldset id="<?php echo esc_attr($field_key); ?>_container"
            class="cardcorp-checkbox-list <?php echo esc_attr($data['class']); ?>"
            style="<?php echo esc_attr($data['css']); ?>">
            <?php echo $this->get_description_html($data); ?>
            <?php foreach ($data['options'] as $option_key => $option_label) { ?>
            <label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr($field_key); ?>[]"
                    value="<?php echo esc_attr($option_key); ?>" <?php checked(in_array($option_key, $value, true)); ?>
                    <?php echo $disabled; ?> />
                <?php echo esc_html($option_label); ?>
            </label>
            <?php } ?>
        </fieldset>
    </td>
</tr>
<?php
			return ob_get_clean();
		}

		public function validate_cardcorp_checkbox_list_field($key, $value)
		{
			$field_key = $this->get_field_key($key);
			$posted_values = isset($_POST[$field_key]) ? (array) wp_unslash($_POST[$field_key]) : array();
			$allowed_values = isset($this->form_fields[$key]['options']) ? array_keys($this->form_fields[$key]['options']) : array();

			$sanitized = array();

			foreach ($posted_values as $posted_value) {
				$clean_value = wc_clean($posted_value);
				if (in_array($clean_value, $allowed_values, true)) {
					$sanitized[] = $clean_value;
				}
			}

			return $sanitized;
		}

		/**
		 * Custom Credit Card Icons on a checkout page
		 *
		 * @return void
		 */
		public function get_icon()
		{
			$icon_html = $this->cardcorp_get_icon();
			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}

		function cardcorp_get_icon()
		{
			$icon_html = '';

			if (isset($this->cards_supported) && '' !== $this->cards_supported) {

				if (!function_exists('get_plugin_data')) {
					require_once(ABSPATH . 'wp-admin/includes/plugin.php');
				}

				foreach ($this->cards_supported as $card) {
					$icons = plugins_url() . '/' . get_plugin_data(__FILE__)['TextDomain'] . '/assets/images/general/' . strtolower($card) . '.svg';
					$icon_html .= '<img src="' . $icons . '" alt="' . strtolower($card) . '" title="' . strtolower($card) . '" style="height:30px; margin:5px 0px 5px 10px; vertical-align: middle; float: none; display: inline; text-align: right;" />';
				}
			}
			return $icon_html;
		}

		/* Adding CardCorp Payment Button in checkout page. */
		function payment_fields()
		{
			if ($this->description) echo wpautop(wptexturize($this->description));
		}

		/**
		 * Creating CardCorp Payment Form.
		 *
		 * @param int $order_id
		 * @return void
		 */
		public function generate_cardcorp_payment_form($order_id)
		{
			global $woocommerce;

			$order = new WC_Order($order_id);
			if (!$order->needs_payment()) {
				return;
			}

			$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);
			$i = 0;
			$cards = "";
			$duplicates = array();
			foreach ($tokens as $token) {

				if (!in_array($token->get_token(), $duplicates)) {
					$token_get_expiry_year = $token->get_expiry_year();
					$token_get_expiry_month = $token->get_expiry_month();
					$card_expiry_str = $token_get_expiry_year . '-' . $token_get_expiry_month;
					$card_expiry_unix = strtotime($card_expiry_str);
					$expiry_last_day_month_unix = strtotime(date("Y-m-t", $card_expiry_unix));

					$date_now_unix = strtotime(date('Y-m-d'));

					if ($expiry_last_day_month_unix > $date_now_unix) {
						$duplicates[] = $token->get_token();
						$cards .= "&registrations[" . $i . "].id=" . urlencode($token->get_token());
						$i++;
					}
				}
			}


			/* Required Order Details */
			$amount 	= $order->get_total();
			$currency 	= $order->get_currency();
			$url = $this->cardcorp_url . "/v1/checkouts";
			$customer =  get_current_user_id() != 0 ? get_current_user_id() : $order_id;
			$billing_street = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
			$billing_street2 = '';
			if (mb_strlen($billing_street) > 100) {
				$billing_street = mb_substr($order->get_billing_address_1(), 0, 100);
				$billing_street2 = mb_substr($order->get_billing_address_2(), 0, 100);
			}
			$billing_postcode = $order->get_billing_postcode();
			if (mb_strlen($billing_postcode) > 16) {
				$billing_postcode = preg_replace('/[\s-]+/u', '', $billing_postcode);
			}
			$shipping_postcode = $order->get_shipping_postcode();
			if (mb_strlen($shipping_postcode) > 16) {
				$shipping_postcode = preg_replace('/[\s-]+/u', '', $shipping_postcode);
			}
			$email = $order->get_billing_email();
			$phone = $order->get_billing_phone();
			$data = "entityId=" . urlencode($this->ENTITY_ID)
				. "&currency=" . urlencode($currency)
				. "&merchantTransactionId=" . urlencode($order->get_id() . rand(1000, 9999))
				. "&amount=" . urlencode($order->get_total())
				. "&billing.city=" . urlencode(mb_substr($order->get_billing_city(), 0, 80))
				. "&billing.country=" . urlencode($order->get_billing_country())
				. "&billing.street1=" . urlencode($billing_street)
				. ($billing_street2 !== '' ? "&billing.street2=" . urlencode($billing_street2) : '')
				. (mb_strlen($billing_postcode) <= 16 ? "&billing.postcode=" . urlencode($billing_postcode) : '')
				. (mb_strlen($email) <= 128 ? "&customer.email=" . urlencode($email) : '')
				. (mb_strlen($phone) <= 25 ? "&customer.phone=" . urlencode($phone) : '')
				. "&customer.givenName=" . urlencode(mb_substr($order->get_billing_first_name(), 0, 50))
				. "&customer.surname=" . urlencode(mb_substr($order->get_billing_last_name(), 0, 50))
				. "&customer.merchantCustomerId=" . urlencode($customer)
				. "&integrity=true";

			if (!empty($this->merchant_url)) {
				$data .= "&merchant.url=" . urlencode($this->merchant_url);
			}

			if($this->settings['force3ds'] == 'yes'){
				$data .= "&threeDSecure.challengeIndicator=04"; 
			};

			if ($this->operation == 'test'){
				$data .= "&testMode=EXTERNAL"; 
			};

			if (($this->operation == 'test') && $this->settings['force3ds'] == 'yes'){
				$data .= "&customParameters[3DS2_enrolled]=true"; 
				$data .= "&customParameters[3DS2_flow]=challenge"; 
			};

			$data .= "&paymentType=" . urlencode($this->paymentType)
				. "&shipping.city=" . urlencode(mb_substr($order->get_shipping_city(), 0, 80))
				. "&shipping.country=" . urlencode($order->get_shipping_country())
				. "&shipping.street1=" . urlencode(mb_substr($order->get_shipping_address_1(), 0, 100))
				. (mb_strlen($shipping_postcode) <= 16 ? "&shipping.postcode=" . urlencode($shipping_postcode) : '')
				. $cards;



			$fingerprint = hash('sha256', preg_replace('/&merchantTransactionId=[^&]*/', '', $data));
			$checkout = null;
			$stored_id = (string) $order->get_meta('_cardcorp_checkout_id');
			$stored = $order->get_meta('_cardcorp_checkout_data');
			if ($stored_id !== '' && is_array($stored)) {
				$read = wp_remote_post(
					$this->cardcorp_url . '/v1/checkouts/' . $stored_id . '/payment?entityId=' . $this->ENTITY_ID,
					array(
						'method' => 'GET',
						'headers' => array('Authorization' => 'Bearer ' . $this->ACCESS_TOKEN),
						'sslverify' => true,
						'timeout' => 45
					)
				);
				$read_status = is_wp_error($read) ? null : json_decode($read['body']);
				// A refused (rate-limited) read is no answer
				$answered = is_object($read_status) && isset($read_status->result->code)
					&& (int) wp_remote_retrieve_response_code($read) !== 429
					&& (string) $read_status->result->code !== '800.120.100';
				$fresh = (time() - (int) $stored['created']) < 30 * MINUTE_IN_SECONDS && hash_equals((string) $stored['fingerprint'], $fingerprint);
				if ($answered && $this->cardcorp_is_success_code($read_status->result->code, $this->operation == 'live')) {
					$outcome = $this->cardcorp_apply_payment($read_status, $order);
					wc_print_notice($outcome === 'mismatch'
						? 'We received your payment, but it must be checked by the shop. Please do not pay again.'
						: 'Your payment for this order has been received.', 'notice');
					echo '<p><a href="' . esc_url($this->get_return_url($order)) . '">View your order</a></p>';
					return;
				}
				if ($answered && preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', (string) $read_status->result->code)) {
					// Manual review: the money is taken
					$this->cardcorp_apply_payment($read_status, $order, true);
					wc_print_notice('We received your payment, but it must be checked by the shop. Please do not pay again.', 'notice');
					echo '<p><a href="' . esc_url($this->get_return_url($order)) . '">View your order</a></p>';
					return;
				}
				if ($fresh) {
					$checkout = (object) array('id' => $stored_id, 'integrity' => $stored['integrity']);
				} elseif (!$answered) {
					wc_print_notice('We could not check your previous payment. Please reload in a minute and do not pay again.', 'error');
					return;
				}
			}
			if ($checkout === null) {
				$created_at = time();
				$head_data = "Bearer " . $this->ACCESS_TOKEN;
				$gtwresponse = wp_remote_post(
					$url,
					array(
						'headers' => array('Authorization' => $head_data),
						'body' => $data,
						'sslverify' => 'true', // this should be set to true in production
						'timeout' => 200,
					)
				);
				if (is_wp_error($gtwresponse)) {
					cardcorp_write_log(sprintf('Order %s: checkout not created: %s', $order->get_id(), $gtwresponse->get_error_message()));
					wc_print_notice('We could not reach the payment service. Please reload this page in a minute.', 'error');
					return;
				}
				$status = json_decode($gtwresponse['body']);
				if (!isset($status->id)) {
					if (isset(json_decode($gtwresponse['body'])->result->parameterErrors[0]) && !empty(json_decode($gtwresponse['body'])->result->parameterErrors[0])) {
						$ee = json_decode($gtwresponse['body'])->result->parameterErrors[0];
						$order->add_order_note(sprintf('CardCorp Configuration error: %s', 'Field: ' . $ee->name . ', Value: ' . $ee->value . ', Error:' . $ee->message));
						cardcorp_write_log(sprintf('Order %s: CardCorp Configuration error: Field: %s, Error: %s', $order->get_id(), $ee->name, $ee->message));
					} else {
						$order->add_order_note(sprintf('CardCorp Configuration error: %s', $gtwresponse['body']));
						$err = json_decode($gtwresponse['body']);
						cardcorp_write_log(sprintf('Order %s: CardCorp Configuration error: %s %s', $order->get_id(), isset($err->result->code) && is_scalar($err->result->code) ? $err->result->code : '-', isset($err->result->description) && is_scalar($err->result->description) ? $err->result->description : '-'));
					}
					wc_add_notice('Configuration error', 'error');
					wp_safe_redirect(wc_get_page_permalink('cart'));
					return;
				}
				$order->read_meta_data(true);
				$other_id = (string) $order->get_meta('_cardcorp_checkout_id');
				$other = $order->get_meta('_cardcorp_checkout_data');
				if ($other_id !== '' && $other_id !== $stored_id && is_array($other)) {
					$status = (object) array('id' => $other_id, 'integrity' => $other['integrity']);
				} else {
					$order->update_meta_data('_cardcorp_checkout_id', $status->id);
					$order->update_meta_data('_cardcorp_checkout_data', array('integrity' => $status->integrity, 'created' => $created_at, 'fingerprint' => $fingerprint));
					$order->save();
				}
			} else {
				$status = $checkout;
			} ?>

<?php if ($this->basket) {
						echo do_shortcode('[woocommerce_cart]');
					?>
<ul style="display:block" class="order_details">
    <li class="order">
        <?php esc_html_e('Order number:', 'woocommerce'); ?>
        <strong><?php echo esc_html($order->get_order_number()); ?></strong>
    </li>
    <li class="date">
        <?php esc_html_e('Date:', 'woocommerce'); ?>
        <strong><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></strong>
    </li>
    <li class="total">
        <?php esc_html_e('Total:', 'woocommerce'); ?>
        <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
    </li>
    <?php if ($order->get_payment_method_title()) : ?>
    <li class="method">
        <?php esc_html_e('Payment method:', 'woocommerce'); ?>
        <strong><?php echo wp_kses_post($order->get_payment_method_title()); ?></strong>
    </li>
    <?php endif; ?>
</ul>
<style>
#ajax-loading-screen,
.woocommerce-cart-form .actions,
.product-remove {
    display: none !important;
}

.woocommerce-cart-form {
    margin-bottom: 1.5rem;
}

.order_details {
    display: none;
}
</style>
<script>
$ = jQuery;
$.each($(".product-quantity select"), function() {
    $(this).replaceWith('x ' + $(this).val())
});
</script>
<?php } else { ?>
<style>
#ajax-loading-screen,
.order_details {
    display: none;
}
</style>
<?php } ?>



<?php

					if (!function_exists('get_plugin_data')) {
						require_once(ABSPATH . 'wp-admin/includes/plugin.php');
					}

					// ICON
					// <div id=\"d3\"><img border=\"0\" src=\"' . plugins_url() . '/'. get_plugin_data( __FILE__ )['TextDomain'] .'/assets/images/general/cardcorp-dark.svg\" alt=\"Secure Payment\"></div>
					$lang = strtolower(substr(get_bloginfo('language'), 0, 2));
                    echo '<script src="' . $this->cardcorp_url . '/v1/paymentWidgets.js?checkoutId=' . $status->id . '" integrity="' . $status->integrity . '" crossorigin="anonymous"></script>';
					echo '<script src="https://code.jquery.com/jquery-2.1.4.min.js" type="text/javascript"></script>';
					echo '<script type="text/javascript">
						var wpwlOptions = { 
						style: "plain",' . PHP_EOL;
					echo 'locale: "' . $lang . '",' . PHP_EOL;
					if (strpos($this->cards, 'APPLEPAY') !== false && $i == 0) {
						echo 'applePay: {' . PHP_EOL;
						echo '    merchantIdentifier: "' . esc_js($this->ENTITY_ID) . '",' . PHP_EOL;
						echo '    checkAvailability: "applePayCapabilities",' . PHP_EOL;
						echo '    buttonType: "pay",' . PHP_EOL;
						echo '    buttonColor: "white"' . PHP_EOL;
						echo '},' . PHP_EOL;
					}
					if (strpos($this->cards, 'GOOGLEPAY') !== false && $i == 0) {
						echo 'googlePay: {' . PHP_EOL;
						echo '    gatewayMerchantId: "' . esc_js($this->ENTITY_ID) . '",' . PHP_EOL;
						
						if ($this->operation == 'live') {
							echo '    merchantId: "' . esc_js($this->settings['googlepay_mid']) . '",' . PHP_EOL;
						}
						echo '    buttonType: "pay",' . PHP_EOL;
						echo '    buttonSizeMode: "fill",' . PHP_EOL;
						echo '},' . PHP_EOL;
					}
					echo 'showCVVHint: true,
						brandDetection: true,
						showPlaceholders: true,
						autofocus : null,
						showLabels: false,
						registrations: {
							requireCvv: true
						},
						labels: {
							givenName: "Firstname", 
							surname: "Lastname",
							cardHolder: "Cardholder",
						},
						allowEmptyCardHolderName: false,
						errorMessages: {
						    cardHolderError: "Invalid card holder",
							givenNameError: "Invalid Firstname",
							surNameError: "Invalid Lastname",
						},
						onReady: function(e) { 
							$(".wpwl-group-cvv").after( $(".wpwl-group-cardHolder").detach()); 

							var $toggleButton = $("button[data-action=show-initial-forms]");
							var $cardForm = $(".wpwl-form-card");
							if ($toggleButton.length) {
								$toggleButton.trigger("click");
								$toggleButton.hide();
							}
							var $cardContainers = $(".wpwl-container-card");
							var $paymentContainer = $("#cardcorp_payment_container");
							var hasSavedCards = $(".wpwl-container-registration .wpwl-registration").length > 0;
							$paymentContainer.toggleClass("cardcorp-has-registrations", hasSavedCards);
							$cardContainers.toggleClass("cardcorp-section--after-registrations", hasSavedCards);
							if (hasSavedCards && !$(".cardcorp-alt-card-title--registrations").length) {
								$(".wpwl-container-registration").prepend("<p class=\"cardcorp-alt-card-title cardcorp-alt-card-title--registrations\">Saved cards</p>");
							}
				if ($cardForm.length && !$cardForm.prev(".cardcorp-alt-card-title").length) {
					var cardFormHeading = hasSavedCards ? "Use a different card" : "Enter your card details";
					$cardForm.before("<p class=\"cardcorp-alt-card-title\">" + cardFormHeading + "</p>");
				}

				var $cardBrandGroup = $cardForm.find(".wpwl-group-brand");
				$cardBrandGroup.addClass("cardcorp-brand-group").hide();

				var $altPayments = $(".wpwl-container-virtualAccount-APPLEPAY, .wpwl-container-virtualAccount-GOOGLEPAY");
							if ($altPayments.length && !$(".alt_payments_buttons_container").length) {
								var $altContainer = $altPayments.wrapAll("<div class=\"alt_payments_buttons_container\"></div>").first().parent();
								$altContainer.prepend("<p class=\"cardcorp-alt-card-title cardcorp-alt-card-title--alt-payments\">Or continue with</p>");
							}

						    $(".wpwl-group-cardNumber").after( $(".wpwl-group-cardHolder").detach());
							var visa = $(".wpwl-brand:first").clone().removeAttr("class").attr("class", "wpwl-brand-card wpwl-brand-custom wpwl-brand-VISA");
							var master = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MASTER");
							var maestro = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MAESTRO");
							var amex = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-AMEX");
							var diners = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-DINERS");
							var jcb = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-JCB");
							var createRegistrationHtml = "<div class=\"customLabel\"><div class=\"customInput\"><input type=\"checkbox\" name=\"createRegistration\" value=\"true\" /></div> Store payment details?</div>";
							$("form.wpwl-form-card").find(".wpwl-button").before(createRegistrationHtml);
							$(".wpwl-brand:first")';
					if (strpos($this->cards, 'VISA') !== false && $i == 0) {
						echo '.after($(visa))';
					}
					if (strpos($this->cards, 'MASTER') !== false && $i == 0) {
						echo '.after($(master))';
					}
					if (strpos($this->cards, 'MAESTRO') !== false && $i == 0) {
						echo '.after($(maestro))';
					}
					if (strpos($this->cards, 'AMEX') !== false && $i == 0) {
						echo '.after($(amex))';
					}
					if (strpos($this->cards, 'DINERS') !== false && $i == 0) {
						echo '.after($(diners))';
					}
					if (strpos($this->cards, 'JCB') !== false && $i == 0) {
						echo '.after($(jcb))';
					}
					echo ';' . PHP_EOL;
					echo '},
					onChangeBrand: function(e){
						$(".wpwl-brand-custom").css("opacity", "0.2");
						$(".wpwl-brand-" + e).css("opacity", "5"); 
						var $brandGroup = $(".cardcorp-brand-group");
						if (e) {
							$brandGroup.show().addClass("cardcorp-brand-group--visible");
						} else {
							$brandGroup.hide().removeClass("cardcorp-brand-group--visible");
						}
					}
					}
					</script>';
					if ($this->operation == 'test') {
						echo '<div class="testmode">';
						echo '<span class="testmode__badge">Test Mode</span>';
						echo '<span class="testmode__copy">This is the TEST MODE. No money will be charged.</span>';
						echo '</div>';
					}
					echo '<div id="cardcorp_payment_container">';
					echo '<form action="' . $this->return_url . '" class="paymentWidgets" data-brands="' . $this->cards . '"></form>';
					echo '<div class="cardcorp-logo-container">';
					echo '<a href="https://cardcorp.com" target="_blank" title="CardCorp – Secure Online Payments & Payment Gateway Solutions">';
					echo '<img src="' . plugins_url() . '/' . get_plugin_data(__FILE__)['TextDomain'] . '/assets/images/general/cardcorp-dark.svg" width="100px" alt="CardCorp logo – Payment Gateway and Online Payment Solutions">';
					echo '</a>';
					echo '</div>';
					echo '</div>';
					
		}

		/**
		 * Checkout status path returned by the payment widget, e.g. /v1/checkouts/{checkoutId}/payment
		 *
		 * @param mixed $value
		 * @return string|false
		 */
		private function cardcorp_valid_resource_path($value)
		{
			if (!is_string($value) || strlen($value) > 160) {
				return false;
			}
			return preg_match('#^/v1/checkouts/[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*/payment$#D', $value) ? $value : false;
		}

		/**
		 * Payment or transaction identifier as issued by the gateway
		 *
		 * @param mixed $value
		 * @return string|false
		 */
		private function cardcorp_valid_payment_id($value)
		{
			if (!is_string($value) || strlen($value) > 64) {
				return false;
			}
			return preg_match('#^[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*$#D', $value) ? $value : false;
		}

		/**
		 * Whether a result code is a success, per the gateway's pattern
		 *
		 * @param string $code
		 * @param bool $exclude_test_codes reject the 000.100.1 test results (live mode)
		 * @return bool
		 */
		private function cardcorp_is_success_code($code, $exclude_test_codes)
		{
			return preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', (string) $code)
				&& !($exclude_test_codes && preg_match('/^000\.100\.1/', (string) $code));
		}

		/**
		 * Compare the payment status details with the order they refer to
		 *
		 * @param object $status
		 * @param WC_Order $order
		 * @return bool
		 */
		private function cardcorp_status_matches_order($status, $order)
		{
			if (!isset($status->id) || !$this->cardcorp_valid_payment_id($status->id)) {
				return false;
			}
			if (!isset($status->amount) || !is_numeric($status->amount) || abs((float) $status->amount - (float) $order->get_total()) >= 0.005) {
				return false;
			}
			if (!isset($status->currency) || !is_string($status->currency)) {
				return false;
			}
			return strtoupper($status->currency) === strtoupper($order->get_currency());
		}

		/**
		 * Apply a successful payment status to its order. Neither redirects nor exits
		 *
		 * @param object $status
		 * @param WC_Order $order
		 * @param bool $review manual review: record the payment and put the order on hold
		 * @return string 'mismatch', 'second', 'review' or 'paid'
		 */
		private function cardcorp_apply_payment($status, $order, $review = false)
		{
						if (!$this->cardcorp_status_matches_order($status, $order)) {
							$mismatch_note = sprintf(
								'CardCorp Transaction not completed: the payment details did not match the order. The Transaction ID was %s, amount %s %s.',
								isset($status->id) && $this->cardcorp_valid_payment_id($status->id) ? $status->id : '-',
								isset($status->amount) && is_numeric($status->amount) ? $status->amount : '-',
								isset($status->currency) && is_string($status->currency) && preg_match('/^[A-Za-z]{3}$/D', $status->currency) ? strtoupper($status->currency) : '-'
							);
							$order->add_order_note($mismatch_note);
							cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $mismatch_note));
							if ($order->needs_payment()) {
								$order->update_status('on-hold');
							}
							return 'mismatch';
						}

						$existing = (string) $order->get_transaction_id();
						if ($existing !== '' && $existing !== (string) $status->id && !$order->needs_payment()) {
							$note = sprintf('CardCorp second payment %s on this order, which is already paid by %s: refund it in the CardCorp back office.', $status->id, $existing);
							$order->add_order_note($note);
							cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $note));
							return 'second';
						}

						if ($review) {
							$order->set_transaction_id($status->id);
							$order->update_meta_data('_cardcorp_charged_currency', strtoupper($status->currency));
							$order->add_order_note(sprintf('CardCorp payment %s needs manual review (%s): the money is taken; check it in the CardCorp back office.', $status->id, $status->result->code));
							$order->save();
							if ($order->needs_payment()) {
								$order->update_status('on-hold');
							}
							return 'review';
						}

						if (isset($status->registrationId) && !empty($status->registrationId) && get_current_user_id() > 0) {
							$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);
							$cards = array();
							foreach ($tokens as $token) {
								$cards[] = $token->get_token();
							}
							if (!in_array($status->registrationId, $cards)) {
								$token = new WC_Payment_Token_CC();
								$token->set_token($status->registrationId); // Token comes from payment processor
								$token->set_gateway_id($this->id);
								$token->set_last4($status->card->last4Digits);
								$token->set_expiry_year($status->card->expiryYear);
								$token->set_expiry_month($status->card->expiryMonth);
								$token->set_card_type($status->paymentBrand);
								$token->set_user_id(get_current_user_id());
								$token->save();
								WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
							}
						}


						$order->add_order_note(sprintf('CardCorp Transaction Successful. The Transaction ID was %s and Payment Status %s. 
						Payment type was %s. Authorisation bank code: %s', $status->id, $status->result->description, $status->paymentType, $status->resultDetails->ConnectorTxID3));
						cardcorp_write_log(sprintf('Order %s: CardCorp Transaction Successful. The Transaction ID was %s and Payment Status %s. 
						Payment type was %s. Authorisation bank code: %s', $order->get_id(), $status->id, $status->result->description, $status->paymentType, $status->resultDetails->ConnectorTxID3));

					$message = sprintf('Transaction Successful. The status message <b>%s</b>', $status->result->description);
					$bank_code = $status->resultDetails;
					$astrxId = $status->id;

					$order->payment_complete($status->id);
					$order->update_meta_data('bank_code', $bank_code);
					$order->update_meta_data('AS-TrxId', $astrxId);
					$order->update_meta_data('_cardcorp_charged_currency', strtoupper($status->currency));
					$order->save();

						if (in_array($status->paymentType, array('DB'))) {
							//$order->update_status('wc-accepted');
							if (version_compare(WOOCOMMERCE_VERSION, "2.6") <= 0) {
								$order->reduce_order_stock();
							} else {
								//wc_reduce_stock_levels($orderid);
							}
						} else {
							$order->update_status('wc-preauth');
						}
						return 'paid';
		}

		/**
		 * Updating the Payment Status and redirect to Success/Fail Page
		 *
		 * @return void
		 */
		public function cardcorp_process()
		{
			global $woocommerce;
			global $wpdb;
			$resource_path = isset($_GET['resourcePath']) ? $this->cardcorp_valid_resource_path(wp_unslash($_GET['resourcePath'])) : false;
			if ($resource_path) {
				$url = $this->cardcorp_url . $resource_path;
				$url .= "?entityId=" . $this->ENTITY_ID;
				$head_data = "Bearer " . $this->ACCESS_TOKEN;
				$gtwresponse = wp_remote_post(
					$url,
					array(
						'method' => 'GET',
						'headers' => array('Authorization' => $head_data),
						'sslverify' => true,
						'timeout'	=> 45
					)
				);

				$status = is_wp_error($gtwresponse) ? null : json_decode($gtwresponse['body']);
				// A refused (rate-limited) answer is no answer either
				$refused = !is_wp_error($gtwresponse) && ((int) wp_remote_retrieve_response_code($gtwresponse) === 429
					|| (isset($status->result->code) && (string) $status->result->code === '800.120.100'));
				if ($refused || !is_object($status) || !isset($status->result->code)) {
					preg_match('#^/v1/checkouts/([^/]+)/payment$#', $resource_path, $m);
					$checkout_id = isset($m[1]) ? $m[1] : '-';
					$why = is_wp_error($gtwresponse) ? $gtwresponse->get_error_message() : ($refused ? 'request refused (rate limited)' : 'unreadable response');
					cardcorp_write_log(sprintf('Checkout %s: payment status not readable: %s', $checkout_id, $why));
					wc_add_notice('We could not confirm your payment yet. If you have paid, please do not pay again.', 'notice');
					$found = wc_get_orders(array('limit' => 1, 'meta_key' => '_cardcorp_checkout_id', 'meta_value' => $checkout_id));
					$found = ($found && $found[0]->get_meta('_cardcorp_checkout_id') === $checkout_id) ? $found[0] : null;
					if ($found) {
						$found->add_order_note(sprintf('CardCorp payment status for checkout %s could not be read: %s', $checkout_id, $why));
						wp_redirect($found->get_checkout_payment_url(true));
						exit;
					}
					wp_redirect(wc_get_checkout_url());
					exit;
				}
				{
					$code = (string) $status->result->code;
					$order = isset($status->merchantTransactionId) && is_scalar($status->merchantTransactionId) ? wc_get_order(substr((string) $status->merchantTransactionId, 0, -4)) : false;
					if (!$order) {
						// No order to update (e.g. expired checkout), send the shopper back to the checkout page
						$astrx_id = isset($status->id) ? $this->cardcorp_valid_payment_id($status->id) : false;
						$url = wc_get_checkout_url();
						if ($astrx_id) {
							$url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'astrxId=' . $astrx_id;
						}
						wp_redirect($url);
						exit;
					}
					if ($this->cardcorp_is_success_code($code, $this->operation == 'live')) {

						$outcome = $this->cardcorp_apply_payment($status, $order);
						if ($outcome === 'mismatch') {
							wc_add_notice('We received your payment, but it must be checked by the shop. Please do not pay again.', 'notice');
							wp_redirect($this->get_return_url($order));
							exit;
						}
						if ($outcome === 'second') {
							wp_redirect($this->get_return_url($order));
							exit;
						}
					$astrxId = $status->id;
					WC()->cart->empty_cart();

						/* Add content to the WC emails. */
						$query = parse_url($this->get_return_url($order), PHP_URL_QUERY);
						if ($query) {
							$url = $this->get_return_url($order) . '&astrxId=' . $astrxId;
						} else {
							$url = $this->get_return_url($order) . '?astrxId=' . $astrxId;
						}

						wp_redirect($url);
						exit;
					} elseif (preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', $code)) {
						// Manual review: the money is taken
						$this->cardcorp_apply_payment($status, $order, true);
						wc_add_notice('We received your payment, but it must be checked by the shop. Please do not pay again.', 'notice');
						wp_redirect($this->get_return_url($order));
						exit;
					} elseif (preg_match('/^(800\.400\.5|100\.400\.500|900\.100\.300|000\.400\.030|000\.400\.1[12]0|000\.500\.)/', $code)) {
						// Delayed or uncertain: not final
						$note = sprintf('CardCorp payment %s is not final (%s %s): check it in the CardCorp back office before any action.', isset($status->id) && $this->cardcorp_valid_payment_id($status->id) ? $status->id : '-', $code, $status->result->description);
						$order->add_order_note($note);
						cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $note));
						if ($order->needs_payment()) {
							$order->update_status('on-hold');
						}
						wc_add_notice('We received your payment, but it must be checked by the shop. Please do not pay again.', 'notice');
						wp_redirect($this->get_return_url($order));
						exit;
					} elseif (preg_match('/^000\.200/', $code)) {
						// Pending: the stored checkout is kept
						$order->add_order_note(sprintf('CardCorp Transaction pending. The Transaction Status %s', $status->result->description));
						cardcorp_write_log(sprintf('Order %s: CardCorp Transaction pending. The Transaction Status %s', $order->get_id(), $status->result->description));
					$astrxId = isset($status->id) && is_scalar($status->id) ? rawurlencode((string) $status->id) : '';
					$query = parse_url(wc_get_checkout_url(), PHP_URL_QUERY);
					if ($query) {
						$url = wc_get_checkout_url() . '&astrxId=' . $astrxId;
					} else {
						$url = wc_get_checkout_url() . '?astrxId=' . $astrxId;
					}
						wp_redirect($url);
						exit;
					} else {
						include_once(dirname(__FILE__) . '/includes/error_list.php');
						$resp_code = $status->result->code;
						$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] : $status->result->description;
						cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $resp_code_translated));
						$order->add_order_note(sprintf('CardCorp Transaction Failed. The Transaction Status %s', $status->result->description));
						cardcorp_write_log(sprintf('Order %s: CardCorp Transaction Failed. The Transaction Status %s', $order->get_id(), $status->result->description));
						// $declinemessage = sprintf('Transaction Unsuccessful. The status message <b>%s</b>', $resp_code_translated ) ;
						// wc_add_notice( $declinemessage, 'error' );
						if (preg_match('#^/v1/checkouts/([^/]+)/payment$#', $resource_path, $m)
							&& $order->get_meta('_cardcorp_checkout_id') === $m[1]) {
							$order->delete_meta_data('_cardcorp_checkout_id');
							$order->delete_meta_data('_cardcorp_checkout_data');
							$order->save();
						}
					$astrxId = isset($status->id) && is_scalar($status->id) ? rawurlencode((string) $status->id) : '';
					$query = parse_url(wc_get_checkout_url(), PHP_URL_QUERY);
					if ($query) {
						$url = wc_get_checkout_url() . '&astrxId=' . $astrxId;
					} else {
						$url = wc_get_checkout_url() . '?astrxId=' . $astrxId;
					}
						wp_redirect($url);
						exit;
					}
				}
			}
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int$order_id
		 * @return array
		 */
		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);
			$redirect = $order->get_checkout_payment_url(true);
			if (!$redirect) {
				$redirect = wc_get_checkout_url();
			}
			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);
		}

		/**
		 * Capture the payment and return the result
		 *
		 * @param int $order_id
		 * @return void
		 */
		function capture_payment($order_id)
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency = (string) $order->get_meta('_cardcorp_charged_currency');
			if ($currency === '') {
				if (strtoupper($order->get_currency()) !== strtoupper((string) get_option('woocommerce_currency'))) {
					$msg = 'Process this order in the CardCorp back office: the currency it was charged in is not recorded.';
					$order->add_order_note('CardCorp Capture not sent. ' . $msg);
					return false;
				}
				$currency = $order->get_currency();
			}
			$raw = $this->capture_request($order_trx_id_cardcorp, $amount, $currency);
			$response = is_wp_error($raw) ? null : json_decode($raw);
			if (!is_object($response) || !isset($response->result->code) || preg_match('/^(900\.100\.300|000\.400\.030|000\.400\.0[^3]|000\.400\.100|000\.400\.1[12]0|000\.500\.|800\.400\.5|100\.400\.500)/', (string) $response->result->code)) {
				$msg = 'CardCorp capture outcome unknown: check the back office before retrying';
				$order->add_order_note($msg);
				cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $msg));
				return false;
			}
			if ($this->cardcorp_is_success_code($response->result->code, false)) {
				$order->add_order_note(sprintf('CardCorp Capture Processed Successful. The Capture ID was %s and Request Status => %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Order %s: CardCorp Capture Processed Successful. The Capture ID was %s and Request Status => %s', $order->get_id(), $response->id, $response->result->description));
				$order->update_status('wc-accepted');
				return true;
			} else {
				$order->add_order_note(sprintf('CardCorp Capture Request Failed. The Capture Status => %s. Code is == %s', $response->result->description, $response->result->code));
				cardcorp_write_log(sprintf('Order %s: CardCorp Capture Request Failed. The Capture Status => %s. Code is == %s', $order->get_id(), $response->result->description, $response->result->code));
				return false;
			}
			return false;
		}

		/**
		 * Capture Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return string
		 */
		function capture_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=DB";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			return $gtwresponse;
		}

		/**
		 * Reverse the payment and return the result
		 *
		 * @param int $order_id
		 * @return bool
		 */
		function reverse_payment($order_id)
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency = (string) $order->get_meta('_cardcorp_charged_currency');
			if ($currency === '') {
				if (strtoupper($order->get_currency()) !== strtoupper((string) get_option('woocommerce_currency'))) {
					$msg = 'Process this order in the CardCorp back office: the currency it was charged in is not recorded.';
					$order->add_order_note('CardCorp Reversal not sent. ' . $msg);
					return false;
				}
				$currency = $order->get_currency();
			}
			$raw = $this->reverse_request($order_trx_id_cardcorp, $amount, $currency);
			$response = is_wp_error($raw) ? null : json_decode($raw);
			if (!is_object($response) || !isset($response->result->code) || preg_match('/^(900\.100\.300|000\.400\.030|000\.400\.0[^3]|000\.400\.100|000\.400\.1[12]0|000\.500\.|800\.400\.5|100\.400\.500)/', (string) $response->result->code)) {
				$msg = 'CardCorp reversal outcome unknown: check the back office before retrying';
				$order->add_order_note($msg);
				cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $msg));
				return false;
			}
			if ($this->cardcorp_is_success_code($response->result->code, false)) {
				$order->add_order_note(sprintf('CardCorp Reversal Processed Successful. The Reversal ID was: %s and Request Status: %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Order %s: CardCorp Reversal Processed Successful. The Reversal ID was: %s and Request Status: %s', $order->get_id(), $response->id, $response->result->description));
				$order->update_status('wc-reversed');
				return true;
			} else {
				$order->add_order_note(sprintf('CardCorp Reversal Request Failed. The Reversal Status: %s. Code is: %s', $response->result->description, $response->result->code));
				cardcorp_write_log(sprintf('Order %s: CardCorp Reversal Request Failed. The Reversal Status: %s. Code is: %s', $order->get_id(), $response->result->description, $response->result->code));
				return false;
			}
			return false;
		}

		/**
		 * Reverse Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return void
		 */
		function reverse_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=RV";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			return $gtwresponse;
		}

		/**
		 * Receipt Page
		 *
		 * @param int $order
		 * @return void
		 */
		function receipt_page($order)
		{
			$this->generate_cardcorp_payment_form($order);
		}

		/**
		 * Refund the payment and return the result
		 *
		 * @param int $order_id
		 * @param NULL $amount
		 * @param string $reason
		 * @return void
		 */
		function process_refund($order_id, $amount = null, $reason = '')
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$full_refund = $amount === null || $amount === '' || abs((float) $amount - (float) $order->get_total()) < 0.005;
			$amount 	= $full_refund ? $order->get_total() : number_format((float) $amount, 2, '.', '');
			$currency = (string) $order->get_meta('_cardcorp_charged_currency');
			if ($currency === '') {
				if (strtoupper($order->get_currency()) !== strtoupper((string) get_option('woocommerce_currency'))) {
					$msg = 'Process this order in the CardCorp back office: the currency it was charged in is not recorded.';
					$order->add_order_note('CardCorp Refund not sent. ' . $msg);
					return new WP_Error('cardcorp_currency_unknown', $msg);
				}
				$currency = $order->get_currency();
			}
			$raw = $this->refund_request($order_trx_id_cardcorp, $amount, $currency);
			$response = is_wp_error($raw) ? null : json_decode($raw);
			if (!is_object($response) || !isset($response->result->code) || preg_match('/^(900\.100\.300|000\.400\.030|000\.400\.0[^3]|000\.400\.100|000\.400\.1[12]0|000\.500\.|800\.400\.5|100\.400\.500)/', (string) $response->result->code)) {
				$msg = 'CardCorp refund outcome unknown: check the back office before retrying';
				$order->add_order_note($msg);
				cardcorp_write_log(sprintf('Order %s: %s', $order->get_id(), $msg));
				return new WP_Error('cardcorp_refund_unknown', $msg);
			}
			if ($this->cardcorp_is_success_code($response->result->code, false)) {
				$order->add_order_note(sprintf('CardCorp Refund Processed Successful. The Refund ID: %s and Request Status: %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Order %s: CardCorp Refund Processed Successful. The Refund ID: %s and Request Status: %s', $order->get_id(), $response->id, $response->result->description));
				if ($full_refund) {
					$order->update_status('wc-refunded');
				}
				return true;
			} else {
				$order->add_order_note(sprintf('CardCorp Refund Request Failed. The Refund Status: %s', $response->result->description));
				cardcorp_write_log(sprintf('Order %s: CardCorp Refund Request Failed. The Refund Status: %s', $order->get_id(), $response->result->description));
				return false;
			}
			return false;
		}

		/**
		 * Refund Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return void
		 */
		function refund_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=RF";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			return $gtwresponse;
		}


		/**
		 * Gateway transaction details on declined trx
		 *
		 * @param int $order_id
		 * @return void
		 */
		function parse_value_cardcorp_error($order_id)
		{
			if (isset($_REQUEST['astrxId'])) {
				if (!$this->cardcorp_valid_payment_id(wp_unslash($_REQUEST['astrxId']))) {
					return;
				}
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
				include_once(dirname(__FILE__) . '/includes/error_list.php');
				$resp_code = $gwresponse->result->code;
				$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] : $gwresponse->result->description;
				cardcorp_write_log($resp_code_translated); ?>

<div class="woocommerce">
    <ul class="woocommerce-error" role="alert">
        <li><?= sprintf('Transaction Unsuccessful. The status message <b>%s</b>', esc_html($resp_code_translated)); ?> * </li>
    </ul>
</div>
<?php }
		}

		/**
		 * Gateway transaction details on a thankyou page
		 *
		 * @param int $order_id
		 * @return void
		 */
		function parse_value_cardcorp_success_page($order_id)
		{
			if (isset($_REQUEST['astrxId'])) {
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
			?>
<div class="woocommerce-order">
    <h2>Transaction details</h2>
    <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details tst">
        <li class="woocommerce-order-overview__email email">Transaction Codes

            <?php if (isset($gwresponse->resultDetails->ConnectorTxID1)) { ?>
            <strong><?= $gwresponse->resultDetails->ConnectorTxID1; ?></strong>
            <?php } ?>

            <?php if (isset($gwresponse->resultDetails->ConnectorTxID2)) { ?>
            <strong><?= $gwresponse->resultDetails->ConnectorTxID2; ?></strong>
            <?php } ?>

            <?php if (isset($gwresponse->resultDetails->ConnectorTxID3)) { ?>
            <strong><?= $gwresponse->resultDetails->ConnectorTxID3; ?></strong>
            <?php } ?>

        </li>
        <li class="woocommerce-order-overview__email email">Card Type
            <strong><?= $gwresponse->paymentBrand; ?> *** <?= $gwresponse->card->last4Digits; ?></strong>
        </li>
        <li class="woocommerce-order-overview__email email">Payment Type
            <strong><?= $gwresponse->paymentType; ?></strong>
        </li>
        <li class="woocommerce-order-overview__email email">Transaction Time
            <strong><?= $gwresponse->timestamp; ?></strong>
        </li>
    </ul>
</div>
<?php }
		}


		/**
		 * Report Payment to CardCorp
		 *
		 * @param int $order_id
		 * @return void
		 */
		function report_payment($order_id)
		{
			if (is_wc_endpoint_url('order-received') && ($o = wc_get_order($order_id)) && $o->get_payment_method() === $this->id
				&& function_exists('wc_print_notices') && WC()->session) {
				wc_print_notices();
			}
			$url = $this->cardcorp_url . "/v1/query/";
			if (isset($_REQUEST['astrxId'])) {
				$astrx_id = $this->cardcorp_valid_payment_id(wp_unslash($_REQUEST['astrxId']));
				if (!$astrx_id) {
					return;
				}
				$url .= $astrx_id;
			}
			$url .= "?entityId=" . $this->ENTITY_ID;
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'method' => 'GET',
					'headers' => array('Authorization' => $head_data),
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
		}
	}

	/**
	 * Add the CardCorp gateway to WooCommerce
	 *
	 * @param array $methods
	 * @return void
	 */
	function add_cardcorp_gateway($methods)
	{
		$methods[] = 'woocommerce_cardcorp';
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'add_cardcorp_gateway');
}
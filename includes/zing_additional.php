<?php
/* Add custom order actions to meta box */
add_action('woocommerce_order_actions', 'add_custom_order_for_preath', 10, 1);
function add_custom_order_for_preath($actions)
{
	global $theorder;
	/* Action will show only if status is 'preauth' */
	if (!$theorder->has_status(array('preauth'))) {
		return $actions;
	}
	//$actions['zing_capture'] = __( 'Zing Capture', 'woocommerce' );
	//$actions['zing_reverse'] = __( 'Zing Reverse', 'woocommerce' );
	return $actions;
}

add_action('woocommerce_order_actions', 'add_custom_order_for_accepted', 10, 1);
function add_custom_order_for_accepted($actions)
{
	global $theorder;
	/* Action will show only if status is 'accepted' */
	if (!$theorder->has_status(array('accepted'))) {
		return $actions;
	}
	//$actions['zing_reverse'] = __( 'Zing Reverse', 'woocommerce' );
	return $actions;
}

/* Show Statuses in Admin Panel */
add_action('init', 'add_custom_order_status');
function add_custom_order_status()
{
	register_post_status(
		'wc-preauth',
		array(
			'label'                     => _x('preauth', 'WooCommerce Order Status', 'woo-allsecure-gateway'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Preauth <span class="count">(%s)</span>', 'Preauth <span class="count">(%s)</span>')
		)
	);

	register_post_status(
		'wc-accepted',
		array(
			'label'                     => _x('Accepted', 'WooCommerce Order Status', 'woo-allsecure-gateway'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Accepted <span class="count">(%s)</span>', 'Accepted <span class="count">(%s)</span>')
		)
	);

	register_post_status(
		'wc-reversed',
		array(
			'label'                     => _x('Reversed', 'WooCommerce Order Status', 'woo-allsecure-gateway'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Reversed <span class="count">(%s)</span>', 'Reversed <span class="count">(%s)</span>')
		)
	);
}

/* Add new order statuses to woocommerce */
add_filter('wc_order_statuses', 'add_order_statuses');
function add_order_statuses($order_status)
{
	$order_status['wc-preauth'] = _x('Preauth', 'Preauth Order Status', 'woo-allsecure-gateway');
	$order_status['wc-accepted'] = _x('Accepted', 'Accepted Order Status', 'woo-allsecure-gateway');
	$order_status['wc-reversed'] = _x('Reversed', 'Reversed Order Status', 'woo-allsecure-gateway');
	return $order_status;
}

/* Change Status by number order  */
add_filter('wc_order_statuses', 'change_statuses_order');
function change_statuses_order($wc_statuses_arr)
{
	$new_statuses_arr = array(
		'wc-preauth' => $wc_statuses_arr['wc-preauth'], // 1
		'wc-accepted' => $wc_statuses_arr['wc-accepted'], // 2
		'wc-reversed' => $wc_statuses_arr['wc-reversed'], // 3	
		'wc-processing' => $wc_statuses_arr['wc-processing'], //4
		'wc-completed' => $wc_statuses_arr['wc-completed'], //5
		'wc-cancelled' => $wc_statuses_arr['wc-cancelled'], 	//6
		'wc-failed' => $wc_statuses_arr['wc-failed'], // 7
		'wc-pending' => $wc_statuses_arr['wc-pending'], // 8
		'wc-on-hold' => $wc_statuses_arr['wc-on-hold'], // 9
		'wc-refunded' => $wc_statuses_arr['wc-refunded'] // 10
	);
	return $new_statuses_arr;
}

/* Add custom order status icon */
add_action('wp_print_scripts', 'add_custom_order_status_icon');
function add_custom_order_status_icon()
{
	if (!is_admin()) {
		return;
	}
	?><style>
		.column-order_status mark.preauth:after,
		.column-order_status mark.accepted:after,
		.column-order_status mark.reversed:after {
			background-size: 100%;
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			text-align: center;
			content: '';
			background-repeat: no-repeat;
		}

		.column-order_status mark.preauth:after {
			background-image: url(<?php echo esc_attr(plugins_url('../assets/images/general/preauto.png', __FILE__)); ?>);
		}

		.column-order_status mark.accepted:after {
			background-image: url(<?php echo esc_attr(plugins_url('../assets/images/general/accepted.png', __FILE__)); ?>);
		}

		.column-order_status mark.reversed:after {
			background-image: url(<?php echo esc_attr(plugins_url('../assets/images/general/reversed.png', __FILE__)); ?>);
		}
	</style><?php
			}

			/* This function hides Refund button */
			add_action('admin_head', 'hide_wc_refund_button');
			function hide_wc_refund_button()
			{
				global $post;
				/* Here you can choose from which user roles to hide button from */
				return;
				if (!current_user_can('administrator') || current_user_can('editor') || current_user_can('author') || current_user_can('contributor') || current_user_can('subscriber')) {
					return;
				}
				if (strpos($_SERVER['REQUEST_URI'], 'post.php?post=') === false) {
					return;
				}
				if (empty($post) || $post->post_type != 'shop_order') {
					return;
				}
				?>
	<script>
		jQuery(function() {
			jQuery('.refund-items').hide();
			jQuery('.order_actions option[value=send_email_customer_refunded_order]').remove();
			if (jQuery('#original_post_status').val() == 'wc-refunded') {
				jQuery('#s2id_order_status').html('Refunded');
			} else {
				jQuery('#order_status option[value=wc-refunded]').remove();
			}
		});
	</script>
<?php
}

// Adding a custom checkout date field
// REMOVE VALDIATION FOR
add_action('woocommerce_checkout_process', 'check_if_have_18_years');
function check_if_have_18_years()
{

	$plugin = new woocommerce_zing();

	if ($plugin->settings['dob'] == 'yes' && $plugin->settings['enabled'] == 'yes') {

		if (!isset($_POST['have_18_years']) || empty($_POST['have_18_years'])) {
			wc_add_notice(__("You need at least to be 18 years old, to be able to checkout."), "error");
		}
	}
}


//-----


function check_client_age_field() {
    echo '<div id="check_client_age_field">';

    woocommerce_form_field( 'have_18_years', array(
        'type'      => 'checkbox',
        'class'     => array('input-checkbox'),
        'label'     => __('I confirm that I am over 18 years old'),
        'required'	=> true
    ),  WC()->checkout->get_value( 'have_18_years' ) );
    echo '</div>';
}

add_action('woocommerce_checkout_update_order_meta', 'check_client_age_field_update_order_meta', 10, 1);
function  check_client_age_field_update_order_meta( $order_id ) {
    if ( ! empty( $_POST['have_18_years'] ) )
        update_post_meta( $order_id, 'have_18_years', $_POST['have_18_years'] );
}
//-----


//Validation
//add_action('woocommerce_checkout_process', 'added_zing_validation');
function added_zing_validation()
{ 
	// Billing Address is too long
	if (isset($_POST['billing_address_1']) && !empty($_POST['billing_address_1'])) {   
		if (strlen($_POST['billing_address_1']) > 99) {
			wc_add_notice('Street Address is too long. Please shorten it.', "error");
		}
	}	
	// Billing country needs to be 2 charaters  (ISO 3166-1)
	if (isset($_POST['billing_country']) && !empty($_POST['billing_country'])) {   
		if (strlen($_POST['billing_country']) != 2) {
			wc_add_notice('Billing Country Error', "error");
		}
	}
	// Billing city is too long
	if (isset($_POST['billing_city']) && !empty($_POST['billing_city'])) {   
		if (strlen($_POST['billing_city']) > 79) {
			wc_add_notice('Town / City is too long. Please shorten it.', "error");
		}
	}
	// Billing city is too long
	if (isset($_POST['billing_postcode']) && !empty($_POST['billing_postcode'])) {   
		if (strlen($_POST['billing_postcode']) > 29) {
			wc_add_notice('Postcode is too long. Please shorten it.', "error");
		}
	}
}


function zing_write_log($message) { 
    if(is_array($message)) { 
        $message = json_encode($message); 
    } 
    $file = fopen(plugin_dir_path( __FILE__ ) . "/../custom_logs.log", "a"); 
   	fwrite($file, "\n" . date('Y-m-d h:i:s') . " :: " . $message); 
    fclose($file); 
}

function get_zing_logs() {
	return file_get_contents(plugin_dir_path( __FILE__ ) . "/../custom_logs.log");
}
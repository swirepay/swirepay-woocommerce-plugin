<?php
/**
 * Plugin Name: Swirepay WooCommerce Gateway
 * Plugin URI: https://www.swirepay.com/woocommerce
 * Description: Helps process payments online using Swirepay
 * Author: Swirepay
 * Author URI: http://www.swirepay.com/
 * Version: 1.0.0
 * Text Domain: wc-swirepay
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2019-2021 Swirepay, Inc. (legal@swirepay.com) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   wc-swirepay
 * @author    Swirepay
 * @category  Admin
 * @copyright Copyright (c) 2019-2021, Swirepay, Inc. and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
 
defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + swirepay gateway
 */
function wc_swirepay_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Swirepay_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_swirepay_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_swirepay_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=swirepay_gateway' ) . '">' . __( 'Configure', 'wc-swirepay' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_swirepay_gateway_plugin_links' );


/**
 * Swirepay Payment Gateway
 *
 * Provides an Swirepay Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Swirepay_Gateway
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 */
add_action( 'plugins_loaded', 'wc_swirepay_gateway_init', 11 );

function wc_swirepay_gateway_init() {

	class WC_Swirepay_Gateway extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
	  
			$this->id                 = 'swirepay_gateway';
			$this->icon               = apply_filters('woocommerce_offline_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __( 'Swirepay', 'wc-swirepay' );
			$this->method_description = __( 'Allows Swirepay payments.', 'wc-swirepay' );
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
			$this->enabled = $this->get_option( 'enabled' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );
			$this->private_key = $this->testmode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'private_key' );
			$this->publishable_key = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );		
			$this->delivery = 0;

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		  
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	
			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}

	
		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'wc_swirepay_form_fields', array(
		  
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'wc-swirepay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Swirepay Payment', 'wc-swirepay' ),
					'default' => 'yes'
				),
				
				'title' => array(
					'title'       => __( 'Title', 'wc-swirepay' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-swirepay' ),
					'default'     => __( 'Credit Card', 'wc-swirepay' ),
					'desc_tip'    => true,
				),
				
				'description' => array(
					'title'       => __( 'Description', 'wc-swirepay' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-swirepay' ),
					'default'     => __( 'All shipments will be processed within 1 business day upon successful payment.', 'wc-swirepay' ),
					'desc_tip'    => true,
				),

                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_publishable_key' => array(
                    'title'       => 'Test Public Key',
                    'type'        => 'text'
                ),
                'test_private_key' => array(
                    'title'       => 'Test Secret Key',
                    'type'        => 'password',
                ),
                'publishable_key' => array(
                    'title'       => 'Live Public Key',
                    'type'        => 'text'
                ),
                'private_key' => array(
                    'title'       => 'Live Secret Key',
                    'type'        => 'password'
                ),
			) );
		}

		public function payment_fields() {
 
			// ok, let's display some description before the payment form
			if ( $this->description ) {
				// you can instructions for test mode, I mean test card numbers etc.
				if ( $this->testmode ) {
					$this->description .= ' TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="#">documentation</a>.';
					$this->description  = trim( $this->description );
				}
				// display the description with <p> tags etc.
				echo wpautop( wp_kses_post( $this->description ) );
			}
		 
			// I will echo() the form, but you can close PHP tags and print it directly in HTML
			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';
		 
			// Add this action hook if you want your custom payment gateway to support it
			// do_action( 'woocommerce_credit_card_form_start', $this->id );
		 
			// I recommend to use inique IDs, because other gateways could already use #ccNo, #expdate, #cvc
			echo '<div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
				<input id="misha_ccNo" type="text" autocomplete="off">
				<div id="misha_ccNo_err"></div>
				</div>
				<div class="form-row form-row-first">
					<label>Expiry Date <span class="required">*</span></label>
					<input id="misha_expdate" type="text" autocomplete="off" placeholder="MM / YY">
				</div>
				<div class="form-row form-row-last">
					<label>Card Code (CVC) <span class="required">*</span></label>
					<input id="misha_cvv" type="password" autocomplete="off" placeholder="CVC">
				</div>
				<div class="clear"></div>';
		 
			do_action( 'woocommerce_credit_card_form_end', $this->id );

			echo '<div class="clear"></div></fieldset>';
		 
		}
	
		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}
	
		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}

		public function payment_scripts() {

			// we need JavaScript to process a token only on cart/checkout pages, right?
			if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
				return;
			}
		
			// if our payment gateway is disabled, we do not have to enqueue JS too
			if ( 'no' === $this->enabled ) {
				return;
			}
		
			// no reason to enqueue JavaScript if API keys are not set
			if ( empty( $this->private_key ) || empty( $this->publishable_key ) ) {
				return;
			}
		
			// do not work with card detailes without SSL unless your website is in a test mode
			if ( ! $this->testmode && ! is_ssl() ) {
				return;
			}
		
			// let's suppose it is our payment processor JavaScript that allows to obtain a token
			wp_enqueue_script( 'sp_js', 'https://s3.ap-south-1.amazonaws.com/ionic.swirepay.com/checkout.js' );
		
			// and this is our custom JS in your plugin directory that works with token.js
			wp_register_script( 'woocommerce_sp', plugins_url( 'checkout.js', __FILE__ ), array( 'jquery', 'sp_js' ) );
		
			wp_enqueue_script( 'woocommerce_sp' );
		
		}
	
	
		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
	
			$order = wc_get_order( $order_id );
			$orderDec = json_decode( $order, true);
			$total = $orderDec['total'];
			$billing = $orderDec['billing'];
			$name = $billing['first_name'];
			$name = $name + ' ' + $billing['last_name'];
			$email = $billing['email'];
			$phoneNumber = $billing['phone'];
			
			// Mark as on-hold (we're awaiting the payment)
			$order->update_status( 'on-hold', __( 'Awaiting payment', 'wc-swirepay' ) );
			
			// Reduce stock levels
			$order->reduce_order_stock();
			
			// Remove cart
			WC()->cart->empty_cart();

			// /*
			// * Your API interaction could be built with wp_remote_post()
			// */

			$url  = 'https://api.swirepay.com/v1/payment-link';

			$body = array(
				'amount' => $total * 100,
				'redirectUri' => $this->get_return_url( $order ),
				'currencyCode' => 'USD',
				'paymentMethodType' => ["CARD"],
				'email' => $email,
				'name' => $name,
				'phoneNumber' => $phoneNumber,
				'meta' => $orderDec			
			);
		
			$args = array(
				'method'      => 'POST',
				'timeout'     => 45,
				'sslverify'   => false,
				'headers'     => [
					'x-api-key' => $this->private_key,
					'Content-Type'  => 'application/json',
				],
				'body'        => json_encode($body),
			);
		
			$response = wp_remote_post( $url, $args );
			$redirect = "https://secure.swirepay.com/checkout-page/checkoutpage-c0d5ec8abb144b40ac04fb555ec8e8ed";

			if( !is_wp_error( $response ) ) {
				$body2 = json_decode( $response['body'], true );
				$redirect = $body2['entity']['link'];
			} else {
				wc_add_notice(  'Connection error.', 'error' );
				return;
			}
			
			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);
		}
	
  } // end \WC_Swirepay_Gateway class
}
<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * PayPal NVP (Name-Value Pair) API client. This client supports both certificate
 * and signature for authentication.
 *
 * @see https://developer.paypal.com/docs/classic/api/#ec
 */
class WC_Gateway_PPEC_Client {

	/**
	 * Client credential.
	 *
	 * @var WC_Gateway_PPEC_Client_Credential
	 */
	protected $_credential;

	/**
	 * PayPal environment. Either 'sandbox' or 'live'.
	 *
	 * @var string
	 */
	protected $_environment;

	const INVALID_CREDENTIAL_ERROR  = 1;
	const INVALID_ENVIRONMENT_ERROR = 2;
	const REQUEST_ERROR             = 3;
	const API_VERSION               = '120.0';

	/**
	 * Constructor.
	 *
	 * @param mixed  $credential  Client's credential
	 * @param string $environment Client's environment
	 *
	 */
	public function __construct( $credential, $environment = 'live' ) {
		$this->_environment = $environment;

		if ( is_a( $credential, 'WC_Gateway_PPEC_Client_Credential' ) ) {
			$this->set_credential( $credential );
		}
	}

	/**
	 * Set credential for the client.
	 *
	 * @param WC_Gateway_PPEC_Client_Credential $credential Client's credential
	 */
	public function set_credential( WC_Gateway_PPEC_Client_Credential $credential ) {
		$this->_credential = $credential;
	}

	/**
	 * Get payer ID from API.
	 */
	public function get_payer_id() {
		$option_key = 'woocommerce_ppec_payer_id_' . $this->_environment . '_' . md5( $this->_credential->get_username() . ':' . $this->_credential->get_password() );

		$payer_id = get_option( $option_key );
		if ( $payer_id ) {
			return $payer_id;
		} else {
			$result = $this->get_pal_details();

			if ( ! empty( $result['PAL'] ) ) {
				update_option( $option_key, wc_clean( $result['PAL'] ) );
				return $payer_id;
			}
		}

		return false;
	}

	/**
	 * Set environment for the client.
	 *
	 * @param string $environment Environment. Either 'live' or 'sandbox'
	 */
	public function set_environment( $environment ) {
		if ( ! in_array( $environment, array( 'live', 'sandbox' ), true ) ) {
			$environment = 'live';
		}

		$this->_environment = $environment;
	}

	/**
	 * Get PayPal endpoint.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/#ec
	 *
	 * @return string
	 */
	public function get_endpoint() {
		return sprintf(
			'https://%s%s.paypal.com/nvp',
			$this->_credential->get_endpoint_subdomain(),
			'sandbox' === $this->_environment ? '.sandbox' : ''
		);
	}

	/**
	 * Make a remote request to PayPal API.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#creating-an-nvp-request
	 *
	 * @param  array $params NVP request parameters
	 * @return array         NVP response
	 */
	protected function _request( array $params ) {
		try {
			$this->_validate_request();

			// First, add in the necessary credential parameters.
			$body = apply_filters( 'woocommerce_paypal_express_checkout_request_body', array_merge( $params, $this->_credential->get_request_params() ) );
			$args = array(
				'method'      => 'POST',
				'body'        => $body,
				'user-agent'  => __CLASS__,
				'httpversion' => '1.1',
				'timeout'     => 30,
			);

			// For cURL transport.
			add_action( 'http_api_curl', array( $this->_credential, 'configure_curl' ), 10, 3 );

			wc_gateway_ppec_log( sprintf( '%s: remote request to %s with params: %s', __METHOD__, $this->get_endpoint(), print_r( $body, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

			$resp = wp_safe_remote_post( $this->get_endpoint(), $args );

			return $this->_process_response( $resp );

		} catch ( Exception $e ) {

			remove_action( 'http_api_curl', array( $this->_credential, 'configure_curl' ), 10 );

			// TODO: Maybe returns WP_Error ?
			$error = array(
				'ACK'             => 'Failure',
				'L_ERRORCODE0'    => $e->getCode(),
				'L_SHORTMESSAGE0' => 'Error in ' . __METHOD__,
				'L_LONGMESSAGE0'  => $e->getMessage(),
				'L_SEVERITYCODE0' => 'Error',
			);

			wc_gateway_ppec_log( sprintf( '%s: returns error: %s', __METHOD__, print_r( $error, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

			return $error;
		}
	}

	/**
	 * Validate request.
	 *
	 * @since 1.2.0
	 *
	 * @throws \Exception
	 */
	protected function _validate_request() {
		// Make sure $_credential and $_environment have been configured.
		if ( ! $this->_credential ) {
			throw new Exception( __( 'Missing credential', 'woocommerce-gateway-paypal-express-checkout' ), self::INVALID_CREDENTIAL_ERROR );
		}

		if ( ! is_a( $this->_credential, 'WC_Gateway_PPEC_Client_Credential' ) ) {
			throw new Exception( __( 'Invalid credential object', 'woocommerce-gateway-paypal-express-checkout' ), self::INVALID_CREDENTIAL_ERROR );
		}

		if ( ! in_array( $this->_environment, array( 'live', 'sandbox' ), true ) ) {
			throw new Exception( __( 'Invalid environment', 'woocommerce-gateway-paypal-express-checkout' ), self::INVALID_ENVIRONMENT_ERROR );
		}
	}

	/**
	 * Process response from API.
	 *
	 * @since 1.2.0
	 *
	 * @throws \Exception
	 *
	 * @param WP_Error|array Response from remote API
	 *
	 * @return array
	 */
	protected function _process_response( $response ) {
		if ( is_wp_error( $response ) ) {
			// Translators: placeholder is an error message.
			throw new Exception( sprintf( __( 'An error occurred while trying to connect to PayPal: %s', 'woocommerce-gateway-paypal-express-checkout' ), $response->get_error_message() ), self::REQUEST_ERROR );
		}

		parse_str( wp_remote_retrieve_body( $response ), $result );

		if ( ! array_key_exists( 'ACK', $result ) ) {
			throw new Exception( __( 'Malformed response received from PayPal', 'woocommerce-gateway-paypal-express-checkout' ), self::REQUEST_ERROR );
		}

		wc_gateway_ppec_log( sprintf( '%s: acknowleged response body: %s', __METHOD__, print_r( $result, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		remove_action( 'http_api_curl', array( $this->_credential, 'configure_curl' ), 10 );

		return $result;
	}

	/**
	 * Initiates an Express Checkout transaction.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/
	 *
	 * @param  array $params NVP params
	 * @return array         NVP response
	 */
	public function set_express_checkout( array $params ) {
		$params['METHOD']  = 'SetExpressCheckout';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	/**
	 * Get params for SetExpressCheckout call.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args {
	 *     Context args to retrieve SetExpressCheckout parameters.
	 *
	 *     @type string $skip_checkout            Whether checking out ahead of store checkout screen.
	 *     @type int    $order_id                 Order ID if checking out after order is created.
	 *     @type bool   $create_billing_agreement Whether billing agreement creation
	 *                                            is needed after returned from PayPal.
	 * }
	 *
	 * @return array Params for SetExpressCheckout call
	 */
	public function get_set_express_checkout_params( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'skip_checkout'            => true,
				'order_id'                 => '',
				'create_billing_agreement' => false,
			)
		);

		$settings = wc_gateway_ppec()->settings;

		$params              = array();
		$logo_url_or_id      = $settings->logo_image_url;
		$header_url_or_id    = $settings->header_image_url;
		$params['LOGOIMG']   = filter_var( $logo_url_or_id, FILTER_VALIDATE_URL ) ? $logo_url_or_id : wp_get_attachment_image_url( $logo_url_or_id, 'ppec_logo_image_size' );
		$params['HDRIMG']    = filter_var( $header_url_or_id, FILTER_VALIDATE_URL ) ? $header_url_or_id : wp_get_attachment_image_url( $header_url_or_id, 'ppec_header_image_size' );
		$params['PAGESTYLE'] = $settings->page_style;
		$params['BRANDNAME'] = $settings->get_brand_name();
		$params['RETURNURL'] = $this->_get_return_url( $args );
		$params['CANCELURL'] = $this->_get_cancel_url( $args );

		if ( wc_gateway_ppec_is_using_credit() ) {
			$params['USERSELECTEDFUNDINGSOURCE'] = 'Finance';
		}

		if ( ! $args['skip_checkout'] ) {
			// Display shipping address sent from checkout page, rather than selecting from addresses on file with PayPal.
			$params['ADDROVERRIDE'] = '1';
		}

		if ( in_array( $settings->landing_page, array( 'Billing', 'Login' ), true ) ) {
			$params['LANDINGPAGE'] = $settings->landing_page;
		}

		if ( apply_filters( 'woocommerce_paypal_express_checkout_allow_guests', true ) ) {
			$params['SOLUTIONTYPE'] = 'Sole';
		}

		if ( 'yes' === $settings->require_billing ) {
			$params['REQBILLINGADDRESS'] = '1';
		}

		$params['PAYMENTREQUEST_0_PAYMENTACTION'] = $settings->get_paymentaction();
		if ( 'yes' === $settings->instant_payments && 'sale' === $settings->get_paymentaction() ) {
			$params['PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD'] = 'InstantPaymentOnly';
		}

		$params['PAYMENTREQUEST_0_INSURANCEAMT'] = 0;
		$params['PAYMENTREQUEST_0_HANDLINGAMT']  = 0;
		$params['PAYMENTREQUEST_0_CUSTOM']       = '';
		$params['PAYMENTREQUEST_0_INVNUM']       = '';
		$params['PAYMENTREQUEST_0_CURRENCYCODE'] = get_woocommerce_currency();

		if ( ! empty( $args['order_id'] ) ) {
			$details = $this->_get_details_from_order( $args['order_id'] );
		} else {
			$details = $this->_get_details_from_cart();
		}

		$params = array_merge(
			$params,
			array(
				'PAYMENTREQUEST_0_AMT'          => set_currency_format( $details['order_total'] ),
				'PAYMENTREQUEST_0_ITEMAMT'      => set_currency_format( $details['total_item_amount'] ),
				'PAYMENTREQUEST_0_SHIPPINGAMT'  => set_currency_format( $details['shipping'] ),
				'PAYMENTREQUEST_0_TAXAMT'       => set_currency_format( $details['order_tax'] ),
				'PAYMENTREQUEST_0_SHIPDISCAMT'  => set_currency_format( $details['ship_discount_amount'] ),
				'NOSHIPPING'                    => WC_Gateway_PPEC_Plugin::needs_shipping() ? 0 : 1,
			)
		);

		if ( ! empty( $details['email'] ) ) {
			$params['EMAIL'] = $details['email'];
		}

		if ( $args['create_billing_agreement'] ) {
			$params['L_BILLINGTYPE0']                 = 'MerchantInitiatedBillingSingleAgreement';
			$params['L_BILLINGAGREEMENTDESCRIPTION0'] = $this->_get_billing_agreement_description();
			$params['L_BILLINGAGREEMENTCUSTOM0']      = '';
		}

		if ( ! empty( $details['shipping_address'] ) ) {
			$params = array_merge(
				$params,
				$details['shipping_address']->getAddressParams( 'PAYMENTREQUEST_0_SHIPTO' )
			);
		}

		if ( ! empty( $details['items'] ) ) {
			$count = 0;
			foreach ( $details['items'] as $line_item_key => $values ) {
				$line_item_params = array(
					'L_PAYMENTREQUEST_0_NAME' . $count => $values['name'],
					'L_PAYMENTREQUEST_0_DESC' . $count => ! empty( $values['description'] ) ? substr( strip_tags( $values['description'] ), 0, 127 ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
					'L_PAYMENTREQUEST_0_QTY' . $count  => $values['quantity'],
					'L_PAYMENTREQUEST_0_AMT' . $count  => set_currency_format( $values['amount'] ),
				);

				if ( isset( $values['sku'] ) ) {
					$line_item_params[ 'L_PAYMENTREQUEST_0_NUMBER' . $count ] = $values['sku'];
				}

				$params = array_merge( $params, $line_item_params );
				$count++;
			}
		}

		return $params;
	}

	/**
	 * Get return URL.
	 *
	 * The URL to return from express checkout.
	 *
	 * @since 1.2.0
	 *
	 * @param array $context_args {
	 *     Context args to retrieve SetExpressCheckout parameters.
	 *
	 *     @type bool   $create_billing_agreement Whether billing agreement creation
	 *                                            is needed after returned from PayPal.
	 * }
	 *
	 * @return string Return URL
	 */
	protected function _get_return_url( array $context_args ) {
		$query_args = array(
			'woo-paypal-return' => 'true',
		);
		if ( $context_args['create_billing_agreement'] ) {
			$query_args['create-billing-agreement'] = 'true';
		}

		$url      = add_query_arg( $query_args, wc_get_checkout_url() );
		$order_id = $context_args['order_id'];
		return apply_filters( 'woocommerce_paypal_express_checkout_set_express_checkout_params_get_return_url', $url, $order_id );
	}

	/**
	 * Get cancel URL.
	 *
	 * The URL to return when canceling the express checkout.
	 *
	 * @since 1.2.0
	 *
	 * @return string Cancel URL
	 */
	protected function _get_cancel_url( $context_args ) {
		$url      = add_query_arg( 'woo-paypal-cancel', 'true', wc_get_cart_url() );
		$order_id = $context_args['order_id'];
		return apply_filters( 'woocommerce_paypal_express_checkout_set_express_checkout_params_get_cancel_url', $url, $order_id );
	}

	/**
	 * Get billing agreement description to be passed to PayPal.
	 *
	 * @since 1.2.0
	 *
	 * @return string Billing agreement description
	 */
	protected function _get_billing_agreement_description() {
		/* Translators: placeholder is blogname. */
		$description = sprintf( _x( 'Orders with %s', 'data sent to PayPal', 'woocommerce-gateway-paypal-express-checkout' ), get_bloginfo( 'name' ) );

		if ( strlen( $description ) > 127 ) {
			$description = substr( $description, 0, 124 ) . '...';
		}

		return html_entity_decode( $description, ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * Get extra line item when for subtotal mismatch.
	 *
	 * @since 1.2.0
	 *
	 * @param float $amount Item's amount
	 *
	 * @return array Line item
	 */
	protected function _get_extra_offset_line_item( $amount ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		return array(
			'name'        => 'Line Item Amount Offset',
			'description' => 'Adjust cart calculation discrepancy',
			'quantity'    => 1,
			'amount'      => round( $amount, $decimals ),
		);
	}

	/**
	 * Get extra line item when for discount.
	 *
	 * @since 1.2.0
	 *
	 * @param float $amount Item's amount
	 *
	 * @return array Line item
	 */
	protected function _get_extra_discount_line_item( $amount ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		return array(
			'name'     => 'Discount',
			'quantity' => 1,
			'amount'   => '-' . round( $amount, $decimals ),
		);
	}

	/**
	 * Get details, not params to be passed in PayPal API request, from cart contents.
	 *
	 * This is the details when buyer is checking out from cart page.
	 *
	 * @since 1.2.0
	 * @version 1.2.1
	 *
	 * @return array Order details
	 */
	protected function _get_details_from_cart() {
		$settings = wc_gateway_ppec()->settings;
		$old_wc   = version_compare( WC_VERSION, '3.0', '<' );

		WC()->cart->calculate_totals();

		$decimals      = $settings->get_number_of_decimal_digits();
		$rounded_total = $this->_get_rounded_total_in_cart();
		$discounts     = WC()->cart->get_cart_discount_total();

		$details = array(
			'total_item_amount' => round( WC()->cart->cart_contents_total + WC()->cart->fee_total, $decimals ),
			'order_tax'         => round( WC()->cart->tax_total + WC()->cart->shipping_tax_total, $decimals ),
			'shipping'          => round( WC()->cart->shipping_total, $decimals ),
			'items'             => $this->_get_paypal_line_items_from_cart(),
			'shipping_address'  => $this->_get_address_from_customer(),
			'email'             => $old_wc ? WC()->customer->billing_email : WC()->customer->get_billing_email(),
		);

		return $this->get_details( $details, $discounts, $rounded_total, WC()->cart->total );
	}

	/**
	 * Get line items from cart contents.
	 *
	 * @since 1.2.0
	 *
	 * @return array Line items
	 */
	protected function _get_paypal_line_items_from_cart() {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$items = array();
		foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
			$amount = round( $values['line_subtotal'] / $values['quantity'], $decimals );

			if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
				$name        = $values['data']->post->post_title;
				$description = $values['data']->post->post_content;
			} else {
				$product     = $values['data'];
				$name        = $product->get_name();
				$description = $product->get_description();
			}

			$item = array(
				'name'        => apply_filters( 'woocommerce_paypal_express_checkout_cart_line_item_name', $name, $values, $cart_item_key ),
				'description' => apply_filters( 'woocommerce_paypal_express_checkout_cart_line_item_description', $description, $values, $cart_item_key ),
				'quantity'    => $values['quantity'],
				'amount'      => $amount,
				'sku'         => $values['data']->get_sku(),
			);

			$items[] = $item;
		}

		foreach ( WC()->cart->get_fees() as $fee_key => $fee_values ) {
			$item = array(
				'name'        => apply_filters( 'woocommerce_paypal_express_checkout_cart_fee_name', $fee_values->name, $fee_values, $fee_key ),
				'description' => apply_filters( 'woocommerce_paypal_express_checkout_cart_fee_description', '', $fee_values, $fee_key ),
				'quantity'    => 1,
				'amount'      => round( $fee_values->total, $decimals ),
			);

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Get rounded total of items in cart.
	 *
	 * @since 1.2.0
	 *
	 * @return float Rounded total in cart
	 */
	protected function _get_rounded_total_in_cart() {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$rounded_total = 0;
		foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
			$amount         = round( $values['line_subtotal'] / $values['quantity'], $decimals );
			$rounded_total += round( $amount * $values['quantity'], $decimals );
		}

		foreach ( WC()->cart->get_fees() as $fee_key => $fee_values ) {
			$rounded_total += round( $fee_values->total, $decimals );
		}

		return $rounded_total;
	}

	/**
	 * Get details from populated price array
	 *
	 * @since 1.4.1
	 *
	 * @param array $details Prices
	 *
	 * @return array Details
	 */
	protected function get_details( $details, $discounts, $rounded_total, $total ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$discounts = round( $discounts, $decimals );

		$details['order_total'] = round(
			$details['total_item_amount'] + $details['order_tax'] + $details['shipping'],
			$decimals
		);

		// Compare WC totals with what PayPal will calculate to see if they match.
		// if they do not match, check to see what the merchant would like to do.
		// Options are to remove line items or add a line item to adjust for
		// the difference.
		$diff = 0;

		if ( $details['total_item_amount'] + $discounts != $rounded_total ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			if ( 'add' === $settings->get_subtotal_mismatch_behavior() ) {
				// Add line item to make up different between WooCommerce
				// calculations and PayPal calculations.
				$diff = round( $details['total_item_amount'] + $discounts - $rounded_total, $decimals );
				if ( abs( $diff ) > 0.000001 && 0.0 !== (float) $diff ) {
					$extra_line_item = $this->_get_extra_offset_line_item( $diff );

					$details['items'][]            = $extra_line_item;
					$details['total_item_amount'] += $extra_line_item['amount'];
					$details['order_total']       += $extra_line_item['amount'];
				}
			} else {
				// Omit line items altogether.
				unset( $details['items'] );
			}
		}

		// Enter discount shenanigans. Item total cannot be 0 so make modifications
		// accordingly.
		if ( 0 == $details['total_item_amount'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			// Omit line items altogether.
			unset( $details['items'] );
		} elseif ( $discounts > 0 && 0 < $details['total_item_amount'] && ! empty( $details['items'] ) ) {
			// Else if there is discount, add them to the line-items
			$details['items'][] = $this->_get_extra_discount_line_item( $discounts );
		}

		$details['ship_discount_amount'] = 0;

		// AMT
		$details['order_total'] = round( $details['order_total'], $decimals );

		// ITEMAMT
		$details['total_item_amount'] = round( $details['total_item_amount'], $decimals );

		// If the totals don't line up, adjust the tax to make it work (it's
		// probably a tax mismatch).
		$wc_order_total   = round( $total, $decimals );
		$discounted_total = $details['order_total'];

		if ( $wc_order_total != $discounted_total ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			// tax cannot be negative
			if ( $discounted_total < $wc_order_total ) {
				$details['order_tax'] += $wc_order_total - $discounted_total;
				$details['order_tax']  = round( $details['order_tax'], $decimals );
			} else {
				$details['ship_discount_amount'] += $wc_order_total - $discounted_total;
				$details['ship_discount_amount']  = round( $details['ship_discount_amount'], $decimals );
			}

			$details['order_total'] = $wc_order_total;
		}

		if ( ! is_numeric( $details['shipping'] ) ) {
			$details['shipping'] = 0;
		}

		$lisum = 0;

		if ( ! empty( $details['items'] ) ) {
			foreach ( $details['items'] as $li => $values ) {
				$lisum += $values['quantity'] * $values['amount'];
			}
		}

		if ( abs( $lisum ) > 0.000001 && 0.0 !== (float) $diff ) {
			$details['items'][] = $this->_get_extra_offset_line_item( $details['total_item_amount'] - $lisum );
		}

		/**
		 * Filter PayPal order details.
		 *
		 * Provide opportunity for developers to modify details passed to PayPal.
		 * This was originally introduced to add a mechanism to allow for
		 * decimal product quantity support.
		 *
		 * @since 1.6.6
		 *
		 * @param array $details Current PayPal order details
		 */
		return apply_filters( 'woocommerce_paypal_express_checkout_get_details', $details );
	}

	protected function _get_total_order_fees( $order ) {
		$total = 0;
		$fees  = $order->get_fees();
		foreach ( $fees as $fee ) {
			$total = $total + $fee->get_amount();
		}

		return $total;
	}

	/**
	 * Get details from given order_id.
	 *
	 * This is the details when buyer is checking out from checkout page.
	 *
	 * @since 1.2.0
	 *
	 * @param int $order_id Order ID
	 *
	 * @return array Order details
	 */
	protected function _get_details_from_order( $order_id ) {
		$order    = wc_get_order( $order_id );
		$settings = wc_gateway_ppec()->settings;

		$decimals      = $settings->is_currency_supports_zero_decimal() ? 0 : 2;
		$rounded_total = $this->_get_rounded_total_in_order( $order );
		$discounts     = $order->get_total_discount();
		$fees          = round( $this->_get_total_order_fees( $order ), $decimals );

		$details = array(
			'total_item_amount' => round( $order->get_subtotal() - $discounts + $fees, $decimals ),
			'order_tax'         => round( $order->get_total_tax(), $decimals ),
			'shipping'          => round( ( version_compare( WC_VERSION, '3.0', '<' ) ? $order->get_total_shipping() : $order->get_shipping_total() ), $decimals ),
			'items'             => $this->_get_paypal_line_items_from_order( $order ),
		);

		$details = $this->get_details( $details, $order->get_total_discount(), $rounded_total, $order->get_total() );

		// PayPal shipping address from order.
		$shipping_address = new PayPal_Address();

		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		if ( ( $old_wc && ( $order->shipping_address_1 || $order->shipping_address_2 ) ) || ( ! $old_wc && $order->has_shipping_address() ) ) {
			// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.SpacingBefore
			$shipping_first_name = $old_wc ? $order->shipping_first_name : $order->get_shipping_first_name();
			$shipping_last_name  = $old_wc ? $order->shipping_last_name  : $order->get_shipping_last_name();
			$shipping_address_1  = $old_wc ? $order->shipping_address_1  : $order->get_shipping_address_1();
			$shipping_address_2  = $old_wc ? $order->shipping_address_2  : $order->get_shipping_address_2();
			$shipping_city       = $old_wc ? $order->shipping_city       : $order->get_shipping_city();
			$shipping_state      = $old_wc ? $order->shipping_state      : $order->get_shipping_state();
			$shipping_postcode   = $old_wc ? $order->shipping_postcode   : $order->get_shipping_postcode();
			$shipping_country    = $old_wc ? $order->shipping_country    : $order->get_shipping_country();
			// phpcs:enable
		} else {
			// Fallback to billing in case no shipping methods are set. The address returned from PayPal
			// will be stored in the order as billing.
			// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.SpacingBefore
			$shipping_first_name = $old_wc ? $order->billing_first_name : $order->get_billing_first_name();
			$shipping_last_name  = $old_wc ? $order->billing_last_name  : $order->get_billing_last_name();
			$shipping_address_1  = $old_wc ? $order->billing_address_1  : $order->get_billing_address_1();
			$shipping_address_2  = $old_wc ? $order->billing_address_2  : $order->get_billing_address_2();
			$shipping_city       = $old_wc ? $order->billing_city       : $order->get_billing_city();
			$shipping_state      = $old_wc ? $order->billing_state      : $order->get_billing_state();
			$shipping_postcode   = $old_wc ? $order->billing_postcode   : $order->get_billing_postcode();
			$shipping_country    = $old_wc ? $order->billing_country    : $order->get_billing_country();
			// phpcs:enable
		}

		$shipping_address->setName( $shipping_first_name . ' ' . $shipping_last_name );
		$shipping_address->setStreet1( $shipping_address_1 );
		$shipping_address->setStreet2( $shipping_address_2 );
		$shipping_address->setCity( $shipping_city );
		$shipping_address->setState( $shipping_state );
		$shipping_address->setZip( $shipping_postcode );

		// In case merchant only expects domestic shipping and hides shipping
		// country, fallback to base country.
		//
		// @see https://github.com/woothemes/woocommerce-gateway-paypal-express-checkout/issues/139
		if ( empty( $shipping_country ) ) {
			$shipping_country = WC()->countries->get_base_country();
		}
		$shipping_address->setCountry( $shipping_country );

		$shipping_address->setPhoneNumber( $old_wc ? $order->billing_phone : $order->get_billing_phone() );

		$details['shipping_address'] = $shipping_address;

		$details['email'] = $old_wc ? $order->billing_email : $order->get_billing_email();

		return $details;
	}

	/**
	 * Get PayPal shipping address from customer.
	 *
	 * @return array Address
	 */
	protected function _get_address_from_customer() {
		$customer = WC()->customer;

		$shipping_address = new PayPal_Address();

		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		if ( $customer->get_shipping_address() || $customer->get_shipping_address_2() ) {
			$shipping_first_name = $old_wc ? $customer->shipping_first_name : $customer->get_shipping_first_name();
			$shipping_last_name  = $old_wc ? $customer->shipping_last_name : $customer->get_shipping_last_name();
			$shipping_address_1  = $customer->get_shipping_address();
			$shipping_address_2  = $customer->get_shipping_address_2();
			$shipping_city       = $customer->get_shipping_city();
			$shipping_state      = $customer->get_shipping_state();
			$shipping_postcode   = $customer->get_shipping_postcode();
			$shipping_country    = $customer->get_shipping_country();
		} else {
			// Fallback to billing in case no shipping methods are set. The address returned from PayPal
			// will be stored in the order as billing.
			// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.SpacingBefore
			$shipping_first_name = $old_wc ? $customer->billing_first_name : $customer->get_billing_first_name();
			$shipping_last_name  = $old_wc ? $customer->billing_last_name  : $customer->get_billing_last_name();
			$shipping_address_1  = $old_wc ? $customer->get_address()      : $customer->get_billing_address_1();
			$shipping_address_2  = $old_wc ? $customer->get_address_2()    : $customer->get_billing_address_2();
			$shipping_city       = $old_wc ? $customer->get_city()         : $customer->get_billing_city();
			$shipping_state      = $old_wc ? $customer->get_state()        : $customer->get_billing_state();
			$shipping_postcode   = $old_wc ? $customer->get_postcode()     : $customer->get_billing_postcode();
			$shipping_country    = $old_wc ? $customer->get_country()      : $customer->get_billing_country();
			// phpcs:enable
		}

		$shipping_address->setName( $shipping_first_name . ' ' . $shipping_last_name );
		$shipping_address->setStreet1( $shipping_address_1 );
		$shipping_address->setStreet2( $shipping_address_2 );
		$shipping_address->setCity( $shipping_city );
		$shipping_address->setState( $shipping_state );
		$shipping_address->setZip( $shipping_postcode );
		$shipping_address->setCountry( $shipping_country );
		$shipping_address->setPhoneNumber( $old_wc ? $customer->billing_phone : $customer->get_billing_phone() );

		return $shipping_address;
	}

	/**
	 * Get line items from given order.
	 *
	 * @since 1.2.0
	 *
	 * @param int|WC_Order $order Order ID or order object
	 *
	 * @return array Line items
	 */
	protected function _get_paypal_line_items_from_order( $order ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();
		$order    = wc_get_order( $order );

		$items = array();
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $cart_item_key => $order_item ) {

			if ( 'fee' === $order_item['type'] ) {
				$item = array(
					'name'     => apply_filters( 'woocommerce_paypal_express_checkout_order_line_item_name', $order_item['name'], $order_item, $cart_item_key ),
					'quantity' => 1,
					'amount'   => round( $order_item['line_total'], $decimals ),
				);
			} else {
				$amount  = round( $order_item['line_subtotal'] / $order_item['qty'], $decimals );
				$product = version_compare( WC_VERSION, '3.0', '<' ) ? $order->get_product_from_item( $order_item ) : $order_item->get_product();
				$item    = array(
					'name'     => apply_filters( 'woocommerce_paypal_express_checkout_order_line_item_name', $order_item['name'], $order_item, $cart_item_key ),
					'quantity' => $order_item['qty'],
					'amount'   => $amount,
					'sku'      => ( $product && is_callable( array( $product, 'get_sku' ) ) ) ? $product->get_sku() : '',
				);
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Get rounded total of a given order.
	 *
	 * @since 1.2.0
	 *
	 * @param int|WC_Order Order ID or order object
	 *
	 * @return float
	 */
	protected function _get_rounded_total_in_order( $order ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();
		$order    = wc_get_order( $order );

		$rounded_total = 0;
		foreach ( $order->get_items( array( 'line_item', 'fee', 'coupon' ) ) as $cart_item_key => $values ) {
			if ( 'coupon' === $values['type'] ) {
				$amount         = round( $values['line_total'], $decimals );
				$rounded_total -= $amount;
				continue;
			}
			if ( 'fee' === $values['type'] ) {
				$amount = round( $values['line_total'], $decimals );
			} else {
				$amount = round( $values['line_subtotal'] / $values['qty'], $decimals );
				$amount = round( $amount * $values['qty'], $decimals );
			}
			$rounded_total += $amount;
		}

		return $rounded_total;
	}

	/**
	 * Get details from a given token.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
	 *
	 * @param  string $token Token from SetExpressCheckout response
	 * @return array         NVP response
	 */
	public function get_express_checkout_details( $token ) {
		$params = array(
			'METHOD'  => 'GetExpressCheckoutDetails',
			'VERSION' => self::API_VERSION,
			'TOKEN'   => $token,
		);

		return $this->_request( $params );
	}

	/**
	 * Completes an Express Checkout transaction. If you set up a billing agreement
	 * in your 'SetExpressCheckout' API call, the billing agreement is created
	 * when you call the DoExpressCheckoutPayment API operation.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/
	 *
	 * @param  array $params NVP params
	 * @return array         NVP response
	 */
	public function do_express_checkout_payment( array $params ) {
		$params['METHOD']       = 'DoExpressCheckoutPayment';
		$params['VERSION']      = self::API_VERSION;
		$params['BUTTONSOURCE'] = 'WooThemes_EC';

		return $this->_request( $params );
	}

	/**
	 * Get params for DoExpressCheckoutPayment call.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Args
	 *
	 * @return array Params for DoExpressCheckoutPayment call
	 */
	public function get_do_express_checkout_params( array $args ) {
		$settings = wc_gateway_ppec()->settings;
		$order    = wc_get_order( $args['order_id'] );

		$old_wc       = version_compare( WC_VERSION, '3.0', '<' );
		$order_id     = $old_wc ? $order->id : $order->get_id();
		$order_number = $order->get_order_number();
		$details      = $this->_get_details_from_order( $order_id );
		$order_key    = $old_wc ? $order->order_key : $order->get_order_key();

		$params = array(
			'TOKEN'                          => $args['token'],
			'PAYERID'                        => $args['payer_id'],
			'PAYMENTREQUEST_0_AMT'           => set_currency_format( $details['order_total'] ),
			'PAYMENTREQUEST_0_ITEMAMT'       => set_currency_format( $details['total_item_amount'] ),
			'PAYMENTREQUEST_0_SHIPPINGAMT'   => set_currency_format( $details['shipping'] ),
			'PAYMENTREQUEST_0_TAXAMT'        => set_currency_format( $details['order_tax'] ),
			'PAYMENTREQUEST_0_SHIPDISCAMT'   => set_currency_format( $details['ship_discount_amount'] ),
			'PAYMENTREQUEST_0_INSURANCEAMT'  => 0,
			'PAYMENTREQUEST_0_HANDLINGAMT'   => 0,
			'PAYMENTREQUEST_0_CURRENCYCODE'  => get_woocommerce_currency(),
			'PAYMENTREQUEST_0_NOTIFYURL'     => WC()->api_request_url( 'WC_Gateway_PPEC' ),
			'PAYMENTREQUEST_0_PAYMENTACTION' => $settings->get_paymentaction(),
			'PAYMENTREQUEST_0_INVNUM'        => $settings->invoice_prefix . $order->get_order_number(),
			'PAYMENTREQUEST_0_CUSTOM'        => wp_json_encode(
				array(
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'order_key'    => $order_key,
				)
			),
			'NOSHIPPING'                     => WC_Gateway_PPEC_Plugin::needs_shipping() ? 0 : 1,
		);

		if ( WC_Gateway_PPEC_Plugin::needs_shipping() && ! empty( $details['shipping_address'] ) ) {
			$params = array_merge(
				$params,
				$details['shipping_address']->getAddressParams( 'PAYMENTREQUEST_0_SHIPTO' )
			);
		}

		if ( ! empty( $details['items'] ) ) {
			$count = 0;
			foreach ( $details['items'] as $line_item_key => $values ) {
				$line_item_params = array(
					'L_PAYMENTREQUEST_0_NAME' . $count => $values['name'],
					'L_PAYMENTREQUEST_0_DESC' . $count => ! empty( $values['description'] ) ? strip_tags( $values['description'] ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
					'L_PAYMENTREQUEST_0_QTY' . $count  => $values['quantity'],
					'L_PAYMENTREQUEST_0_AMT' . $count  => set_currency_format( $values['amount'] ),
				);

				if ( isset( $values['sku'] ) ) {
					$line_item_params[ 'L_PAYMENTREQUEST_0_NUMBER' . $count ] = $values['sku'];
				}

				$params = array_merge( $params, $line_item_params );
				$count++;
			}
		}

		return $params;
	}

	/**
	 * Creates a billing agreement with a PayPal account holder.
	 *
	 * Used for subscription products in the purchase.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/CreateBillingAgreement_API_Operation_NVP/
	 *
	 * @since 1.2.0
	 *
	 * @param string $token Token from SetExpressCheckout response
	 */
	public function create_billing_agreement( $token ) {
		$params = array(
			'METHOD'  => 'CreateBillingAgreement',
			'VERSION' => self::API_VERSION,
			'TOKEN'   => $token,
		);

		return $this->_request( $params );
	}

	/**
	 * Processes a payment from a buyer's account, which is identified by a
	 * previous transaction
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/DoReferenceTransaction_API_Operation_NVP/
	 *
	 * @since 1.2.0
	 *
	 * @param  array $params NVP params
	 * @return array         NVP response
	 */
	public function do_reference_transaction( array $params ) {
		$params['METHOD']       = 'DoReferenceTransaction';
		$params['VERSION']      = self::API_VERSION;
		$params['BUTTONSOURCE'] = 'WooThemes_EC';

		return $this->_request( $params );
	}

	/**
	 * Get params for DoReferenceTransaction call.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Args
	 *
	 * @return array Params for DoReferenceTransaction call
	 */
	public function get_do_reference_transaction_params( array $args ) {
		$settings = wc_gateway_ppec()->settings;
		$order    = wc_get_order( $args['order_id'] );

		$old_wc    = version_compare( WC_VERSION, '3.0', '<' );
		$order_id  = $old_wc ? $order->id : $order->get_id();
		$details   = $this->_get_details_from_order( $order_id );
		$order_key = $old_wc ? $order->order_key : $order->get_order_key();

		$params = array(
			'REFERENCEID'   => $args['reference_id'],
			'AMT'           => $args['amount'],
			'ITEMAMT'       => $details['total_item_amount'],
			'SHIPPINGAMT'   => $details['shipping'],
			'TAXAMT'        => $details['order_tax'],
			'SHIPDISCAMT'   => $details['ship_discount_amount'],
			'INSURANCEAMT'  => 0,
			'HANDLINGAMT'   => 0,
			'CURRENCYCODE'  => $old_wc ? $order->order_currency : $order->get_currency(),
			'NOTIFYURL'     => WC()->api_request_url( 'WC_Gateway_PPEC' ),
			'PAYMENTACTION' => $settings->get_paymentaction(),
			'INVNUM'        => $settings->invoice_prefix . $order->get_order_number(),
			'CUSTOM'        => wp_json_encode(
				array(
					'order_id'  => $order_id,
					'order_key' => $order_key,
				)
			),
		);

		// We want to add the shipping parameters only if we have all of the required
		// parameters for a DoReferenceTransaction call. Otherwise, we don't want to
		// include any of the shipping parameters, even if we have some of them.
		// The call will fail if not all of the required paramters are present.
		if (
			! empty( $details['shipping_address'] )
			&& $details['shipping_address']->has_all_required_shipping_params()
		) {
			$params = array_merge(
				$params,
				$details['shipping_address']->getAddressParams( 'SHIPTO' )
			);

			$params['SHIPTOCOUNTRY'] = $params['SHIPTOCOUNTRYCODE'];
			unset( $params['SHIPTOCOUNTRYCODE'] );
		}

		if ( ! empty( $details['items'] ) ) {
			$count = 0;
			foreach ( $details['items'] as $line_item_key => $values ) {
				$line_item_params = array(
					'L_NAME' . $count   => $values['name'],
					'L_DESC' . $count   => ! empty( $values['description'] ) ? strip_tags( $values['description'] ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
					'L_QTY' . $count    => $values['quantity'],
					'L_AMT' . $count    => $values['amount'],
					'L_NUMBER' . $count => $values['sku'],
				);

				$params = array_merge( $params, $line_item_params );
				$count++;
			}
		}

		return $params;
	}

	public function do_express_checkout_capture( $params ) {
		$params['METHOD']  = 'DoCapture';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	public function do_express_checkout_void( $params ) {
		$params['METHOD']  = 'DoVoid';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	public function get_transaction_details( $params ) {
		$params['METHOD']  = 'GetTransactionDetails';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	/**
	 * Obtain your Pal ID, which is the PayPal–assigned merchant account number,
	 * and other informaton about your account.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/GetPalDetails_API_Operation_NVP/
	 *
	 * @return array NVP response
	 */
	public function get_pal_details() {
		$params['METHOD']  = 'GetPalDetails';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	/**
	 * Issues a refund to the PayPal account holder associated with a transaction.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/RefundTransaction_API_Operation_NVP/
	 *
	 * @param  array $params NVP params
	 * @return array         NVP response
	 */
	public function refund_transaction( $params ) {
		$params['METHOD']  = 'RefundTransaction';
		$params['VERSION'] = self::API_VERSION;

		return $this->_request( $params );
	}

	public function test_api_credentials( $credentials, $environment = 'sandbox' ) {
		$this->set_credential( $credentials );
		$this->set_environment( $environment );

		$result = $this->get_pal_details();

		if ( 'Success' !== $result['ACK'] && 'SuccessWithWarning' !== $result['ACK'] ) {
			// Look at the result a little more closely to make sure it's a credentialing issue.
			$found_10002 = false;
			foreach ( $result as $index => $value ) {
				if ( preg_match( '/^L_ERRORCODE\d+$/', $index ) ) {
					if ( '10002' == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						$found_10002 = true;
					}
				}
			}

			if ( $found_10002 ) {
				return false;
			} else {
				// Call failed for some other reason.
				throw new PayPal_API_Exception( $result );
			}
		}

		update_option( 'woocommerce_ppec_payer_id_' . $this->_environment . '_' . md5( $this->_credential->get_username() . ':' . $this->_credential->get_password() ), wc_clean( $result['PAL'] ) );

		return $result['PAL'];
	}

	// Probe to see whether the merchant has the billing address feature enabled.  We do this
	// by running a SetExpressCheckout call with REQBILLINGADDRESS set to 1; if the merchant has
	// this feature enabled, the call will complete successfully; if they do not, the call will
	// fail with error code 11601.
	public function test_for_billing_address_enabled( $credentials, $environment = 'sandbox' ) {
		$this->set_credential( $credentials );
		$this->set_environment( $environment );

		$req    = array(
			'RETURNURL'         => home_url( '/' ),
			'CANCELURL'         => home_url( '/' ),
			'REQBILLINGADDRESS' => '1',
			'AMT'               => '1.00',
		);
		$result = $this->set_express_checkout( $req );

		if ( 'Success' !== $result['ACK'] && 'SuccessWithWarning' !== $result['ACK'] ) {
			$found_11601 = false;
			foreach ( $result as $index => $value ) {
				if ( preg_match( '/^L_ERRORCODE\d+$/', $index ) ) {
					if ( '11601' == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						$found_11601 = true;
					}
				}
			}

			if ( $found_11601 ) {
				return false;
			} else {
				throw new PayPal_API_Exception( $result );
			}
		}

		return true;
	}

	/**
	 * Checks whether response indicates a successful operation.
	 *
	 * @since 1.2.0
	 *
	 * @param array $response NVP response
	 *
	 * @return bool Returns true if response indicates a successful operation
	 */
	public function response_has_success_status( $response ) {
		return (
			isset( $response['ACK'] )
			&&
			in_array( $response['ACK'], array( 'Success', 'SuccessWithWarning' ), true )
		);
	}

	/** Deprecated Functions */

	/**
	 * Updates or deletes a billing agreement.
	 *
	 * @see https://developer.paypal.com/docs/classic/api/merchant/BAUpdate_API_Operation_NVP/
	 *
	 * @since 1.2.0
	 * @deprecated 1.7.0
	 *
	 * @param string $billing_agreement_id Billing agreement ID
	 */
	public function update_billing_agreement( $billing_agreement_id ) {
		_deprecated_function( __METHOD__, '1.7.0' );
		$params = array(
			'METHOD'      => 'BillAgreementUpdate',
			'VERSION'     => self::API_VERSION,
			'REFERENCEID' => $billing_agreement_id,
		);
	}
}

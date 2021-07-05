<?php

function woo_pp_start_checkout() {
	$checkout = wc_gateway_ppec()->checkout;

	try {
		$redirect_url = $checkout->start_checkout_from_cart();
		wp_safe_redirect( $redirect_url );
		exit;
	} catch ( PayPal_API_Exception $e ) {
		wc_add_notice( $e->getMessage(), 'error' );

		$redirect_url = wc_get_cart_url();
		$settings     = wc_gateway_ppec()->settings;
		$client       = wc_gateway_ppec()->client;

		if ( $settings->is_enabled() && $client->get_payer_id() ) {
			ob_end_clean();
			?>
			<script type="text/javascript">
				if( ( window.opener != null ) && ( window.opener !== window ) &&
						( typeof window.opener.paypal != "undefined" ) &&
						( typeof window.opener.paypal.checkout != "undefined" ) ) {
					window.opener.location.assign( "<?php echo $redirect_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" );
					window.close();
				} else {
					window.location.assign( "<?php echo $redirect_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" );
				}
			</script>
			<?php
			exit;
		} else {
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}

/**
 * @deprecated
 */
function wc_gateway_ppec_format_paypal_api_exception( $errors ) {
	_deprecated_function( 'wc_gateway_ppec_format_paypal_api_exception', '1.2.0', '' );
}

/**
 * Log a message via WC_Logger.
 *
 * @param string $message Message to log
 */
function wc_gateway_ppec_log( $message ) {
	static $wc_ppec_logger;

	// No need to write to log file if logging is disabled.
	if ( ! wc_gateway_ppec()->settings->is_logging_enabled() ) {
		return false;
	}

	if ( ! isset( $wc_ppec_logger ) ) {
		$wc_ppec_logger = new WC_Logger();
	}

	$wc_ppec_logger->add( 'wc_gateway_ppec', $message );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Whether PayPal credit is supported.
 *
 * @since 1.5.0
 *
 * @return bool Returns true if PayPal credit is supported
 */
function wc_gateway_ppec_is_credit_supported() {
	return wc_gateway_ppec_is_US_based_store() && 'USD' === get_woocommerce_currency();
}

/**
 * Checks whether buyer is checking out with PayPal Credit.
 *
 * @since 1.2.0
 *
 * @return bool Returns true if buyer is checking out with PayPal Credit
 */
function wc_gateway_ppec_is_using_credit() {
	return ! empty( $_GET['use-ppc'] ) && 'true' === $_GET['use-ppc']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

const PPEC_FEE_META_NAME_OLD = 'PayPal Transaction Fee';
const PPEC_FEE_META_NAME_NEW = '_paypal_transaction_fee';

/**
 * Sets the PayPal Fee in the order metadata
 *
 * @since 1.6.6
 *
 * @param object $order Order to modify
 * @param string $fee Fee to save
 */
function wc_gateway_ppec_set_transaction_fee( $order, $fee ) {
	if ( empty( $fee ) ) {
		return;
	}
	$fee = wc_clean( $fee );
	if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		update_post_meta( $order->id, PPEC_FEE_META_NAME_NEW, $fee );
	} else {
		$order->update_meta_data( PPEC_FEE_META_NAME_NEW, $fee );
		$order->save_meta_data();
	}
}

/**
 * Gets the PayPal Fee from the order metadata, migrates if the fee was saved under a legacy key
 *
 * @since 1.6.6
 *
 * @param object $order Order to read
 * @return string Returns the fee or an empty string if the fee has not been set on the order
 */
function wc_gateway_ppec_get_transaction_fee( $order ) {
	$old_wc = version_compare( WC_VERSION, '3.0', '<' );

	//retrieve the fee using the new key
	if ( $old_wc ) {
		$fee = get_post_meta( $order->id, PPEC_FEE_META_NAME_NEW, true );
	} else {
		$fee = $order->get_meta( PPEC_FEE_META_NAME_NEW, true );
	}

	//if the fee was found, return
	if ( is_numeric( $fee ) ) {
		return $fee;
	}

	//attempt to retrieve the old meta, delete its old key, and migrate it to the new one
	if ( $old_wc ) {
		$fee = get_post_meta( $order->id, PPEC_FEE_META_NAME_OLD, true );
		delete_post_meta( $order->id, PPEC_FEE_META_NAME_OLD );
	} else {
		$fee = $order->get_meta( PPEC_FEE_META_NAME_OLD, true );
		$order->delete_meta_data( PPEC_FEE_META_NAME_OLD );
		$order->save_meta_data();
	}

	if ( is_numeric( $fee ) ) {
		wc_gateway_ppec_set_transaction_fee( $order, $fee );
	}

	return $fee;
}

/**
 * Checks whether the store is based in the US.
 *
 * Stores with a base location in the US, Puerto Rico, Guam, US Virgin Islands, American Samoa, or Northern Mariana Islands are considered US based stores.
 *
 * @return bool True if the store is located in the US or US Territory, otherwise false.
 */
function wc_gateway_ppec_is_US_based_store() {
	$base_location = wc_get_base_location();
	return in_array( $base_location['country'], array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP' ), true );
}

/**
 * Saves the transaction details from the transaction response into a post meta.
 *
 * @since 2.0
 *
 * @param object $order                Order for which the transaction was made
 * @param object $transaction_response Response from a transaction, which contains the transaction details
 * @param object $prefix               A prefix string which is empty for Reference Transactions and is 'PAYMENTINFO_0_' for Express Checkout
 * @return void
 */
function wc_gateway_ppec_save_transaction_data( $order, $transaction_response, $prefix = '' ) {

	$settings = wc_gateway_ppec()->settings;
	$old_wc   = version_compare( WC_VERSION, '3.0', '<' );
	$order_id = $old_wc ? $order->id : $order->get_id();
	$meta     = $old_wc ? get_post_meta( $order_id, '_woo_pp_txnData', true ) : $order->get_meta( '_woo_pp_txnData', true );

	if ( ! empty( $meta ) ) {
		$txnData = $meta;
	} else {
		$txnData = array( 'refundable_txns' => array() );
	}

	$txn = array(
		'txnID'           => $transaction_response[ $prefix . 'TRANSACTIONID' ],
		'amount'          => $transaction_response[ $prefix . 'AMT' ],
		'refunded_amount' => 0,
	);

	$status = ! empty( $transaction_response[ $prefix . 'PAYMENTSTATUS' ] ) ? $transaction_response[ $prefix . 'PAYMENTSTATUS' ] : '';

	if ( 'Completed' === $status ) {
		$txn['status'] = 'Completed';
	} else {
		$txn['status'] = $status . '_' . $transaction_response[ $prefix . 'REASONCODE' ];
	}
	$txnData['refundable_txns'][] = $txn;

	$paymentAction = $settings->get_paymentaction();

	if ( 'authorization' === $paymentAction ) {
		$txnData['auth_status'] = 'NotCompleted';
	}

	$txnData['txn_type'] = $paymentAction;

	if ( $old_wc ) {
		update_post_meta( $order_id, '_woo_pp_txnData', $txnData );
	} else {
		$order->update_meta_data( '_woo_pp_txnData', $txnData );
		$order->save();
	}
}

function set_currency_format ( $value ) {
	$settings = wc_gateway_ppec()->settings;
	$decimals = $settings->get_number_of_decimal_digits();
	$value = number_format( $value, $decimals );
	return $value;
}

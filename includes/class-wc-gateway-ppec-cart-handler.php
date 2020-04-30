<?php
/**
 * Cart handler.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_PPEC_Cart_Handler handles button display in the frontend.
 */
class WC_Gateway_PPEC_Cart_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! wc_gateway_ppec()->settings->is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_before_cart_totals', array( $this, 'before_cart_totals' ) );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_paypal_button' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		if ( 'yes' === wc_gateway_ppec()->settings->use_spb ) {
			add_action( 'woocommerce_after_mini_cart', array( $this, 'display_mini_paypal_button' ), 20 );
		} else {
			add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'display_mini_paypal_button' ), 20 );
		}
		add_action( 'widget_title', array( $this, 'maybe_enqueue_checkout_js' ), 10, 3 );

		if ( 'yes' === wc_gateway_ppec()->settings->checkout_on_single_product_enabled ) {
			add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'display_paypal_button_product' ), 1 );
			add_action( 'wc_ajax_wc_ppec_generate_cart', array( $this, 'wc_ajax_generate_cart' ) );
			add_action( 'wp', array( $this, 'ensure_session' ) ); // Ensure there is a customer session so that nonce is not invalidated by new session created on AJAX POST request.
		}

		add_action( 'wc_ajax_wc_ppec_update_shipping_costs', array( $this, 'wc_ajax_update_shipping_costs' ) );
		add_action( 'wc_ajax_wc_ppec_start_checkout', array( $this, 'wc_ajax_start_checkout' ) );
	}

	/**
	 * Start checkout handler when cart is loaded.
	 */
	public function before_cart_totals() {
		// If there then call start_checkout() else do nothing so page loads as normal.
		if ( ! empty( $_GET['startcheckout'] ) && 'true' === $_GET['startcheckout'] ) {
			// Trying to prevent auto running checkout when back button is pressed from PayPal page.
			$_GET['startcheckout'] = 'false';
			woo_pp_start_checkout();
		}
	}

	/**
	 * Generates the cart for PayPal Checkout on a product level.
	 * TODO: Why not let the default "add-to-cart" PHP form handler insert the product into the cart? Investigate.
	 *
	 * @since 1.4.0
	 */
	public function wc_ajax_generate_cart() {
		global $post;

		if ( ! wp_verify_nonce( $_POST['nonce'], '_wc_ppec_generate_cart_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce-gateway-paypal-express-checkout' ) );
		}

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		WC()->shipping->reset_shipping();
		$product = wc_get_product( $post->ID );

		if ( ! empty( $_POST['ppec-add-to-cart'] ) ) {
			$product = wc_get_product( absint( $_POST['ppec-add-to-cart'] ) );
		}

		/**
		 * If this page is single product page, we need to simulate
		 * adding the product to the cart taken account if it is a
		 * simple or variable product.
		 */
		if ( $product ) {
			$qty     = ! isset( $_POST['quantity'] ) ? 1 : absint( $_POST['quantity'] );
			wc_empty_cart();

			if ( $product->is_type( 'variable' ) ) {
				$attributes = array();

				foreach ( $product->get_attributes() as $attribute ) {
					if ( ! $attribute['is_variation'] ) {
						continue;
					}

					$attribute_key = 'attribute_' . sanitize_title( $attribute['name'] );

					if ( isset( $_POST['attributes'][ $attribute_key ] ) ) {
						if ( $attribute['is_taxonomy'] ) {
							// Don't use wc_clean as it destroys sanitized characters.
							$value = sanitize_title( wp_unslash( $_POST['attributes'][ $attribute_key ] ) );
						} else {
							$value = html_entity_decode( wc_clean( wp_unslash( $_POST['attributes'][ $attribute_key ] ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
						}

						$attributes[ $attribute_key ] = $value;
					}
				}

				if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
					$variation_id = $product->get_matching_variation( $attributes );
				} else {
					$data_store = WC_Data_Store::load( 'product' );
					$variation_id = $data_store->find_matching_product_variation( $product, $attributes );
				}

				WC()->cart->add_to_cart( $product->get_id(), $qty, $variation_id, $attributes );
			} else {
				WC()->cart->add_to_cart( $product->get_id(), $qty );
			}

			WC()->cart->calculate_totals();
		}

		wp_send_json( new stdClass() );
	}

	/**
	 * Update shipping costs. Trigger this update before checking out to have total costs up to date.
	 *
	 * @since 1.4.0
	 */
	public function wc_ajax_update_shipping_costs() {
		if ( ! wp_verify_nonce( $_POST['nonce'], '_wc_ppec_update_shipping_costs_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce-gateway-paypal-express-checkout' ) );
		}

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		WC()->shipping->reset_shipping();

		WC()->cart->calculate_totals();

		wp_send_json( new stdClass() );
	}

	/**
	 * Handle AJAX request to start checkout flow, first triggering form validation if necessary.
	 *
	 * @since 1.6.0
	 */
	public function wc_ajax_start_checkout() {
		if ( ! wp_verify_nonce( $_POST['nonce'], '_wc_ppec_start_checkout_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce-gateway-paypal-express-checkout' ) );
		}

		if ( isset( $_POST['from_checkout'] ) && 'yes' === $_POST['from_checkout'] ) {
			// Intercept process_checkout call to exit after validation.
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'maybe_start_checkout' ), 10, 2 );
			WC()->checkout->process_checkout();
		} else {
			$this->start_checkout( true );
		}
	}

	/**
	 * Report validation errors if any, or else save form data in session and proceed with checkout flow.
	 *
	 * @since 1.6.4
	 */
	public function maybe_start_checkout( $data, $errors = null ) {
		// We need to also check for errors added via wc_add_notice 
		$error_messages = array_map( function( $notice ) {
			return $notice[ 'notice' ];
		}, wc_get_notices( 'error' ) );

		if ( is_null( $errors ) && empty( $error_messages ) ) {
			// Compatibility with WC <3.0: clear notices so they don't re-appear.
			wc_clear_notices();
		} else {
			$error_messages = array_merge( $error_messages, $errors->get_error_messages() );
		}

		if ( empty( $error_messages ) ) {
			$this->set_customer_data( $_POST );
			$this->start_checkout( false );
		} else {
			wp_send_json_error( array( 'messages' => $error_messages ) );
		}
		exit;
	}

	/**
	 * Set Express Checkout and return token in response.
	 *
	 * @param bool $skip_checkout  Whether checkout screen is being bypassed.
	 *
	 * @since 1.6.4
	 */
	protected function start_checkout( $skip_checkout ) {
		try {
			wc_gateway_ppec()->checkout->start_checkout_from_cart( $skip_checkout );
			wp_send_json_success( array( 'token' => WC()->session->paypal->token ) );
		} catch( PayPal_API_Exception $e ) {
			wp_send_json_error( array( 'messages' => array( $e->getMessage() ) ) );
		}
	}

	/**
	 * Store checkout form data in customer session.
	 *
	 * @since 1.6.4
	 */
	protected function set_customer_data( $data ) {
		$customer = WC()->customer;

		$billing_first_name = empty( $data[ 'billing_first_name' ] ) ? '' : wc_clean( $data[ 'billing_first_name' ] );
		$billing_last_name  = empty( $data[ 'billing_last_name' ] )  ? '' : wc_clean( $data[ 'billing_last_name' ] );
		$billing_country    = empty( $data[ 'billing_country' ] )    ? '' : wc_clean( $data[ 'billing_country' ] );
		$billing_address_1  = empty( $data[ 'billing_address_1' ] )  ? '' : wc_clean( $data[ 'billing_address_1' ] );
		$billing_address_2  = empty( $data[ 'billing_address_2' ] )  ? '' : wc_clean( $data[ 'billing_address_2' ] );
		$billing_city       = empty( $data[ 'billing_city' ] )       ? '' : wc_clean( $data[ 'billing_city' ] );
		$billing_state      = empty( $data[ 'billing_state' ] )      ? '' : wc_clean( $data[ 'billing_state' ] );
		$billing_postcode   = empty( $data[ 'billing_postcode' ] )   ? '' : wc_clean( $data[ 'billing_postcode' ] );
		$billing_phone      = empty( $data[ 'billing_phone' ] )      ? '' : wc_clean( $data[ 'billing_phone' ] );
		$billing_email      = empty( $data[ 'billing_email' ] )      ? '' : wc_clean( $data[ 'billing_email' ] );

		if ( isset( $data['ship_to_different_address'] ) ) {
			$shipping_first_name = empty( $data[ 'shipping_first_name' ] ) ? '' : wc_clean( $data[ 'shipping_first_name' ] );
			$shipping_last_name  = empty( $data[ 'shipping_last_name' ] )  ? '' : wc_clean( $data[ 'shipping_last_name' ] );
			$shipping_country    = empty( $data[ 'shipping_country' ] )    ? '' : wc_clean( $data[ 'shipping_country' ] );
			$shipping_address_1  = empty( $data[ 'shipping_address_1' ] )  ? '' : wc_clean( $data[ 'shipping_address_1' ] );
			$shipping_address_2  = empty( $data[ 'shipping_address_2' ] )  ? '' : wc_clean( $data[ 'shipping_address_2' ] );
			$shipping_city       = empty( $data[ 'shipping_city' ] )       ? '' : wc_clean( $data[ 'shipping_city' ] );
			$shipping_state      = empty( $data[ 'shipping_state' ] )      ? '' : wc_clean( $data[ 'shipping_state' ] );
			$shipping_postcode   = empty( $data[ 'shipping_postcode' ] )   ? '' : wc_clean( $data[ 'shipping_postcode' ] );
		} else {
			$shipping_first_name = $billing_first_name;
			$shipping_last_name  = $billing_last_name;
			$shipping_country    = $billing_country;
			$shipping_address_1  = $billing_address_1;
			$shipping_address_2  = $billing_address_2;
			$shipping_city       = $billing_city;
			$shipping_state      = $billing_state;
			$shipping_postcode   = $billing_postcode;
		}

		$customer->set_shipping_country( $shipping_country );
		$customer->set_shipping_address( $shipping_address_1 );
		$customer->set_shipping_address_2( $shipping_address_2 );
		$customer->set_shipping_city( $shipping_city );
		$customer->set_shipping_state( $shipping_state );
		$customer->set_shipping_postcode( $shipping_postcode );

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$customer->shipping_first_name = $shipping_first_name;
			$customer->shipping_last_name = $shipping_last_name;
			$customer->billing_first_name = $billing_first_name;
			$customer->billing_last_name = $billing_last_name;

			$customer->set_country( $billing_country );
			$customer->set_address( $billing_address_1 );
			$customer->set_address_2( $billing_address_2 );
			$customer->set_city( $billing_city );
			$customer->set_state( $billing_state );
			$customer->set_postcode( $billing_postcode );
			$customer->billing_phone = $billing_phone;
			$customer->billing_email = $billing_email;
		} else {
			$customer->set_shipping_first_name( $shipping_first_name );
			$customer->set_shipping_last_name( $shipping_last_name );
			$customer->set_billing_first_name( $billing_first_name );
			$customer->set_billing_last_name( $billing_last_name );

			$customer->set_billing_country( $billing_country );
			$customer->set_billing_address_1( $billing_address_1 );
			$customer->set_billing_address_2( $billing_address_2 );
			$customer->set_billing_city( $billing_city );
			$customer->set_billing_state( $billing_state );
			$customer->set_billing_postcode( $billing_postcode );
			$customer->set_billing_phone( $billing_phone );
			$customer->set_billing_email( $billing_email );
		}
	}

	/**
	 * Display paypal button on the product page.
	 *
	 * @since 1.4.0
	 */
	public function display_paypal_button_product() {
		global $product;

		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( ! is_product() || ! isset( $gateways['ppec_paypal'] ) || ! $product->is_in_stock() || $product->is_type( 'external' ) || $product->is_type( 'grouped' ) ) {
			return;
		}

		if ( apply_filters( 'woocommerce_paypal_express_checkout_hide_button_on_product_page', false ) ) {
			return;
		}

		$settings = wc_gateway_ppec()->settings;

		$express_checkout_img_url = apply_filters( 'woocommerce_paypal_express_checkout_button_img_url', sprintf( 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/checkout-logo-%s.png', $settings->button_size ) );

		?>
		<div class="wcppec-checkout-buttons woo_pp_cart_buttons_div">
			<?php if ( 'yes' === $settings->use_spb ) :
				wp_enqueue_script( 'wc-gateway-ppec-smart-payment-buttons' ); ?>
			<div id="woo_pp_ec_button_product"></div>
			<?php else : ?>

			<a href="<?php echo esc_url( add_query_arg( array( 'startcheckout' => 'true' ), wc_get_page_permalink( 'cart' ) ) ); ?>" id="woo_pp_ec_button_product" class="wcppec-checkout-buttons__button">
				<img src="<?php echo esc_url( $express_checkout_img_url ); ?>" alt="<?php _e( 'Check out with PayPal', 'woocommerce-gateway-paypal-express-checkout' ); ?>" style="width: auto; height: auto;">
			</a>
			<?php endif; ?>
		</div>
		<?php

		wp_enqueue_script( 'wc-gateway-ppec-generate-cart', wc_gateway_ppec()->plugin_url . 'assets/js/wc-gateway-ppec-generate-cart.js', array( 'jquery' ), wc_gateway_ppec()->version, true );
		wp_localize_script( 'wc-gateway-ppec-generate-cart', 'wc_ppec_generate_cart_context',
			array(
				'generate_cart_nonce' => wp_create_nonce( '_wc_ppec_generate_cart_nonce' ),
				'ajaxurl'             => WC_AJAX::get_endpoint( 'wc_ppec_generate_cart' ),
			)
		);
	}

	/**
	 * Display paypal button on the cart page.
	 */
	public function display_paypal_button() {

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$settings = wc_gateway_ppec()->settings;

		// billing details on checkout page to calculate shipping costs
		if ( ! isset( $gateways['ppec_paypal'] ) || 'no' === $settings->cart_checkout_enabled ) {
			return;
		}

		$express_checkout_img_url = apply_filters( 'woocommerce_paypal_express_checkout_button_img_url', sprintf( 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/checkout-logo-%s.png', $settings->button_size ) );
		$paypal_credit_img_url    = apply_filters( 'woocommerce_paypal_express_checkout_credit_button_img_url', sprintf( 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/ppcredit-logo-%s.png', $settings->button_size ) );
		?>
		<div class="wcppec-checkout-buttons woo_pp_cart_buttons_div">

			<?php if ( has_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout' ) ) : ?>
				<div class="wcppec-checkout-buttons__separator">
					<?php _e( '&mdash; or &mdash;', 'woocommerce-gateway-paypal-express-checkout' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings->use_spb ) :
				wp_enqueue_script( 'wc-gateway-ppec-smart-payment-buttons' ); ?>
			<div id="woo_pp_ec_button_cart"></div>
			<?php else : ?>

			<a href="<?php echo esc_url( add_query_arg( array( 'startcheckout' => 'true' ), wc_get_page_permalink( 'cart' ) ) ); ?>" id="woo_pp_ec_button" class="wcppec-checkout-buttons__button">
				<img src="<?php echo esc_url( $express_checkout_img_url ); ?>" alt="<?php _e( 'Check out with PayPal', 'woocommerce-gateway-paypal-express-checkout' ); ?>" style="width: auto; height: auto;">
			</a>

			<?php if ( $settings->is_credit_enabled() ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'startcheckout' => 'true', 'use-ppc' => 'true' ), wc_get_page_permalink( 'cart' ) ) ); ?>" id="woo_pp_ppc_button" class="wcppec-checkout-buttons__button">
				<img src="<?php echo esc_url( $paypal_credit_img_url ); ?>" alt="<?php _e( 'Pay with PayPal Credit', 'woocommerce-gateway-paypal-express-checkout' ); ?>" style="width: auto; height: auto;">
				</a>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Display paypal button on the cart widget
	 */
	public function display_mini_paypal_button() {

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$settings = wc_gateway_ppec()->settings;

		// billing details on checkout page to calculate shipping costs
		if ( ! isset( $gateways['ppec_paypal'] ) || 'no' === $settings->cart_checkout_enabled || 0 === WC()->cart->get_cart_contents_count() || ! WC()->cart->needs_payment() ) {
			return;
		}
		?>

		<?php if ( 'yes' === $settings->use_spb ) : ?>
		<p class="woocommerce-mini-cart__buttons buttons wcppec-cart-widget-spb">
			<span id="woo_pp_ec_button_mini_cart"></span>
		</p>
		<?php else : ?>

		<a href="<?php echo esc_url( add_query_arg( array( 'startcheckout' => 'true' ), wc_get_page_permalink( 'cart' ) ) ); ?>" id="woo_pp_ec_button" class="wcppec-cart-widget-button">
			<img src="<?php echo esc_url( 'https://www.paypalobjects.com/webstatic/en_US/i/btn/png/gold-rect-paypalcheckout-26px.png' ); ?>" alt="<?php _e( 'Check out with PayPal', 'woocommerce-gateway-paypal-express-checkout' ); ?>" style="width: auto; height: auto;">
		</a>
		<?php endif; ?>
		<?php
	}

	public function maybe_enqueue_checkout_js( $widget_title, $widget_instance = array(), $widget_id = null ) {
		if ( 'woocommerce_widget_cart' === $widget_id ) {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			$settings = wc_gateway_ppec()->settings;
			if ( isset( $gateways['ppec_paypal'] ) && 'yes' === $settings->cart_checkout_enabled && 'yes' === $settings->use_spb ) {
				wp_enqueue_script( 'wc-gateway-ppec-smart-payment-buttons' );
			}
        }
		return $widget_title;
    }

	/**
	 * Convert from settings to values expected by PayPal Button API:
	 *   - 'small' button size only allowed if layout is 'vertical'.
	 *   - 'disallowed' funding methods if layout is 'vertical'.
	 *   - 'allowed' funding methods if layout is 'horizontal'.
	 *   - Only allow PayPal Credit if supported.
	 *
	 * @param array Raw settings.
	 *
	 * @return array Same array adapted to include data suitable for client-side rendering.
	 *
	 * @since 1.6.0
	 */
	protected function get_button_settings( $settings, $context = '' ) {
		$prefix = $context ? $context . '_' : $context;
		$data = array(
			'button_layout'        => $settings->{ $prefix . 'button_layout' },
			'button_size'          => $settings->{ $prefix . 'button_size' },
			'button_label'         => $settings->{ $prefix . 'button_label' },
			'hide_funding_methods' => $settings->{ $prefix . 'hide_funding_methods' },
			'credit_enabled'       => $settings->{ $prefix . 'credit_enabled' },
		);

		$button_layout        = $data['button_layout'];
		$data['button_size']  = 'vertical' === $button_layout && 'small' === $data['button_size']
			? 'medium'
			: $data['button_size'];

		if ( ! wc_gateway_ppec_is_credit_supported() ) {
			$data['credit_enabled'] = 'no';
			if ( ! is_array( $data['hide_funding_methods'] ) ) {
				$data['hide_funding_methods'] = array( 'CREDIT' );
			} elseif ( ! in_array( 'CREDIT', $data['hide_funding_methods'] ) ) {
				$data['hide_funding_methods'][] = 'CREDIT';
			}
		}

		if ( 'vertical' === $button_layout ) {
			$data['disallowed_methods'] = $data['hide_funding_methods'];
		} else {
			$data['allowed_methods'] = 'yes' === $data['credit_enabled'] ? array( 'CREDIT' ) : array();
		}
		unset( $data['hide_funding_methods'], $data['credit_enabled'] );

		return $data;
	}

	/**
	 * Frontend scripts
	 */
	public function enqueue_scripts() {
		$settings = wc_gateway_ppec()->settings;
		$client   = wc_gateway_ppec()->client;

		wp_enqueue_style( 'wc-gateway-ppec-frontend', wc_gateway_ppec()->plugin_url . 'assets/css/wc-gateway-ppec-frontend.css' );

		$is_cart     = is_cart() && ! WC()->cart->is_empty() && 'yes' === $settings->cart_checkout_enabled;
		$is_product  = ( is_product() || wc_post_content_has_shortcode( 'product_page' ) ) && 'yes' === $settings->checkout_on_single_product_enabled;
		$is_checkout = is_checkout() && 'yes' === $settings->mark_enabled && ! wc_gateway_ppec()->checkout->has_active_session();
		$page        = $is_cart ? 'cart' : ( $is_product ? 'product' : ( $is_checkout ? 'checkout' : null ) );

		if ( 'yes' !== $settings->use_spb && $is_cart ) {
			wp_enqueue_script( 'paypal-checkout-js', 'https://www.paypalobjects.com/api/checkout.js', array(), null, true );
			wp_enqueue_script( 'wc-gateway-ppec-frontend-in-context-checkout', wc_gateway_ppec()->plugin_url . 'assets/js/wc-gateway-ppec-frontend-in-context-checkout.js', array( 'jquery' ), wc_gateway_ppec()->version, true );
			wp_localize_script( 'wc-gateway-ppec-frontend-in-context-checkout', 'wc_ppec_context',
				array(
					'payer_id'    => $client->get_payer_id(),
					'environment' => $settings->get_environment(),
					'locale'      => $settings->get_paypal_locale(),
					'start_flow'  => esc_url( add_query_arg( array( 'startcheckout' => 'true' ), wc_get_page_permalink( 'cart' ) ) ),
					'show_modal'  => apply_filters( 'woocommerce_paypal_express_checkout_show_cart_modal', true ),
					'update_shipping_costs_nonce' => wp_create_nonce( '_wc_ppec_update_shipping_costs_nonce' ),
					'ajaxurl'     => WC_AJAX::get_endpoint( 'wc_ppec_update_shipping_costs' ),
				)
			);

		} elseif ( 'yes' === $settings->use_spb ) {
			wp_register_script( 'paypal-checkout-js', 'https://www.paypalobjects.com/api/checkout.js', array(), null, true );
			wp_register_script( 'wc-gateway-ppec-smart-payment-buttons', wc_gateway_ppec()->plugin_url . 'assets/js/wc-gateway-ppec-smart-payment-buttons.js', array( 'jquery', 'paypal-checkout-js' ), wc_gateway_ppec()->version, true );

			$data = array(
				'environment'          => 'sandbox' === $settings->get_environment() ? 'sandbox' : 'production',
				'locale'               => $settings->get_paypal_locale(),
				'page'                 => $page,
				'button_color'         => $settings->button_color,
				'button_shape'         => $settings->button_shape,
				'button_label'         => $settings->button_label,
				'start_checkout_nonce' => wp_create_nonce( '_wc_ppec_start_checkout_nonce' ),
				'start_checkout_url'   => WC_AJAX::get_endpoint( 'wc_ppec_start_checkout' ),
			);

			if ( ! is_null(  $page ) ) {
				if ( 'product' === $page && 'yes' === $settings->single_product_settings_toggle ) {
					$button_settings = $this->get_button_settings( $settings, 'single_product' );
				} elseif ( 'checkout' === $page && 'yes' === $settings->mark_settings_toggle ) {
					$button_settings = $this->get_button_settings( $settings, 'mark' );
				} else {
					$button_settings = $this->get_button_settings( $settings );
				}

				$data = array_merge( $data, $button_settings );
			}

			$settings_toggle = 'yes' === $settings->mini_cart_settings_toggle;
			$mini_cart_data  = $this->get_button_settings( $settings, $settings_toggle ? 'mini_cart' : '' );
			foreach( $mini_cart_data as $key => $value ) {
				unset( $mini_cart_data[ $key ] );
				$mini_cart_data[ 'mini_cart_' . $key ] = $value;
			}
			$data = array_merge( $data, $mini_cart_data );

			wp_localize_script( 'wc-gateway-ppec-smart-payment-buttons', 'wc_ppec_context', $data );
		}
	}

	/**
	 * Creates a customer session if one is not already active.
	 */
	public function ensure_session() {
		// TODO: this tries to replicate Woo core functionality of checking for frontend requests.
		// It can be removed once we drop support for pre-3.5 versions.
		$frontend = ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' ) && ! defined( 'REST_REQUEST' );

		if ( ! $frontend ) {
			return;
		}

		if ( ! empty( WC()->session ) && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * @deprecated
	 */
	public function loadCartDetails() {
		_deprecated_function( __METHOD__, '1.2.0', '' );
	}

	/**
	 * @deprecated
	 */
	public function loadOrderDetails( $order_id ) {
		_deprecated_function( __METHOD__, '1.2.0', '' );
	}

	/**
	 * @deprecated
	 */
	public function setECParams() {
		_deprecated_function( __METHOD__, '1.2.0', '' );
	}
}

/* global wc_ppec_generate_cart_context */
;(function( $, window, document ) {
	'use strict';

	// This button state is only applicable to non-SPB click handler below.
	var button_enabled = true;
	$( '#woo_pp_ec_button_product' )
		.on( 'enable.legacy', function() {
			button_enabled = true;
		} )
		.on( 'disable.legacy', function() {
			button_enabled = false;
		} );

	$( '#woo_pp_ec_button_product' )
		.on( 'enable', function() {
			$( '#woo_pp_ec_button_product' )
				.css( {
					'cursor': '',
					'-webkit-filter': '', // Safari 6.0 - 9.0
					'filter': '',
				} )
				.off( 'mouseup' )
				.find( '> *' )
				.css( 'pointer-events', '' );
		} )
		.on( 'disable', function() {
			$( '#woo_pp_ec_button_product' )
				.css( {
					'cursor': 'not-allowed',
					'-webkit-filter': 'grayscale( 100% )', // Safari 6.0 - 9.0
					'filter': 'grayscale( 100% )',
				} )
				.on( 'mouseup', function( event ) {
					event.stopImmediatePropagation();
					form.find( ':submit' ).trigger( 'click' );
				} )
				.find( '> *' )
				.css( 'pointer-events', 'none' );
		} );

	// True if the product is simple or the user selected a valid variation. False on variable product without a valid variation selected
	var variation_valid = true;

	// True if all the fields of the product form are valid (such as required fields configured by Product Add-Ons). False otherwise
	var fields_valid = true;

	var form = $( 'form.cart' );

	var update_button = function() {
		$( '#woo_pp_ec_button_product' ).trigger( ( variation_valid && fields_valid ) ? 'enable' : 'disable' );
	};

	var validate_form = function() {
		// Check fields are valid and allow third parties to attach their own validation checks
		fields_valid = form.get( 0 ).checkValidity() && $( document ).triggerHandler( 'wc_ppec_validate_product_form', [ fields_valid, form ] ) !== false;

		// WC Product Addons Validation
		fields_valid = validate_addon_image_fields();
		update_button();
	};

	// It's a variations form, button availability should depend on its events
	if ( $( '.variations_form' ).length ) {
		variation_valid = false;

		$( '.variations_form' )
		.on( 'show_variation', function( event, form, purchasable ) {
			variation_valid = purchasable;
			update_button();
		} )
		.on( 'hide_variation', function() {
			variation_valid = false;
			update_button();
		} );
	}

	// Disable the button if there are invalid fields in the product page (like required fields from Product Addons)
	form.on( 'change', 'select, input, textarea', function() {
		// Hack: IE11 uses the previous field value for the checkValidity() check if it's called in the onChange handler
		setTimeout( validate_form, 0 );
	} );

	// WC Product Addons: If image swatch is a required field, check whether it is selected. Disable PP button if false.
	function validate_addon_image_fields() {
		var swatch_select_count = 0;
		var wc_pao_form_rows = $( '.wc-pao-required-addon > .form-row' ); // Only select required image fields.

		for ( var j = 0; j < wc_pao_form_rows.length; j++ ) {
			var item = wc_pao_form_rows[j];
			var image_swatches = item.children;

			for ( var i = 0; i < image_swatches.length-1; i++ ) {
				var image_swatch = image_swatches[i];

				if ( $( image_swatch ).hasClass( 'selected' ) ) {
					swatch_select_count += 1;
					break;
				}
			}
		}

		// Return true if at least one image swatch is selected in each image field.
		return wc_pao_form_rows.length === swatch_select_count;
	}

	// WC Product Addons: Trigger validation when image fields are clicked.
	$( '.wc-pao-addon-image-swatch' ).click( function() {
		setTimeout( validate_form, 0 );
	} );

	$( document ).ready(function() {
		validate_form();
	} );

	var generate_cart = function( callback ) {
		var data = {
			'nonce': wc_ppec_generate_cart_context.generate_cart_nonce,
			'attributes': {},
		};

		var field_pairs = form.serializeArray();

		for ( var i = 0; i < field_pairs.length; i++ ) {
			// Prevent the default WooCommerce PHP form handler from recognizing this as an "add to cart" call
			if ( 'add-to-cart' === field_pairs[ i ].name ) {
				field_pairs[ i ].name = 'ppec-add-to-cart';
			}

			// Save attributes as a separate prop in `data` object,
			// so that `attributes` can be used later on when adding a variable product to cart
			if ( -1 !== field_pairs[ i ].name.indexOf( 'attribute_' ) ) {
				data.attributes[ field_pairs[ i ].name ] = field_pairs[ i ].value;
				continue;
			}

			data[ field_pairs[ i ].name ] = field_pairs[ i ].value;
		}

		// If this is a simple product, the "Submit" button has the product ID as "value", we need to include it explicitly
		data[ 'ppec-add-to-cart' ] = $( '[name=add-to-cart]' ).val();

		$.ajax( {
			type:    'POST',
			data:    data,
			url:     wc_ppec_generate_cart_context.ajaxurl,
			success: callback,
		} );
	};

	window.wc_ppec_generate_cart = generate_cart;

	// Non-SPB mode click handler, namespaced as 'legacy' as it's replaced by `payment` callback of Button API.
	$( '#woo_pp_ec_button_product' ).on( 'click.legacy', function( event ) {
		event.preventDefault();

		if ( ! button_enabled ) {
			return;
		}

		$( '#woo_pp_ec_button_product' ).trigger( 'disable' );

		var href = $(this).attr( 'href' );

		generate_cart( function() {
			window.location.href = href;
		} );
	} );

})( jQuery, window, document );

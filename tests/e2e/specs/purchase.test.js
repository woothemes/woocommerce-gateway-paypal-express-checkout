/**
 * External dependencies
 */
import config from 'config';

/**
 * Internal dependencies
 */
import { CustomerFlow, uiUnblocked } from '@woocommerce/e2e-utils';

const TIMEOUT = 40000;
let paypalForm;

describe( 'Successful PayPal Purchase as Guest', () => {
	it( 'Set up the cart and checkout', async () => {
		await CustomerFlow.goToShop();
		await CustomerFlow.addToCartFromShopPage( config.get( 'products.simple.name' ) );
		await CustomerFlow.goToCheckout();
		await uiUnblocked();
		await CustomerFlow.fillBillingDetails(
			config.get( 'addresses.customer.billing' )
		);
		await uiUnblocked();
	}, TIMEOUT );

	it( 'Check PayPal Checkout is loaded on the checkout', async () => {
		await expect( page ).toMatchElement( '#payment_method_ppec_paypal' );
	}, TIMEOUT );

	it( 'Click the PayPal Smart Payment button', async () => {
		const paypal_iframe_element = await page.$( '#woo_pp_ec_button_checkout iframe' );
		const frame                 = await paypal_iframe_element.contentFrame();

		await frame.waitForSelector( '[data-funding-source="paypal"]', { visible: true } );
		await frame.click( '[data-funding-source="paypal"]' );
	}, TIMEOUT );

	it( 'Wait for PayPal Pop-up', async () => {
		// switch to new window
		const newPopUpPromise = new Promise( x => browser.once( 'targetcreated',  target => x( target.page() ) ) );
		const popup = await newPopUpPromise;
		await popup.waitForSelector( '#cardNumber', { visible: true } );

		return paypalForm = popup;
	}, TIMEOUT );

	it( 'Complete PayPal Checkout Form', async () => {
		await paypalForm.type( '#cardNumber', '4242424242424242' );
		await paypalForm.type( '#cardExpiry', '02/23' );
		await paypalForm.type( '#cardCvv', '123' );
		await paypalForm.type( '#phone', '41555555555' );
		await paypalForm.click( '[type="submit"]' );
		// select
	}, TIMEOUT );

	it( 'Wait for Order Thank-you Page', async () => {
		await page.waitForSelector( '.woocommerce-order-received .woocommerce-order-overview__order.order', { visible: true } );
	}, 80000 );
} );
 
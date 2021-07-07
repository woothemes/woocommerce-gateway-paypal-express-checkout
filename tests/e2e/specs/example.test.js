/**
 * External dependencies
 */
import config from 'config';

/**
 * Internal dependencies
 */
import { CustomerFlow, uiUnblocked } from '../utils';

const TIMEOUT = 20000;

describe( 'Example Checkout Test', () => {
	it( 'Adds a simple product to the cart and loads checkout page', async () => {
		await CustomerFlow.goToShop();
		await CustomerFlow.addToCartFromShopPage( config.get( 'products.simple.name' ) );
		await CustomerFlow.goToCheckout();
		await uiUnblocked();
		await CustomerFlow.fillBillingDetails(
			config.get( 'addresses.customer.billing' )
		);
		await uiUnblocked();
	}, TIMEOUT );
} );

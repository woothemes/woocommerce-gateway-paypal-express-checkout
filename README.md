# [PayPal Checkout](https://woocommerce.com/products/woocommerce-gateway-paypal-checkout/)

## Getting Started

If Docker is your friend, please follow the instructions [here](https://github.com/Automattic/woocommerce-payments/blob/trunk/docker/README.md) to setup up the local Docker containers to manage all the services required to run this project. 

Otherwise, if you prefer doing things the hard way, please follow the instructions below.

1. Clone this repository locally within the plugins directory of WordPress.
2. Go to your local installation, and activate this plugin.
3. Signup or sign into your [PayPal developer account]( https://developer.paypal.com) and [create buyer and seller accounts](https://developer.paypal.com/docs/payflow/express-checkout/testing/#create-paypal-sandbox-seller-and-buyer-accounts).
4. View your newly created seller account and copy the API credentials.
5. Go to **WooCommerce > Settings > Payments** and select the Set up button next to PayPal Checkout.
6. Set **Environment** to Sandbox, then select the **click here to toggle manual API credential input** link.
7. Paste your API credentials and save.
8. Ensure PayPal Checkout is enabled by checking the **Enable/Disable** checkbox.
9. To display PayPal buttons on the cart page, check the **Checkout on cart page** checkbox.
10. Similarly, check the **Checkout on Single Product** checkbox to display buttons on single product pages.

You can find a detailed list of all settings in the [WooCommerce PayPal Checkout documentation](https://docs.woocommerce.com/document/paypal-express-checkout/).

To checkout, you can use your sandbox buyer account, or [generate a test credit card](https://developer.paypal.com/developer/creditCardGenerator).

## API

The documentation for the API endpoints can be found [in PayPal's developer documentation](https://developer.paypal.com/docs/api/overview/)

You can also explore the API from [PayPal's API Executor](https://www.paypal.com/apex/product-profile/ordersv2)

## Repository

* The `/woocommerce/woocommerce-gateway-paypal-express-checkout/` repository is treated as a _development_ repository: this includes development assets, like unit tests and configuration files. Commit history for this repository includes all commits for all changes to the code base, not just for new versions.

## Deployment

A "_deployment_" in our sense means:
 * validating the version in the header and `WC_GATEWAY_PPEC_VERSION` constant match
 * generating a `.pot` file for all translatable strings in the development repository
 * running a custom deploy script
 * the changes will be pushed to a branch with the name `release/{version}` so that a PR can be issued on `/woocommerce/woocommerce-gateway-paypal-express-checkout/`
 * tagging a new version

## Branches

* [`woocommerce/woocommerce-gateway-paypal-express-checkout/trunk`](https://github.com/woocommerce/woocommerce-gateway-paypal-express-checkout/tree/trunk) includes all code for the current version and any new pull requests merged that will be released with the next version. It can be considered stable for staging and development sites but not for production.

## Coding Standards

This project enforces [WooCommerce coding standards](https://github.com/woocommerce/woocommerce-sniffs). Please respect these standards and if possible run appropriate IDE/editor plugins to help you enforce these rules.

## Testing

We are striving to subject this extension to tests at various levels. They are works in progress. The following will be updated as there is progress.
Do check with us if you want to contribute in some way towards these.
* TBD - Travis Integration
* TBD - Unit Testing
* TBD - E2E Testing

## Contribution
Contribution can be done in many ways. We appreciate it.
* If you test this extension and find a bug/have a question, please submit a bug report.
* If you have fixed any of the issues, please submit a pull request.

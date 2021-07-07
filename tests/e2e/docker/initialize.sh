#!/bin/bash

# Remove all WP pages - Docker wait-for-build uses a page with --post_title="ready" to determine when the environment is ready. We need to remove this file which is created in docker/entrypoint.sh and re-create it at the end of this file.
wp post delete $(wp post list --post_type='page' --format=ids)

echo "Updating permalink structure"
wp rewrite structure '/%postname%/'

echo "Initializing WooCommerce E2E"
wp plugin install woocommerce --activate
wp theme install storefront --activate

echo "Activate and setup PayPal Checkout"
wp plugin activate woocommerce-gateway-paypal-express-checkout

echo "Setting up PayPal Checkout..."
wp option set woocommerce_ppec_paypal_settings --format=json '{"enabled":"yes","title":"PayPal Checkout","description":"Pay via PayPal Checkout","account_settings":"","environment":"sandbox","api_credentials":"","api_username":"","api_password":"","api_signature":"","api_certificate":"","api_subject":"","sandbox_api_credentials":"","sandbox_api_username":"ppec_e2e_api1.business.example.com","sandbox_api_password":"LMBU4MZARU2BVBJE","sandbox_api_signature":"AD6.CjaJSCfDPJMZnUMvahZe6rLFAEhy2luGVkxJ91aM9SgRaN8S.V2F","sandbox_api_certificate":"","sandbox_api_subject":"","paypal_hosted_settings":"","brand_name":"Store","logo_image_url":"","header_image_url":"","page_style":"","landing_page":"Billing","advanced":"","debug":"yes","invoice_prefix":"WC-","require_billing":"no","require_phone_number":"no","paymentaction":"sale","instant_payments":"no","subtotal_mismatch_behavior":"add","button_settings":"","use_spb":"yes","button_color":"gold","button_shape":"rect","button_label":"paypal","button_layout":"vertical","button_size":"responsive","hide_funding_methods":"","credit_enabled":"no","cart_checkout_enabled":"yes","mini_cart_settings":"","mini_cart_settings_toggle":"yes","mini_cart_button_layout":"horizontal","mini_cart_button_size":"responsive","mini_cart_button_label":"paypal","mini_cart_hide_funding_methods":"","mini_cart_credit_enabled":"no","single_product_settings":"","checkout_on_single_product_enabled":"yes","single_product_settings_toggle":"no","single_product_button_layout":"vertical","single_product_button_size":"responsive","single_product_button_label":"paypal","single_product_hide_funding_methods":["CARD"],"single_product_credit_enabled":"no","mark_settings":"","mark_enabled":"yes","mark_settings_toggle":"no","mark_button_layout":"vertical","mark_button_size":"responsive","mark_button_label":"paypal","mark_hide_funding_methods":["CARD"],"mark_credit_enabled":"no"}'

echo "Adding basic WooCommerce settings..."
wp option set woocommerce_store_address "60 29th Street"
wp option set woocommerce_store_address_2 "#343"
wp option set woocommerce_store_city "San Francisco"
wp option set woocommerce_default_country "US:CA"
wp option set woocommerce_store_postcode "94110"
wp option set woocommerce_currency "USD"
wp option set woocommerce_product_type "both"
wp option set woocommerce_allow_tracking "no"

echo "Importing WooCommerce shop pages..."
wp wc --user=admin tool run install_pages

echo "Installing and activating the WordPress Importer plugin"
wp plugin install wordpress-importer --activate
echo "Importing the WooCommerce sample data..."
wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=skip

wp user create customer customer@woocommercecoree2etestsuite.com --user_pass=password --role=customer
# Create the page which is used to determine if the test environment has been setup
wp post create --post_type=page --post_status=publish --post_title='Ready' --post_content='E2E-tests.'
echo "Success! E2E test environment has been setup"

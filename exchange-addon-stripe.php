<?php
/*
 * Plugin Name: iThemes Exchange - Stripe Add-on
 * Version: 0.1.0
 * Description: Adds the ability for users to checkout with Stripe.
 * Plugin URI: http://ithemes.com/exchange/stripe/
 * Author: iThemes
 * Author URI: http://ithemes.com
 * iThemes Package: exchange-addon-stripe
 
 * Installation:
 * 1. Download and unzip the latest release zip file.
 * 2. If you use the WordPress plugin uploader to install this plugin skip to step 4.
 * 3. Upload the entire plugin directory to your `/wp-content/plugins/` directory.
 * 4. Activate the plugin through the 'Plugins' menu in WordPress Administration.
 *
*/

/**
 * This registers our plugin as a stripe addon
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_register_stripe_addon() {
	$options = array(
		'name'              => __( 'Stripe', 'LION' ),
		'description'       => __( 'Process transactions via Stripe.', 'LION' ),
		'author'            => 'iThemes',
		'author_url'        => 'http://ithemes.com/exchange/stripe/',
		'icon'              => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/images/stripe50px.png' ),
		'wizard-icon'       => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/images/wizard-stripe.png' ),
		'file'              => dirname( __FILE__ ) . '/init.php',
		'basename'          => plugin_basename( __FILE__ ),
		'category'          => 'transaction-methods',
		'settings-callback' => 'it_exchange_stripe_addon_settings_callback',	
	);
	it_exchange_register_addon( 'stripe', $options );
}
add_action( 'it_exchange_register_addons', 'it_exchange_register_stripe_addon' );

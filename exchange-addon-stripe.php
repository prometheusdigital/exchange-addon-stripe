<?php
/*
 * Plugin Name: iThemes Exchange - Stripe Add-on
 * Version: 1.1.14
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
 * To learn how to create your own-addon, visit http://ithemes.com/codex/page/Exchange_Custom_Add-ons:_Overview
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_register_stripe_addon() {
	$options = array(
		'name'              => __( 'Stripe', 'LION' ),
		'description'       => __( 'Process transactions via Stripe, a simple and elegant payment gateway.', 'LION' ),
		'author'            => 'iThemes',
		'author_url'        => 'http://ithemes.com/exchange/stripe/',
		'icon'              => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/stripe50px.png' ),
		'wizard-icon'       => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/wizard-stripe.png' ),
		'file'              => dirname( __FILE__ ) . '/init.php',
		'category'          => 'transaction-methods',
		'settings-callback' => 'it_exchange_stripe_addon_settings_callback',	
	);
	it_exchange_register_addon( 'stripe', $options );
}
add_action( 'it_exchange_register_addons', 'it_exchange_register_stripe_addon' );

/**
 * Loads the translation data for WordPress
 *
 * @uses load_plugin_textdomain()
 * @since 1.0.3
 * @return void
*/
function it_exchange_stripe_set_textdomain() {
	load_plugin_textdomain( 'LION', false, dirname( plugin_basename( __FILE__  ) ) . '/lang/' );
}
add_action( 'plugins_loaded', 'it_exchange_stripe_set_textdomain' );

/**
 * Registers Plugin with iThemes updater class
 *
 * @since 1.0.0
 *
 * @param object $updater ithemes updater object
 * @return void
*/
function ithemes_exchange_addon_stripe_updater_register( $updater ) { 
	    $updater->register( 'exchange-addon-stripe', __FILE__ );
}
add_action( 'ithemes_updater_register', 'ithemes_exchange_addon_stripe_updater_register' );
require( dirname( __FILE__ ) . '/lib/updater/load.php' );

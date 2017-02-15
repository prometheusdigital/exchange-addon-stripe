<?php
/**
 * Load the Stripe plugin.
 *
 * @since   2.0.0
 * @license GPLv2
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
	if ( extension_loaded( 'mbstring' ) && version_compare( phpversion(), '5.3', '>=' ) ) {
		$options = array(
			'name'              => __( 'Stripe', 'LION' ),
			'description'       => __( 'Process transactions via Stripe, a simple and elegant payment gateway.', 'LION' ),
			'author'            => 'iThemes',
			'author_url'        => 'http://ithemes.com/exchange/stripe/',
			'icon'              => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/stripe50px.png' ),
			'wizard-icon'       => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/wizard-stripe.png' ),
			'file'              => dirname( __FILE__ ) . '/init.php',
			'category'          => 'transaction-methods',
			//'settings-callback' => 'it_exchange_stripe_addon_settings_callback',
		);
		it_exchange_register_addon( 'stripe', $options );
	}
}
add_action( 'it_exchange_register_addons', 'it_exchange_register_stripe_addon' );

function it_exchange_stripe_addon_show_mbstring_nag() {
	if ( !extension_loaded( 'mbstring' ) ) {
		?>
		<div id="it-exchange-add-on-mbstring-nag" class="it-exchange-nag">
			<?php _e( 'You must have the mbstring PHP extension installed and activated on your web server to use the Stripe Add-on for iThemes Exchange. Please contact your web host provider to ensure this extension is enabled.', 'LION' ); ?>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'it_exchange_stripe_addon_show_mbstring_nag' );

/**
 * Show a nag if PHP 5.3 is not available.
 *
 * @since 1.10.4
 */
function it_exchange_stripe_addon_show_php_version_nag() {

	if ( version_compare( phpversion(), '5.3', '<' ) ) {
		?>
		<div id="it-exchange-add-on-mbstring-nag" class="it-exchange-nag">
			<?php _e( 'You must have PHP version 5.3 or greater to use the Stripe Add-on for iThemes Exchange. Please contact your web host provider to upgrade your PHP version.', 'LION' ); ?>
		</div>
		<?php
	}
}

add_action( 'admin_notices', 'it_exchange_stripe_addon_show_php_version_nag' );

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

it_exchange_stripe_set_textdomain();
<?php
/*
 * Plugin Name: iThemes Exchange - Stripe Add-on
 * Version: 1.5.0
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
	if ( extension_loaded( 'mbstring' ) ) {
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

function ithemes_exchange_stripe_deactivate() {
	if ( empty( $_REQUEST['remove-gateway'] ) || __( 'Yes', 'LION' ) !== $_REQUEST['remove-gateway'] ) {
		$title = __( 'Payment Gateway Warning', 'LION' );
		$yes = get_submit_button( __( 'Yes', 'LION' ), 'small', 'remove-gateway', false );
		$no  = '<a href="javascript:history.back()" style="background: #F7F7F7 none repeat scroll 0% 0%; border: 1px solid #CCC; color: #555; display: inline-block; text-decoration: none; font-size: 13px; line-height: 26px; height: 28px; margin: 0px; padding: 0px 10px 1px; cursor: pointer; border-radius: 3px; white-space: nowrap; box-sizing: border-box; box-shadow: 0px 1px 0px #FFF inset, 0px 1px 0px rgba(0, 0, 0, 0.08); vertical-align: top;">' . __( 'No', 'LION' ) . '</a>';
		$message  = '<form action="" method="POST">';
		foreach ( $_POST as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $val ) {
					$message .= '<input type="hidden" name="' . $key . '[]" value="' . $val . '" />';
				}
			} else {
				$message .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
			}
		}
		$message .= '<p>' . __( 'Deactivating a payment gateway can cause customers to lose access to any membership products they have purchased using this payment gateway. Are you sure you want to proceed?' ) . '</p>';
		$message .= '<p>' . $yes . ' &nbsp; ' . $no . '</p>';
		$message .= '</form>';
		$args = array(
			'response'  => 200,
			'back_link' => false,
		);
		wp_die( $message, $title, $args );
	}
}
register_deactivation_hook( __FILE__, 'ithemes_exchange_stripe_deactivate' );

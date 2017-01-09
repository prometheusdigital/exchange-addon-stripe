<?php
/*
 * Plugin Name: iThemes Exchange - Stripe Add-on
 * Version: 2.0.0
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
 * Load the Stripe plugin.
 *
 * @since 2.0.0
 */
function it_exchange_load_stripe() {
	if ( ! function_exists( 'it_exchange_load_deprecated' ) || it_exchange_load_deprecated() ) {
		require_once dirname( __FILE__ ) . '/deprecated/exchange-addon-stripe.php';
	} else {
		require_once dirname( __FILE__ ) . '/plugin.php';
	}
}

add_action( 'plugins_loaded', 'it_exchange_load_stripe' );

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

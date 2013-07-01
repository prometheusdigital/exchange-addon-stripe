<?php
/**
 * iThemes Exchange Stripe Add-on
 * @package IT_Exchange_Addon_Stripe
 * @since 0.1.0
*/

// Initialized Stripe...
if ( !class_exists( 'Stripe' ) )
	require_once('stripe-api/lib/Stripe.php');

/**
 * Enqueues any scripts we need on the frontend during a stripe checkout
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_stripe_addon_enqueue_script() {
	wp_enqueue_script( 'stripe', 'https://checkout.stripe.com/v2/checkout.js', array( 'jquery' ) );
	wp_enqueue_script( 'stripe-addon-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/stripe-addon.js', array( 'jquery' ) );
	wp_localize_script( 'stripe-addon-js', 'stripeAddonL10n', array(
			'processing_payment_text'  => __( 'Processing payment, please wait...', 'LION' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'it_exchange_stripe_addon_enqueue_script' );

/**
 * Stripe URL to perform refunds
 *
 * @since 0.1.0
 *
 * @param string $url passed by WP filter.
 * @param string $url transaction URL
*/
function it_exchange_refund_url_for_stripe( $url ) {

	return 'https://manage.stripe.com/';
	
}
add_filter( 'it_exchange_refund_url_for_stripe', 'it_exchange_refund_url_for_stripe' );

/**
 * This proccesses a stripe transaction.
 *
 * @since 0.1.0
 *
 * @param string $status passed by WP filter.
 * @param object $transaction_object The transaction object
*/
function it_exchange_stripe_addon_process_transaction( $status, $transaction_object ) {

	// If this has been modified as true already, return.
	if ( $status )
		return $status;

	// Verify nonce
	if ( ! empty( $_REQUEST['_stripe_nonce'] ) && ! wp_verify_nonce( $_REQUEST['_stripe_nonce'], 'stripe-checkout' ) ) {
		it_exchange_add_message( 'error', __( 'Transaction Failed, unable to verify security token.', 'LION' ) );
		return false;
	}

	// Make sure we have the correct $_POST argument
	if ( ! empty( $_POST['stripeToken'] ) ) {

		try {

			$general_settings = it_exchange_get_option( 'settings_general' );
			$settings         = it_exchange_get_option( 'addon_stripe' );

			$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
			Stripe::setApiKey( $secret_key );

			// Set stripe token
			$token = $_POST['stripeToken'];

			// Set stripe customer from WP customer ID
			$it_exchange_customer = it_exchange_get_current_customer();
			if ( $stripe_id = it_exchange_stripe_addon_get_stripe_customer_id( $it_exchange_customer->id ) )
				$stripe_customer = Stripe_Customer::retrieve( $stripe_id );

			// If the user has been deleted from Stripe, we need to create a new Stripe ID.
			if ( ! empty( $stripe_customer ) ) {
				if ( isset( $stripe_customer->deleted ) && true === $stripe_customer->deleted )
					$stripe_customer = array();
			}

			// If this user isn't an existing Stripe User, create a new Stripe ID for them...
			if ( ! empty( $stripe_customer ) ) {
				$stripe_customer->card = $token;
				$stripe_customer->email = $it_exchange_customer->data->user_email;
				$stripe_customer->save();
			} else {
				$customer_array = array(
					'email' => $it_exchange_customer->data->user_email,
					'card'  => $token,
				);

				// Creates a new Stripe ID for this customer
				$stripe_customer = Stripe_Customer::create( $customer_array );

				it_exchange_stripe_addon_set_stripe_customer_id( $it_exchange_customer->id, $stripe_customer->id );
			}

			// Now that we have a valid Customer ID, charge them!
			$charge = Stripe_Charge::create(array(
				'customer'    => $stripe_customer->id,
				'amount'      => number_format( $transaction_object->total, 2, '', '' ),
				'currency'    => $general_settings['default-currency'],
				'description' => $transaction_object->description,
			));
		}
		catch ( Exception $e ) {
			it_exchange_add_message( 'error', $e->getMessage() );
			return false;
		}
		return it_exchange_add_transaction( 'stripe', $charge->id, 'succeeded', $it_exchange_customer->id, $transaction_object );
	} else {
		it_exchange_add_message( 'error', __( 'Unknown error. Please try again later.', 'LION' ) );
	}
	return false;

}
add_action( 'it_exchange_do_transaction_stripe', 'it_exchange_stripe_addon_process_transaction', 10, 2 );

/**
 * Grab the stripe customer ID for a WP user
 *
 * @since 0.1.0
 *
 * @param integer $customer_id the WP customer ID
 * @return integer
*/
function it_exchange_stripe_addon_get_stripe_customer_id( $customer_id ) {
	$settings = it_exchange_get_option( 'addon_stripe' );
	$mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';
			
	return get_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode, true );
}

/**
 * Add the stripe customer ID as user meta on a WP user
 *
 * @since 0.1.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $stripe_id the stripe customer ID
 * @return boolean
*/
function it_exchange_stripe_addon_set_stripe_customer_id( $customer_id, $stripe_id ) {
	$settings = it_exchange_get_option( 'addon_stripe' );
	$mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';
			
	return update_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode, $stripe_id );
}

/**
 * This is the function registered in the options array when it_exchange_register_addon was called for stripe
 *
 * It tells Exchange where to find the settings page
 *
 * @return void
*/
function it_exchange_stripe_addon_settings_callback() {
	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	$IT_Exchange_Stripe_Add_On->print_settings_page();
}

/**
 * Outputs wizard settings for Stripe
 *
 * @since 0.1.0
 * @todo make this better, probably
 * @param object $form Current IT Form object
 * @return void
*/
function it_exchange_stripe_addon_print_wizard_settings( $form ) {
	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	$settings = it_exchange_get_option( 'addon_stripe', true );
	?>
	<div class="field stripe-wizard hide-if-js">
		<?php $IT_Exchange_Stripe_Add_On->get_stripe_payment_form_table( $form, $settings ); ?>
	</div>
	<?php
}
add_action( 'it_exchange_print_wizard_settings', 'it_exchange_stripe_addon_print_wizard_settings' );

/**
 * Saves stripe settings when the Wizard is saved
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_stripe_addon_save_wizard_settings( $errors ) {
	if ( ! empty( $errors ) )
		return $errors;
		
	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	return $IT_Exchange_Stripe_Add_On->stripe_save_wizard_settings();
}
add_action( 'it_exchange_save_transaction_method_wizard_settings', 'it_exchange_stripe_addon_save_wizard_settings' );

/**
 * Default settings for stripe
 *
 * @since 0.1.0
 *
 * @param array $values
 * @return array
*/
function it_exchange_stripe_addon_default_settings( $values ) {
	$defaults = array(
		'stripe-test-mode'             => false,
		'stripe-live-secret-key'       => '',
		'stripe-live-publishable-key'  => '',
		'stripe-test-secret-key'       => '',
		'stripe-test-publishable-key'  => '',
		'stripe-purchase-button-label' => __( 'Purchase', 'LION' ),
	);
	$values = ITUtility::merge_defaults( $values, $defaults );
	return $values;
}
add_filter( 'it_storage_get_defaults_exchange_addon_stripe', 'it_exchange_stripe_addon_default_settings' );

/**
 * Returns the button for making the payment
 *
 * @since 0.1.0
 *
 * @param array $options
 * @return string HTML button
*/
function it_exchange_stripe_addon_make_payment_button( $options ) {

	if ( 0 >= it_exchange_get_cart_total( false ) )
		return;
		
	$general_settings = it_exchange_get_option( 'settings_general' );
	$stripe_settings = it_exchange_get_option( 'addon_stripe' );
	
	$publishable_key = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-publishable-key'] : $stripe_settings['stripe-live-publishable-key'];

	$products = it_exchange_get_cart_data( 'products' );

	$payment_form = '<form class="stripe_form" action="' . esc_attr( it_exchange_get_page_url( 'transaction' ) ) . '" method="post">';
	$payment_form .= '<input type="hidden" name="it-exchange-transaction-method" value="stripe" />';
	$payment_form .= wp_nonce_field( 'stripe-checkout', '_stripe_nonce', true, false );

	$payment_form .= '<div class="hide-if-no-js">';
	$payment_form .= '<input type="submit" class="it-exchange-stripe-payment-button" name="stripe_purchase" value="' . esc_attr( $stripe_settings['stripe-purchase-button-label'] ) .'" />';

	$payment_form .= '<script>' . "\n";
	$payment_form .= '	jQuery(".it-exchange-stripe-payment-button").click(function(){' . "\n";
	$payment_form .= '	  var token = function(res){' . "\n";
	$payment_form .= '		var $stripeToken = jQuery("<input type=hidden name=stripeToken />").val(res.id);' . "\n";
	$payment_form .= '		jQuery("form.stripe_form").append($stripeToken).submit();' . "\n";
	$payment_form .= '		it_exchange_stripe_processing_payment_popup();' . "\n";
	$payment_form .= '	  };' . "\n";
	$payment_form .= '	  StripeCheckout.open({' . "\n";
	$payment_form .= '		key:         "' . esc_js( $publishable_key ) . '",' . "\n";
	$payment_form .= '		amount:      "' . esc_js( number_format( it_exchange_get_cart_total( false ), 2, '', '' ) ) . '",' . "\n";
	$payment_form .= '		currency:    "' . esc_js( $general_settings['default-currency'] ) . '",' . "\n";
	$payment_form .= '		name:        "' . empty( $general_settings['company-name'] ) ? '' : esc_js( $general_settings['company-name'] ) . '",' . "\n";
	$payment_form .= '		description: "' . esc_js( it_exchange_get_cart_description() ) . '",' . "\n";
	$payment_form .= '		panelLabel:  "Checkout",' . "\n";
	$payment_form .= '		token:       token' . "\n";
	$payment_form .= '	  });' . "\n";
	$payment_form .= '	  return false;' . "\n";
	$payment_form .= '	});' . "\n";
	$payment_form .= '</script>' . "\n";

	$payment_form .= '</form>';
	$payment_form .= '</div>';

	/*
	 * Going to remove this for now. It should be
	 * the responsibility of the site owner to
	 * notify if Javascript is disabled, but I will
	 * revisit this in case we want to a notifications.
	 *
	$payment_form .= '<div class="hide-if-js">';

	$payment_form .= '<h3>' . __( 'JavaScript disabled: Stripe Payment Gateway cannot be loaded!', 'LION' ) . '</h3>';

	$payment_form .= '</div>';
	*/

	return $payment_form;
}
add_filter( 'it_exchange_get_stripe_make_payment_button', 'it_exchange_stripe_addon_make_payment_button', 10, 2 );

/**
 * Filters default currencies to only display those supported by Stripe
 *
 * @since 0.1.0
 *
 * @param array $default_currencies Array of default currencies supplied by iThemes Exchange
 * @return array filtered list of currencies only supported by Stripe
 */
function it_exchange_stripe_addon_get_currency_options( $default_currencies ) {
	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	$stripe_currencies = $IT_Exchange_Stripe_Add_On->get_supported_currency_options();
	return array_intersect_key( $default_currencies, $stripe_currencies );
}
add_filter( 'it_exchange_get_currency_options', 'it_exchange_stripe_addon_get_currency_options' );

/**
 * Adds the stripe webhook key to the global array of keys to listen for
 *
 * @since 0.1.0
 *
 * @param array $webhooks existing
 * @return array
*/
function it_exchange_stripe_addon_register_webhook_key() {
	$key   = 'stripe';
	$param = apply_filters( 'it_exchange_stripe_addon_webhook', 'it_exchange_stripe' );
	it_exchange_register_webhook( $key, $param );
}
add_filter( 'init', 'it_exchange_stripe_addon_register_webhook_key' );

/**
 * Processes webhooks for Stripe
 *
 * @since 0.1.0
 * @todo actually handle the exceptions
 *
 * @param array $request really just passing  $_REQUEST
 */
function it_exchange_stripe_addon_process_webhook( $request ) {

	$general_settings = it_exchange_get_option( 'settings_general' );
	$settings = it_exchange_get_option( 'addon_stripe' );

	$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
	Stripe::setApiKey( $secret_key );

	$body = @file_get_contents('php://input');
	$stripe_event = json_decode( $body );

	if ( isset( $stripe_event->id ) ) {

		try {

			$stripe_object = $stripe_event->data->object;

			//https://stripe.com/docs/api#event_types
			switch( $stripe_event->type ) :

				case 'charge.succeeded' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'succeeded' );
					break;
				case 'charge.failed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'failed' );
					break;
				case 'charge.refunded' :
					if ( $stripe_object->refunded )
						it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'refunded' );
					else
						it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'partial-refund' );

					it_exchange_stripe_addon_add_refund_to_transaction( $stripe_object->id, $stripe_object->amount_refunded );

					break;
				case 'charge.dispute.created' :
				case 'charge.dispute.updated' :
				case 'charge.dispute.closed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->charge, $stripe_object->status );
					break;
				case 'customer.deleted' :
					it_exchange_stripe_addon_delete_stripe_id_from_customer( $stripe_object->id );
					break;

			endswitch;

		} catch ( Exception $e ) {

			// What are we going to do here?

		}
	}

}
add_action( 'it_exchange_webhook_it_exchange_stripe', 'it_exchange_stripe_addon_process_webhook' );

/**
 * Grab a transaction from the stripe transaction ID
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id id of stripe transaction
 * @return transaction object
*/
function it_exchange_stripe_addon_get_transaction_id( $stripe_id ) {
	$args = array(
		'meta_key'    => '_it_exchange_transaction_method_id',
		'meta_value'  => $stripe_id,
		'numberposts' => 1, //we should only have one, so limit to 1
	);
	return it_exchange_get_transactions( $args );
}

/**
 * Updates a stripe transaction status based on stripe ID
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id id of stripe transaction
 * @param string $new_status new status
 * @return void
*/
function it_exchange_stripe_addon_update_transaction_status( $stripe_id, $new_status ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
	foreach( $transactions as $transaction ) { //really only one
		$current_status = it_exchange_get_transaction_status( $transaction );
		if ( $new_status !== $current_status )
			it_exchange_update_transaction_status( $transaction, $new_status );
	}
}

/**
 * Adds a refund to post_meta for a stripe transaction
 *
 * @since 0.1.0
*/
function it_exchange_stripe_addon_add_refund_to_transaction( $stripe_id, $refund ) {

	// Stripe money format comes in as cents. Divide by 100.
	$refund = ( $refund / 100 );

	// Grab transaction
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
	foreach( $transactions as $transaction ) { //really only one

		$refunds = it_exchange_get_transaction_refunds( $transaction );

		$refunded_amount = 0;
		foreach( ( array) $refunds as $refund_meta ) {
			$refunded_amount += $refund_meta['amount'];
		}

		// In Stripe the Refund is the total amount that has been refunded, not just this transaction
		$this_refund = $refund - $refunded_amount;

		// This refund is already formated on the way in. Don't reformat.
		it_exchange_add_refund_to_transaction( $transaction, $this_refund );
	}

}

/**
 * Removes a stripe Customer ID from a WP user
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id the id of the stripe transaction
*/
function it_exchange_stripe_addon_delete_stripe_id_from_customer( $stripe_id ) {
	$settings = it_exchange_get_option( 'addon_stripe' );
	$mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';
	
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
	foreach( $transactions as $transaction ) { //really only one
		$customer_id = get_post_meta( $transaction->ID, '_it_exchange_customer_id', true );
		if ( false !== $current_stripe_id = it_exchange_stripe_addon_get_stripe_customer_id( $customer_id ) ) {

			if ( $current_stripe_id === $stripe_id )
				delete_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode );

		}
	}
}

/**
 * Gets the interpretted transaction status from valid stripe transaction statuses
 *
 * @since 0.1.0
 *
 * @param string $status the string of the stripe transaction
 * @return string translaction transaction status
*/
function it_exchange_stripe_addon_transaction_status_label( $status ) {
	switch ( $status ) {
		case 'succeeded':
			return __( 'Paid', 'LION' );
		case 'refunded':
			return __( 'Refunded', 'LION' );
		case 'partial-refund':
			return __( 'Partially Refunded', 'LION' );
		case 'needs_response':
			return __( 'Disputed: Stripe needs a response', 'LION' );
		case 'under_review':
			return __( 'Disputed: Under review', 'LION' );
		case 'won':
			return __( 'Disputed: Won, Paid', 'LION' );
		default:
			return __( 'Unknown', 'LION' );
	}
}
add_filter( 'it_exchange_transaction_status_label_stripe', 'it_exchange_stripe_addon_transaction_status_label' );

/**
 * Returns a boolean. Is this transaction a status that warrants delivery of any products attached to it?
 *
 * @since 0.4.2
 *
 * @param boolean $cleared passed in through WP filter. Ignored here.
 * @param object $transaction
 * @return boolean
*/
function it_exchange_stripe_transaction_is_cleared_for_delivery( $cleared, $transaction ) {
	$valid_stati = array( 'succeeded', 'partial-refund', 'won' );
	return in_array( it_exchange_get_transaction_status( $transaction ), $valid_stati );
}
add_filter( 'it_exchange_stripe_transaction_is_cleared_for_delivery', 'it_exchange_stripe_transaction_is_cleared_for_delivery', 10, 2 );

/**
 * Class for Stripe
 * @since 0.1.0
*/
class IT_Exchange_Stripe_Add_On {

	/**
	 * @var boolean $_is_admin true or false
	 * @since 0.1.0
	*/
	var $_is_admin;

	/**
	 * @var string $_current_page Current $_GET['page'] value
	 * @since 0.1.0
	*/
	var $_current_page;

	/**
	 * @var string $_current_add_on Current $_GET['add-on-settings'] value
	 * @since 0.1.0
	*/
	var $_current_add_on;

	/**
	 * @var string $status_message will be displayed if not empty
	 * @since 0.1.0
	*/
	var $status_message;

	/**
	 * @var string $error_message will be displayed if not empty
	 * @since 0.1.0
	*/
	var $error_message;

	/**
	 * Class constructor
	 *
	 * Sets up the class.
	 * @since 0.1.0
	 * @return void
	*/
	function IT_Exchange_Stripe_Add_On() {
		$this->_is_admin       = is_admin();
		$this->_current_page   = empty( $_GET['page'] ) ? false : $_GET['page'];
		$this->_current_add_on = empty( $_GET['add-on-settings'] ) ? false : $_GET['add-on-settings'];

		if ( ! empty( $_POST ) && $this->_is_admin && 'it-exchange-addons' == $this->_current_page && 'stripe' == $this->_current_add_on ) {
			add_action( 'it_exchange_save_add_on_settings_stripe', array( $this, 'save_settings' ) );
			do_action( 'it_exchange_save_add_on_settings_stripe' );
		}
	}

	/**
	 * Prints settings page
	 *
	 * @since 0.4.5
	 * @return void
	*/
	function print_settings_page() {
		$settings = it_exchange_get_option( 'addon_stripe', true );
		$form_values  = empty( $this->error_message ) ? $settings : ITForm::get_post_data();
		$form_options = array(
			'id'      => apply_filters( 'it_exchange_add_on_stripe', 'it-exchange-add-on-stripe-settings' ),
			'enctype' => apply_filters( 'it_exchange_add_on_stripe_settings_form_enctype', false ),
			'action'  => 'admin.php?page=it-exchange-addons&add-on-settings=stripe',
		);
		$form         = new ITForm( $form_values, array( 'prefix' => 'it-exchange-add-on-stripe' ) );

		if ( ! empty ( $this->status_message ) )
			ITUtility::show_status_message( $this->status_message );
		if ( ! empty( $this->error_message ) )
			ITUtility::show_error_message( $this->error_message );

		?>
		<div class="wrap">
            <?php screen_icon( 'it-exchange' ); ?>
            <h2><?php _e( 'Stripe Settings', 'LION' ); ?></h2>

            <?php do_action( 'it_exchange_stripe_settings_page_top' ); ?>
            <?php do_action( 'it_exchange_addon_settings_page_top' ); ?>
			<?php $form->start_form( $form_options, 'it-exchange-stripe-settings' ); ?>
				<?php do_action( 'it_exchange_stripe_settings_form_top' ); ?>
				<?php $this->get_stripe_payment_form_table( $form, $form_values ); ?>
				<?php do_action( 'it_exchange_stripe_settings_form_bottom' ); ?>
				<p class="submit">
					<?php $form->add_submit( 'submit', array( 'value' => __( 'Save Changes', 'LION' ), 'class' => 'button button-primary button-large' ) ); ?>
				</p>
			<?php $form->end_form(); ?>
			<?php do_action( 'it_exchange_stripe_settings_page_bottom' ); ?>
			<?php do_action( 'it_exchange_addon_settings_page_bottom' ); ?>
		</div>
		<?php
	}

	/**
	 * @todo verify video link
	 */
	function get_stripe_payment_form_table( $form, $settings = array() ) {

		$general_settings = it_exchange_get_option( 'settings_general' );

		if ( !empty( $settings ) )
			foreach ( $settings as $key => $var )
				$form->set_option( $key, $var );

		?>
		<div class="it-exchange-addon-settings it-exchange-stripe-addon-settings">
            <p>
				<?php _e( 'To get Stripe setup for your ecommerce site, you will need to do a couple of things in Stripe first.<br /><br />
				<a href="http://ithemes.com/tutorial/category/exchange" target="_blank">Video: Getting Stripe Setup with Exchange</a>', 'LION' ); ?>
			</p>
			<p><?php _e( 'Do not have a Stripe account yet? <a href="http://stripe.com" target="_blank">Go set one up here</a>.', 'LION' ); ?></p>
			<?php
				if ( ! in_array( $general_settings['default-currency'], array_keys( $this->get_supported_currency_options() ) ) )
					echo '<h4>' . sprintf( __( 'You are currently using a currency that is not supported by Stripe. <a href="%s">Please update your currency settings</a>.', 'LION' ), add_query_arg( 'page', 'it-exchange-settings' ) ) . '</h4>';
			?>
            <h4><?php _e( 'Step 1. Fill out your Stripe API Credentials', 'LION' ); ?></h4>
			<p>
				<label for="stripe-live-secret-key"><?php _e( 'Live Secret Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Live Secret Key is available in your Stripe Dashboard.', 'LION' ); ?>">i</span></label>
				<?php $form->add_text_box( 'stripe-live-secret-key' ); ?>
			</p>
			<p>
				<label for="stripe-live-publishable-key"><?php _e( 'Live Publishable Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Live Publishable Key is available in your Stripe Dashboard.', 'LION' ); ?>">i</span></label>
				<?php $form->add_text_box( 'stripe-live-publishable-key' ); ?>
			</p>
			<p class="hide-if-wizard">
				<?php $form->add_check_box( 'stripe-test-mode', array( 'class' => 'show-test-mode-options' ) ); ?>
				<label for="stripe-test-mode"><?php _e( 'Enable Stripe Test Mode?', 'LION' ); ?> <span class="tip" title="<?php _e( 'Use this mode for testing your store. This mode will need to be disabled when the store is ready to process customer payments.', 'LION' ); ?>">i</span></label>
			</p>
            <?php $hidden_class = ( $settings['stripe-test-mode'] ) ? '' : 'hide-if-live-mode'; ?>
			<p class="test-mode-options hide-if-wizard <?php echo $hidden_class; ?>">
				<label for="stripe-test-secret-key"><?php _e( 'Test Secret Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Test Secret Key is available in your Stripe Dashboard.', 'LION' ); ?>">i</span></label>
				<?php $form->add_text_box( 'stripe-test-secret-key' ); ?>
			</p>
			<p class="test-mode-options hide-if-wizard <?php echo $hidden_class; ?>">
				<label for="stripe-test-publishable-key"><?php _e( 'Test Publishable Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Test Publishable Key is available in your Stripe Dashboard.', 'LION' ); ?>">i</span></label>
				<?php $form->add_text_box( 'stripe-test-publishable-key' ); ?>
			</p>
			<p>
				<label for="stripe-purchase-button-label"><?php _e( 'Purchase Button Label', 'LION' ); ?> <span class="tip" title="<?php _e( 'This is the text inside the button your customers will press to purchase with Stripe', 'LION' ); ?>">i</span></label>
				<?php $form->add_text_box( 'stripe-purchase-button-label' ); ?>
			</p>
            <h4><?php _e( 'Step 2. Setup Stripe Webhooks', 'LION' ); ?></h4>
			<p><?php _e( 'Webhooks can be configured in the <a href="https://manage.stripe.com/account/webhooks">webhook settings section</a> of the Stripe dashboard. Clicking Add URL will reveal a form to add a new URL for receiving webhooks.', 'LION' ); ?></p>
			<p><?php _e( 'Please log into your account and add this URL to your Webhooks so iThemes Exchange is notified of things like refunds, payments, etc.', 'LION' ); ?></p>
			<code><?php echo get_site_url(); ?>/?<?php esc_attr_e( it_exchange_get_webhook( 'stripe' ) ); ?>=1</code>
		</div>
		<?php
	}

	/**
	 * Save settings
	 *
	 * @since 0.1.0
	 * @return void
	*/
	function save_settings() {
		$defaults = it_exchange_get_option( 'addon_stripe' );
		$new_values = wp_parse_args( ITForm::get_post_data(), $defaults );

		// Check nonce
		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'it-exchange-stripe-settings' ) ) {
			$this->error_message = __( 'Error. Please try again', 'LION' );
			return;
		}

		$errors = apply_filters( 'it_exchange_add_on_stripe_validate_settings', $this->get_form_errors( $new_values ), $new_values );
		if ( ! $errors && it_exchange_save_option( 'addon_stripe', $new_values ) ) {
			ITUtility::show_status_message( __( 'Settings saved.', 'LION' ) );
		} else if ( $errors ) {
			$errors = implode( '<br />', $errors );
			$this->error_message = $errors;
		} else {
			$this->status_message = __( 'Settings not saved.', 'LION' );
		}
	}

	function stripe_save_wizard_settings() {
		if ( empty( $_REQUEST['it_exchange_settings-wizard-submitted'] ) )
			return;

		$stripe_settings = array();

		// Fields to save
		$fields = array(
			'stripe-live-secret-key',
			'stripe-live-publishable-key',
			'stripe-test-secret-key',
			'stripe-test-publishable-key',
			'stripe-test-mode',
			'stripe-purchase-button-label',
		);
		$default_wizard_stripe_settings = apply_filters( 'default_wizard_stripe_settings', $fields );

		foreach( $default_wizard_stripe_settings as $var ) {
			if ( isset( $_REQUEST['it_exchange_settings-' . $var] ) ) {
				$stripe_settings[$var] = $_REQUEST['it_exchange_settings-' . $var];	
			}
		}

		$settings = wp_parse_args( $stripe_settings, it_exchange_get_option( 'addon_stripe' ) );

		if ( $error_msg = $this->get_form_errors( $settings ) ) {

			return $error_msg;

		} else {
			it_exchange_save_option( 'addon_stripe', $settings );
			$this->status_message = __( 'Settings Saved.', 'LION' );
		}
		
		return;
	}

	/**
	 * Validates for values
	 *
	 * Returns string of errors if anything is invalid
	 *
	 * @since 0.1.0
	 * @return void
	*/
	public function get_form_errors( $values ) {

		$errors = array();
		if ( empty( $values['stripe-live-secret-key'] ) )
			$errors[] = __( 'Please include your Stripe Live Secret Key', 'LION' );
		if ( empty( $values['stripe-live-publishable-key'] ) )
			$errors[] = __( 'Please include your Stripe Live Publishable Key', 'LION' );

		if ( !empty( $values['stripe-test-mode' ] ) ) {
			if ( empty( $values['stripe-test-secret-key'] ) )
				$errors[] = __( 'Please include your Stripe Test Secret Key', 'LION' );
			if ( empty( $values['stripe-test-publishable-key'] ) )
				$errors[] = __( 'Please include your Stripe Test Publishable Key', 'LION' );
		}

		return $errors;
	}

	/**
	 * Prints HTML options for default status
	 *
	 * @since 0.1.0
	 * @return void
	*/
	function get_supported_currency_options() {
		$options = array( 'USD' => __( 'US Dollar' ), 'CAD' => __( 'Canadian Dollar' ) );
		return $options;
	}

}

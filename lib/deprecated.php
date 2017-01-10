<?php
/**
 * Deprecated Functions.
 *
 * @since 2.0.0
 * @license GPLv2
 */

/**
 * This proccesses a stripe transaction.
 *
 * The it_exchange_do_transaction_[addon-slug] action is called when
 * the site visitor clicks a specific add-ons 'purchase' button. It is
 * passed the default status of false along with the transaction object
 * The transaction object is a package of data describing what was in the user's cart
 *
 * Exchange expects your add-on to either return false if the transaction failed or to
 * call it_exchange_add_transaction() and return the transaction ID
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @param string $status             passed by WP filter.
 * @param object $transaction_object The transaction object
 *
 * @return bool|false|int
 */
function it_exchange_stripe_addon_process_transaction( $status, $transaction_object ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

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

		if ( !empty( $_POST['stripe_subscription_id'] ) )
			$subscription_id = $_POST['stripe_subscription_id'];
		else
			$subscription_id = false;

		try {

			$general_settings = it_exchange_get_option( 'settings_general' );
			$settings         = it_exchange_get_option( 'addon_stripe' );

			$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
			\Stripe\Stripe::setApiKey( $secret_key );
			\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

			// Set stripe token
			$token = $_POST['stripeToken'];

			// Set stripe customer from WP customer ID
			$it_exchange_customer = it_exchange_get_current_customer();
			if ( $stripe_id = it_exchange_stripe_addon_get_stripe_customer_id( $it_exchange_customer->id ) )
				$stripe_customer = \Stripe\Customer::retrieve( $stripe_id );

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
				$stripe_customer = \Stripe\Customer::create( $customer_array );

				it_exchange_stripe_addon_set_stripe_customer_id( $it_exchange_customer->id, $stripe_customer->id );
			}

			if ( $subscription_id ) {

				$plan = \Stripe\Plan::retrieve( $subscription_id );

				if ( ! empty( $plan->trial_period_days ) ) {
					//This has a trial period, so we need to set the cart object totals to 0.00
					$transaction_object->total    = '0.00'; //should be 0.00 ... since this is a free trial!
					$transaction_object->subtotal = '0.00'; //should be 0.00 ... since this is a free trial!
				}

				$args = array(
					'plan'    => $subscription_id,
					'prorate' => apply_filters( 'it_exchange_stripe_subscription_prorate', false ),
				);

				$args = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args );
				$stripe_subscription = $stripe_customer->subscriptions->create( $args );
				$charge_id = $stripe_subscription->id;	//need a temporary ID

				it_exchange_stripe_addon_set_stripe_customer_subscription_id( $it_exchange_customer->id, $stripe_subscription->id );

				$txn_id = it_exchange_add_transaction( 'stripe', $charge_id, 'succeeded', $it_exchange_customer->id, $transaction_object );

				if ( function_exists( 'it_exchange_get_transaction_subscriptions' ) ) {
					$subscriptions = it_exchange_get_transaction_subscriptions( it_exchange_get_transaction( $txn_id ) );

					// should be only one
					foreach ( $subscriptions as $subscription ) {
						$subscription->set_subscriber_id( $stripe_subscription->id );
					}
				}

				return $txn_id;
			} else {
				// Now that we have a valid Customer ID, charge them!
				$args = array(
					'customer'    => $stripe_customer->id,
					'amount'      => number_format( $transaction_object->total, 2, '', '' ),
					'currency'    => strtolower( $general_settings['default-currency'] ),
					'description' => $transaction_object->description,
				);

				$args = apply_filters( 'it_exchange_stripe_addon_charge_args', $args );
				$charge = \Stripe\Charge::create( $args );
				$charge_id = $charge->id;
			}

		}
		catch ( Exception $e ) {
			it_exchange_add_message( 'error', $e->getMessage() );
			return false;
		}

		return it_exchange_add_transaction( 'stripe', $charge_id, 'succeeded', $it_exchange_customer->id, $transaction_object );
	} else {
		it_exchange_add_message( 'error', __( 'Unknown error. Please try again later.', 'LION' ) );
	}
	return false;

}

function it_exchange_cancel_stripe_subscription( $subscription_details ) {

	_deprecated_function( __FUNCTION__, '2.0.0', 'IT_Exchange_Subscription::cancel()' );

	if ( empty( $subscription_details['old_subscriber_id'] ) )
		return;

	$subscriber_id   = $subscription_details['old_subscriber_id'];
	$stripe_settings = it_exchange_get_option( 'addon_stripe' );
	$secret_key      = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-secret-key'] : $stripe_settings['stripe-live-secret-key'];
	\Stripe\Stripe::setApiKey( $secret_key );
	\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

	try {
		$user_id = empty( $subscription_details['customer'] ) ? get_current_user_id() : $subscription_details['customer']->id;

		$stripe_customer_id = it_exchange_stripe_addon_get_stripe_customer_id( $user_id );

		$cu = \Stripe\Customer::retrieve( $stripe_customer_id );
		$cu->subscriptions->retrieve( $subscriber_id )->cancel();
	}
	catch( Exception $e ) {
		it_exchange_add_message( 'error', sprintf( __( 'Error: Unable to unsubscribe user %s', 'LION' ), $e->getMessage() ) );
	}
}
add_action( 'it_exchange_cancel_stripe_subscription', 'it_exchange_cancel_stripe_subscription' );


/**
 * Returns the button for making the payment
 *
 * Exchange will loop through activated Payment Methods on the checkout page
 * and ask each transaction method to return a button using the following filter:
 * - it_exchange_get_[addon-slug]_make_payment_button
 * Transaction Method add-ons must return a button hooked to this filter if they
 * want people to be able to make purchases.
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @param array $options
 * @return string HTML button
 */
function it_exchange_stripe_addon_make_payment_button( $options ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	if ( 0 >= it_exchange_get_cart_total( false ) )
		return;

	$general_settings = it_exchange_get_option( 'settings_general' );
	$stripe_settings = it_exchange_get_option( 'addon_stripe' );
	$subscription = false;
	$payment_image = false;
	$bitcoin_enabled = false;

	$publishable_key = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-publishable-key'] : $stripe_settings['stripe-live-publishable-key'];

	$products = it_exchange_get_cart_data( 'products' );
	$cart = it_exchange_get_cart_products();

	if ( 1 === count( $cart ) ) {
		foreach( $cart as $product ) {
			if ( it_exchange_product_supports_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
				if ( it_exchange_product_has_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
					$trial_enabled = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-enabled' ) );
					$trial_interval = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval' ) );
					$trial_interval_count = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval-count' ) );
					$auto_renew = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) );
					$interval = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval' ) );
					$interval_count = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval-count' ) );

					$trial_period_days = NULL;
					if ( $trial_enabled ) {
						$allow_trial = true;
						//Should we all trials?
						if ( 'membership-product-type' === it_exchange_get_product_type( $product['product_id'] ) ) {
							if ( is_user_logged_in() ) {
								if ( function_exists( 'it_exchange_get_session_data' ) ) {
									$member_access = it_exchange_get_session_data( 'member_access' );
									$children = (array)it_exchange_membership_addon_get_all_the_children( $product['product_id'] );
									$parents = (array)it_exchange_membership_addon_get_all_the_parents( $product['product_id'] );
									foreach( $member_access as $prod_id => $txn_id ) {
										if ( $prod_id === $product['product_id'] || in_array( $prod_id, $children ) || in_array( $prod_id, $parents ) ) {
											$allow_trial = false;
											break;
										}
									}
								}
							}
						}

						$allow_trial = apply_filters( 'it_exchange_stripe_addon_make_payment_button_allow_trial', $allow_trial, $product['product_id'] );

						if ( $allow_trial && 0 < $trial_interval_count ) {
							switch ( $trial_interval ) {
								case 'year':
									$days = 365;
									break;
								case 'month':
									$days = 31;
									break;
								case 'week':
									$days = 7;
									break;
								case 'day':
								default:
									$days = 1;
									break;
							}
							$trial_period_days = $trial_interval_count * $days;
						}
					}

					$subscription = true;
					$product_id = $product['product_id'];
				}
			}
		}
	}

	$upgrade_downgrade = it_exchange_get_session_data( 'updowngrade_details' );
	if ( !empty( $upgrade_downgrade ) ) {
		foreach( $cart as $product ) {
			if ( !empty( $upgrade_downgrade[$product['product_id']] ) ) {
				$product_id = $product['product_id'];
				if (   !empty( $upgrade_downgrade[$product_id]['old_transaction_id'] )
				       && !empty( $upgrade_downgrade[$product_id]['old_transaction_method'] ) ) {
					$subscription_details[$product_id] = array(
						'product_id'             => $product_id,
						'free_days'              => $upgrade_downgrade[$product_id]['free_days'],
						'credit'                 => $upgrade_downgrade[$product_id]['credit'],
						'old_transaction_id'     => $upgrade_downgrade[$product_id]['old_transaction_id'],
						'old_transaction_method' => $upgrade_downgrade[$product_id]['old_transaction_method'],
					);
					if ( !empty( $upgrade_downgrade[$product_id]['old_subscriber_id'] ) )
						$subscription_details[$product_id]['old_subscriber_id'] = $upgrade_downgrade[$product_id]['old_subscriber_id'];
					it_exchange_update_session_data( 'cancel_subscription', $subscription_details );
				}
			}
		}
	} else {
		it_exchange_clear_session_data( 'cancel_subscription' );
	}

	$it_exchange_customer = it_exchange_get_current_customer();
	$customer_email       = empty( $it_exchange_customer->data->user_email ) ? '' : $it_exchange_customer->data->user_email;

	$payment_form = '<form class="stripe_form" action="' . esc_attr( it_exchange_get_page_url( 'transaction' ) ) . '" method="post">';
	$payment_form .= '<input type="hidden" name="it-exchange-transaction-method" value="stripe" />';
	$payment_form .= wp_nonce_field( 'stripe-checkout', '_stripe_nonce', true, false );
	$payment_form .= '<div class="hide-if-no-js">';
	$unique = it_exchange_create_unique_hash();
	$payment_form .= '<input type="submit" id="it-exchange-stripe-payment-button-' . $unique . '" class="it-exchange-stripe-payment-button" name="stripe_purchase" value="' . esc_attr( $stripe_settings['stripe-purchase-button-label'] ) .'" />';

	if ( !empty( $stripe_settings['stripe-checkout-image'] ) ) {
		$attachment_image = wp_get_attachment_image_src( $stripe_settings['stripe-checkout-image'], 'it-exchange-stripe-addon-checkout-image' );
		if ( !empty( $attachment_image[0] ) ) {
			$relative_url = parse_url( $attachment_image[0], PHP_URL_PATH );
			$payment_image = '  image:       "' . esc_js( $relative_url ) . '",' . "\n";
		}
	}

	if ( !empty( $stripe_settings['enable-bitcoin'] ) ) {
		$bitcoin_enabled = '  bitcoin:       "true",' . "\n";
	}

	if ( $subscription ) {

		$secret_key = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-secret-key'] : $stripe_settings['stripe-live-secret-key'];
		\Stripe\Stripe::setApiKey( $secret_key );
		\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
		$stripe_plan = false;
		$time = time();
		$amount = esc_js( number_format( it_exchange_get_cart_total( false ), 2, '', '' ) );
		$trial_period_days = empty( $upgrade_downgrade[$product_id]['free_days'] ) ? $trial_period_days : $upgrade_downgrade[$product_id]['free_days']; //stripe returns null if it isn't set

		$existing_plan = get_post_meta( $product_id, '_it_exchange_stripe_plan_id', true );
		if ( $existing_plan ) {
			try {
				$stripe_plan = \Stripe\Plan::retrieve( $existing_plan );
			}
			catch( Exception $e ) {
				$stripe_plan = false;
			}
		}

		if ( !is_object( $stripe_plan ) ) {
			$args = array(
				'amount'            => $amount,
				'interval'          => $interval,
				'interval_count'    => $interval_count,
				'name'              => get_the_title( $product_id ) . ' ' . $time,
				'currency'          => esc_js( strtolower( $general_settings['default-currency'] ) ),
				'id'                => sanitize_title_with_dashes( get_the_title( $product_id ) ) . '-' . $time,
				'trial_period_days' => $trial_period_days,
			);

			try {
				$stripe_plan = \Stripe\Plan::create( $args );
			} catch( Exception $e ) {
				return sprintf( __( 'Error: Unable to create Plan in Stripe - %s', 'LION' ), $e->getMessage() );
			}

			update_post_meta( $product_id, '_it_exchange_stripe_plan_id', $stripe_plan->id );
		} else if ( $amount != $stripe_plan->amount || $interval != $stripe_plan->interval || $interval_count != $stripe_plan->interval_count || $trial_period_days != $stripe_plan->trial_period_days ) {
			$args = array(
				'amount'            => $amount,
				'interval'          => $interval,
				'interval_count'    => $interval_count,
				'name'              => get_the_title( $product_id ) . ' ' . $time,
				'currency'          => esc_js( strtolower( $general_settings['default-currency'] ) ),
				'id'                => sanitize_title_with_dashes( get_the_title( $product_id ) ) . '-' . $time,
				'trial_period_days' => $trial_period_days,
			);

			try {
				$stripe_plan = \Stripe\Plan::create( $args );
			} catch( Exception $e ) {
				return sprintf( __( 'Error: Unable to create Plan in Stripe - %s', 'LION' ), $e->getMessage() );
			}

			update_post_meta( $product_id, '_it_exchange_stripe_plan_id', $stripe_plan->id );
		}

		$payment_form .= '<input type="hidden" class="it-exchange-stripe-subscription-id" name="stripe_subscription_id" value="' . esc_attr( $stripe_plan->id ) .'" />';
		$payment_form .= '<script>' . "\n";
		$payment_form .= '  jQuery("#it-exchange-stripe-payment-button-' . $unique . '").click(function(event){' . "\n";
		$payment_form .= '    event.preventDefault();';
		$payment_form .= '    jQuery(this).attr("disabled", "disabled");';
		$payment_form .= '    itExchange.stripeAddonCheckoutEmail = "' . esc_js( $customer_email ) . '";';
		$payment_form .= '    itExchange.hooks.doAction( "itExchangeStripeAddon.makePayment" );';
		$payment_form .= '    var token = function(res){' . "\n";
		$payment_form .= '      var $stripeToken = jQuery("<input type=hidden name=stripeToken />").val(res.id);' . "\n";
		$payment_form .= '      jQuery("form.stripe_form").append($stripeToken).submit();' . "\n";
		$payment_form .= '      it_exchange_stripe_processing_payment_popup();' . "\n";
		$payment_form .= '    };' . "\n";
		$payment_form .= '    StripeCheckout.open({' . "\n";
		$payment_form .= '      key:         "' . esc_js( $publishable_key ) . '",' . "\n";
		$payment_form .= '      email:       itExchange.stripeAddonCheckoutEmail,' . "\n";
		$payment_form .= '      plan:        "' . esc_js( $stripe_plan->id ) . '",' . "\n";
		$payment_form .= '      name:        "' . ( empty( $general_settings['company-name'] ) ? '' : esc_js( $general_settings['company-name'] ) ) . '",' . "\n";
		$payment_form .= '      description: "' . esc_js( strip_tags( it_exchange_get_cart_description() ) ) . '",' . "\n";
		$payment_form .= '      panelLabel:  "Checkout",' . "\n";
		if ( !empty( $payment_image ) ){
			$payment_form .= $payment_image;
		}
		$payment_form .= apply_filters( 'it_exchange_stripe_addon_payment_form_checkout_arg', '' );
		$payment_form .= '      token:       token,' . "\n";
		$payment_form .= '      zipCode:     true' . "\n";
		$payment_form .= '    });' . "\n";
		// $payment_form .= '    return false;' . "\n";
		$payment_form .= '  });' . "\n";
		$payment_form .= '  jQuery(document).on("DOMNodeRemoved",".stripe_checkout_app", function() { jQuery(".it-exchange-stripe-payment-button").removeAttr("disabled");});' . "\n";
		$payment_form .= '</script>' . "\n";

	} else {

		$payment_form .= '<script>' . "\n";
		$payment_form .= '  jQuery("#it-exchange-stripe-payment-button-' . $unique . '").click(function(event){' . "\n";
		$payment_form .= '    event.preventDefault();';
		$payment_form .= '    jQuery(this).attr("disabled", "disabled");';
		$payment_form .= '    itExchange.stripeAddonCheckoutEmail = "' . esc_js( $customer_email ) . '";';
		$payment_form .= '    itExchange.hooks.doAction( "itExchangeStripeAddon.makePayment" );';
		$payment_form .= '    var token = function(res){' . "\n";
		$payment_form .= '      var $stripeToken = jQuery("<input type=hidden name=stripeToken />").val(res.id);' . "\n";
		$payment_form .= '      jQuery("form.stripe_form").append($stripeToken).submit();' . "\n";
		$payment_form .= '      it_exchange_stripe_processing_payment_popup();' . "\n";
		$payment_form .= '    };' . "\n";
		$payment_form .= '    StripeCheckout.open({' . "\n";
		$payment_form .= '      key:         "' . esc_js( $publishable_key ) . '",' . "\n";
		$payment_form .= '      email:       itExchange.stripeAddonCheckoutEmail,' . "\n";
		$payment_form .= '      amount:      "' . esc_js( number_format( it_exchange_get_cart_total( false ), 2, '', '' ) ) . '",' . "\n";
		$payment_form .= '      currency:    "' . esc_js( strtolower( $general_settings['default-currency'] ) ) . '",' . "\n";
		$payment_form .= '      name:        "' . ( empty( $general_settings['company-name'] ) ? '' : esc_js( $general_settings['company-name'] ) ) . '",' . "\n";
		$payment_form .= '      description: "' . esc_js( strip_tags( it_exchange_get_cart_description() ) ) . '",' . "\n";
		$payment_form .= '      panelLabel:  "Checkout",' . "\n";
		if ( !empty( $payment_image ) ){
			$payment_form .= $payment_image;
		}
		if ( !empty( $bitcoin_enabled ) ) {
			$payment_form .= $bitcoin_enabled;
		}
		$payment_form .= apply_filters( 'it_exchange_stripe_addon_payment_form_checkout_arg', '' );
		$payment_form .= '      token:       token,' . "\n";
		$payment_form .= '      zipCode:     true' . "\n";
		$payment_form .= '    });' . "\n";
		// $payment_form .= '    return false;' . "\n";
		$payment_form .= '  });' . "\n";
		$payment_form .= '  jQuery(document).on("DOMNodeRemoved",".stripe_checkout_app", function() { jQuery(".it-exchange-stripe-payment-button").removeAttr("disabled");});' . "\n";
		$payment_form .= '</script>' . "\n";

	}

	$payment_form .= '</form>';
	$payment_form .= '</div>';

	return apply_filters( 'it_exchange_stripe_addon_payment_form', $payment_form );
}

/**
 * Returns the Unsubscribe button for Stripe
 *
 * @since 1.1.0
 *
 * @deprecated 2.0.0
 *
 * @param string $output Stripe output (should be empty)
 * @param array $options Recurring Payments options
 * @return string
 */
function it_exchange_stripe_unsubscribe_action( $output, $options, $transaction_object ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$subscriber_id = $transaction_object->get_transaction_meta( 'subscriber_id' );

	$output  = '<a class="button" href="' . esc_url( add_query_arg( array( 'it-exchange-stripe-action' => 'unsubscribe', 'it-exchange-subscriber-id' => $subscriber_id ) ) ) . '">';
	$output .= $options['label'];
	$output .= '</a>';

	return $output;
}
add_filter( 'it_exchange_stripe_unsubscribe_action', 'it_exchange_stripe_unsubscribe_action', 10, 3 );

/**
 * Performs user requested unsubscribe
 *
 * @since 1.1.22
 *
 * @deprecated 2.0.0
 *
 * @return void
 */
function it_exchange_stripe_unsubscribe_action_submit() {
	if ( !empty( $_REQUEST['it-exchange-stripe-action'] ) ) {

		_deprecated_function( __FUNCTION__, '2.0.0', 'IT_Exchange_Subscription::cancel' );

		$settings = it_exchange_get_option( 'addon_stripe' );

		$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
		\Stripe\Stripe::setApiKey( $secret_key );
		\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

		switch( $_REQUEST['it-exchange-stripe-action'] ) {

			case 'unsubscribe' :
				try {
					$current_user_id = get_current_user_id();
					$stripe_customer_id = it_exchange_stripe_addon_get_stripe_customer_id( $current_user_id );
					$cu = \Stripe\Customer::retrieve( $stripe_customer_id );

					if ( !empty( $_REQUEST['it-exchange-subscriber-id'] ) )
						$cu->subscriptions->retrieve( $_REQUEST['it-exchange-subscriber-id'] )->cancel();
				}
				catch( Exception $e ) {
					it_exchange_add_message( 'error', sprintf( __( 'Error: Unable to unsubscribe user. %s', 'LION' ), $e->getMessage() ) );
				}
				break;

			case 'unsubscribe-user' :
				if ( is_admin() && current_user_can( 'administrator' ) ) {
					if ( !empty( $_REQUEST['it-exchange-stripe-customer-id'] ) && $stripe_customer_id = $_REQUEST['it-exchange-stripe-customer-id'] ) {
						try {
							$cu = \Stripe\Customer::retrieve( $stripe_customer_id );
							if ( !empty( $_REQUEST['it-exchange-stripe-subscriber-id'] ) )
								$cu->subscriptions->retrieve( $_REQUEST['it-exchange-stripe-subscriber-id'] )->cancel();
						}
						catch( Exception $e ) {
							it_exchange_add_message( 'error', sprintf( __( 'Error: Unable to unsubscribe user. %s', 'LION' ), $e->getMessage() ) );
						}
					}
				}
				break;

		}

	}
}
add_action( 'init', 'it_exchange_stripe_unsubscribe_action_submit' );

/**
 * Output the Cancel URL for the Payments screen
 *
 * @since 1.3.1
 *
 * @deprecated 2.0.0
 *
 * @param object $transaction iThemes Transaction object
 * @return void
 */
function it_exchange_stripe_after_payment_details_cancel_url( $transaction ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$cart_object = get_post_meta( $transaction->ID, '_it_exchange_cart_object', true );
	if ( !empty( $cart_object->products ) ) {
		foreach ( $cart_object->products as $product ) {
			$autorenews = $transaction->get_transaction_meta( 'subscription_autorenew_' . $product['product_id'], true );
			if ( $autorenews ) {
				$customer_id = it_exchange_get_transaction_customer_id( $transaction->ID );
				$stripe_customer_id = it_exchange_stripe_addon_get_stripe_customer_id( $customer_id );
				$status = $transaction->get_transaction_meta( 'subscriber_status', true );
				$subscriber_id = $transaction->get_transaction_meta( 'subscriber_id', true );
				switch( $status ) {

					case 'deactivated':
						$output = __( 'Recurring payment has been deactivated', 'LION' );
						break;

					case 'cancelled':
						$output = __( 'Recurring payment has been cancelled', 'LION' );
						break;

					case 'active':
					default:
						$output  = '<a href="' . esc_url( add_query_arg( array( 'it-exchange-stripe-action' => 'unsubscribe-user', 'it-exchange-stripe-customer-id' => $stripe_customer_id, 'it-exchange-stripe-subscriber-id' => $subscriber_id ) ) ) . '">' . __( 'Cancel Recurring Payment', 'LION' ) . '</a>';
						break;
				}
				?>
				<div class="transaction-autorenews clearfix spacing-wrapper">
					<div class="recurring-payment-cancel-options left">
						<div class="recurring-payment-status-name"><?php echo $output; ?></div>
					</div>
				</div>
				<?php
				continue;
			}
		}
	}
}
add_action( 'it_exchange_after_payment_details_cancel_url_for_stripe', 'it_exchange_stripe_after_payment_details_cancel_url' );

/**
 * This is the function registered in the options array when it_exchange_register_addon was called for stripe
 *
 * It tells Exchange where to find the settings page
 *
 * @deprecated 2.0.0
 *
 * @return void
 */
function it_exchange_stripe_addon_settings_callback() {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	$IT_Exchange_Stripe_Add_On->print_settings_page();
}

/**
 * Outputs wizard settings for Stripe
 *
 * Exchange allows add-ons to add a small amount of settings to the wizard.
 * You can add these settings to the wizard by hooking into the following action:
 * - it_exchange_print_[addon-slug]_wizard_settings
 * Exchange exspects you to print your fields here.
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @param object $form Current IT Form object
 * @return void
 */
function it_exchange_print_stripe_wizard_settings( $form ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	$settings = it_exchange_get_option( 'addon_stripe', true );
	$form_values = ITUtility::merge_defaults( ITForm::get_post_data(), $settings );
	$hide_if_js =  it_exchange_is_addon_enabled( 'stripe' ) ? '' : 'hide-if-js';
	?>
    <div class="field stripe-wizard <?php echo $hide_if_js; ?>">
		<?php if ( empty( $hide_if_js ) ) { ?>
            <input class="enable-stripe" type="hidden" name="it-exchange-transaction-methods[]" value="stripe" />
		<?php } ?>
		<?php $IT_Exchange_Stripe_Add_On->get_stripe_payment_form_table( $form, $form_values ); ?>
    </div>
	<?php
}

/**
 * Saves stripe settings when the Wizard is saved
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @return void
 */
function it_exchange_save_stripe_wizard_settings( $errors ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	if ( ! empty( $errors ) )
		return $errors;

	$IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
	return $IT_Exchange_Stripe_Add_On->stripe_save_wizard_settings();
}

/**
 * Default settings for stripe
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @param array $values
 * @return array
 */
function it_exchange_stripe_addon_default_settings( $values ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$defaults = array(
		'stripe-test-mode'             => false,
		'stripe-live-secret-key'       => '',
		'stripe-live-publishable-key'  => '',
		'stripe-test-secret-key'       => '',
		'stripe-test-publishable-key'  => '',
		'stripe-purchase-button-label' => __( 'Purchase', 'LION' ),
		'use-checkout'                 => true,
		'stripe-checkout-image'        => '',
		'enable-bitcoin'               => false,
	);
	$values = ITUtility::merge_defaults( $values, $defaults );
	return $values;
}

/**
 * Filters default currencies to only display those supported by Stripe
 *
 * @since 0.1.0
 *
 * @deprecated 2.0.0
 *
 * @param array $default_currencies Array of default currencies supplied by iThemes Exchange
 * @return array filtered list of currencies only supported by Stripe
 */
function it_exchange_stripe_addon_get_currency_options( $default_currencies ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$current_screen = get_current_screen();
		if ( ! empty( $current_screen->base )  && ( 'exchange_page_it-exchange-settings' == $current_screen->base || 'exchange_page_it-exchange-setup' == $current_screen->base ) ) {
			$stripe_currencies = IT_Exchange_Stripe_Add_On::get_supported_currency_options();
			if ( !empty( $stripe_currencies ) )
				return array_intersect_key( $default_currencies, $stripe_currencies );
		}
	}
	return $default_currencies;
}
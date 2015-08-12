<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_stripe
 * We've placed them all in one file to help add-on devs identify them more easily
*/

/**
 * Adds actions to the plugins page for the iThemes Exchange Stripe plugin
 *
 * @since 1.0.0
 *
 * @param array $meta Existing meta
 * @param string $plugin_file the wp plugin slug (path)
 * @param array $plugin_data the data WP harvested from the plugin header
 * @param string $context
 * @return array
*/
function it_exchange_stripe_plugin_row_actions( $actions, $plugin_file, $plugin_data, $context ) {
    $actions['setup_addon'] = '<a href="' . get_admin_url( NULL, 'admin.php?page=it-exchange-addons&add-on-settings=stripe' ) . '">' . __( 'Setup Add-on', 'LION' ) . '</a>';
    return $actions;
}
add_filter( 'plugin_action_links_exchange-addon-stripe/exchange-addon-stripe.php', 'it_exchange_stripe_plugin_row_actions', 10, 4 );

/**
 * Enqueues admin scripts on Settings page
 *
 * @since 1.1.24
 *
 * @return void
*/
function it_exchange_stripe_addon_admin_enqueue_script( $hook ) {
	if ( 'exchange_page_it-exchange-addons' === $hook )
	    wp_enqueue_style( 'stripe-addon-settings-css', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/css/settings.css' );
}
add_action( 'admin_enqueue_scripts', 'it_exchange_stripe_addon_admin_enqueue_script' );

/**
 * Enqueues any scripts we need on the frontend during a stripe checkout
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_stripe_addon_enqueue_script() {
    wp_enqueue_script( 'stripe', 'https://checkout.stripe.com/v2/checkout.js', array( 'jquery', 'it-exchange-event-manager' ) );
    wp_enqueue_script( 'stripe-addon-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/stripe-addon.js', array( 'jquery', 'it-exchange-event-manager' ) );
    wp_localize_script( 'stripe-addon-js', 'stripeAddonL10n', array(
            'processing_payment_text'  => __( 'Processing payment, please wait...', 'LION' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'it_exchange_stripe_addon_enqueue_script' );

/**
 * Stripe URL to perform refunds
 *
 * The it_exchange_refund_url_for_[addon-slug] filter is
 * used to generate the link for the 'Refund Transaction' button
 * found in the admin under Customer Payments
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

		if ( !empty( $_POST['stripe_subscription_id'] ) )
			$subscription_id = $_POST['stripe_subscription_id'];
		else
			$subscription_id = false;

		try {

			$general_settings = it_exchange_get_option( 'settings_general' );
			$settings         = it_exchange_get_option( 'addon_stripe' );

			$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
			Stripe::setApiKey( $secret_key );
		    Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

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
				
				/*	
				if ( !empty( $transaction_object->billing_address ) ) {
					$stripe_customer->source['name']            = $transaction_object->billing_address['first-name'] . ' ' . $transaction_object->billing_address['last-name'];
					$stripe_customer->source['address_line1']   = $transaction_object->billing_address['address1'];
					$stripe_customer->source['address_line2']   = $transaction_object->billing_address['address2'];
					$stripe_customer->source['address_city']    = $transaction_object->billing_address['city'];
					$stripe_customer->source['address_state']   = $transaction_object->billing_address['state'];
					$stripe_customer->source['address_zip']     = $transaction_object->billing_address['zip'];
					$stripe_customer->source['address_country'] = $transaction_object->billing_address['country'];
				}
				*/
				
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
						
			if ( $subscription_id ) {
				// We don't want to update the stripe customer if they're trying to subscribe to the same plan!
				if ( empty( $stripe_customer->subscription->plan->name ) || $subscription_id != $stripe_customer->subscription->plan->name ) {
					
					$plan = Stripe_Plan::retrieve( $subscription_id );
					if ( !empty( $plan->trial_period_days ) ) {
						//This has a trial period, so we need to set the cart object totals to 0.00
						$transaction_object->total = '0.00'; //should be 0.00 ... since this is a free trial!
						$transaction_object->subtotal = '0.00'; //should be 0.00 ... since this is a free trial!
					}
					
					$args = array(
						'plan'    => $subscription_id,
						'prorate' => apply_filters( 'it_exchange_stripe_subscription_prorate', false ) ,
					);
					
					$args = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args );
					$subscription = $stripe_customer->subscriptions->create( $args );
					$charge_id = $subscription->id;	//need a temporary ID
					it_exchange_stripe_addon_set_stripe_customer_subscription_id( $it_exchange_customer->id, $subscription->id );

				} else {
					throw new Exception( __( 'Error: You are already subscribed to this plan.', 'LION' ) );
				}
			} else {
				// Now that we have a valid Customer ID, charge them!
				$args = array(
					'customer'    => $stripe_customer->id,
					'amount'      => number_format( $transaction_object->total, 2, '', '' ),
					'currency'    => strtolower( $general_settings['default-currency'] ),
					'description' => $transaction_object->description,
				);

				$args = apply_filters( 'it_exchange_stripe_addon_charge_args', $args );
				$charge = Stripe_Charge::create( $args );
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
add_action( 'it_exchange_do_transaction_stripe', 'it_exchange_stripe_addon_process_transaction', 10, 2 );

function it_exchange_cancel_stripe_subscription( $subscription_details ) {

	if ( empty( $subscription_details['old_subscriber_id'] ) )
		return;

	$subscriber_id   = $subscription_details['old_subscriber_id'];
	$stripe_settings = it_exchange_get_option( 'addon_stripe' );
	$secret_key      = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-secret-key'] : $stripe_settings['stripe-live-secret-key'];
	Stripe::setApiKey( $secret_key );
    Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

	try {
		$current_user_id = get_current_user_id();
		$stripe_customer_id = it_exchange_stripe_addon_get_stripe_customer_id( $current_user_id );

		$cu = Stripe_Customer::retrieve( $stripe_customer_id );
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
 * @param array $options
 * @return string HTML button
*/
function it_exchange_stripe_addon_make_payment_button( $options ) {

    if ( 0 >= it_exchange_get_cart_total( false ) )
        return;

    $general_settings = it_exchange_get_option( 'settings_general' );
    $stripe_settings = it_exchange_get_option( 'addon_stripe' );
	$subscription = false;
	$payment_image = false;

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
			$payment_image .= '  image:       "' . esc_js( $relative_url ) . '",' . "\n";
		}
	}

	if ( $subscription ) {

		$secret_key = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-secret-key'] : $stripe_settings['stripe-live-secret-key'];
		Stripe::setApiKey( $secret_key );
	    Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
		$stripe_plan = false;
		$time = time();
		$amount = esc_js( number_format( it_exchange_get_cart_total( false ), 2, '', '' ) );
		$trial_period_days = empty( $upgrade_downgrade[$product_id]['free_days'] ) ? $trial_period_days : $upgrade_downgrade[$product_id]['free_days']; //stripe returns null if it isn't set

		$existing_plan = get_post_meta( $product_id, '_it_exchange_stripe_plan_id', true );
		if ( $existing_plan ) {
			try {
				$stripe_plan = Stripe_Plan::retrieve( $existing_plan );
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
				$stripe_plan = Stripe_Plan::create( $args );
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
				$stripe_plan = Stripe_Plan::create( $args );
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
		if ( !empty( $payment_image ) )
			$payment_form .= $payment_image;
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
		if ( !empty( $payment_image ) )
			$payment_form .= $payment_image;
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
add_filter( 'it_exchange_get_stripe_make_payment_button', 'it_exchange_stripe_addon_make_payment_button', 10, 2 );

/**
 * Gets the interpretted transaction status from valid stripe transaction statuses
 *
 * Most gateway transaction stati are going to be lowercase, one word strings.
 * Hooking a function to the it_exchange_transaction_status_label_[addon-slug] filter
 * will allow add-ons to return the human readable label for a given transaction status.
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
        case 'cancelled':
            return __( 'Cancelled', 'LION' );
        default:
            return __( 'Unknown', 'LION' );
    }
}
add_filter( 'it_exchange_transaction_status_label_stripe', 'it_exchange_stripe_addon_transaction_status_label' );

/**
 * Returns a boolean. Is this transaction a status that warrants delivery of any products attached to it?
 *
 * Just because a transaction gets added to the DB doesn't mean that the admin is ready to give over
 * the goods yet. Each payment gateway will have different transaction stati. Exchange uses the following
 * filter to ask transaction-methods if a current status is cleared for delivery. Return true if the status
 * means its okay to give the download link out, ship the product, etc. Return false if we need to wait.
 * - it_exchange_[addon-slug]_transaction_is_cleared_for_delivery
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
 * Returns the Unsubscribe button for Stripe
 *
 * @since 1.1.0
 *
 * @param string $output Stripe output (should be empty)
 * @param array $options Recurring Payments options
 * @return string
*/
function it_exchange_stripe_unsubscribe_action( $output, $options, $transaction_object ) {
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
 * @return void
*/
function it_exchange_stripe_unsubscribe_action_submit() {
	if ( !empty( $_REQUEST['it-exchange-stripe-action'] ) ) {

		$settings = it_exchange_get_option( 'addon_stripe' );

		$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
		Stripe::setApiKey( $secret_key );
	    Stripe::setApiVersion( ITE_STRIPE_API_VERSION );

		switch( $_REQUEST['it-exchange-stripe-action'] ) {

			case 'unsubscribe' :
				try {
					$current_user_id = get_current_user_id();
					$stripe_customer_id = it_exchange_stripe_addon_get_stripe_customer_id( $current_user_id );
					$cu = Stripe_Customer::retrieve( $stripe_customer_id );

					if ( !empty( $_REQUEST['it-exchange-subscriber-id'] ) )
						$cu->subscriptions->retrieve( $_REQUEST['it-exchange-subscriber-id'] )->cancel();
				}
				catch( Exception $e ) {
					it_exchange_add_message( 'error', sprintf( __( 'Error: Unable to unsubscribe user %s', 'LION' ), $e->getMessage() ) );
				}
				break;

			case 'unsubscribe-user' :
				if ( is_admin() && current_user_can( 'administrator' ) ) {
					if ( !empty( $_REQUEST['it-exchange-stripe-customer-id'] ) && $stripe_customer_id = $_REQUEST['it-exchange-stripe-customer-id'] ) {
						try {
							$cu = Stripe_Customer::retrieve( $stripe_customer_id );
							if ( !empty( $_REQUEST['it-exchange-stripe-subscriber-id'] ) )
								$cu->subscriptions->retrieve( $_REQUEST['it-exchange-stripe-subscriber-id'] )->cancel();
						}
						catch( Exception $e ) {
							it_exchange_add_message( 'error', sprintf( __( 'Error: Unable to unsubscribe user %s', 'LION' ), $e->getMessage() ) );
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
 * @param object $transaction iThemes Transaction object
 * @return void
*/
function it_exchange_stripe_after_payment_details_cancel_url( $transaction ) {
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
 * Mark this transaction method as okay to manually change transactions
 *
 * @since 1.1.36 
*/
add_filter( 'it_exchange_stripe_transaction_status_can_be_manually_changed', '__return_true' );

/**
 * Returns status options
 *
 * @since 1.1.36 
 * @return array
*/
function it_exchange_stripe_get_default_status_options() {
	$options = array(
		'succeeded'       => _x( 'Paid', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'refunded'        => _x( 'Refunded', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'partial-refund'  => _x( 'Partially Refunded', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'cancelled'       => _x( 'Cancelled', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'needs_response'  => _x( 'Disputed: Stripe needs a response', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'under_review'    => _x( 'Disputed: Under review', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'won'             => _x( 'Disputed: Won, Paid', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
	);
	return $options;
}
add_filter( 'it_exchange_get_status_options_for_stripe_transaction', 'it_exchange_stripe_get_default_status_options' );


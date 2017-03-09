<?php
/**
 * Purchase Request Handler Helper.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Purchase_Request_Handler_Helper
 */
class IT_Exchange_Stripe_Purchase_Request_Handler_Helper {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * IT_Exchange_Stripe_Purchase_Request_Handler_Helper constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * Get the Stripe plan for a cart.
	 *
	 * @since 2.0.0
	 *
	 * @param \ITE_Cart $cart
	 * @param string    $currency
	 *
	 * @return \Stripe\Plan|null|false Null if no plan needed. False if unable to be created.
	 */
	public function get_plan_for_cart( ITE_Cart $cart, $currency ) {

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $cart->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( ! $cart_product ) {
			return null;
		}

		$tax_excluded = false;
		$total        = $cart->get_total();

		if ( count( array_diff( $cart->get_item_types(), array( 'product', 'fee', 'tax' ) ) ) === 0 ) {
			$total -= $cart->calculate_total( 'tax' );
			$tax_excluded = true;
		}

		$one_time  = $cart->get_items( 'fee', true )->filter( function ( ITE_Fee_Line_Item $fee ) { return ! $fee->is_recurring(); } );
		$otf_total = $one_time->total();

		if ( $tax_excluded ) {
			$otf_sum = $one_time->flatten()->summary_only()->without( 'tax' )->total();
		} else {
			$otf_sum = $one_time->flatten()->summary_only()->total();
		}

		// ITE_Line_Item::get_total() excludes summary only line items when getting each items total.
		$total -= ( $otf_total + $otf_sum );

		$total   = number_format( $total, 2, '', '' );
		$product = $cart_product->get_product();

		$allow_trial = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-enabled' ) );

		if ( $allow_trial && function_exists( 'it_exchange_is_customer_eligible_for_trial' ) ) {
			$allow_trial = it_exchange_is_customer_eligible_for_trial( $product, $cart->get_customer() );
		}

		$allow_trial = apply_filters( 'it_exchange_stripe_addon_make_payment_button_allow_trial', $allow_trial, $product->ID );

		$interval       = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval' ) );
		$interval_count = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval-count' ) );

		$trial_interval       = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-interval' ) );
		$trial_interval_count = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-interval-count' ) );
		$trial_period_days    = null;

		if ( $allow_trial && $trial_interval_count > 0 ) {
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

		$plan_config = md5( "{$total}|{$interval}|{$interval_count}|{$trial_period_days}" );
		$plans       = get_post_meta( $product->ID, '_it_exchange_stripe_plans' );

		it_exchange_setup_stripe_request();

		if ( in_array( $plan_config, $plans, true ) ) {
			$plan = \Stripe\Plan::retrieve( $plan_config );

			if ( $plan ) {
				return $plan;
			}
		}

		try {
			$plan = \Stripe\Plan::create( array(
				'amount'            => $total,
				'interval'          => $interval,
				'interval_count'    => $interval_count,
				'name'              => get_the_title( $product->ID ),
				'currency'          => $currency,
				'id'                => $plan_config,
				'trial_period_days' => $trial_period_days,
			) );

			if ( $plan ) {
				update_post_meta( $product->ID, '_it_exchange_stripe_plan_id', $plan_config );
				add_post_meta( $product->ID, '_it_exchange_stripe_plans', $plan_config );
			}

			return $plan;
		} catch ( Exception $e ) {

			if ( strpos( strtolower( $e->getMessage() ), 'plan already exists' ) !== false ) {

				update_post_meta( $product->ID, '_it_exchange_stripe_plan_id', $plan_config );
				add_post_meta( $product->ID, '_it_exchange_stripe_plans', $plan_config );

				return \Stripe\Plan::retrieve( $plan_config );
			}

			$cart->get_feedback()->add_error(
				sprintf( __( 'Error: Unable to create Plan in Stripe - %s', 'LION' ), $e->getMessage() )
			);

			return false;
		}
	}

	/**
	 * Perform the transaction for a given request.
	 *
	 * @since 2.0.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 * @param string                        $plan_id
	 *
	 * @return \IT_Exchange_Transaction|null
	 */
	public function do_transaction( ITE_Gateway_Purchase_Request $request, $plan_id = '' ) {

		$cart = $request->get_cart();

		try {

			it_exchange_setup_stripe_request();

			$stripe_customer = $this->get_stripe_customer_for_request( $request, $previous_default_source, $payment_token );

			if ( $plan_id ) {

				$args = array(
					'plan'    => $plan_id,
					'prorate' => apply_filters( 'it_exchange_stripe_subscription_prorate', false ),
				);

				if ( $request instanceof ITE_Gateway_Prorate_Purchase_Request && ( $prorates = $request->get_prorate_requests() ) ) {
					if ( $end_at = $this->get_trial_end_at_for_prorate( $request ) ) {
						$args['trial_end'] = $end_at;
					}
				}

				$one_time_fee = $cart->get_items( 'fee', true )->filter( function ( ITE_Fee_Line_Item $fee ) { return ! $fee->is_recurring(); } );
				$sign_up_fee  = $one_time_fee->not_having_param( 'is_free_trial' );

				// The goal here is to reuse plans by utilizing Stripe's tax_percent support.
				if ( count( array_diff( $cart->get_item_types(), array( 'product', 'fee', 'tax' ) ) ) === 0 ) {

					// Goal is for total to end up as the recurring total amount without taxes and taxes to be the
					// total taxed on the recurring amount only.

					// We have to account that the total cart amount can be filtered.
					$total = $cart->get_total();
					$taxes = $cart->calculate_total( 'tax' );

					$total_wo_tax = $total - $taxes;

					$otf_total = $one_time_fee->total();
					$taxes -= $one_time_fee->flatten()->summary_only()->with_only( 'tax' )->total();
					$otf_sum = $one_time_fee->flatten()->summary_only()->without( 'tax' )->total();

					// ITE_Line_Item::get_total() excludes summary only line items when getting each items total.
					$total_wo_tax -= ( $otf_total + $otf_sum );

					if ( $taxes ) {
						$tax_percent         = ( $taxes / $total_wo_tax ) * 100;
						$args['tax_percent'] = number_format( $tax_percent, 4, '.', '' );
					}
				}

				$args = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args, $request );

				if ( $sign_up_fee->total() ) {

					$invoice_item_amount = $sign_up_fee->total();

					// Stripe uses 'tax_percent' for Invoice Items
					if ( empty( $args['tax_percent'] ) ) {
						$invoice_item_amount += $sign_up_fee->flatten()->summary_only()->total();
					} else {
						$invoice_item_amount_with_tax = $invoice_item_amount +  $sign_up_fee->flatten()->summary_only()->total();
						$invoice_item_amount = $invoice_item_amount_with_tax / ( 1 + $args['tax_percent'] / 100 );
					}

					$invoice_item_amount_formatted = number_format( $invoice_item_amount, 2, '', '' );

					\Stripe\InvoiceItem::create( array(
						'customer'     => $stripe_customer,
						'amount'       => $invoice_item_amount_formatted,
						'currency'     => $cart->get_currency_code(),
						'description'  => it_exchange_get_line_item_collection_description( $sign_up_fee, $cart ),
						'discountable' => false,
						'metadata'     => array(
							'cart' => $cart->get_id(),
						)
					) );
				}

				$stripe_subscription = $stripe_customer->subscriptions->create( $args );

				$txn_id = $this->add_transaction( $request, $stripe_subscription->id, 'succeeded', array(
					'payment_token' => $payment_token ? $payment_token->ID : 0
				) );

				if ( ! $txn_id ) {
					return null;
				}

				if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
					it_exchange_stripe_addon_set_stripe_customer_subscription_id( $request->get_customer()->ID, $stripe_subscription->id );
				}

				if ( function_exists( 'it_exchange_get_transaction_subscriptions' ) ) {
					$subscriptions = it_exchange_get_transaction_subscriptions( it_exchange_get_transaction( $txn_id ) );

					// should be only one
					foreach ( $subscriptions as $subscription ) {
						$subscription->set_subscriber_id( $stripe_subscription->id );
					}
				}
			} else {

				$total = $cart->get_total();

				// Now that we have a valid Customer ID, charge them!
				$args = array(
					'customer'    => $stripe_customer->id,
					'amount'      => number_format( $total, 2, '', '' ),
					'currency'    => strtolower( $cart->get_currency_code() ),
					'description' => strip_tags( it_exchange_get_cart_description( array( 'cart' => $cart ) ) ),
				);

				$args     = apply_filters( 'it_exchange_stripe_addon_charge_args', $args, $request );
				$charge   = \Stripe\Charge::create( $args );
				$txn_args = array();

				if ( $payment_token ) {
					$txn_args['payment_token'] = $payment_token->ID;
				} elseif ( $request->get_one_time_token() && $charge->source ) {
					$txn_args['card'] = new ITE_Gateway_Card(
						$charge->source->last4,
						$charge->source->exp_year,
						$charge->source->exp_month,
						0
					);
				}

				$txn_id = $this->add_transaction( $request, $charge->id, 'succeeded', $txn_args );
			}

			$transaction = it_exchange_get_transaction( $txn_id );

			if ( ! $transaction ) {
				throw new Exception( __( 'Unable to create transaction.', 'LION' ) );
			}

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$transaction->update_meta( 'stripe_guest_customer_id', $stripe_customer->id );
			}

			if ( $previous_default_source && $request->get_token() && $stripe_customer->default_source !== $request->get_token()->token ) {
				$stripe_customer->default_source = $previous_default_source;
				$stripe_customer->save();
			}

			return $transaction;
		} catch ( Exception $e ) {
			error_log( $e->getMessage() );
			$cart->get_feedback()->add_error( $e->getMessage() );

			return null;
		}
	}

	/**
	 * Add the transaction in Exchange.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param string                       $method_id
	 * @param string                       $status
	 * @param array                        $args
	 *
	 * @return int|false
	 */
	protected function add_transaction( ITE_Gateway_Purchase_Request $request, $method_id, $status, $args ) {

		if ( $p = $request->get_child_of() ) {
			return it_exchange_add_child_transaction( 'stripe', $method_id, $status, $request->get_cart(), $p->get_ID(), $args );
		}

		return it_exchange_add_transaction( 'stripe', $method_id, $status, $request->get_cart(), null, $args );
	}

	/**
	 * Get the trial end at time for a prorate purchase request.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Prorate_Purchase_Request $request
	 *
	 * @return int
	 */
	protected function get_trial_end_at_for_prorate( ITE_Gateway_Prorate_Purchase_Request $request ) {

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $request->get_cart()->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( $cart_product && $cart_product->get_product() ) {

			$product = $cart_product->get_product();

			if ( isset( $prorates[ $product->ID ] ) && $prorates[ $product->ID ]->get_credit_type() === 'days' ) {

				if ( $prorates[ $product->ID ]->get_free_days() ) {
					return time() + ( $prorates[ $product->ID ]->get_free_days() * DAY_IN_SECONDS );
				}
			}
		}

		return 0;
	}

	/**
	 * Get a Stripe customer object for a given purchase request.
	 *
	 * @since 2.0.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 * @param string|null                   $previous_default_source
	 * @param \ITE_Payment_Token|null       $token
	 *
	 * @return \Stripe\Customer
	 */
	public function get_stripe_customer_for_request( ITE_Gateway_Purchase_Request $request, &$previous_default_source, &$token ) {

		if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
			$source = $request->get_one_time_token();
		} elseif ( $request->get_token() ) {
			$token  = $request->get_token();
			$source = $request->get_token()->token;
		} elseif ( $tokenize = $request->get_tokenize() ) {
			$token = $this->gateway->get_handler_for( $tokenize )->handle( $tokenize );

			if ( $token ) {
				$source = $token->token;
			}
		}

		if ( empty( $source ) ) {
			throw new InvalidArgumentException( __( 'Unable to create payment source.', 'LION' ) );
		}

		$stripe_customer = it_exchange_stripe_addon_get_stripe_customer_id( $request->get_customer()->ID );
		$stripe_customer = $stripe_customer ? \Stripe\Customer::retrieve( $stripe_customer ) : '';

		if ( ! $stripe_customer || ! empty( $stripe_customer->deleted ) ) {

			$args = array(
				'email'    => $request->get_customer()->get_email(),
				'metadata' => array( 'wp_user_id' => $request->get_customer()->ID ),
				'source'   => $source,
			);

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$args['metadata']['is_guest'] = true;
			}

			$stripe_customer = \Stripe\Customer::create( $args );

			if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				it_exchange_stripe_addon_set_stripe_customer_id( $request->get_customer()->ID, $stripe_customer->id );
			}
		} else {
			$previous_default_source         = $stripe_customer->default_source;
			$stripe_customer->default_source = $source;
			$stripe_customer->save();
		}

		return $stripe_customer;
	}

	/**
	 * Get the JS function to tokenize a source.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_tokenize_js_function() {

		$general_settings = it_exchange_get_option( 'settings_general' );
		$currency         = $general_settings['default-currency'];

		if ( $this->gateway->is_sandbox_mode() ) {
			$publishable = $this->gateway->settings()->get( 'stripe-test-publishable-key' );
		} else {
			$publishable = $this->gateway->settings()->get( 'stripe-live-publishable-key' );
		}

		return <<<JS
		
		function( type, tokenize ) {
		
			var deferred = jQuery.Deferred();
			
			var fn = function() {
			
				window.Stripe.setPublishableKey( '$publishable' );
			
				var addressTransform = {
					address1: 'address_line_1',
					address2: 'address_line_2',
					city: 'address_city',
					state: 'address_state',
					zip: 'address_zip',
					country: 'address_country'
				};
				
				var cardTransform = {
					number: 'number',
					cvc: 'cvc',
					year: 'exp_year',
					month: 'exp_month',
				};
				
				var bankTransform = {
					name: 'account_holder_name',
					number: 'account_number',
					type: 'account_holder_type',
					routing: 'routing_number',
				};
				
				var toStripe = {};
				
				if ( tokenize.name ) {
					toStripe.name;
				}
				
				if ( tokenize.address ) {
					for ( var from in addressTransform ) {
						if ( ! addressTransform.hasOwnProperty( from ) ) {
							continue;
						}
						
						var to = addressTransform[ from ];
						
						if ( tokenize.address[ from ] ) {
							toStripe[ to ] = tokenze.address[ from ];
						}
					}
				}
			
				if ( type === 'card' ) {
					for ( var from in cardTransform ) {
						if ( ! cardTransform.hasOwnProperty( from ) ) {
							continue;
						}
						
						var to = cardTransform[ from ];
						
						if ( tokenize[from] ) {
							toStripe[to] = tokenize[from];
						} else {
							deferred.reject( 'Missing property ' + from );
							
							return;
						}
					}
					
					Stripe.card.createToken( toStripe, function( status, response ) {
						if ( response.error ) {
							deferred.reject( response.error.message );
						} else {
							deferred.resolve( response.id );
						}
					} );
				} else if ( type === 'bank' ) {
					for ( var from in bankTransform ) {
						if ( ! bankTransform.hasOwnProperty( from ) ) {
							continue;
						}
						
						var to = bankTransform[ from ];
						
						if ( tokenize[from] ) {
							toStripe[to] = tokenize[from];
						} else {
							deferred.reject( 'Missing property ' + from );
							
							return;
						}
					}
						
					if ( ! tokenize.address || ! tokenize.address.country ) {
						
						deferred.reject( 'Missing property address.country' );
						
						return;
					}
					
					toStripe.country = tokenize.address.country;
					toStripe.currency = '$currency';
					
					Stripe.bank.createToken( toStripe, function( status, response ) {
						if ( response.error ) {
							deferred.reject( response.error.message );
						} else {
							deferred.resolve( response.id );
						}
					} );
				} else {
					deferred.reject( 'Unknown token request type.' );
				}
			};
			
			if ( ! window.hasOwnProperty( 'Stripe' ) ) {
				jQuery.getScript( 'https://js.stripe.com/v2/', fn );
			} else {
				fn();
			}
			
			return deferred.promise();
		}
JS;
	}
}
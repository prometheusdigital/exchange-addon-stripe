<?php
/**
 * Refund Handler.
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Refund_Request_Handler
 */
class IT_Exchange_Stripe_Refund_Request_Handler implements ITE_Gateway_Request_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Refund_Request $request
	 *
	 * @throws \UnexpectedValueException
	 */
	public function handle( $request ) {

		$transaction = $request->get_transaction();
		$method_id   = $transaction->get_method_id();

		if ( ! $method_id || strpos( $method_id, 'ch_' ) !== 0 ) {
			throw new UnexpectedValueException(
				__( 'Unable to process refunds for this transaction.', 'it-l10n-ithemes-exchange' )
			);
		}

		it_exchange_setup_stripe_request();

		// Stripe sends webhooks insanely quick. Make sure we create the refund before the webhook handler does.
		it_exchange_lock( "stripe-refund-created-{$transaction->ID}", 2 );

		$response = \Stripe\Refund::create( array(
			'charge'   => $method_id,
			'amount'   => number_format( $request->get_amount(), 2, '', '' ),
			'metadata' => array(
				'internal_reason' => $request->get_reason()
			)
		) );

		$refund = ITE_Refund::create( array(
			'transaction' => $transaction,
			'amount'      => $response->amount / 100,
			'created_at'  => new \DateTime( "@{$response->created}" ),
			'gateway_id'  => $response->id,
			'reason'      => $request->get_reason(),
			'issued_by'   => $request->issued_by(),
		) );

		it_exchange_release_lock( "stripe-refund-created-{$transaction->ID}" );

		return $refund;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'refund'; }
}
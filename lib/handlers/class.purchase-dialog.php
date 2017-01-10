<?php
/**
 * Purchase Request Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */
use iThemes\Exchange\REST\Route\Customer\Token\Serializer;
use iThemes\Exchange\REST\Route\Customer\Token\Token;
use iThemes\Exchange\REST\Route\Customer\Token\Tokens;

/**
 * Class IT_Exchange_Stripe_Purchase_Dialog_Request_Handler
 */
class IT_Exchange_Stripe_Purchase_Dialog_Request_Handler extends ITE_Dialog_Purchase_Request_Handler {

	/** @var \IT_Exchange_Stripe_Purchase_Request_Handler_Helper */
	private $helper;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		\ITE_Gateway $gateway,
		\ITE_Gateway_Request_Factory $factory,
		IT_Exchange_Stripe_Purchase_Request_Handler_Helper $helper
	) {
		parent::__construct( $gateway, $factory );
		$this->helper = $helper;
	}

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 */
	public function handle( $request ) {

		if ( ! static::can_handle( $request::get_name() ) ) {
			throw new InvalidArgumentException();
		}

		$cart = $request->get_cart();

		if ( ! wp_verify_nonce( $request->get_nonce(), $this->get_nonce_action() ) ) {
			$cart->get_feedback()->add_error(
				__( 'Purchase failed. Unable to verify security token.', 'it-l10n-ithemes-exchange' )
			);

			return null;
		}

		$plan = $this->helper->get_plan_for_cart( $cart, $cart->get_currency_code() );

		return $this->helper->do_transaction( $request, $plan ? $plan->id : '' );
	}
}
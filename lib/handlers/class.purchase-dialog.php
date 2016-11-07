<?php
/**
 * Purchase Request Handler.
 *
 * @since   1.36.0
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

		$general = it_exchange_get_option( 'settings_general' );
		$cart    = $request->get_cart();

		if ( ! wp_verify_nonce( $request->get_nonce(), $this->get_nonce_action() ) ) {
			$cart->get_feedback()->add_error(
				__( 'Purchase failed. Unable to verify security token.', 'it-l10n-ithemes-exchange' )
			);

			return null;
		}

		$plan = $this->helper->get_plan_for_cart( $cart, $general['default-currency'] );

		return $this->helper->do_transaction( $request, $plan ? $plan->id : '' );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_html_before_form_end( ITE_Gateway_Purchase_Request_Interface $request ) {

		$setting     = $this->get_gateway()->is_sandbox_mode() ? 'stripe-test-publishable-key' : 'stripe-live-publishable-key';
		$publishable = $this->get_gateway()->settings()->get( $setting );

		$html = '<script type="text/javascript" src="https://js.stripe.com/v2/"></script>';

		ob_start();
		?>
		<script type="text/javascript">

			<?php if ( ! it_exchange_in_superwidget() ) : ?>
			jQuery( document ).on( 'submit', 'form.it-exchange-purchase-dialog-stripe', function ( e ) {

				var $ = jQuery;

				if ( ! $( "#new-method-stripe" ).is( ':checked' ) ) {
					return;
				}

				var $form = $( this );

				if ( $( "input[name='to_tokenize']", $form ).length ) {
					return;
				}

				e.preventDefault();

				var $submit = $( ':submit', $form );
				$submit.data( 'old-value', $submit.val() );
				$submit.val( 'Processing' ).attr( 'disabled', true );

				var deferred = $.Deferred();
				itExchangeStripeAddTokenizeInput( deferred );

				deferred.done( function () {
					$form.submit();
				} ).fail( function () {
					$submit.removeAttr( 'disabled' );
					$submit.val( $submit.data( 'old-value' ) );
				} );
			} );
			<?php endif; ?>

			if ( itExchange && itExchange.hooks ) {
				itExchange.hooks.addAction( 'itExchangeSW.preSubmitPurchaseDialog_stripe', function ( args ) {

					var $ = jQuery, $form = jQuery( 'form.it-exchange-purchase-dialog-stripe' );

					if ( $( "input[name='to_tokenize']", $form ).length ) {
						deferred.resolve( { alreadyProcessed: true } );

						return;
					}

					itExchangeStripeAddTokenizeInput( args );
				} );
			}

			function itExchangeStripeAddTokenizeInput( deferred ) {

				var $ = jQuery, $form = jQuery( 'form.it-exchange-purchase-dialog-stripe' );

				var name = $( "#it-exchnage-purchase-dialog-cc-first-name-for-stripe" ).val()
					+ ' ' +
					$( "#it-exchnage-purchase-dialog-cc-last-name-for-stripe" ).val();

				Stripe.setPublishableKey( '<?php echo esc_js( $publishable ); ?>' );
				Stripe.card.createToken( {
					number   : $( '#it-exchnage-purchase-dialog-cc-number-for-stripe' ).val().replace( /\s+/g, '' ),
					cvc      : $( '#it-exchnage-purchase-dialog-cc-code-for-stripe' ).val(),
					exp_month: $( '#it-exchnage-purchase-dialog-cc-expiration-month-for-stripe' ).val(),
					exp_year : $( '#it-exchnage-purchase-dialog-cc-expiration-year-for-stripe' ).val(),
					name     : name
				}, function ( status, response ) {
					if ( response.error ) {
						$( '.it-exchange-visual-cc-wrap', $form ).prepend(
							'<div class="notice notice-error"><p>' + response.error.message + '</p></div>'
						);

						$( 'input[type="submit"]', $form ).attr( 'disabled', true );

						deferred.reject();
					} else {
						$( '.it-exchange-visual-cc-wrap', $form ).hide();
						$( ".it-exchange-visual-cc input[type!='hidden']", $form ).each( function () {
							$( this ).val( '' );
						} );
						$form.append( $( '<input type="hidden" name="to_tokenize">' ).val( response.id ) );

						deferred.resolve();
					}
				} );
			}
		</script>

		<?php
		return $html . ob_get_clean();
	}
}
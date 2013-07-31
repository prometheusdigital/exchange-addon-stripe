/**
 * Adds a processing div to background after user hits subit but before the page refreshes
*/
function it_exchange_stripe_processing_payment_popup() {
	jQuery( document ).ready( function( $ ) {
		var it_exchange_stripe_processing_html = '<div id="it-exchange-processing-payment"><div><span></span><p>' + stripeAddonL10n.processing_payment_text + '</p></div></div>';
		$( 'body' ).append( it_exchange_stripe_processing_html );
		$( '#it-exchange-processing-payment' ).show();
	});
}

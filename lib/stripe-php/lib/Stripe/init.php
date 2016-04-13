<?php
// Tested on PHP 5.2, 5.3
// This snippet (and some of the curl code) due to the Facebook SDK.
if (!function_exists('curl_init')) {
	throw new Exception('Stripe needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
	throw new Exception('Stripe needs the JSON PHP extension.');
}
if (!function_exists('mb_detect_encoding')) {
	throw new Exception('Stripe needs the Multibyte String PHP extension.');
}
// Stripe singleton
require(dirname(__FILE__) . '/Stripe.php');
// Utilities
require(dirname(__FILE__) . '/Util.php');
require(dirname(__FILE__) . '/Util/Set.php');
// Errors
require(dirname(__FILE__) . '/Error.php');
require(dirname(__FILE__) . '/ApiError.php');
require(dirname(__FILE__) . '/ApiConnectionError.php');
require(dirname(__FILE__) . '/AuthenticationError.php');
require(dirname(__FILE__) . '/CardError.php');
require(dirname(__FILE__) . '/InvalidRequestError.php');
require(dirname(__FILE__) . '/RateLimitError.php');
// Plumbing
require(dirname(__FILE__) . '/Object.php');
require(dirname(__FILE__) . '/ApiRequestor.php');
require(dirname(__FILE__) . '/ApiResource.php');
require(dirname(__FILE__) . '/SingletonApiResource.php');
require(dirname(__FILE__) . '/AttachedObject.php');
require(dirname(__FILE__) . '/List.php');
// Stripe API Resources
require(dirname(__FILE__) . '/Account.php');
require(dirname(__FILE__) . '/Card.php');
require(dirname(__FILE__) . '/Balance.php');
require(dirname(__FILE__) . '/BalanceTransaction.php');
require(dirname(__FILE__) . '/Charge.php');
require(dirname(__FILE__) . '/Customer.php');
require(dirname(__FILE__) . '/Invoice.php');
require(dirname(__FILE__) . '/InvoiceItem.php');
require(dirname(__FILE__) . '/Plan.php');
require(dirname(__FILE__) . '/Subscription.php');
require(dirname(__FILE__) . '/Token.php');
require(dirname(__FILE__) . '/Coupon.php');
require(dirname(__FILE__) . '/Event.php');
require(dirname(__FILE__) . '/Transfer.php');
require(dirname(__FILE__) . '/Recipient.php');
require(dirname(__FILE__) . '/Refund.php');
require(dirname(__FILE__) . '/ApplicationFee.php');
require(dirname(__FILE__) . '/ApplicationFeeRefund.php');
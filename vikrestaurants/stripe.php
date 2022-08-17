<?php
/**
 * @package     VikStripe
 * @subpackage  vikrestaurants
 * @author      Matteo Galletti - E4J s.r.l.
 * @copyright   Copyright (C) 2018 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */

defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('stripe', VIKSTRIPE_DIR);

// Enhance the configuration array to include password and default values
add_action('payment_after_admin_params_vikrestaurants', function(&$payment, &$config)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}

	// make the secret key input as a password
	$config['secretkey']['type'] = 'password';

	// make default currency as EUR
	$config['currency']['default'] = 'EUR: Euro';

}, 10, 2);

// Store the SESSION ID of the transaction for later use
add_action('payment_after_begin_transaction_vikrestaurants', function(&$payment, &$html)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}

	// save the transaction session ID within a transient (should not work on a multisite, try using `set_site_transient`)
	set_transient('vikstripe_' . $payment->get('oid') . '_' . $payment->get('sid'), $payment->get('session_id'), 1440 * MINUTE_IN_SECONDS);

}, 10, 2);

// Retrieve the total amount and the session id from the static transaction file.
add_action('payment_before_validate_transaction_vikrestaurants', function($payment)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}

	$transient = 'vikstripe_' . $payment->get('oid') . '_' . $payment->get('sid');
	$payment->set('is_transient', true);
	$payment->set('transient_name', $transient);

	// get session ID from transient (should not work on a multisite, try using `get_site_transient`)
	$session_id = get_transient($transient);

	// make sure the session ID was previously set
	if ($session_id)
	{
		// set session ID within the payment instance
		$payment->set('session_id', $session_id);

	}
	
});

/**
 * This class is used to collect payments in VikRestaurants plugin
 * by using the Stripe gateway.
 *
 * @since 1.0
 */
class VikRestaurantsStripePayment extends AbstractStripePayment
{
	/**
	 * @override
	 * Class constructor.
	 *
	 * @param 	string 	$alias 	 The name of the plugin that requested the payment.
	 * @param 	mixed 	$order 	 The order details to start the transaction.
	 * @param 	mixed 	$params  The configuration of the payment.
	 */
	public function __construct($alias, $order, $params = array())
	{
		parent::__construct($alias, $order, $params);

		if (!$this->get('custmail'))
		{
			$details = $this->get('details', array());
			$this->set('custmail', isset($details['purchaser_mail']) ? $details['purchaser_mail'] : '');
		}
	}

	/**
	 * @override
	 * Method used to begin a payment transaction.
	 * This method usually generates the HTML form of the payment.
	 * The HTML contents can be echoed directly because this method
	 * is executed always within a buffer.
	 *
	 * @return 	void
	 */
	public function beginTransaction()
	{
		$key = \Stripe\Stripe::setApiKey($this->getParam('secretkey'));

		// load cart items
		$items = $this->loadCartItems($this->get('oid'));

		$submit_type = $this->getParam('paytype') == "auth" ? "manual" : "automatic";

		$payment_intent_data = array(
			'capture_method' 		=> $submit_type,
			'description'	 		=> $this->get('transaction_name'),
			'setup_future_usage' 	=> 'on_session'
		);


		// init payment transaction
		$session = \Stripe\Checkout\Session::create([
			'customer_email'       => $this->get('custmail'),
			'payment_method_types' => ['card'],
		  	'success_url'          => $this->get('notify_url'),
		  	'cancel_url'           => $this->get('return_url'),
		  	'product'           => $items,
		  	'payment_intent_data'  => $payment_intent_data
		]);

		// store session ID for later use
		$this->set('session_id', $session['id']);
		
		// get notification URL
		$url = $this->get('notify_url');

		// force HTTPS if needed
		if ($this->getParam('ssl'))
		{
			$url = str_replace('http:', 'https:', $url);
		}

		// get public key
		$pubkey = $this->getParam('pubkey');

		// load Stripe JS
		JFactory::getDocument()->addScript('https://js.stripe.com/v3');

		if ($this->getParam('skipbtn') == __('No', 'vikstripe')) {
			// register on click event
		JFactory::getDocument()->addScriptDeclaration(
<<<JS
jQuery(document).ready(function() {
	jQuery('#stripe-checkout-button').on('click', function() {
		var stripe = Stripe('{$pubkey}');
		stripe.redirectToCheckout({
			// Make the id field from the Checkout Session creation API response
			// available to this file, so you can provide it as parameter here
			// instead of the {{CHECKOUT_SESSION_ID}} placeholder.
			sessionId: '{$session['id']}',
		}).then(function (result) {

		});
	});
});
JS
		);

		// display Pay Now button
		$form = '<button class="btn btn-primary" id="stripe-checkout-button">'. __('Pay Now', 'vikstripe') . '</button>';
		//echo $this->get('payment_info')['note'];
		echo $form;


		}
		else {
			JFactory::getDocument()->addScriptDeclaration(
<<<JS
jQuery(document).ready(function() {
	
	var stripe = Stripe('{$pubkey}');
	stripe.redirectToCheckout({
		// Make the id field from the Checkout Session creation API response
		// available to this file, so you can provide it as parameter here
		// instead of the {{CHECKOUT_SESSION_ID}} placeholder.
		sessionId: '{$session['id']}',
	}).then(function (result) {
		
	});
});
JS
		);
		}

		

		return true;
	}
}

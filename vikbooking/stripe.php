<?php
/**
 * @package     VikStripe
 * @subpackage  vikbooking
 * @author      Lorenzo - E4J s.r.l.
 * @copyright   Copyright (C) 2019 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */

defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('stripe', VIKSTRIPE_DIR);

// Prepend the deposit message before the payment form (only if specified).
// Store the SESSION ID of the transaction for later use.
add_action('payment_after_begin_transaction_vikbooking', function(&$payment, &$html)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}

	if ($payment->get('leave_deposit'))
	{
		$html = '<p class="vbo-leave-deposit">
			<span>' . JText::_('VBLEAVEDEPOSIT') . '</span>' . 
			$payment->get('currency_symb') . ' ' . number_format($payment->get('total_to_pay'), 2) . 
		'</p><br/>' . $html;
	}

	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}

	$json = json_encode(array(
		'session_id'   => $payment->get('session_id'),
		'total_to_pay' => $payment->get('total_to_pay'),
		'notify_url' => $payment->get('notify_url'),
	));

	/**
	 * Force the system to avoid using the cache for transient.
	 * The previous value will be reset after terminating the callback.
	 *
	 * @since 1.1.0
	 */
	$was_using_cache = wp_using_ext_object_cache(false);


	// save the transaction session ID within a transient (should not work on a multisite, try using `set_site_transient`)
	$transient = set_transient('vikstripe_' . $payment->get('oid') . '_' . $payment->get('sid'), $json, 1440 * MINUTE_IN_SECONDS);

	// restore cache flag
	wp_using_ext_object_cache($was_using_cache);
	
	if (!$transient) {
		$txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
		$fp = fopen(VIKSTRIPE_DIR . DIRECTORY_SEPARATOR . 'Stripe' . DIRECTORY_SEPARATOR . $txname , 'w+');
		fwrite($fp, $payment->get('total_to_pay') . '^' . $session['id'] . '^' . $payment->get('notify_url'));
		fclose($fp);
	}

}, 10, 2);

// Retrieve the total amount and the session id from the static transaction file.
add_action('payment_before_validate_transaction_vikbooking', function($payment)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}
	$txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
	$txdata = '';

	$path = VIKSTRIPE_DIR . DIRECTORY_SEPARATOR . 'Stripe' . DIRECTORY_SEPARATOR . $txname;
	/**
	 * Force the system to avoid using the cache for transient.
	 * The previous value will be reset after terminating the callback.
	 *
	 * @since 1.1.0
	 */
	$was_using_cache = wp_using_ext_object_cache(false);

	$transient = 'vikstripe_' . $payment->get('oid') . '_' . $payment->get('sid');

	// get session ID from transient (should not work on a multisite, try using `get_site_transient`)
	$json = get_transient($transient);
	/**
	 *	Check if transient exists: if it doesn't exist, then attempt to recover the needed data from the file.
	 *	If also the file results empty, then throw an error on the session id.
 	 *	
 	 *	@since 1.1.0
 	 *
 	 */

	if ($json) {
	
		$data = (array) json_decode($json, true);
		/**
		 *  @since 1.1.4
		 *
		 *	Deletion of transients and files is done directly there to support multiple transactions.
		 *	Checking if this is a file or not. 
		 *
		 */
		$payment->set('is_transient', true);
		$payment->set('transient_name', $transient);

		if (isset($data['session_id']))
		{
			// set session ID within the payment instance
			$payment->set('session_id', $data['session_id']);
		}

		if (isset($data['total_to_pay']))
		{
			// set total to pay as it is probably missing
			$payment->set('total_to_pay', $data['total_to_pay']);
		}
		/**
		 *  @since 1.1.4
		 *
		 *	Retrieving notify_url so that I'll be able to check it if the two session IDs are not equals.
		 *
		 */

		if (isset($data['notify_url'])) {
			//set notify url
			$payment->set('notify_url', $data['notify_url']);
		}
	// restore cache flag
		wp_using_ext_object_cache($was_using_cache);
		
	} else if (is_file($path)) {
		/**
		 *  @since 1.1.4
		 *
		 *	Deletion of transients and files is done directly there to support multiple transactions.
		 *	Checking if this is a file or not. 
		 *
		 */
		$payment->set('is_transient', false);
		$payment->set('file_path', $path);
		$fp = fopen($path, 'rb');
		$txdata = fread($fp, filesize($path));
		fclose($fp);

		$parts = explode('^', $txdata);

		if (!empty($parts[0]))
		{
			$payment->set('total_to_pay', $parts[0]);
		}
		else 
		{
			// if not set, specify an empty value to pay
			$payment->set('total_to_pay', $payment->get('total_to_pay', 0));
		}
		if (!empty($parts[1])) {
			$payment->set('session_id' , $parts[1]);
		}
		if (!empty($parts[2])) {
			$payment->set('notify_url' , $parts[2]);
		}
		
	} else {
		$payment->set('session_id', 'SESSION ID NOT FOUND!');

	}	
	
});

// VikBooking doesn't have a return_url to use within the afterValidation method.
// Use this hook to construct it and route it following the shortcodes standards.
add_action('payment_on_after_validation_vikbooking', function(&$payment, $res)
{
	// make sure the driver is Stripe
	if (!$payment->isDriver('stripe'))
	{
		return;
	}
	$url = 'index.php?option=com_vikbooking&view=booking&sid=' . $payment->get('sid') . '&ts=' . $payment->get('ts');

	$model 		= JModel::getInstance('vikbooking', 'shortcodes', 'admin');
	$itemid 	= $model->best(array('booking'));
	
	if ($itemid)
	{
		$url = JRoute::_($url . '&Itemid=' . $itemid, false);
	}

	JFactory::getApplication()->redirect($url);
	exit;
}, 10, 2);

/**
 * This class is used to collect payments in VikBooking plugin
 * by using the Stripe gateway.
 *
 * @since 1.0
 */
class VikBookingStripePayment extends AbstractStripePayment
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

		$details = $this->get('details', array());

		$this->set('oid', $this->get('id', null));
		
		if (!$this->get('oid'))
		{
			$this->set('oid', isset($details['id']) ? $details['id'] : 0);
		}

		if (!$this->get('sid'))
		{
			$this->set('sid', isset($details['sid']) ? $details['sid'] : 0);
		}

		if (!$this->get('ts'))
		{
			$this->set('ts', isset($details['ts']) ? $details['ts'] : 0);
		}

		if (!$this->get('custmail'))
		{
			$this->set('custmail', isset($details['custmail']) ? $details['custmail'] : '');
		}
	}
	public function loadCartItems($orderid) {

		$amount_to_pay = round($this->get('total_to_pay'), 2) * 100;	
		$rooms = array();
		$rooms = VikBooking::loadOrdersRoomsData($orderid);

		$rooms_id_map = array(
		// 'id' => 'qty' 
		);
		$rooms_info_map = array(
		// 'id' => 'info' 
		);
	

		$images = array();
		foreach ($rooms as $room) {
			if (!isset($rooms_id_map[$room['idroom']])) {
				$rooms_id_map[$room['idroom']] = 0;
			}
			$rooms_id_map[$room['idroom']]++;
		}

		foreach (array_keys($rooms_id_map) as $idroom) {
			$rooms_info_map[$idroom] = VikBooking::getRoomInfo($idroom);
		}
		$img = '';
		foreach ($rooms_id_map as $room_id => $qty) {
			$img;
			foreach ($rooms_info_map as $room ) {
				$img = $room['id'] == $room_id ? $room['img'] : $img;
			}

			array_push($images, array(
				[VBO_SITE_URI."/resources/uploads/".$img],
			));
		}
		$item['line_items'] = array(['price_data' =>
			[
			'name'     => $this->get('transaction_name'),
			'images'   => $images,
			'amount'   => $amount_to_pay,
			'currency' => $this->getParam('currency'),
			'quantity' => 1,
            ]
		]);
		
		return array($item);

	}

}

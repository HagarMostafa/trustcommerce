<?php
/*
 * This file is part of CiviCRM.
 *
 * CiviCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * CiviCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CiviCRM.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Copyright (C) 2012
 * Licensed to CiviCRM under the GPL v3 or higher
 *
 * Written and contributed by Ward Vandewege <ward@fsf.org> (http://www.fsf.org)
 * Modified by Lisa Marie Maginnis <lisa@fsf.org> (http://www.fsf.org)
 * Copyright © 2015 David Thompson <davet@gnu.org>
 *
 */

/**
  * CiviCRM payment processor module for TrustCommerece.
  *
  * This module uses the TrustCommerece API via the tc_link module (GPLv3)
  * distributed by TrustCommerece.com. For full documentation on the 
  * TrustCommerece API, please see the TCDevGuide for more information:
  * https://vault.trustcommerce.com/downloads/TCDevGuide.htm
  *
  * This module supports the following features: Single credit/debit card
  * transactions, AVS checking, recurring (create, update, and cancel
  * subscription) optional blacklist with fail2ban,
  *
  * @copyright Ward Vandewege <ward@fsf.org> (http://www.fsf.org)
  * @copyright Lisa Marie Maginnis <lisa@fsf.org> (http://www.fsf.org)
  * @copyright David Thompson <davet@gnu.org>
  * @version   0.4
  * @package   org.fsf.payment.trustcommerce
  */

/**
 * Define logging level (0 = off, 4 = log everything)
 */
define('TRUSTCOMMERCE_LOGGING_LEVEL', 4);

/**
 * Load the CiviCRM core payment class so we can extend it.
 */
require_once 'CRM/Core/Payment.php';

/**
 * The payment processor object, it extends CRM_Core_Payment.
 */
//class org_fsf_payment_trustcommerce extends CRM_Core_Payment {
class CRM_Core_Payment_TrustCommerce extends CRM_Core_Payment {

  /**#@+
   * Constants
   */
  /**
   * This is our default charset, currently unused.
   */
  CONST CHARSET = 'iso-8859-1';
  /**
   * The API response value for transaction approved.
   */
  CONST AUTH_APPROVED = 'approve';
  /**
   * The API response value for transaction declined.
   */
  CONST AUTH_DECLINED = 'decline';
  /**
   * The API response value for baddata passed to the TC API.
   */
  CONST AUTH_BADDATA = 'baddata';
  /**
   * The API response value for an error in the TC API call.
   */
  CONST AUTH_ERROR = 'error';
  /**
   * The API response value for blacklisted in our local blacklist
   */
  CONST AUTH_BLACKLIST = 'blacklisted';
  /**
   * The API response value for approved status per the TCDevGuide.
   */
  CONST AUTH_ACCEPTED = 'accepted';

  /**
   * The current mode of the payment processor, valid values are: live, demo.
   * @static
   * @var string
   */
  protected $_mode = NULL;
  /**
   * The array of params cooked and passed to the TC API via tc_link().
   * @static
   * @var array
   */
  protected $_params = array();

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   * @static
   * @var object
   */
  static private $_singleton = NULL;

  /**
   * Sets our basic TC API paramaters (username, password). Also sets up:
   * logging level, processor name, the mode (live/demo), and creates/copies
   * our singleton.
   *
   * @param string $mode the mode of operation: live or test
   * @param CRM_Core_Payment The payment processor object.
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;

    $this->_paymentProcessor = $paymentProcessor;

    $this->_processorName = ts('TrustCommerce');

    $config = CRM_Core_Config::singleton();
    $this->_setParam('user_name', $paymentProcessor['user_name']);
    $this->_setParam('password', $paymentProcessor['password']);

    $this->_setParam('timestamp', time());
    srand(time());
    $this->_setParam('sequence', rand(1, 1000));
    $this->logging_level     = TRUSTCOMMERCE_LOGGING_LEVEL;

  }

  /**
   * The singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   * @param CRM_Core_Payment The payment processor object.
   *
   * @return object
   * @static
   */
    static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_TrustCommerce($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
    }

  /**
   * Submit a payment using the TC API
   *
   * @param  array $params The params we will be sending to tclink_send()
   * @return mixed An array of our results, or an error object if the transaction fails.
   * @public
   */
  function doDirectPayment(&$params) {
    if (!extension_loaded("tclink")) {
      return self::error(9001, 'TrustCommerce requires that the tclink module is loaded');
    }

    /* Copy our paramaters to ourself */
    foreach ($params as $field => $value) {
      $this->_setParam($field, $value);
    }

    /* Get our fields to pass to tclink_send() */
    $tc_params = $this->_getTrustCommerceFields();

    /* Are we recurring? If so add the extra API fields. */
    if (CRM_Utils_Array::value('is_recur', $params) == 1) {
      $tc_params = $this->_getRecurPaymentFields($tc_params);
      $recur=1;
    }

    /* Pass our cooked params to the alter hook, per Core/Payment/Dummy.php */
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $tc_params);

    // TrustCommerce will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->_checkDupe($tc_params['ticket'])) {
      return self::error(9004, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem. You can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }

    /* This implements a local blacklist, and passes us though as a normal failure
     * if the luser is on the blacklist. */
    if(!$this->_isBlacklisted($tc_params)) {
      /* Call the TC API, and grab the reply */
      $reply = $this->_sendTCRequest($tc_params);
    } else {
      $this->_logger($tc_params);
      $reply['status'] = self::AUTH_BLACKLIST;
      usleep(rand(1000000,10000000));
    }

    /* Parse our reply */
    $result = $this->_getTCReply($reply);

    if(!is_object($result)) {
      if($result == 0) {
	/* We were successful, congrats. Lets wrap it up:
	 * Convert back to dollars
	 * Save the transaction ID
	 */
	
	if (array_key_exists('billingid', $reply)) {
	  $params['recurr_profile_id'] = $reply['billingid'];
	  CRM_Core_DAO::setFieldValue(
				      'CRM_Contribute_DAO_ContributionRecur',
				      $this->_getParam('contributionRecurID'),
				      'processor_id', $reply['billingid']
				      );
	}
	$params['trxn_id'] = $reply['transid'];
	
	$params['gross_amount'] = $tc_params['amount'] / 100;
	
	return $params;
      }
    } else {
      /* Otherwise we return the error object */
      return $result;
    }
  }

  /**
   * Hook to update CC info for a recurring contribution
   *
   * @param string $message The message to dispaly on update success/failure
   * @param array  $params  The paramters to pass to the payment processor
   *
   * @return bool True if successful, false on failure
   */
  function updateSubscriptionBillingInfo(&$message = '', $params = array()) {
    $expYear = $params['credit_card_exp_date']['Y'];
    $expMonth = $params['credit_card_exp_date']['M'];

    // TODO: This should be our build in params set function, not by hand!
    $tc_params = array(
      'custid' => $this->_paymentProcessor['user_name'],
      'password' => $this->_paymentProcessor['password'],
      'action' => 'store',
      'billingid' => $params['subscriptionId'],
      'avs' => 'y', // Enable address verification
      'address1' => $params['street_address'],
      'zip' => $params['postal_code'],
      'name' => $this->_formatBillingName($params['first_name'],
                                          $params['last_name']),
      'cc' => $params['credit_card_number'],
      'cvv' => $params['cvv2'],
      'exp' => $this->_formatExpirationDate($expYear, $expMonth),
      'amount' => $this->_formatAmount($params['amount']),
    );

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $tc_params);

    $reply = $this->_sendTCRequest($tc_params);
    $result = $this->_getTCReply($reply);

    if($result === 0) {
      // TODO: Respect vaules for $messages passed in from our caller
      $message = 'Successfully updated TC billing id ' . $tc_params['billingid'];

      return TRUE;
    } else {
      return FALSE;
    }
  }

  // TODO: Use the formatting functions throughout the entire class to
  // dedupe the conversions done elsewhere in a less reusable way.

  /**
   * Internal routine to convert from CiviCRM amounts to TC amounts.
   *
   * Multiplies the amount by 100.
   *
   * @param float $amount The currency value to convert.
   *
   * @return int The TC amount
   */
  private function _formatAmount($amount) {
    return $amount * 100;
  }

  /**
   * Internal routine to format the billing name for TC
   *
   * @param string $firstName The first name to submit to TC
   * @param string $lastName The last name to submit to TC
   *
   * @return string The TC name format, "$firstName $lastName"
   */
  private function _formatBillingName($firstName, $lastName) {
    return "$firstName $lastName";
  }

  /**
   * Formats the expiration date for TC
   *
   * @param int $year  The credit card expiration year
   * @param int $month The credit card expiration year
   *
   * @return The TC CC expiration date format, "$month$year"
   */
  private function _formatExpirationDate($year, $month) {
    $exp_month = str_pad($month, 2, '0', STR_PAD_LEFT);
    $exp_year = substr($year, -2);

    return "$exp_month$exp_year";
  }

  private function _isParamsBlacklisted($tc_params) {
    if($tc_params['amount'] == 101) {
      error_log("TrustCommerce: _isParamsBlacklisted() triggered");
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Checks to see if the source IP/USERAGENT are blacklisted.
   *
   * @return bool TRUE if on the blacklist, FALSE if not.
   */
  private function _isBlacklisted($tc_params) {
    if($this->_isIPBlacklisted()) {
      return TRUE;
    } else if($this->_isAgentBlacklisted()) {
      return TRUE;
    } else if($this->_isParamsBlacklisted($tc_params)) {
      return TRUE;
    }
    return FALSE;

  }

  /**
   * Checks to see if the source USERAGENT is blacklisted
   *
   * @return bool TRUE if on the blacklist, FALSE if not.
   */
  private function _isAgentBlacklisted() {
    // TODO: fix DB calls to be more the CiviCRM way
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'];
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM `trustcommerce_useragent_blacklist`');
    while($dao->fetch()) {
      if(preg_match('/'.$dao->name.'/', $agent) === 1) {
	error_log(' [client '.$ip.'] [agent '.$agent.'] - Blacklisted by USER_AGENT rule #'.$dao->id);
	return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks to see if the source IP is blacklisted
   *
   * @return bool TRUE if on the blacklist, FALSE if not.
   */
  private function _isIPBlacklisted() {
    // TODO: fix DB calls to be more the CiviCRM way
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'];
    # Disable on IPv6
    if ( strpos(":", $ip) !== false ){
      return TRUE;
    }
    $ip = ip2long($ip);
    $blacklist = array();
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM `trustcommerce_blacklist`');
    while($dao->fetch()) {
      if($ip >= $dao->start && $ip <= $dao->end) {
	error_log('[client '.long2ip($ip).'] [agent '.$agent.'] Blacklisted by IP rule #'.$dao->id);
	return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sends the API call to TC for processing
   *
   * @param array $request The array of paramaters to pass the TC API
   *
   * @return array The response from the TC API
   */
  function _sendTCRequest($request) {
    $this->_logger($request);
    return tclink_send($request);
  }

  /**
   * Logs paramaters from TC along with the remote address of the client
   *
   * Will log paramaters via the error_log() routine. For security reasons
   * the following values are not logged (skipped): custid, password, cc
   * exp, and cvv.
   *
   * @param array $params The paramaters to log
   */
  function _logger($params) {
    $msg = '';
    foreach ($params as $key => $data) {
      /* Delete any data we should not be writing to disk. This includes:
       * custid, password, cc, exp, and cvv
       */
      switch($key) {
      case 'custid':
      case 'password':
      case 'cc':
      case 'exp':
      case 'cvv':
	break;
      default:
	$msg .= ' '.$key.' => '.$data;
      }
    }
    error_log('[client '.$_SERVER['REMOTE_ADDR'].'] TrustCommerce:'.$msg);
  }

  /**
   * Gets the recurring billing fields for the TC API
   *
   * @param  array $fields The fields to modify.
   * @return array The fields for tclink_send(), modified for recurring billing.
   * @public
   */
  function _getRecurPaymentFields($fields) {
    $payments = $this->_getParam('frequency_interval');
    $cycle = $this->_getParam('frequency_unit');

    /* Translate billing cycle from CiviCRM -> TC */
    switch($cycle) {
    case 'day':
      $cycle = 'd';
      break;
    case 'week':
      $cycle = 'w';
      break;
    case 'month':
      $cycle = 'm';
      break;
    case 'year':
      $cycle = 'y';
      break;
    }

    /* Translate frequency interval from CiviCRM -> TC
     * Payments are the same, HOWEVER a payment of 1 (forever) should be 0 in TC */
    if($payments == 1) {
      $payments = 0;
    }

    $fields['cycle'] = '1'.$cycle;   /* The billing cycle in years, months, weeks, or days. */
    $fields['payments'] = $payments;
    $fields['action'] = 'store';      /* Change our mode to `store' mode. */

    return $fields;
  }

  /** Parses a response from TC via the tclink_send() command.
   *
   * @param array $reply The result of a call to tclink_send().
   *
   * @return mixed|CRM_Core_Error CRM_Core_Error object if transaction failed, otherwise
   * returns 0.
   */
  function _getTCReply($reply) {

    /* DUPLIATE CODE, please refactor. ~lisa */
    if (!$reply) {
      return self::error(9002, 'Could not initiate connection to payment gateway.');
    }

    $this->_logger($reply);

    switch($reply['status']) {
    case self::AUTH_BLACKLIST:
      return self::error(9001, "Your transaction was declined for address verification reasons. If your address was correct please contact us at donate@fsf.org before attempting to retry your transaction.");
      break;
    case self::AUTH_APPROVED:
      break;
    case self::AUTH_ACCEPTED:
      // It's all good
      break;
    case self::AUTH_DECLINED:
      // TODO FIXME be more or less specific?
      // declinetype can be: decline, avs, cvv, call, expiredcard, carderror, authexpired, fraud, blacklist, velocity
      // See TC documentation for more info
      switch($reply['declinetype']) {
      case 'avs':
	return self::error(9009, "Your transaction was declined for address verification reasons. If your address was correct please contact us at donate@fsf.org before attempting to retry your transaction.");
	break;
      }
      return self::error(9009, "Your transaction was declined. Please check the correctness of your credit card information, including CC number, expiration date and CVV code.");
      break;
    case self::AUTH_BADDATA:
      // TODO FIXME do something with $reply['error'] and $reply['offender']
      return self::error(9011, "Invalid credit card information. The following fields were invalid: {$reply['offenders']}.");
      break;
    case self::AUTH_ERROR:
      return self::error(9002, 'Could not initiate connection to payment gateway');
      break;
    }
    return 0;
  }

  /**
   * Generate the basic paramaters to send the TC API
   *
   * @return array The array of paramaters to pass _sendTCRequest()
   */
  function _getTrustCommerceFields() {
    // Total amount is from the form contribution field
    $amount = $this->_getParam('total_amount');
    // CRM-9894 would this ever be the case??
    if (empty($amount)) {
      $amount = $this->_getParam('amount');
    }
    $fields = array();

    $fields['custid'] = $this->_paymentProcessor['user_name'];
    $fields['password'] = $this->_paymentProcessor['password'];

    $fields['action'] = 'sale';

    // Enable address verification
    $fields['avs'] = 'y';

    $fields['address1'] = $this->_getParam('street_address');
    $fields['zip'] = $this->_getParam('postal_code');
    $fields['country'] = $this->_getParam('country');

    $fields['name'] = $this->_getParam('billing_first_name') . ' ' . $this->_getParam('billing_last_name');

    // This assumes currencies where the . is used as the decimal point, like USD
    $amount = preg_replace("/([^0-9\\.])/i", "", $amount);

    // We need to pass the amount to TrustCommerce in dollar cents
    $fields['amount'] = $amount * 100;

    // Unique identifier
    $fields['ticket'] = substr($this->_getParam('invoiceID'), 0, 20);

    // cc info
    $fields['cc'] = $this->_getParam('credit_card_number');
    $fields['cvv'] = $this->_getParam('cvv2');
    $exp_month = str_pad($this->_getParam('month'), 2, '0', STR_PAD_LEFT);
    $exp_year = substr($this->_getParam('year'),-2);
    $fields['exp'] = "$exp_month$exp_year";

    if ($this->_mode != 'live') {
      $fields['demo'] = 'y';
    }
    return $fields;
  }

  /**
   * Checks to see if invoice_id already exists in db
   *
   * @param  int     $invoiceId   The ID to check
   *
   * @return bool                 True if ID exists, else false
   */
  function _checkDupe($invoiceId) {
    require_once 'CRM/Contribute/DAO/Contribution.php';
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->invoice_id = $invoiceId;
    return $contribution->find();
  }

  /**
   * Get the value of a field if set
   *
   * @param string $field the field
   *
   * @return mixed value of the field, or empty string if the field is
   * not set
   */
  function _getParam($field) {
    $value = CRM_Utils_Array::value($field, $this->_params, '');
    if ($xmlSafe) {
      $value = str_replace(array('&', '"', "'", '<', '>'), '', $value);
    }
    return $value;
  }

  /**
   * Sets our error message/logging information for CiviCRM
   *
   * @param int $errorCode The numerical code of the error, defaults to 9001
   * @param string $errorMessage The error message to display/log
   *
   * @return CRM_Core_Error The error object with message and code.
   */
  function &error($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();
    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9001, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**
   * Set a field to the specified value.  Value must be a scalar (int,
   * float, string, or boolean)
   *
   * @param string $field
   * @param mixed $value
   *
   * @return bool false if value is not a scalar, true if successful
   */
  function _setParam($field, $value) {
    if (!is_scalar($value)) {
      return FALSE;
    }
    else {
      $this->_params[$field] = $value;
    }
  }

  /**
   * Checks to see if we have the manditory config values set.
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = array();
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Customer ID is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Password is not set for this payment processor');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    } else {
      return NULL;
    }
  }

  /**
   * Hook to cancel a recurring contribution
   *
   * @param string $message The message to dispaly on update success/failure
   * @param array  $params  The paramters to pass to the payment processor
   *
   * @return bool True if successful, false on failure
   */
  function cancelSubscription(&$message = '', $params = array()) {
    $tc_params['custid'] = $this->_getParam('user_name');
    $tc_params['password'] = $this->_getParam('password');
    $tc_params['action'] = 'unstore';
    $tc_params['billingid'] = CRM_Utils_Array::value('subscriptionId', $params);

    $result = $this->_sendTCRequest($tc_params);

    /* Test if call failed */
    if(!$result) {
      return self::error(9002, 'Could not initiate connection to payment gateway');
    }
    /* We are done, pass success */
    return TRUE;
  }

  /**
   * Hook to update amount billed for a recurring contribution
   *
   * @param string $message The message to dispaly on update success/failure
   * @param array  $params  The paramters to pass to the payment processor
   *
   * @return bool True if successful, false on failure
   */
  function changeSubscriptionAmount(&$message = '', $params = array()) {
    $tc_params['custid'] = $this->_paymentProcessor['user_name'];
    $tc_params['password'] = $this->_paymentProcessor['password'];
    $tc_params['action'] = 'store';

    $tc_params['billingid'] = CRM_Utils_Array::value('subscriptionId', $params);
    $tc_params['payments'] = CRM_Utils_Array::value('installments', $params);
    $tc_params['amount'] = CRM_Utils_Array::value('amount', $params) * 100;

    if($tc_params['payments'] == 1) {
      $tc_params['payments'] = 0;
    }
    $reply = $this->_sendTCRequest($tc_params);
    $result = $this->_getTCReply($reply);

    /* We are done, pass success */
    return TRUE;

    }

  /**
   * Installs the trustcommerce module (currently a dummy)
   */
  public function install() {
    return TRUE;
  }

  /**
   * Uninstalls the trustcommerce module (currently a dummy)
   */
  public function uninstall() {
    return TRUE;
  }

}


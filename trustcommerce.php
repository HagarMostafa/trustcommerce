<?php
/*
 * Copyright (C) 2012
 * Licensed to CiviCRM under the GPL v3 or higher
 *
 * Written and contributed by Ward Vandewege <ward@fsf.org> (http://www.fsf.org)
 * Modified by Lisa Marie Maginnis <lisa@fsf.org> (http://www.fsf.org)
 *
 */

// Define logging level (0 = off, 4 = log everything)
define('TRUSTCOMMERCE_LOGGING_LEVEL', 4);

require_once 'CRM/Core/Payment.php';
class org_fsf_payment_trustcommerce extends CRM_Core_Payment {
  CONST CHARSET = 'iso-8859-1';
  CONST AUTH_APPROVED = 'approve';
  CONST AUTH_DECLINED = 'decline';
  CONST AUTH_BADDATA = 'baddata';
  CONST AUTH_ERROR = 'error';

  static protected $_mode = NULL;

  static protected $_params = array();

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */ function __construct($mode, &$paymentProcessor) {
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
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static
  function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new org_fsf_payment_trustcommerce($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Submit a payment using the TC API
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
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      $tc_params = $this->_getRecurPaymentFields($tc_params);
    }

    /* Pass our cooked params to the alter hook, per Core/Payment/Dummy.php */
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $tc_params);

    // TrustCommerce will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->_checkDupe($tc_params['ticket'])) {
      return self::error(9004, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem. You can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }

    /* Call the TC API, and grab the reply */
    $reply = tclink_send($tc_params);

    /* Parse our reply */
    $result = $this->_getTrustCommerceReply($reply);

    if($result == 0) {
      /* We were successful, congrats. Lets wrap it up:
       * Convert back to dollars
       * Save the transaction ID
       */
      $params['trxn_id'] = $reply['transid'];
      $params['gross_amount'] = $tc_params['amount'] / 100;

      return $params;

    } else {
      /* Otherwise we return the error object */
      return $result;
    }
  }

  /**
   * Gets the recurring billing fields for the TC API
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

  /* Parses a response from TC via the tclink_send() command.
   * @param  $reply array The result of a call to tclink_send().
   * @return mixed self::error() if transaction failed, otherwise returns 0.
   */
  function _getTrustCommerceReply($reply) {

    /* DUPLIATE CODE, please refactor. ~lisa */
    if (!$reply) {
      return self::error(9002, 'Could not initiate connection to payment gateway');
    }

    switch($reply['status']) {
    case self::AUTH_APPROVED:
      // It's all good
      break;
    case self::AUTH_DECLINED:
      // TODO FIXME be more or less specific? 
      // declinetype can be: decline, avs, cvv, call, expiredcard, carderror, authexpired, fraud, blacklist, velocity
      // See TC documentation for more info
      return self::error(9009, "Your transaction was declined: {$reply['declinetype']}");
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

  function _getTrustCommerceFields() {
    // Total amount is from the form contribution field
    $amount = $this->_getParam('total_amount');
    // CRM-9894 would this ever be the case??
    if (empty($amount)) {
      $amount = $this->_getParam('amount');
    }
    $fields = array();
    $fields['custid'] = $this->_getParam('user_name');
    $fields['password'] = $this->_getParam('password');
    $fields['action'] = 'sale';

    // Enable address verification
    $fields['avs'] = 'y';

    $fields['address1'] = $this->_getParam('street_address');
    $fields['zip'] = $this->_getParam('postal_code');

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
    return CRM_Utils_Array::value($field, $this->_params, '');
  }

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
   * This function checks to see if we have the right config values
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

  function cancelSubscriptionURL($entityID = NULL, $entity = NULL) {
    if ($entityID && $entity == 'membership') {
      require_once 'CRM/Contact/BAO/Contact/Utils.php';
      $contactID = CRM_Core_DAO::getFieldValue("CRM_Member_DAO_Membership", $entityID, "contact_id");
      $checksumValue = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, NULL, 'inf');

      return CRM_Utils_System::url('civicrm/contribute/unsubscribe',
        "reset=1&mid={$entityID}&cs={$checksumValue}", TRUE, NULL, FALSE, FALSE
      );
    }

    return ($this->_mode == 'test') ? 'https://test.authorize.net' : 'https://authorize.net';
  }

  function cancelSubscription() {
    $template = CRM_Core_Smarty::singleton();

    $template->assign('subscriptionType', 'cancel');

    $template->assign('apiLogin', $this->_getParam('apiLogin'));
    $template->assign('paymentKey', $this->_getParam('paymentKey'));
    $template->assign('subscriptionId', $this->_getParam('subscriptionId'));

    $arbXML = $template->fetch('CRM/Contribute/Form/Contribution/AuthorizeNetARB.tpl');

    // submit to authorize.net
    $submit = curl_init($this->_paymentProcessor['url_recur']);
    if (!$submit) {
      return self::error(9002, 'Could not initiate connection to payment gateway');
    }

    curl_setopt($submit, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($submit, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));
    curl_setopt($submit, CURLOPT_HEADER, 1);
    curl_setopt($submit, CURLOPT_POSTFIELDS, $arbXML);
    curl_setopt($submit, CURLOPT_POST, 1);
    curl_setopt($submit, CURLOPT_SSL_VERIFYPEER, 0);

    $response = curl_exec($submit);

    if (!$response) {
      return self::error(curl_errno($submit), curl_error($submit));
    }

    curl_close($submit);

    $responseFields = $this->_ParseArbReturn($response);

    if ($responseFields['resultCode'] == 'Error') {
      return self::error($responseFields['code'], $responseFields['text']);
    }

    // carry on cancelation procedure
    return TRUE;
  }
 
  public function install() {
    return TRUE;
  }

  public function uninstall() {
    return TRUE;
  }

}

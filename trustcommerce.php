<?php
/*
 * Copyright (C) 2012
 * Licensed to CiviCRM under the GPL v3 or higher
 *
 * Written and contributed by Ward Vandewege <ward@fsf.org> (http://www.fsf.org)
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
   * Submit a payment using Advanced Integration Method
   *
   * @param  array $params assoc array of input parameters for this transaction
   *
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doDirectPayment(&$params) {
    if (!extension_loaded("tclink")) {
      return self::error(9001, 'TrustCommerce requires that the tclink module is loaded');
    }

    /*
         * recurpayment function does not compile an array & then proces it -
         * - the tpl does the transformation so adding call to hook here
         * & giving it a change to act on the params array
         */

    $newParams = $params;
    if (CRM_Utils_Array::value('is_recur', $params) &&
      $params['contributionRecurID']
    ) {
      CRM_Utils_Hook::alterPaymentProcessorParams($this,
        $params,
        $newParams
      );
    }
    foreach ($newParams as $field => $value) {
      $this->_setParam($field, $value);
    }

    if (CRM_Utils_Array::value('is_recur', $params) &&
      $params['contributionRecurID']
    ) {
      return $this->doRecurPayment($params);
    }

    $postFields = array();
    $tclink = $this->_getTrustCommerceFields();

    // Set up our call for hook_civicrm_paymentProcessor,
    // since we now have our parameters as assigned for the AIM back end.
    CRM_Utils_Hook::alterPaymentProcessorParams($this,
      $params,
      $tclink
    );

    // TrustCommerce will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->_checkDupe($tclink['ticket'])) {
      return self::error(9004, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem. You can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }

    $result = tclink_send($tclink);

    if (!$result) {
      return self::error(9002, 'Could not initiate connection to payment gateway');
    }

    foreach ($result as $field => $value) {
      error_log("result: $field => $value");
    }

    switch($result['status']) {
    case self::AUTH_APPROVED:
      // It's all good
      break;
    case self::AUTH_DECLINED:
      // TODO FIXME be more or less specific? 
      // declinetype can be: decline, avs, cvv, call, expiredcard, carderror, authexpired, fraud, blacklist, velocity
      // See TC documentation for more info
      return self::error(9009, "Your transaction was declined: {$result['declinetype']}");
      break;
    case self::AUTH_BADDATA:
      // TODO FIXME do something with $result['error'] and $result['offender']
      return self::error(9011, "Invalid credit card information. Please re-enter.");
      break;
    case self::AUTH_ERROR:
      return self::error(9002, 'Could not initiate connection to payment gateway');
      break;
    }
    
    // Success

    $params['trxn_id'] = $result['transid'];
    $params['gross_amount'] = $tclink['amount'] / 100;

    return $params;
  }

  /**
   * Submit an Automated Recurring Billing subscription
   *
   * @param  array $params assoc array of input parameters for this transaction
   *
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doRecurPayment(&$params) {
    $payments = $this->_getParam('frequency_interval');
    $cycle = $this->_getParam('frequency_unit');

    /* Sort out our billing scheme */
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
    default:
      return self::error(9001, 'Payment interval not set! Unable to process payment.');
      break;
    }


    $params['authnow'] = 'y';    /* Process this payment `now' */    
    $params['cycle'] = $cycle;   /* The billing cycle in years, months, weeks, or days. */
    $params['payments'] = $payments;


    $tclink = $this->_getTrustCommerceFields();

    // Set up our call for hook_civicrm_paymentProcessor,
    // since we now have our parameters as assigned for the AIM back end.
    CRM_Utils_Hook::alterPaymentProcessorParams($this,
      $params,
      $tclink
    );

    // TrustCommerce will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->_checkDupe($tclink['ticket'])) {
      return self::error(9004, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem. You can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }

    $result = tclink_send($tclink);

    $result = _getTrustCommereceResponse($result);

    if($result == 0) {
      /* Transaction was sucessful */
      $params['trxn_id'] = $result['transid'];         /* Get our transaction ID */
      $params['gross_amount'] = $tclink['amount']/100; /* Convert from cents to dollars */
      return $params;
    } else {
      /* Transaction was *not* successful */
      return $result;
    }
  }

  /* Parses a response from TC via the tclink_send() command.
   * @param  $reply array The result of a call to tclink_send().
   * @return mixed self::error() if transaction failed, otherwise returns 0.
   */
  function _getTrustCommerceResponse($reply) {

    /* DUPLIATE CODE, please refactor. ~lisa */
    if (!$result) {
      return self::error(9002, 'Could not initiate connection to payment gateway');
    }

    switch($result['status']) {
    case self::AUTH_APPROVED:
      // It's all good
      break;
    case self::AUTH_DECLINED:
      // TODO FIXME be more or less specific? 
      // declinetype can be: decline, avs, cvv, call, expiredcard, carderror, authexpired, fraud, blacklist, velocity
      // See TC documentation for more info
      return self::error(9009, "Your transaction was declined: {$result['declinetype']}");
      break;
    case self::AUTH_BADDATA:
      // TODO FIXME do something with $result['error'] and $result['offender']
      return self::error(9011, "Invalid credit card information. Please re-enter.");
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

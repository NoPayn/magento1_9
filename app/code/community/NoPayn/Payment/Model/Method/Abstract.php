<?php

abstract class NoPayn_Payment_Model_Method_Abstract extends Mage_Payment_Model_Method_Abstract
{
    protected $_isGateway              = true;
    protected $_canAuthorize           = false;
    protected $_canCapture             = false;
    protected $_canCapturePartial      = false;
    protected $_canRefund              = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid                = false;
    protected $_canUseInternal         = false;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = false;
    protected $_isInitializeNeeded     = true;

    /** NoPayn API payment method identifier (e.g. 'credit-card') */
    protected $_nopaynMethod = '';

    /**
     * Set initial order state to pending_payment before redirect.
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
        return $this;
    }

    /**
     * Redirect customer to our controller after order placement.
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('nopayn/payment/redirect', ['_secure' => true]);
    }

    public function getNopaynMethod()
    {
        return $this->_nopaynMethod;
    }

    public function getApiKey()
    {
        return Mage::helper('nopayn')->getApiKey($this->getStore());
    }
}

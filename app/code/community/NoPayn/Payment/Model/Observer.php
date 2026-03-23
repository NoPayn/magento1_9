<?php

class NoPayn_Payment_Model_Observer
{
    /**
     * Void authorized (manual capture) transaction when order is cancelled.
     *
     * Event: order_cancel_after
     *
     * @param Varien_Event_Observer $observer
     */
    public function voidOnCancel(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $methodCode = $payment->getMethod();
        if (strpos($methodCode, 'nopayn_') !== 0) {
            return;
        }

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');
        $helper->log('Observer: Order #' . $order->getIncrementId() . ' cancelled, attempting void.');
        $helper->voidAuthorizedOrder($order);
    }

    /**
     * Capture authorized (manual capture) transaction when order moves to processing.
     *
     * Event: sales_order_save_after
     *
     * @param Varien_Event_Observer $observer
     */
    public function captureOnComplete(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PROCESSING) {
            return;
        }

        if ($order->getOrigData('state') === Mage_Sales_Model_Order::STATE_PROCESSING) {
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $methodCode = $payment->getMethod();
        if (strpos($methodCode, 'nopayn_') !== 0) {
            return;
        }

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');
        $helper->log('Observer: Order #' . $order->getIncrementId() . ' moved to processing, attempting capture.');
        $helper->captureAuthorizedOrder($order);
    }
}

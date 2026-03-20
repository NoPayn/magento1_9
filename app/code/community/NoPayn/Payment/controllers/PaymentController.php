<?php

class NoPayn_Payment_PaymentController extends Mage_Core_Controller_Front_Action
{
    /** Exempt webhook from CSRF form-key validation (OpenMage LTS). */
    protected $_publicActions = ['webhook'];

    /**
     * Customer is redirected here after placing an order.
     * Creates NoPayn API order and redirects to the Hosted Payment Page.
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $order   = Mage::getModel('sales/order')->loadByIncrementId(
            $session->getLastRealOrderId()
        );

        if (!$order->getId()) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment        = $order->getPayment();
        $methodInstance = $payment->getMethodInstance();
        $apiKey         = $methodInstance->getApiKey();

        if (!$apiKey) {
            Mage::getSingleton('core/session')->addError(
                Mage::helper('nopayn')->__('NoPayn API key is not configured.')
            );
            $this->_restoreQuoteAndRedirect($session);
            return;
        }

        $amountCents  = (int) round($order->getGrandTotal() * 100);
        $currency     = $order->getOrderCurrencyCode();
        $nopaynMethod = $methodInstance->getNopaynMethod();

        $params = [
            'currency'          => $currency,
            'amount'            => $amountCents,
            'description'       => 'Order #' . $order->getIncrementId(),
            'merchant_order_id' => $order->getIncrementId(),
            'return_url'        => Mage::getUrl('nopayn/payment/success', ['_secure' => true]),
            'failure_url'       => Mage::getUrl('nopayn/payment/cancel', ['_secure' => true]),
            'webhook_url'       => Mage::getUrl('nopayn/payment/webhook', ['_secure' => true]),
            'payment_methods'   => [$nopaynMethod],
            'expiration_period' => 'PT5M',
        ];

        $localeMap = [
            'en_US' => 'en-GB', 'en_GB' => 'en-GB',
            'de_DE' => 'de-DE', 'de_AT' => 'de-DE', 'de_CH' => 'de-DE',
            'nl_NL' => 'nl-NL', 'nl_BE' => 'nl-BE',
            'fr_FR' => 'fr-FR', 'fr_BE' => 'fr-BE',
            'sv_SE' => 'sv-SE',
            'nb_NO' => 'no-NO', 'nn_NO' => 'no-NO',
            'da_DK' => 'da-DK',
        ];
        $locale = Mage::app()->getLocale()->getLocaleCode();
        if (isset($localeMap[$locale])) {
            $params['locale'] = $localeMap[$locale];
        }

        try {
            $api    = Mage::getModel('nopayn/api', $apiKey);
            $result = $api->createOrder($params);

            $nopaynOrderId = $result['id'];

            Mage::helper('nopayn')->saveTransaction(
                $order->getId(),
                $order->getIncrementId(),
                $nopaynOrderId,
                $nopaynMethod,
                $amountCents,
                $currency,
                'new'
            );

            $session->setNopaynOrderId($nopaynOrderId);

            $paymentUrl = null;
            if (!empty($result['transactions'][0]['payment_url'])) {
                $paymentUrl = $result['transactions'][0]['payment_url'];
            } elseif (!empty($result['order_url'])) {
                $paymentUrl = $result['order_url'];
            }

            if (!$paymentUrl) {
                Mage::throwException('No payment URL returned from NoPayn.');
            }

            $this->_redirectUrl($paymentUrl);

        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                Mage::helper('nopayn')->__('Unable to initialize payment. Please try again.')
            );

            if ($order->canCancel()) {
                $order->cancel()->save();
            }

            $this->_restoreQuoteAndRedirect($session);
        }
    }

    /**
     * Customer returns here after successful payment.
     */
    public function successAction()
    {
        $session       = Mage::getSingleton('checkout/session');
        $nopaynOrderId = $session->getNopaynOrderId();

        if (!$nopaynOrderId) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            $helper      = Mage::helper('nopayn');
            $transaction = $helper->getTransactionByNopaynId($nopaynOrderId);

            if (!$transaction) {
                Mage::throwException('Transaction not found.');
            }

            $order = Mage::getModel('sales/order')->load($transaction['order_id']);
            if (!$order->getId()) {
                Mage::throwException('Order not found.');
            }

            $api      = Mage::getModel('nopayn/api');
            $apiOrder = $api->getOrder($nopaynOrderId);
            $status   = $apiOrder['status'];

            $helper->updateTransactionStatus($nopaynOrderId, $status);

            if ($status === 'completed') {
                $helper->completeOrder($order, $nopaynOrderId);
            } elseif (in_array($status, ['cancelled', 'expired', 'error'])) {
                $helper->cancelOrder($order, $nopaynOrderId, $status);
                Mage::getSingleton('core/session')->addError(
                    Mage::helper('nopayn')->__('Payment was not completed.')
                );
                $session->unsNopaynOrderId();
                $this->_restoreQuoteAndRedirect($session);
                return;
            }
            // 'new' or 'processing': leave as pending_payment, webhook will finalize

            $session->unsNopaynOrderId();
            $this->_redirect('checkout/onepage/success');

        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                Mage::helper('nopayn')->__('Unable to verify payment status. Please contact support.')
            );
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Customer returns here after cancelling payment or on payment failure.
     */
    public function cancelAction()
    {
        $session       = Mage::getSingleton('checkout/session');
        $nopaynOrderId = $session->getNopaynOrderId();

        if ($nopaynOrderId) {
            try {
                $helper      = Mage::helper('nopayn');
                $transaction = $helper->getTransactionByNopaynId($nopaynOrderId);

                if ($transaction) {
                    $order = Mage::getModel('sales/order')->load($transaction['order_id']);

                    if ($order->getId() && $order->canCancel()) {
                        $order->cancel();
                        $order->addStatusHistoryComment(
                            'Payment cancelled by customer. NoPayn Order: ' . $nopaynOrderId
                        );
                        $order->save();
                    }

                    $helper->updateTransactionStatus($nopaynOrderId, 'cancelled');
                }
            } catch (Exception $e) {
                Mage::logException($e);
            }

            $session->unsNopaynOrderId();
        }

        $this->_restoreQuoteAndRedirect($session);
    }

    /**
     * Webhook endpoint — NoPayn sends POST here on status changes.
     */
    public function webhookAction()
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!$data || empty($data['order_id'])) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $nopaynOrderId = $data['order_id'];

        try {
            $helper      = Mage::helper('nopayn');
            $transaction = $helper->getTransactionByNopaynId($nopaynOrderId);

            if (!$transaction) {
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            if (in_array($transaction['status'], ['completed', 'cancelled', 'expired'])) {
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            $order = Mage::getModel('sales/order')->load($transaction['order_id']);
            if (!$order->getId()) {
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            $api      = Mage::getModel('nopayn/api');
            $apiOrder = $api->getOrder($nopaynOrderId);
            $status   = $apiOrder['status'];

            $helper->updateTransactionStatus($nopaynOrderId, $status);

            if ($status === 'completed') {
                $helper->completeOrder($order, $nopaynOrderId);
            } elseif (in_array($status, ['cancelled', 'expired', 'error'])) {
                $helper->cancelOrder($order, $nopaynOrderId, $status);
            }

            $this->getResponse()->setHttpResponseCode(200);

        } catch (Exception $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500);
        }
    }

    /**
     * Restore the shopping cart and redirect to cart page.
     */
    protected function _restoreQuoteAndRedirect($session)
    {
        if ($quoteId = $session->getLastQuoteId()) {
            $quote = Mage::getModel('sales/quote')->load($quoteId);
            if ($quote->getId()) {
                $quote->setIsActive(true)->save();
                $session->setQuoteId($quoteId);
            }
        }

        Mage::getSingleton('core/session')->addNotice(
            Mage::helper('nopayn')->__('Your payment was cancelled. Please try again.')
        );
        $this->_redirect('checkout/cart');
    }
}

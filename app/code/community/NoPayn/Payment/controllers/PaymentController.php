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

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');

        $payment        = $order->getPayment();
        $methodInstance = $payment->getMethodInstance();
        $apiKey         = $methodInstance->getApiKey();

        if (!$apiKey) {
            Mage::getSingleton('core/session')->addError(
                $helper->__('NoPayn API key is not configured.')
            );
            $this->_restoreQuoteAndRedirect($session);
            return;
        }

        $amountCents  = (int) round($order->getGrandTotal() * 100);
        $currency     = $order->getOrderCurrencyCode();
        $nopaynMethod = $methodInstance->getNopaynMethod();

        $helper->log('Redirect: Creating order for Magento order #' . $order->getIncrementId()
            . ', method=' . $nopaynMethod . ', amount=' . $amountCents . ' ' . $currency);

        $transactionData = [
            'payment_method'    => $nopaynMethod,
            'expiration_period' => 'PT5M',
        ];

        // Manual capture for credit card
        $captureMode = 'auto';
        if ($nopaynMethod === 'credit-card' && $helper->isManualCaptureEnabled($order->getStoreId())) {
            $transactionData['capture_mode'] = 'manual';
            $captureMode = 'manual';
            $helper->log('Redirect: Manual capture enabled for order #' . $order->getIncrementId());
        }

        // Build order lines from order items
        $orderLines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $unitPriceCents = (int) round($item->getPriceInclTax() * 100);
            $taxPercent     = (int) round($item->getTaxPercent() * 100);

            $orderLines[] = [
                'type'                    => 'physical',
                'name'                    => $item->getName(),
                'quantity'                => (int) $item->getQtyOrdered(),
                'amount'                  => $unitPriceCents,
                'currency'                => $currency,
                'vat_percentage'          => $taxPercent,
                'merchant_order_line_id'  => $item->getSku(),
            ];
        }

        // Add shipping line if applicable
        $shippingAmount = $order->getShippingInclTax();
        if ($shippingAmount > 0) {
            $shippingTaxPercent = 0;
            if ($order->getShippingAmount() > 0) {
                $shippingTaxPercent = (int) round(
                    ($order->getShippingTaxAmount() / $order->getShippingAmount()) * 100 * 100
                );
            }

            $orderLines[] = [
                'type'                    => 'shipping_fee',
                'name'                    => $order->getShippingDescription() ?: 'Shipping',
                'quantity'                => 1,
                'amount'                  => (int) round($shippingAmount * 100),
                'currency'                => $currency,
                'vat_percentage'          => $shippingTaxPercent,
                'merchant_order_line_id'  => 'shipping',
            ];
        }

        $params = [
            'currency'          => $currency,
            'amount'            => $amountCents,
            'description'       => 'Order #' . $order->getIncrementId(),
            'merchant_order_id' => $order->getIncrementId(),
            'return_url'        => Mage::getUrl('nopayn/payment/success', ['_secure' => true]),
            'failure_url'       => Mage::getUrl('nopayn/payment/cancel', ['_secure' => true]),
            'webhook_url'       => Mage::getUrl('nopayn/payment/webhook', ['_secure' => true]),
            'transactions'      => [$transactionData],
            'order_lines'       => $orderLines,
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

            // Extract transaction ID from API response for capture/void
            $transactionId = null;
            if (!empty($result['transactions'][0]['id'])) {
                $transactionId = $result['transactions'][0]['id'];
            }

            $helper->saveTransaction(
                $order->getId(),
                $order->getIncrementId(),
                $nopaynOrderId,
                $nopaynMethod,
                $amountCents,
                $currency,
                'new',
                $captureMode,
                $transactionId
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

            $helper->log('Redirect: Redirecting to payment URL for order #' . $order->getIncrementId());
            $this->_redirectUrl($paymentUrl);

        } catch (Exception $e) {
            Mage::logException($e);
            $helper->log('Redirect: Error for order #' . $order->getIncrementId() . ': ' . $e->getMessage());
            Mage::getSingleton('core/session')->addError(
                $helper->__('Unable to initialize payment. Please try again.')
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

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');
        $helper->log('Success: Customer returned for NoPayn order ' . $nopaynOrderId);

        try {
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

            $helper->log('Success: NoPayn order ' . $nopaynOrderId . ' status: ' . $status);
            $helper->updateTransactionStatus($nopaynOrderId, $status);

            // Store transaction ID if not already saved
            if (empty($transaction['transaction_id']) && !empty($apiOrder['transactions'][0]['id'])) {
                $helper->updateTransactionId($nopaynOrderId, $apiOrder['transactions'][0]['id']);
            }

            if ($status === 'completed') {
                $helper->completeOrder($order, $nopaynOrderId);
            } elseif (in_array($status, ['cancelled', 'expired', 'error'])) {
                $helper->cancelOrder($order, $nopaynOrderId, $status);
                Mage::getSingleton('core/session')->addError(
                    $helper->__('Payment was not completed.')
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
            $helper->log('Success: Error for NoPayn order ' . $nopaynOrderId . ': ' . $e->getMessage());
            Mage::getSingleton('core/session')->addError(
                $helper->__('Unable to verify payment status. Please contact support.')
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

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');

        if ($nopaynOrderId) {
            $helper->log('Cancel: Customer cancelled payment for NoPayn order ' . $nopaynOrderId);

            try {
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
                $helper->log('Cancel: Error for NoPayn order ' . $nopaynOrderId . ': ' . $e->getMessage());
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

        /** @var NoPayn_Payment_Helper_Data $helper */
        $helper = Mage::helper('nopayn');
        $helper->log('Webhook: Received payload: ' . $body);

        if (!$data || empty($data['order_id'])) {
            $helper->log('Webhook: Invalid payload, missing order_id.');
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $nopaynOrderId = $data['order_id'];
        $helper->log('Webhook: Processing NoPayn order ' . $nopaynOrderId);

        try {
            $transaction = $helper->getTransactionByNopaynId($nopaynOrderId);

            if (!$transaction) {
                $helper->log('Webhook: No local transaction found for ' . $nopaynOrderId);
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            if (in_array($transaction['status'], ['completed', 'cancelled', 'expired'])) {
                $helper->log('Webhook: Transaction already in final state: ' . $transaction['status']);
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            $order = Mage::getModel('sales/order')->load($transaction['order_id']);
            if (!$order->getId()) {
                $helper->log('Webhook: Order not found for transaction ' . $nopaynOrderId);
                $this->getResponse()->setHttpResponseCode(200);
                return;
            }

            $api      = Mage::getModel('nopayn/api');
            $apiOrder = $api->getOrder($nopaynOrderId);
            $status   = $apiOrder['status'];

            $helper->log('Webhook: NoPayn order ' . $nopaynOrderId . ' status: ' . $status);
            $helper->updateTransactionStatus($nopaynOrderId, $status);

            // Store transaction ID if not already saved
            if (empty($transaction['transaction_id']) && !empty($apiOrder['transactions'][0]['id'])) {
                $helper->updateTransactionId($nopaynOrderId, $apiOrder['transactions'][0]['id']);
            }

            if ($status === 'completed') {
                $helper->completeOrder($order, $nopaynOrderId);
            } elseif (in_array($status, ['cancelled', 'expired', 'error'])) {
                $helper->cancelOrder($order, $nopaynOrderId, $status);
            }

            $this->getResponse()->setHttpResponseCode(200);

        } catch (Exception $e) {
            Mage::logException($e);
            $helper->log('Webhook: Error for NoPayn order ' . $nopaynOrderId . ': ' . $e->getMessage());
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

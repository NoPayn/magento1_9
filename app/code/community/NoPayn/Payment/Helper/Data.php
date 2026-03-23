<?php

class NoPayn_Payment_Helper_Data extends Mage_Core_Helper_Abstract
{
    const LOG_FILE = 'nopayn_debug.log';

    /**
     * @return string API key
     */
    public function getApiKey($storeId = null)
    {
        return (string) Mage::getStoreConfig('payment/nopayn/api_key', $storeId);
    }

    /**
     * Check if debug logging is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugEnabled($storeId = null)
    {
        return (bool) Mage::getStoreConfig('payment/nopayn/debug_logging', $storeId);
    }

    /**
     * Check if manual capture is enabled for credit card.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isManualCaptureEnabled($storeId = null)
    {
        return (bool) Mage::getStoreConfig('payment/nopayn_creditcard/manual_capture', $storeId);
    }

    /**
     * Write a message to the NoPayn debug log.
     *
     * @param string $message
     */
    public function log($message)
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        Mage::log('NoPayn: ' . $message, null, self::LOG_FILE);
    }

    public function saveTransaction($orderId, $incrementId, $nopaynOrderId, $paymentMethod, $amount, $currency, $status, $captureMode = 'auto', $transactionId = null)
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');

        $connection->insert(
            $resource->getTableName('nopayn_transactions'),
            [
                'order_id'           => $orderId,
                'order_increment_id' => $incrementId,
                'nopayn_order_id'    => $nopaynOrderId,
                'payment_method'     => $paymentMethod,
                'amount'             => $amount,
                'currency'           => $currency,
                'status'             => $status,
                'capture_mode'       => $captureMode,
                'transaction_id'     => $transactionId,
                'created_at'         => Varien_Date::now(),
                'updated_at'         => Varien_Date::now(),
            ]
        );
    }

    public function getTransactionByNopaynId($nopaynOrderId)
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        return $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('nopayn_transactions'))
                ->where('nopayn_order_id = ?', $nopaynOrderId)
        );
    }

    public function getTransactionByOrderId($orderId)
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        return $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('nopayn_transactions'))
                ->where('order_id = ?', $orderId)
        );
    }

    public function updateTransactionStatus($nopaynOrderId, $status)
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');

        $connection->update(
            $resource->getTableName('nopayn_transactions'),
            ['status' => $status, 'updated_at' => Varien_Date::now()],
            ['nopayn_order_id = ?' => $nopaynOrderId]
        );
    }

    /**
     * Update the transaction_id for a NoPayn order.
     *
     * @param string $nopaynOrderId
     * @param string $transactionId
     */
    public function updateTransactionId($nopaynOrderId, $transactionId)
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');

        $connection->update(
            $resource->getTableName('nopayn_transactions'),
            ['transaction_id' => $transactionId, 'updated_at' => Varien_Date::now()],
            ['nopayn_order_id = ?' => $nopaynOrderId]
        );
    }

    /**
     * Transition order to processing after successful payment.
     */
    public function completeOrder(Mage_Sales_Model_Order $order, $nopaynOrderId)
    {
        if ($order->getState() === Mage_Sales_Model_Order::STATE_PROCESSING) {
            return;
        }

        $order->setState(
            Mage_Sales_Model_Order::STATE_PROCESSING,
            true,
            'NoPayn payment completed. Transaction: ' . $nopaynOrderId
        );
        $order->save();
    }

    /**
     * Cancel order after failed/expired/cancelled payment.
     */
    public function cancelOrder(Mage_Sales_Model_Order $order, $nopaynOrderId, $reason)
    {
        if ($order->isCanceled()) {
            return;
        }

        if ($order->canCancel()) {
            $order->cancel();
            $order->addStatusHistoryComment(
                'NoPayn payment ' . $reason . '. Transaction: ' . $nopaynOrderId
            );
            $order->save();
        }
    }

    /**
     * Capture a previously authorized (manual capture) transaction.
     *
     * @param Mage_Sales_Model_Order $order
     * @return bool
     */
    public function captureAuthorizedOrder(Mage_Sales_Model_Order $order)
    {
        $transaction = $this->getTransactionByOrderId($order->getId());

        if (!$transaction) {
            $this->log('Capture: No NoPayn transaction found for order #' . $order->getIncrementId());
            return false;
        }

        if ($transaction['capture_mode'] !== 'manual') {
            $this->log('Capture: Order #' . $order->getIncrementId() . ' is not manual capture, skipping.');
            return false;
        }

        if (empty($transaction['transaction_id'])) {
            $this->log('Capture: No transaction_id stored for order #' . $order->getIncrementId());
            return false;
        }

        if (!in_array($transaction['status'], ['completed', 'processing', 'new'])) {
            $this->log('Capture: Order #' . $order->getIncrementId() . ' status is ' . $transaction['status'] . ', cannot capture.');
            return false;
        }

        try {
            $api    = Mage::getModel('nopayn/api');
            $result = $api->captureTransaction($transaction['nopayn_order_id'], $transaction['transaction_id']);

            $this->log('Capture successful for order #' . $order->getIncrementId() . ': ' . json_encode($result));

            $order->addStatusHistoryComment(
                'NoPayn capture completed. NoPayn Order: ' . $transaction['nopayn_order_id']
                . ', Transaction: ' . $transaction['transaction_id']
            );
            $order->save();

            return true;
        } catch (Exception $e) {
            $this->log('Capture failed for order #' . $order->getIncrementId() . ': ' . $e->getMessage());
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Void a previously authorized (manual capture) transaction.
     *
     * @param Mage_Sales_Model_Order $order
     * @return bool
     */
    public function voidAuthorizedOrder(Mage_Sales_Model_Order $order)
    {
        $transaction = $this->getTransactionByOrderId($order->getId());

        if (!$transaction) {
            $this->log('Void: No NoPayn transaction found for order #' . $order->getIncrementId());
            return false;
        }

        if ($transaction['capture_mode'] !== 'manual') {
            $this->log('Void: Order #' . $order->getIncrementId() . ' is not manual capture, skipping.');
            return false;
        }

        if (empty($transaction['transaction_id'])) {
            $this->log('Void: No transaction_id stored for order #' . $order->getIncrementId());
            return false;
        }

        try {
            $api    = Mage::getModel('nopayn/api');
            $result = $api->voidTransaction(
                $transaction['nopayn_order_id'],
                $transaction['transaction_id'],
                (int) $transaction['amount'],
                'Order #' . $order->getIncrementId() . ' cancelled'
            );

            $this->log('Void successful for order #' . $order->getIncrementId() . ': ' . json_encode($result));

            $order->addStatusHistoryComment(
                'NoPayn void completed. NoPayn Order: ' . $transaction['nopayn_order_id']
                . ', Transaction: ' . $transaction['transaction_id']
            );
            $order->save();

            return true;
        } catch (Exception $e) {
            $this->log('Void failed for order #' . $order->getIncrementId() . ': ' . $e->getMessage());
            Mage::logException($e);
            return false;
        }
    }
}

<?php

class NoPayn_Payment_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * @return string Decrypted API key
     */
    public function getApiKey($storeId = null)
    {
        $encrypted = Mage::getStoreConfig('payment/nopayn/api_key', $storeId);
        if ($encrypted) {
            return Mage::helper('core')->decrypt($encrypted);
        }
        return '';
    }

    public function saveTransaction($orderId, $incrementId, $nopaynOrderId, $paymentMethod, $amount, $currency, $status)
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
}

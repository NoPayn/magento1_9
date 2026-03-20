<?php

class NoPayn_Payment_Model_Api
{
    const BASE_URL        = 'https://api.nopayn.co.uk';
    const TIMEOUT         = 30;
    const CONNECT_TIMEOUT = 10;

    protected $_apiKey;

    public function __construct($apiKey = null)
    {
        if ($apiKey === null) {
            $this->_apiKey = Mage::helper('nopayn')->getApiKey();
        } else {
            $this->_apiKey = $apiKey;
        }
    }

    public function createOrder(array $params)
    {
        return $this->_request('POST', '/v1/orders/', $params);
    }

    public function getOrder($orderId)
    {
        return $this->_request('GET', '/v1/orders/' . urlencode($orderId) . '/');
    }

    public function createRefund($orderId, $amountCents, $description = '')
    {
        return $this->_request('POST', '/v1/orders/' . urlencode($orderId) . '/refunds/', [
            'amount'      => (int) $amountCents,
            'description' => $description,
        ]);
    }

    protected function _request($method, $endpoint, $data = null)
    {
        $url = self::BASE_URL . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERPWD        => $this->_apiKey . ':',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Mage::throwException('NoPayn API connection error: ' . $error);
        }

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = isset($result['error']) ? $result['error'] : ('HTTP ' . $httpCode);
            Mage::throwException('NoPayn API error: ' . $msg);
        }

        return $result;
    }
}

<?php
/**
 * Alipay API.
 *
 * @package blesta
 * @subpackage blesta.components.modules.alipay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AlipayApi
{
    /**
     * @var string The charset with which the request data is encoded
     */
    private $input_charset = 'UTF-8';

    /**
     * @var string Partner ID, composed of 16 digits beginning with 2088
     */
    private $partner;

    /**
     * @var string Signature method, the following are supported. Must be uppercase. DSA, RSA, and MD5
     */
    private $sign_type = 'MD5';

    /**
     * @var string Merchant account signature key
     */
    private $sign_key;

    /**
     * @var bool Post transactions to the Alipay Sandbox environment
     */
    private $dev_mode;

    /**
     * @var array Alipay API error codes
     */
    private $errors = [
        'ILLEGAL_SIGN' => 'Illegal signature',
        'ILLEGAL_SERVICE' => 'Service Parameter is incorrect',
        'ILLEGAL_PARTNER' => 'Incorrect Partner ID',
        'ILLEGAL_SIGN_TYPE' => 'Signature is of wrong type',
        'ILLEGAL_PARTNER_EXTERFACE' => 'Service is not activated for this account',
        'ILLEGAL_DYN_MD5_KEY' => 'Dynamic key information is incorrect',
        'ILLEGAL_ENCRYPT' => 'Encryption is incorrect',
        'ILLEGAL_USER' => 'User ID is incorrect',
        'ILLEGAL_EXTERFACE' => 'Interface configuration is incorrect',
        'ILLEGAL_AGENT' => 'Agency ID is incorrect',
        'ILLEGAL_ARGUMENT' => 'Incorrect parameter',
        'ILLEGAL_CURRENCY' => 'Currency parameter is incorrect',
        'ILLEGAL_TIMEOUT_RULE' => 'Timeout_rule parameter is incorrect',
        'ILLEGAL_SECURITY_PROFILE' => 'Cannot support this kind of encryption',
        'REFUNDMENT_VALID_DATE_EXCEED' => 'Could not refund after the specified refund timeframe.',
        'REPEATED_REFUNDMENT_REQUEST' => 'Duplicated refund request',
        'RETURN_AMOUNT_EXCEED' => 'Refund amount is over the payment amount',
        'CURRENCY_NOT_SAME' => 'Different currency from the payment currency',
        'PURCHASE_TRADE_NOT_EXIST' => 'The payment transaction does not exist'
    ];

    /**
     * Initializes the class.
     *
     * @param string $partner The merchant UID/PID
     * @param string $sign_key The signature key
     * @param bool $dev_mode True to enable the sandbox API
     */
    public function __construct($partner, $sign_key, $dev_mode = false)
    {
        $this->partner = $partner;
        $this->sign_key = $sign_key;
        $this->dev_mode = $dev_mode;
    }

    /**
     * Send a request to the Alipay API.
     *
     * @param string $method Specifies the method to call
     * @param array $params The parameters to include in the api request
     * @return array An array containing the api response
     */
    private function apiRequest($method, array $params = [])
    {
        // Select api url
        if ($this->dev_mode) {
            $url = 'https://openapi.alipaydev.com/gateway.do';
        } else {
            $url = 'https://mapi.alipay.com/gateway.do';
        }

        // Api request settings
        $settings = [
            '_input_charset' => $this->input_charset,
            'service' => $method,
            'partner' => $this->partner,
            'sign_type' => $this->sign_type
        ];

        // Merge the settings with the parameters array and then generate the signature
        $params = array_merge($settings, $params);
        $params['sign'] = $this->buildSignature($params);

        // Make request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);

        // Validate response
        if (strpos($data, 'ILLEGAL_') !== false) {
            $error = trim('ILLEGAL_' . explode("\n", explode('ILLEGAL_', strip_tags($data), 2)[1], 2)[0]);
            throw new Exception(
                (isset($this->errors[$error])
                    ? $this->errors[$error]
                    : 'An internal error occurred, or the server did not respond to the request.')
            );
        }

        return [
            'url' => $url,
            'params' => $params,
            'headers' => $this->parseHeaders($data),
            'response' => $this->parseResponse($data)
        ];
    }

    /**
     * Generates the signature of the request.
     *
     * @param array $params An array contaning the parameters
     * @return mixed The signature or false if an error occurs
     */
    private function buildSignature(&$params)
    {
        // Order the data alphabetically
        $data = ksort($params);

        // Build the signature
        if ($data) {
            // Remove the sign and sign_type parameters
            $data = $params;
            unset($data['sign'], $data['sign_type']);
            $data = urldecode(http_build_query($data));

            // Generate signature
            $signature = md5($data . $this->sign_key);

            return $signature;
        }

        return false;
    }

    /**
     * Builds an array with the HTTP headers of the response.
     *
     * @param $data Raw Alipay API response
     * @return array An array containing the HTTP headers
     */
    private function parseHeaders($data)
    {
        // Remove html and xml response
        if (strpos($data, 'text/html') !== false || strpos($data, 'text/xml') !== false) {
            $data = trim(explode('<', $data, 2)[0]);
        }

        // Build headers array
        $result = [];
        $headers = explode("\n", $data);

        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);

            if (!empty($parts) && count($parts) == 2) {
                $key = strtolower($parts[0]);
                $result[$key] = trim($parts[1]);
            }
        }

        return $result;
    }

    /**
     * Parse the response of the API request.
     *
     * @param $data The raw response from the API
     * @return mixed The parsed response of the API request
     */
    private function parseResponse($data)
    {
        $headers = $this->parseHeaders($data);

        // Parse xml response
        if (strpos($headers['content-type'], 'text/xml') !== false) {
            $xml = '<' . explode('<', $data, 2)[1];
            preg_match_all('/<(.*?)>([^<]+)<\/\\1>/i', $xml, $match);

            $result = [];
            foreach ($match[1] as $x => $y) {
                $result[$y] = $match[2][$x];
            }

            if (isset($result['error'])) {
                $result['error_msg'] = (
                    isset($this->errors[$result['error']])
                        ? $this->errors[$result['error']]
                        : 'An internal error occurred, or the server did not respond to the request.'
                );
            }
            if (isset($result['is_success'])) {
                $result['is_success'] = ($result['is_success'] == 'T' ? true : false);
            }

            return (object) $result;
        }

        // Parse plain response
        if (strpos($headers['content-type'], 'text/plain') !== false) {
            return trim(explode('secure;', $data, 2)[1]);
        }

        return null;
    }

    /**
     * Request a payment.
     *
     * @param array $params An array contaning the following arguments:
     *     - subject: The name of the items. It should not contain special symbols.
     *     - out_trade_no: The unique transaction ID specified by the partner.
     *     - currency: The settlement currency code the merchant specifies in the contract.
     *     - total_fee: The payment amount.
     *     - supplier: Supplier’s name, for page display purpose. (i.e.: Company Name)
     *     - notify_url: The URL for receiving asynchronous notifications after the payment is done. (Optional)
     *     - return_url: After the payment is done, the result is returned to this url via the URL redirect. (Optional)
     * @return stdClass An object containing the request response
     */
    public function requestPayment($params)
    {
        // Force 0 decimals for KRW and JPY
        if ($params['currency'] == 'KRW' || $params['currency'] == 'JPY') {
            $params['total_fee'] = round($params['total_fee'], 0);
        }

        return $this->apiRequest('create_forex_trade', $params);
    }

    /**
     * Request a payment refund.
     *
     * @param array $params An array contaning the following arguments:
     *     - out_return_no: The unique refund ID for refund request.
     *     - out_trade_no: The unique transaction ID for the original payment.
     *     - return_amount: The amount to refund in settlement currency.
     *     - currency: The settlement currency code the merchant specifies in the contract.
     *     - reason: Reason for the refund, for example, out of supply, etc.
     * @return stdClass An object containing the request response
     */
    public function requestRefund($params)
    {
        // Force 0 decimals for KRW and JPY
        if ($params['currency'] == 'KRW' || $params['currency'] == 'JPY') {
            $params['return_amount'] = round($params['return_amount'], 0);
        }

        // Set transaction time
        date_default_timezone_set('Asia/Hong_Kong');
        $params['gmt_return'] = date('omdHis');

        return $this->apiRequest('forex_refund', $params);
    }

    /**
     * Validate a notification is from Alipay Server, ensure the authenticity of the response data.
     *
     * @param array $notify_id The ID of Alipay system’s notification
     * @return bool True if the notification is valid, false otherwise
     */
    public function verifyNotification($notify_id)
    {
        $response = $this->apiRequest('notify_verify', ['notify_id' => $notify_id]);

        return strpos($response['response'], 'true') !== false;
    }
}

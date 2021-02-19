<?php
/**
 * Alipay Gateway.
 *
 * The Alipay API documentation can be found at:
 * https://intl.alipay.com/doc/gr/hx6vzr
 *
 * @package blesta
 * @subpackage blesta.components.gateways.alipay
 * @copyright Copyright (c) 2017, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Alipay extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new merchant gateway.
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this gateway
        Language::loadLang('alipay', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the currency code to be used for all subsequent payments.
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway.
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway.
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        // Verify meta data is valid
        $rules = [
            'merchant_email' => [
                'valid' => [
                    'rule' => ['isEmail', false],
                    'message' => Language::_('Alipay.!error.merchant_email.valid', true)
                ]
            ],
            'merchant_uid' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Alipay.!error.merchant_uid.valid', true)
                ]
            ],
            'signature_key' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Alipay.!error.signature_key.valid', true)
                ]
            ]
        ];

        // Set checkbox if not set
        if (!isset($meta['dev_mode'])) {
            $meta['dev_mode'] = 'false';
        }

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database.
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['merchant_uid', 'signature_key'];
    }

    /**
     * Sets the meta data for this particular gateway.
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form.
     *
     * @param array $contact_info An array of contact info including:
     *  - id The contact ID
     *  - client_id The ID of the client this contact belongs to
     *  - user_id The user ID this contact belongs to (if any)
     *  - contact_type The type of contact
     *  - contact_type_id The ID of the contact type
     *  - first_name The first name on the contact
     *  - last_name The last name on the contact
     *  - title The title of the contact
     *  - company The company name of the contact
     *  - address1 The address 1 line of the contact
     *  - address2 The address 2 line of the contact
     *  - city The city of the contact
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-cahracter country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *  - description The Description of the charge
     *  - return_url The URL to redirect users to after a successful payment
     *  - recur An array of recurring info including:
     *      - start_date The date/time in UTC that the recurring payment begins
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used in
     *          conjunction with term in order to determine the next recurring payment
     * @return mixed A string of HTML markup required to render an authorization and
     *  capture payment form, or an array of HTML markup
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // Load the models required
        Loader::loadModels($this, ['Companies']);

        // Load library methods
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'alipay_api.php');
        $api = new AlipayApi($this->meta['merchant_uid'], $this->meta['signature_key'], $this->meta['dev_mode']);

        // Force 2-decimal places only
        $amount = number_format($amount, 2, '.', '');

        // Get company information
        $company = $this->Companies->get(Configure::get('Blesta.company_id'));

        // Set all invoices to pay
        if (isset($invoice_amounts) && is_array($invoice_amounts)) {
            $invoices = $this->serializeInvoices($invoice_amounts);
        }

        // An array of key/value hidden fields to set for the payment form
        $fields = [
            'subject' => (isset($options['description']) ? $options['description'] : null),
            'out_trade_no' => $contact_info['client_id'] . '@' . (!empty($invoices) ? $invoices : time()),
            'currency' => (isset($this->currency) ? $this->currency : null),
            'total_fee' => (isset($amount) ? $amount : null),
            'supplier' => (isset($company->name) ? $company->name : null),
            'notify_url' => Configure::get('Blesta.gw_callback_url') . Configure::get('Blesta.company_id') . '/alipay/',
            'return_url' => (isset($options['return_url']) ? $options['return_url'] : null)
        ];
        $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($fields), 'input', true);

        // Build payment request
        $request = $api->requestPayment($fields);

        try {
            if (isset($request['headers']['location'])) {
                $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($request), 'output', true);

                return $this->buildForm($request['url'], $request['params']);
            }
            $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($request), 'output', false);

            return null;
        } catch (Exception $e) {
            $this->Input->setErrors(
                ['internal' => ['response' => $e->getMessage()]]
            );
        }
    }

    /**
     * Builds the HTML form.
     *
     * @param string $post_to The URL to post to
     * @param array $fields An array of key/value input fields to set in the form
     * @return string The HTML form
     */
    private function buildForm($post_to, $fields)
    {
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get signature
        $signature = $fields['sign'];
        unset($fields['sign']);

        $this->view->set('post_to', $post_to);
        $this->view->set('fields', $fields);
        $this->view->set('signature', $signature);

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's
     *      original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // Load library methods
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'alipay_api.php');
        $api = new AlipayApi($this->meta['merchant_uid'], $this->meta['signature_key'], $this->meta['dev_mode']);

        $data_parts = explode('@', $post['out_trade_no'], 2);

        // Get client id
        $client_id = $data_parts[0];

        // Get invoices
        $invoices = (isset($data_parts[1]) ? $data_parts[1] : null);
        if (is_numeric($invoices)) {
            $invoices = null;
        }

        // Capture the IPN status, or reject it if invalid
        $response = $api->verifyNotification($post['notify_id']);
        $status = 'error';
        $return_status = false;

        if ($response) {
            switch ($post['trade_status']) {
                case 'TRADE_FINISHED':
                    $status = 'approved';
                    $return_status = true;
                    break;
                case 'TRADE_REFUSE':
                    $status = 'declined';
                    $return_status = true;
                    break;
                case 'TRADE_CANCEL':
                    $status = 'void';
                    $return_status = true;
                    break;
                case 'TRADE_PENDING':
                    $status = 'pending';
                    $return_status = true;
                    break;
            }

            // Print expected response by the Alipay api
            echo 'Success';
        }

        // Log response
        $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($post), 'output', $return_status);

        return [
            'client_id' => $client_id,
            'amount' => (isset($post['total_fee']) ? $post['total_fee'] : null),
            'currency' => (isset($post['currency']) ? $post['currency'] : null),
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => (isset($post['trade_no']) ? $post['trade_no'] : null),
            'invoices' => $this->unserializeInvoices($invoices)
        ];
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        $data_parts = explode('@', $get['out_trade_no'], 2);

        // Get client id
        $client_id = $data_parts[0];

        // Get invoices
        $invoices = (isset($data_parts[1]) ? $data_parts[1] : null);
        if (is_numeric($invoices)) {
            $invoices = null;
        }

        // Get transaction status
        $status = 'error';
        $return_status = false;
        switch ($get['trade_status']) {
            case 'TRADE_FINISHED':
                $status = 'approved';
                $return_status = true;
                break;
            case 'TRADE_REFUSE':
                $status = 'declined';
                $return_status = true;
                break;
            case 'TRADE_CANCEL':
                $status = 'void';
                $return_status = true;
                break;
            case 'TRADE_PENDING':
                $status = 'pending';
                $return_status = true;
                break;
        }

        // Log response
        $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($get), 'output', $return_status);

        return [
            'client_id' => $client_id,
            'amount' => (isset($get['total_fee']) ? $get['total_fee'] : null),
            'currency' => (isset($get['currency']) ? $get['currency'] : null),
            'invoices' => $this->unserializeInvoices($invoices),
            'status' => $status,
            'transaction_id' => (isset($get['trade_no']) ? $get['trade_no'] : null)
        ];
    }

    /**
     * Captures a previously authorized payment.
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction.
     * @param $amount The amount.
     * @param array $invoice_amounts
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function capture($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a payment or authorization.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Refund a payment.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this card
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Serializes an array of invoice info into a string.
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array.
     *
     * @param string $str A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }
}

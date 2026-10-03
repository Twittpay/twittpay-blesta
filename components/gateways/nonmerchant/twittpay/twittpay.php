<?php

/**
 * TwittPay Gateway for Blesta
 * ---------------------------------------------------------------------------
 * A nonmerchant gateway: the customer leaves for the hosted payment page and
 * comes back to Blesta's gateway callback URL, and the gateway also calls that
 * same URL server to server.
 *
 *   buildProcess()  create the payment, send the customer to payment_url
 *   success()       the customer came back in a browser
 *   validate()      the gateway called us with no browser involved
 *
 * Both success() and validate() verify the transaction against the API before
 * they report anything. The return URL carries a status, but anyone can type a
 * URL, so it is never believed.
 *
 * @version 1.0.0
 */
class Twittpay extends NonmerchantGateway
{
    /** @var array The gateway's settings */
    private $meta;

    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input']);

        Language::loadLang('twittpay', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    public function getSettings(array $meta = null)
    {
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView(
            'components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'twittpay' . DS
        );

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        $rules = [
            'api_key' => [
                'valid' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('TwittPay.!error.api_key.valid', true),
                ],
            ],
            'api_url' => [
                'valid' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('TwittPay.!error.api_url.valid', true),
                ],
            ],
            'currency_rate' => [
                'valid' => [
                    'rule'    => ['matches', '/^[0-9]*\.?[0-9]+$/'],
                    'message' => Language::_('TwittPay.!error.currency_rate.valid', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    public function encryptableFields()
    {
        return ['api_key', 'api_url'];
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create the payment and send the customer to the hosted page.
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        Loader::loadModels($this, ['Companies']);

        $invoiceAmount   = round($amount, 2);
        $invoiceCurrency = ($this->currency ?? 'BDT');
        $invoices        = null;

        if (isset($invoice_amounts) && is_array($invoice_amounts)) {
            $invoices = $this->serializeInvoices($invoice_amounts);
        }

        // The gateway charges BDT. Anything else is converted, and the original
        // figures ride along in metadata so the callback can record them.
        $chargeAmount = $this->toBdt($invoiceAmount, $invoiceCurrency);

        $callbackUrl = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id') . '/twittpay/?client_id='
            . (isset($contact_info['client_id']) ? $contact_info['client_id'] : null);

        $data = [
            'cus_name'    => trim(($contact_info['first_name'] ?? '') . ' ' . ($contact_info['last_name'] ?? '')),
            'cus_email'   => $this->clientEmail($contact_info),
            'amount'      => number_format($chargeAmount, 2, '.', ''),
            'success_url' => $callbackUrl,
            'cancel_url'  => ($options['return_url'] ?? $callbackUrl),
            'webhook_url' => $callbackUrl,
            'metadata'    => [
                'customer_id' => (string) ($contact_info['client_id'] ?? ''),
                'invoices'    => (string) $invoices,
                'currency'    => (string) $invoiceCurrency,
                'amount'      => number_format($invoiceAmount, 2, '.', ''),
                'source'      => 'blesta',
            ],
        ];

        $response = $this->apiCall('/api/payment/create', $data);

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            header('Location: ' . $response['payment_url']);
            exit();
        }

        $this->Input->setErrors([
            'api' => [
                'response' => isset($response['message'])
                    ? $response['message']
                    : Language::_('TwittPay.!error.api.response', true),
            ],
        ]);

        return null;
    }

    /**
     * The customer is back in a browser. The URL is a nudge, the verify call is
     * the truth.
     */
    public function success(array $get, array $post)
    {
        return $this->handleNotification($get, $post);
    }

    /**
     * The gateway called us with no browser involved. The webhook is not signed,
     * so it is treated as nothing more than a transaction id to go and verify.
     */
    public function validate(array $get, array $post)
    {
        return $this->handleNotification($get, $post);
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Shared by success() and validate(): find the transaction id, verify it, and
     * report it back to Blesta.
     *
     * @return array|null Blesta's transaction array, or null when there is
     *  nothing to record
     */
    private function handleNotification(array $get, array $post)
    {
        $transactionId = $this->transactionIdFrom($get, $post);

        if ($transactionId === '') {
            return null;
        }

        $verified = $this->apiCall('/api/payment/verify', ['transaction_id' => $transactionId]);

        // A miss answers status 0, a number. Only a real payment carries text.
        $paymentStatus = (isset($verified['status']) && is_string($verified['status']))
            ? strtoupper(trim($verified['status']))
            : '';

        if ($paymentStatus === '') {
            return null;
        }

        $status = 'error';

        if ($paymentStatus === 'COMPLETED') {
            $status = 'approved';
        } elseif ($paymentStatus === 'PENDING') {
            // Sent, not approved by the merchant yet. Blesta records it as
            // pending and updates it when the next notification arrives.
            $status = 'pending';
        } elseif ($paymentStatus === 'ERROR') {
            $status = 'declined';
        }

        $meta = $this->decodeMetadata($verified);

        // Record the invoice's own currency and amount, not the converted BDT.
        $amount   = isset($meta['amount']) ? $meta['amount'] : ($verified['amount'] ?? 0);
        $currency = isset($meta['currency']) && $meta['currency'] !== ''
            ? $meta['currency']
            : ($this->currency ?? 'BDT');

        return [
            'client_id'             => ($meta['customer_id'] ?? ($get['client_id'] ?? null)),
            'amount'                => $amount,
            'currency'              => $currency,
            'invoices'              => $this->unserializeInvoices($meta['invoices'] ?? null),
            'status'                => $status,
            'reference_id'          => null,
            'transaction_id'        => $transactionId,
            'parent_transaction_id' => null,
        ];
    }

    /**
     * The id can arrive three ways: on the return URL, in the webhook's form
     * body, or in a JSON body if something posts one.
     */
    private function transactionIdFrom(array $get, array $post)
    {
        foreach (['transactionId', 'transaction_id'] as $key) {
            if (!empty($get[$key])) {
                return trim((string) $get[$key]);
            }

            if (!empty($post[$key])) {
                return trim((string) $post[$key]);
            }
        }

        $raw = file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /** metadata comes back from verify as a JSON string. */
    private function decodeMetadata(array $verified)
    {
        if (!isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** Convert to BDT with the configured rate. BDT is left alone. */
    private function toBdt($amount, $currency)
    {
        if (strtoupper((string) $currency) === 'BDT') {
            return (float) $amount;
        }

        $rate = isset($this->meta['currency_rate']) ? (float) $this->meta['currency_rate'] : 0;

        if ($rate <= 0) {
            $rate = 1;
        }

        return (float) $amount * $rate;
    }

    /**
     * Scheme and host of the configured API URL. Pasting the full endpoint or a
     * trailing /api still works.
     */
    private function baseUrl()
    {
        $raw    = rtrim(trim((string) ($this->meta['api_url'] ?? '')), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }

    /** One POST to the API. JSON in, array out, and logged either way. */
    private function apiCall($endpoint, array $payload)
    {
        $url = $this->baseUrl() . $endpoint;

        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'API-KEY: ' . ($this->meta['api_key'] ?? ''),
            ],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $this->log($url, json_encode(['error' => $error]), 'output', false);

            return ['status' => 0, 'message' => ($error !== '' ? $error : 'No response from the gateway.')];
        }

        $decoded = json_decode($response, true);
        $success = is_array($decoded) && !empty($decoded['status']);

        $this->log($url, $response, 'output', $success);

        if (!is_array($decoded)) {
            return ['status' => 0, 'message' => 'The gateway sent back something that is not JSON.'];
        }

        return $decoded;
    }

    /** Blesta hands us a client id; the email may need looking up. */
    private function clientEmail(array $contact_info)
    {
        if (!empty($contact_info['email'])) {
            return $contact_info['email'];
        }

        if (empty($contact_info['client_id'])) {
            return 'default@gmail.com';
        }

        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $contact = $this->Record->select(['contacts.email'])
            ->from('contacts')
            ->where('contacts.contact_type', '=', 'primary')
            ->where('contacts.client_id', '=', $contact_info['client_id'])
            ->fetch();

        if ($contact && !empty($contact->email)) {
            return $contact->email;
        }

        return 'default@gmail.com';
    }

    private function serializeInvoices(array $invoices)
    {
        $str = '';

        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return base64_encode($str);
    }

    private function unserializeInvoices($str)
    {
        if (empty($str)) {
            return null;
        }

        $str      = base64_decode($str);
        $invoices = [];
        $temp     = explode('|', $str);

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

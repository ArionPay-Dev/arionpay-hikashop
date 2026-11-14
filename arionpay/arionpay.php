<?php
/**
 * ArionPay crypto payments for HikaShop (Joomla 4 and 5).
 *
 * The customer pays on the ArionPay payment page; the money goes straight to the merchant's own wallet. An order is
 * confirmed only by a signed invoice.paid webhook, or a signed read of the invoice when the customer comes back, for
 * the same amount and currency as the order.
 *
 * @license MIT
 */
defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/lib/arionpay-php/autoload.php';

class plgHikashoppaymentArionpay extends hikashopPaymentPlugin
{
    const VERSION = '1.0.0';
    /** Invoice statuses (signed read) that mean "a payment is on its way". */
    const OPEN = ['pending', 'paid_partial', 'claim_pending'];
    const MAX_BODY_BYTES = 262144;

    public $multiple = true;
    public $name = 'arionpay';
    public $doc_form = 'arionpay';
    public $doc_listing = 'arionpay';
    public $accepted_currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];
    /** HikaShop refuses notifications while one of these settings is empty. */
    public $notification_required_params = ['api_key', 'api_secret', 'webhook_secret'];

    public $pluginConfig = [
        'api_key' => ['API key (starts with pk_)', 'input'],
        'api_secret' => ['API secret (starts with sk_)', 'arionpay_secret'],
        'webhook_secret' => ['Webhook signing secret (starts with whsec_)', 'arionpay_secret'],
        'test_mode' => ['Test mode (test invoices, no real money)', 'boolean', 1],
        'pending_status' => ['Order status while waiting for payment', 'orderstatus'],
        'verified_status' => ['Order status when paid', 'orderstatus'],
        'webhook_info' => ['Payment updates (webhook)', 'html', ''],
        'base_url' => ['ArionPay address (leave empty)', 'input'],
        'debug' => ['DEBUG', 'boolean', 0],
    ];

    // ------------------------------------------------------------------ settings

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'Crypto (Bitcoin, USDT and more)';
        $element->payment_description = 'Pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. You will be taken to the secure ArionPay payment page.';
        $element->payment_params->test_mode = 1;
        $element->payment_params->pending_status = 'created';
        $element->payment_params->verified_status = 'confirmed';
        $element->payment_params->debug = 0;
    }

    public function onPaymentConfiguration(&$element)
    {
        $this->pluginConfig['webhook_info'][2] = 'Nothing to paste into ArionPay: every invoice tells ArionPay to send its payment updates to<br/><code>'
            . htmlspecialchars($this->notifyUrl(), ENT_QUOTES, 'UTF-8') . '</code><br/>In your ArionPay store settings, keep <b>Old ArionPay (v1) integration compatibility</b> off.';
        parent::onPaymentConfiguration($element);
    }

    /** Secrets are never shown again: an empty field keeps the saved value. */
    public function pluginConfigDisplay($fieldType, $data, $type, $paramsType, $key, $element)
    {
        if ($fieldType !== 'arionpay_secret') {
            return '';
        }
        $saved = !empty($element->$paramsType->$key);
        return '<input type="password" autocomplete="new-password" id="data_' . $type . '_' . $paramsType . '_' . $key . '" name="data[' . $type . '][' . $paramsType . '][' . $key . ']" value="" placeholder="'
            . ($saved ? 'Saved. Leave empty to keep it.' : '') . '"/>';
    }

    public function onPaymentConfigurationSave(&$element)
    {
        $old = null;
        if (!empty($element->payment_id)) {
            $paymentClass = hikashop_get('class.payment');
            $old = $paymentClass->get((int) $element->payment_id);
        }
        $p =& $element->payment_params;
        foreach (['api_key', 'api_secret', 'webhook_secret', 'base_url'] as $k) {
            $p->$k = trim((string) @$p->$k);
        }
        foreach (['api_secret', 'webhook_secret'] as $k) {
            if ($p->$k === '' && !empty($old->payment_params->$k)) {
                $p->$k = $old->payment_params->$k;
            }
        }
        // HikaShop saves whatever this returns, so a value that fails the check is put back to the saved one.
        $app = \Joomla\CMS\Factory::getApplication();
        $checks = ['api_key' => '/^pk_[A-Za-z0-9_-]{8,64}$/', 'api_secret' => '/^sk_/', 'webhook_secret' => '/^whsec_/', 'base_url' => '#^https?://#i'];
        $names = ['api_key' => 'API key', 'api_secret' => 'API secret', 'webhook_secret' => 'Webhook signing secret', 'base_url' => 'ArionPay address'];
        $ok = true;
        foreach ($checks as $k => $re) {
            if ($p->$k !== '' && !preg_match($re, $p->$k)) {
                $p->$k = isset($old->payment_params->$k) ? (string) $old->payment_params->$k : '';
                $app->enqueueMessage('ArionPay: the ' . $names[$k] . ' does not look right, so it was not saved. Copy it again from your ArionPay dashboard'
                    . ($k === 'base_url' ? ' (or leave the address empty).' : '.'), 'error');
                $ok = false;
            }
        }
        unset($p->webhook_info);
        return $ok;
    }

    public function onBeforeOrderCreate(&$order, &$do)
    {
        if (parent::onBeforeOrderCreate($order, $do) === true) {
            return true;
        }
        if (empty($this->payment_params->api_key) || empty($this->payment_params->api_secret)) {
            $this->app->enqueueMessage('The crypto payment method is not set up yet. Please choose another payment method.', 'error');
            $do = false;
        }
    }

    // ------------------------------------------------------------------ checkout

    /** The order is placed: send the customer to the ArionPay payment page (also used by "Pay now" on unpaid orders). */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);
        $this->arionpay_url = '';
        $this->arionpay_error = '';
        $this->arionpay_done_url = $this->afterEndUrl($order);
        try {
            $row = $this->prepareRow($order, $method_id);
            $url = $this->paymentPageUrl($row);
            if ($url === null) {
                $this->arionpay_error = 'This order is already paid. Thank you!';
            } else {
                $this->arionpay_url = $url;
            }
        } catch (\Throwable $e) {
            $this->writeToLog('ArionPay: could not start the payment for order ' . (int) $order->order_id . ': ' . $e->getMessage());
            $this->arionpay_error = 'The crypto payment could not be started right now. Please try again in a minute.';
        }
        return $this->showPage('end');
    }

    /**
     * The open ArionPay invoice of this order when it still fits, otherwise a new one. Null when ArionPay already has the
     * order as paid (it is then confirmed here too).
     */
    private function paymentPageUrl($row)
    {
        $client = $this->client($this->payment_params);
        $attempt = max(1, (int) $row->attempt);
        if (!empty($row->invoice_id)) {
            try {
                $read = $client->getInvoice($row->invoice_id);
            } catch (\ArionPay\ApiException $e) {
                if ($e->getStatus() !== 404) {
                    throw $e;
                }
                $read = ['status' => 'missing', 'raw' => []];
            }
            if ($read['status'] === 'paid') {
                $this->confirmPaid((int) $row->order_id, $read, 'signed read');
                return null;
            }
            $expires = isset($read['raw']['expiresAt']) ? strtotime((string) $read['raw']['expiresAt']) : 0;
            if (in_array($read['status'], self::OPEN, true) || ($read['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
                return $row->invoice_url;
            }
            $attempt++;
        }
        $test = (bool) $row->test;
        for ($tries = 0; $tries < 20; $tries++, $attempt++) {
            $created = $client->createInvoice([
                'amount' => $row->amount,
                'currency' => $row->currency,
                'orderId' => (string) $row->order_number,
                'description' => mb_substr(\Joomla\CMS\Factory::getConfig()->get('sitename') . ' order ' . $row->order_number, 0, 500),
                'successUrl' => $this->returnUrl((int) $row->order_id),
                'cancelUrl' => $this->returnUrl((int) $row->order_id) . '&cancel=1',
                'callbackUrl' => $this->notifyUrl(),
                'metadata' => [
                    'hikashop_order_id' => (int) $row->order_id,
                    'hikashop_order_number' => (string) $row->order_number,
                    'hikashop_amount' => (string) $row->amount,
                    'hikashop_currency' => (string) $row->currency,
                    'hikashop_site' => $this->siteId(),
                    'platform' => 'hikashop',
                ],
                'idempotencyKey' => 'hk-' . $this->siteId() . '-' . (int) $row->order_id . '-' . str_replace('.', '', (string) $row->amount) . $row->currency . ($test ? '-test' : '') . '-' . $attempt,
                'test' => $test,
            ]);
            if ($created['status'] === 'paid') {
                return null;
            }
            $expires = $created['expiresAt'] ? strtotime((string) $created['expiresAt']) : 0;
            if (in_array($created['status'], self::OPEN, true) || ($created['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
                $this->updateRow((int) $row->order_id, ['invoice_id' => $created['id'], 'invoice_url' => $created['invoiceUrl'], 'attempt' => $attempt]);
                return $created['invoiceUrl'];
            }
        }
        throw new \RuntimeException('Too many finished ArionPay invoices for this order.');
    }

    /** One row per order with its ArionPay invoice. The amount and mode are fixed when the order is first sent to ArionPay. */
    private function prepareRow($order, $method_id)
    {
        $this->ensureTable();
        $row = $this->row((int) $order->order_id);
        $currency = strtoupper((string) $this->currency->currency_code);
        $amount = number_format(round((float) $order->order_full_price, 2), 2, '.', '');
        if (!in_array($currency, $this->accepted_currencies, true)) {
            throw new \RuntimeException('ArionPay does not take payments in ' . $currency . '.');
        }
        if ($row && ($row->amount !== $amount || $row->currency !== $currency)) {
            // The order total changed (edited in the back office): the next invoice uses the new total.
            $this->updateRow((int) $order->order_id, ['amount' => $amount, 'currency' => $currency, 'invoice_id' => '', 'invoice_url' => '', 'attempt' => (int) $row->attempt + 1]);
            $row = $this->row((int) $order->order_id);
        }
        if (!$row) {
            $db = \Joomla\CMS\Factory::getDbo();
            $q = $db->getQuery(true)->insert($db->quoteName('#__hikashop_arionpay'))
                ->columns($db->quoteName(['order_id', 'order_number', 'payment_id', 'amount', 'currency', 'test', 'attempt', 'invoice_id', 'invoice_url', 'paid_invoice', 'seen', 'created']))
                ->values(implode(',', [(int) $order->order_id, $db->quote((string) $order->order_number), (int) $method_id, $db->quote($amount), $db->quote($currency),
                    $this->isTest($this->payment_params) ? 1 : 0, 1, $db->quote(''), $db->quote(''), $db->quote(''), $db->quote('[]'), time()]));
            $db->setQuery($q)->execute();
            $row = $this->row((int) $order->order_id);
        }
        return $row;
    }

    // ------------------------------------------------------------------ notifications

    /** index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=arionpay: ArionPay webhooks and the customer's return. */
    public function onPaymentNotification(&$statuses)
    {
        if (!empty($_SERVER['HTTP_ARIONPAY_SIGNATURE'])) {
            $this->handleWebhook((string) $_SERVER['HTTP_ARIONPAY_SIGNATURE']);
        }
        if (hikaInput::get()->getInt('arionpay_pay', 0)) {
            $this->handlePayAgain();
        }
        $this->handleReturn();
        return false;
    }

    /** Signed "Pay with crypto" link for an unpaid order: the open invoice again, or a new one when it expired. */
    private function handlePayAgain()
    {
        $app = \Joomla\CMS\Factory::getApplication();
        $orderId = hikaInput::get()->getInt('order_id', 0);
        if (!$orderId || !hash_equals($this->returnKey($orderId), hikaInput::get()->getString('k', ''))) {
            $app->redirect(\Joomla\CMS\Uri\Uri::root());
        }
        $this->ensureTable();
        $row = $this->row($orderId);
        $dbOrder = $this->getOrder($orderId);
        if (!$row || empty($dbOrder) || !$this->pluginParams((int) $row->payment_id)) {
            $app->redirect(\Joomla\CMS\Uri\Uri::root());
        }
        $this->payment_params = $this->plugin_params;
        $config = hikashop_config();
        $waiting = array_unique(array_filter([(string) @$this->payment_params->pending_status, (string) $config->get('order_created_status', 'created'), 'created']));
        if (!in_array((string) $dbOrder->order_status, $waiting, true)) {
            $app->enqueueMessage('This order is no longer waiting for payment.', 'message');
            $app->redirect($this->afterEndUrl($dbOrder));
        }
        try {
            $url = $this->paymentPageUrl($row);
        } catch (\Throwable $e) {
            $this->writeToLog('ArionPay: could not reopen the payment for order ' . $orderId . ': ' . $e->getMessage());
            $app->enqueueMessage('The crypto payment could not be started right now. Please try again in a minute.', 'error');
            $app->redirect($this->afterEndUrl($dbOrder));
        }
        if ($url === null) {
            $app->enqueueMessage('Thank you! Your crypto payment is confirmed.', 'message');
            $app->redirect($this->afterEndUrl($dbOrder));
        }
        $app->redirect($url);
    }

    private function handleWebhook($header)
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            $this->respond(413, ['error' => 'too large']);
        }
        $this->ensureTable();
        $peek = json_decode($raw, true);
        $meta = (array) ($peek['data']['invoice']['metadata'] ?? []);
        $orderId = (int) ($meta['hikashop_order_id'] ?? 0);
        $row = $orderId ? $this->row($orderId) : null;
        // The secret is the one of the order's payment method (or of any ArionPay method for "Send test webhook").
        $paymentId = $row ? (int) $row->payment_id : $this->firstPaymentId();
        if (!$paymentId || !$this->pluginParams($paymentId)) {
            $this->respond(200, ['received' => true, 'ignored' => 'no ArionPay payment method']);
        }
        $this->payment_params = $this->plugin_params;
        if (empty($this->payment_params->webhook_secret)) {
            $this->writeToLog('ArionPay webhook refused: the webhook signing secret of payment method ' . $paymentId . ' is empty.');
            $this->respond(401, ['error' => 'webhook secret not set']);
        }
        try {
            $event = \ArionPay\Webhook::verify($raw, $header, trim((string) $this->payment_params->webhook_secret));
        } catch (\ArionPay\SignatureException $e) {
            $this->writeToLog('ArionPay webhook refused: ' . $e->getMessage());
            $this->respond(401, ['error' => 'invalid signature']);
        }
        if (\ArionPay\Webhook::isTestEvent($event)) {
            $this->writeToLog('ArionPay test webhook received (' . $event['type'] . '). Nothing changed.');
            $this->respond(200, ['received' => true, 'test' => true]);
        }
        $invoice = $event['data']['invoice'];
        if (!$row || ($meta['hikashop_site'] ?? '') !== $this->siteId()) {
            $this->respond(200, ['received' => true, 'ignored' => 'not an order of this shop']);
        }
        if ((bool) ($invoice['test'] ?? false) !== (bool) $row->test) {
            $this->respond(409, ['error' => 'test/live mismatch']);
        }
        if ($event['type'] === 'invoice.paid') {
            $result = $this->confirmPaid($orderId, $invoice, 'webhook');
            $this->respond($result === 'locked' ? 503 : 200, ['received' => true, 'result' => $result]);
        }
        $texts = [
            'invoice.pending' => 'ArionPay: payment seen for invoice %s, waiting for network confirmations.',
            'invoice.partial' => 'ArionPay: partial payment for invoice %s. The order is confirmed only when it is fully paid.',
            'invoice.needs_review' => 'ArionPay flagged invoice %s for review (Payment Radar). Decide in your ArionPay dashboard.',
            'invoice.expired' => 'ArionPay invoice %s expired without full payment. A late payment still confirms the order.',
        ];
        if (isset($texts[$event['type']])) {
            $this->noteOnce($row, (string) $event['id'], (!empty($invoice['test']) ? '[TEST] ' : '') . sprintf($texts[$event['type']], $invoice['id']));
        }
        $this->respond(200, ['received' => true]);
    }

    /** The customer came back from the ArionPay page: check the invoice with ArionPay (never trust the link), then show the order. */
    private function handleReturn()
    {
        $input = hikaInput::get();
        $orderId = $input->getInt('order_id', 0);
        $key = $input->getString('k', '');
        $app = \Joomla\CMS\Factory::getApplication();
        if (!$orderId || !hash_equals($this->returnKey($orderId), $key)) {
            $app->redirect(\Joomla\CMS\Uri\Uri::root());
        }
        $this->ensureTable();
        $row = $this->row($orderId);
        $dbOrder = $this->getOrder($orderId);
        if (!$row || empty($dbOrder) || !$this->pluginParams((int) $row->payment_id)) {
            $app->redirect(\Joomla\CMS\Uri\Uri::root());
        }
        $this->payment_params = $this->plugin_params;
        $status = 'unknown';
        if (!empty($row->invoice_id)) {
            try {
                $read = $this->client($this->payment_params)->getInvoice($row->invoice_id);
                $status = $read['status'];
                if ($status === 'paid' && (bool) $read['test'] === (bool) $row->test) {
                    $this->confirmPaid($orderId, $read, 'signed read on return');
                }
            } catch (\Throwable $e) {
                $this->writeToLog('ArionPay: could not read invoice ' . $row->invoice_id . ': ' . $e->getMessage());
            }
        }
        if ($status === 'paid') {
            $app->enqueueMessage('Thank you! Your crypto payment is confirmed.', 'message');
        } elseif (in_array($status, self::OPEN, true)) {
            $app->enqueueMessage('Your payment is on its way. The order is confirmed as soon as the network confirms it.', 'message');
        } else {
            $app->enqueueMessage('We have not received the crypto payment for this order yet. <a class="btn btn-primary btn-sm" id="arionpay_pay_again" href="'
                . htmlspecialchars($this->payAgainUrl($orderId), ENT_QUOTES, 'UTF-8') . '">Pay with crypto</a>', 'warning');
        }
        $app->redirect($this->afterEndUrl($dbOrder));
    }

    /**
     * Confirm the order for a paid ArionPay invoice. Safe to call many times (under a database lock).
     * Returns confirmed | duplicate | already_done | mismatch | not_waiting | locked.
     */
    private function confirmPaid($orderId, array $invoice, $source)
    {
        $row = $this->row($orderId);
        $meta = (array) ($invoice['metadata'] ?? []);
        $paid = is_numeric($invoice['amountFiat'] ?? null) ? number_format((float) $invoice['amountFiat'], 2, '.', '') : '';
        if (!$row || (int) ($meta['hikashop_order_id'] ?? 0) !== (int) $orderId || ($meta['hikashop_site'] ?? '') !== $this->siteId()
            || $paid !== (string) $row->amount || strtoupper((string) ($invoice['currency'] ?? '')) !== strtoupper((string) $row->currency)) {
            if ($row) {
                $this->noteOnce($row, 'mismatch-' . ($invoice['id'] ?? ''), 'ArionPay invoice ' . ($invoice['id'] ?? '') . ' is paid, but its amount or currency does not match this order. NOT confirmed: check it in your ArionPay dashboard.', true);
            }
            return 'mismatch';
        }
        $db = \Joomla\CMS\Factory::getDbo();
        $lock = 'hk_arionpay_' . (int) $orderId;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 20)');
        if ((string) $db->loadResult() !== '1') {
            return 'locked';
        }
        try {
            $row = $this->row($orderId);
            $dbOrder = $this->getOrder($orderId);
            if (!empty($row->paid_invoice)) {
                if ($row->paid_invoice === $invoice['id']) {
                    return 'duplicate';
                }
                $this->noteOnce($row, 'again-' . $invoice['id'], 'Another ArionPay invoice (' . $invoice['id'] . ') for this order was paid too. Possible double payment: check your wallet and refund it.', true);
                return 'already_done';
            }
            $config = hikashop_config();
            $waiting = array_unique(array_filter([(string) @$this->payment_params->pending_status, (string) $config->get('order_created_status', 'created'), 'created', 'pending']));
            if (empty($dbOrder) || !in_array((string) $dbOrder->order_status, $waiting, true)) {
                $this->noteOnce($row, 'state-' . $invoice['id'], 'ArionPay invoice ' . $invoice['id'] . ' is paid, but the order status is "' . @$dbOrder->order_status . '". NOT changed: check it.', true);
                return 'not_waiting';
            }
            $this->updateRow($orderId, ['paid_invoice' => $invoice['id']]);
            $comment = (!empty($invoice['test']) ? '[TEST - no real money] ' : '') . 'ArionPay invoice ' . $invoice['id'] . ' is paid (' . $paid . ' ' . $row->currency . ')';
            if (!empty($invoice['asset'])) {
                $comment .= '; ' . trim(($invoice['paidCrypto'] ?? '') . ' ' . $invoice['asset'] . (!empty($invoice['network']) ? ' on ' . $invoice['network'] : ''));
            }
            foreach (['late' => 'paid late', 'underpaidWithinTolerance' => 'slightly under, inside your payment tolerance', 'overpaid' => 'overpaid', 'manual' => 'accepted by hand in ArionPay'] as $flag => $text) {
                if (!empty($invoice[$flag])) {
                    $comment .= '; ' . $text;
                }
            }
            $history = new stdClass();
            $history->notified = 1;
            $history->amount = $paid . $row->currency;
            $history->data = $comment . ' [' . $source . '].';
            $status = !empty($this->payment_params->verified_status) ? $this->payment_params->verified_status : 'confirmed';
            $email = new stdClass();
            $email->subject = 'ArionPay: order ' . $row->order_number . ' is paid';
            $email->body = $comment . ".\r\n\r\n" . \Joomla\CMS\Uri\Uri::root() . 'administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=' . (int) $orderId;
            $oid = (int) $orderId;
            $this->modifyOrder($oid, $status, $history, $email);
            return 'confirmed';
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
            $db->loadResult();
        }
    }

    // ------------------------------------------------------------------ helpers

    private function client($params)
    {
        $base = trim((string) @$params->base_url);
        return new \ArionPay\Client(trim((string) @$params->api_key), trim((string) @$params->api_secret), [
            'baseUrl' => $base !== '' ? $base : \ArionPay\Client::DEFAULT_BASE_URL,
            'appInfo' => 'hikashop/' . (defined('HIKASHOP_VERSION') ? HIKASHOP_VERSION : '?') . ' arionpay-hikashop/' . self::VERSION,
        ]);
    }

    private function isTest($params)
    {
        return !isset($params->test_mode) || (string) $params->test_mode !== '0';
    }

    private function notifyUrl()
    {
        return \Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=arionpay&tmpl=component';
    }

    private function returnKey($orderId)
    {
        return substr(hash_hmac('sha256', 'hikashop-arionpay-return|' . (int) $orderId, (string) \Joomla\CMS\Factory::getConfig()->get('secret')), 0, 32);
    }

    private function returnUrl($orderId)
    {
        return $this->notifyUrl() . '&arionpay_return=1&order_id=' . (int) $orderId . '&k=' . $this->returnKey($orderId);
    }

    private function payAgainUrl($orderId)
    {
        return $this->notifyUrl() . '&arionpay_pay=1&order_id=' . (int) $orderId . '&k=' . $this->returnKey($orderId);
    }

    /** HikaShop's thank-you / order page; guests get there with the order token. */
    private function afterEndUrl($order)
    {
        $token = !empty($order->order_token) ? '&order_token=' . urlencode($order->order_token) : '';
        return \Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . (int) $order->order_id . $token;
    }

    private function ensureTable()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $db = \Joomla\CMS\Factory::getDbo();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__hikashop_arionpay') . ' (
            `order_id` INT UNSIGNED NOT NULL,
            `order_number` VARCHAR(255) NOT NULL DEFAULT \'\',
            `payment_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `amount` VARCHAR(32) NOT NULL DEFAULT \'\',
            `currency` CHAR(3) NOT NULL DEFAULT \'\',
            `test` TINYINT(1) NOT NULL DEFAULT 0,
            `attempt` INT NOT NULL DEFAULT 1,
            `invoice_id` VARCHAR(64) NOT NULL DEFAULT \'\',
            `invoice_url` VARCHAR(500) NOT NULL DEFAULT \'\',
            `paid_invoice` VARCHAR(64) NOT NULL DEFAULT \'\',
            `seen` TEXT NULL,
            `created` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`order_id`)
        ) DEFAULT CHARSET=utf8mb4')->execute();
        $done = true;
    }

    private function row($orderId)
    {
        $db = \Joomla\CMS\Factory::getDbo();
        $db->setQuery('SELECT * FROM ' . $db->quoteName('#__hikashop_arionpay') . ' WHERE order_id = ' . (int) $orderId);
        return $db->loadObject();
    }

    private function updateRow($orderId, array $values)
    {
        $db = \Joomla\CMS\Factory::getDbo();
        $sets = [];
        foreach ($values as $k => $v) {
            $sets[] = $db->quoteName($k) . ' = ' . $db->quote((string) $v);
        }
        $db->setQuery('UPDATE ' . $db->quoteName('#__hikashop_arionpay') . ' SET ' . implode(', ', $sets) . ' WHERE order_id = ' . (int) $orderId)->execute();
    }

    /** One order-history entry per key (event id); the order status does not change. */
    private function noteOnce($row, $key, $text, $alertAdmin = false)
    {
        $seen = json_decode((string) $row->seen, true) ?: [];
        if (in_array($key, $seen, true)) {
            return;
        }
        $seen[] = $key;
        $this->updateRow((int) $row->order_id, ['seen' => json_encode(array_slice($seen, -100))]);
        $history = new stdClass();
        $history->notified = 0;
        $history->data = $text;
        $email = null;
        if ($alertAdmin) {
            $email = new stdClass();
            $email->subject = 'ArionPay: order ' . $row->order_number . ' needs your attention';
            $email->body = $text . "\r\n\r\n" . \Joomla\CMS\Uri\Uri::root() . 'administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=' . (int) $row->order_id;
        }
        $oid = (int) $row->order_id;
        $this->modifyOrder($oid, null, $history, $email);
    }

    private function firstPaymentId()
    {
        $db = \Joomla\CMS\Factory::getDbo();
        $db->setQuery('SELECT payment_id FROM ' . $db->quoteName('#__hikashop_payment') . ' WHERE payment_type = ' . $db->quote('arionpay') . ' AND payment_published = 1 ORDER BY payment_id', 0, 1);
        return (int) $db->loadResult();
    }

    /** Random id of this shop (made once), so two shops on one ArionPay store never touch each other's orders. */
    private function siteId()
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $db = \Joomla\CMS\Factory::getDbo();
        $where = ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('hikashoppayment') . ' AND element = ' . $db->quote('arionpay');
        $db->setQuery('SELECT ' . $db->quoteName('params') . ' FROM #__extensions' . $where);
        $params = json_decode((string) $db->loadResult(), true) ?: [];
        if (empty($params['site_id']) || !preg_match('/^[a-f0-9]{12}$/', $params['site_id'])) {
            $params['site_id'] = bin2hex(random_bytes(6));
            $db->setQuery('UPDATE #__extensions SET ' . $db->quoteName('params') . ' = ' . $db->quote(json_encode($params)) . $where)->execute();
        }
        return $id = $params['site_id'];
    }

    private function respond($status, array $body)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body);
        \Joomla\CMS\Factory::getApplication()->close();
    }
}

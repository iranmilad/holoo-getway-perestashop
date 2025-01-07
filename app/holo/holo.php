<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Holo extends Module
{
    public function __construct()
    {
        $this->name = 'holo';
        $this->version = '1.2.0';
        $this->author = 'milad kazemi';
        $this->tab = 'administration';
        $this->ps_versions_compliancy = ['min' => '1.7.6.8', 'max' => '1.7.6.8'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Holo Web Service');
        $this->description = $this->l('Sends a webhook when an order is finalized and paid.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && Configuration::updateValue('WEBHOOK_ORDER_ENABLED', false);
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('WEBHOOK_ORDER_ENABLED')
            && Configuration::deleteByName('WEBHOOK_ORDER_URL');
    }

    public function hookActionValidateOrder($params)
    {
        if (!Configuration::get('WEBHOOK_ORDER_ENABLED')) {
            return;
        }

        $order = $params['order'];
        $paidStatus = Configuration::get('PS_OS_PAYMENT');
        if ($order->current_state != $paidStatus) {
            return;
        }

        $customer = new Customer($order->id_customer);
        $address = new Address($order->id_address_delivery);
        $currency = new Currency($order->id_currency);

        // دریافت اطلاعات پرداخت
        $orderPayments = $order->getOrderPayments();
        $paymentDetails = [];
        foreach ($orderPayments as $payment) {
            $paymentDetails[] = [
                'id' => $payment->id,
                'method' => $payment->payment_method,
            ];
        }

        // ساخت داده‌های سفارش
        $orderData = [
            'order_id' => $order->id,
            'total_price' => $order->total_paid,
            'currency' => $currency->iso_code,
            'created_at' => $order->date_add,
            'payment_method' => $order->payment,
            'payment_details' => $paymentDetails, // اضافه کردن اطلاعات پرداخت
            'customer' => [
                'id' => $customer->id, // اضافه کردن customer_id
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
                'email' => $customer->email,
                'phone' => $address->phone ?: $address->phone_mobile,
                'address' => $address->address1 . ' ' . $address->address2,
                'city' => $address->city,
                'state' => State::getNameById($address->id_state),
                'postcode' => $address->postcode,
                'country' => Country::getNameById($order->id_lang, $address->id_country),
            ],
            'items' => [],
        ];

        foreach ($order->getProducts() as $product) {
            $orderData['items'][] = [
                'product_name' => $product['product_name'],
                'product_reference' => $product['product_reference'],
                'price' => $product['unit_price_tax_incl'],
                'quantity' => $product['product_quantity'],
                'total' => $product['unit_price_tax_incl'] * $product['product_quantity'],
            ];
        }

        // ارسال داده‌ها به وب‌هوک
        $webhookUrl = Configuration::get('WEBHOOK_ORDER_URL');
        $response = $this->sendWebhook($webhookUrl, $orderData);

        if (!$response) {
            PrestaShopLogger::addLog("Failed to send webhook for Order ID: {$order->id}", 3);
        }
    }


    private function sendWebhook($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public function getContent()
    {
        if (Tools::isSubmit('submitWebhookSettings')) {
            $webhookUrl = Tools::getValue('WEBHOOK_ORDER_URL');
            $webhookEnabled = Tools::getValue('WEBHOOK_ORDER_ENABLED');
            Configuration::updateValue('WEBHOOK_ORDER_URL', $webhookUrl);
            Configuration::updateValue('WEBHOOK_ORDER_ENABLED', $webhookEnabled);
        }

        return $this->renderForm();
    }

    private function renderForm()
    {
        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Webhook Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Webhook URL'),
                    'name' => 'WEBHOOK_ORDER_URL',
                    'size' => 50,
                    'required' => true,
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Enable Webhook'),
                    'name' => 'WEBHOOK_ORDER_ENABLED',
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'enabled_on',
                            'value' => 1,
                            'label' => $this->l('Enabled'),
                        ],
                        [
                            'id' => 'enabled_off',
                            'value' => 0,
                            'label' => $this->l('Disabled'),
                        ],
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
            ],
        ];

        $helper = new HelperForm();
        $helper->submit_action = 'submitWebhookSettings';
        $helper->fields_value['WEBHOOK_ORDER_URL'] = Configuration::get('WEBHOOK_ORDER_URL');
        $helper->fields_value['WEBHOOK_ORDER_ENABLED'] = Configuration::get('WEBHOOK_ORDER_ENABLED');

        return $helper->generateForm($fieldsForm);
    }
}

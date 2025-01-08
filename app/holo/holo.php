<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Holo extends Module
{
    public function __construct()
    {
        $this->name = 'holo';
        $this->version = '1.2.3';
        $this->author = 'milad kazemi';
        $this->tab = 'administration';
        $this->ps_versions_compliancy = ['min' => '1.7.6.8', 'max' => '1.7.6.8'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Holo Web Service');
        $this->description = $this->l('Sends a webhook when an order is finalized and paid.');
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

        $webhookBaseUrl = Configuration::get('WEBHOOK_BASE_URL');
        $webhookUrl = rtrim($webhookBaseUrl, '/') . '/api/wcInvoicePayed';

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

        $orderData = [
            'order_id' => $order->id,
            'total_price' => $order->total_paid,
            'currency' => $currency->iso_code,
            'created_at' => $order->date_add,
            'payment_method' => $order->payment,
            'payment_details' => $paymentDetails,
            'customer' => [
                'id' => $customer->id,
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

        $response = $this->sendWebhook($webhookUrl, $orderData);

        if (!$response) {
            PrestaShopLogger::addLog("Failed to send webhook for Order ID: {$order->id}", 3);
        }
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && Configuration::updateValue('WEBHOOK_ORDER_ENABLED', false)
            && Configuration::updateValue('WEBHOOK_BASE_URL', 'https://psshop.nilaserver.com')
            && $this->createLogsTable();
    }


    private function createLogsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "holo_webhook_logs` (
            `id_log` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `webhook_url` VARCHAR(255) NOT NULL,
            `response_code` INT DEFAULT NULL,
            `response_body` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";
        return Db::getInstance()->execute($sql);
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('WEBHOOK_ORDER_ENABLED')
            && Configuration::deleteByName('WEBHOOK_ORDER_URL')
            && $this->dropLogsTable();
    }

    private function dropLogsTable()
    {
        $sql = "DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "holo_webhook_logs`";
        return Db::getInstance()->execute($sql);
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
        $curlError = curl_error($ch); // بررسی خطای cURL
        curl_close($ch);

        // ذخیره لاگ، شامل خطا (در صورت وجود)
        $this->logWebhook($data['order_id'], $url, $httpCode, $response, $curlError);

        return $httpCode === 200;
    }

    private function logWebhook($orderId, $url, $responseCode, $responseBody, $curlError = null)
    {
        $sql = "INSERT INTO `" . _DB_PREFIX_ . "holo_webhook_logs`
            (`order_id`, `webhook_url`, `response_code`, `response_body`, `error_message`, `created_at`)
            VALUES
            (" . (int)$orderId . ", '" . pSQL($url) . "', " . (int)$responseCode . ", '" . pSQL($responseBody) . "', '" . pSQL($curlError) . "', NOW())";
        Db::getInstance()->execute($sql);
    }


    public function getContent()
    {
        if (Tools::isSubmit('updateProductsButton')) {
            $webhookBaseUrl = Configuration::get('WEBHOOK_BASE_URL');
            $updateUrl = rtrim($webhookBaseUrl, '/') . '/api/updateAllProductFromHolooToWC';

            $ch = curl_init($updateUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false) {
                $this->context->controller->errors[] = $this->l('Failed to update products.');
            } else {
                $this->context->controller->confirmations[] = $this->l('Products updated successfully.');
            }
        }

        if (Tools::isSubmit('submitWebhookSettings')) {
            $webhookBaseUrl = Tools::getValue('WEBHOOK_BASE_URL');
            $webhookEnabled = Tools::getValue('WEBHOOK_ORDER_ENABLED');
            Configuration::updateValue('WEBHOOK_BASE_URL', $webhookBaseUrl);
            Configuration::updateValue('WEBHOOK_ORDER_ENABLED', $webhookEnabled);
        }

        return $this->renderForm() . $this->renderLogs();
    }


    private function renderLogs()
    {
        $logs = Db::getInstance()->executeS("SELECT * FROM `" . _DB_PREFIX_ . "holo_webhook_logs` ORDER BY `created_at` DESC LIMIT 50");

        $html = '<h3>' . $this->l('Webhook Logs') . '</h3>';
        if (empty($logs)) {
            $html .= '<p>' . $this->l('No logs found.') . '</p>';
        } else {
            $html .= '<table class="table">';
            $html .= '<thead><tr>
                <th>' . $this->l('ID') . '</th>
                <th>' . $this->l('Order ID') . '</th>
                <th>' . $this->l('Webhook URL') . '</th>
                <th>' . $this->l('Response Code') . '</th>
                <th>' . $this->l('Response Body') . '</th>
                <th>' . $this->l('Error Message') . '</th>
                <th>' . $this->l('Created At') . '</th>
            </tr></thead><tbody>';

            foreach ($logs as $log) {
                $html .= '<tr>
                    <td>' . (int)$log['id_log'] . '</td>
                    <td>' . (int)$log['order_id'] . '</td>
                    <td>' . htmlspecialchars($log['webhook_url']) . '</td>
                    <td>' . (int)$log['response_code'] . '</td>
                    <td>' . htmlspecialchars($log['response_body']) . '</td>
                    <td>' . htmlspecialchars($log['error_message']) . '</td>
                    <td>' . htmlspecialchars($log['created_at']) . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        }

        return $html;
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
                    'label' => $this->l('Base Webhook URL (Site)'),
                    'name' => 'WEBHOOK_BASE_URL',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Enter the base site URL. Default route will be appended automatically.'),
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
        $fieldsForm[1]['form'] = [
            'legend' => [
                'title' => $this->l('Update Products'),
            ],
            'submit' => [
                'title' => $this->l('Update All Products'),
                'name' => 'updateProductsButton',
            ],
        ];



        $helper = new HelperForm();
        $helper->submit_action = 'submitWebhookSettings';
        $helper->fields_value['WEBHOOK_BASE_URL'] = Configuration::get('WEBHOOK_BASE_URL', 'https://psshop.nilaserver.com');
        $helper->fields_value['WEBHOOK_ORDER_ENABLED'] = Configuration::get('WEBHOOK_ORDER_ENABLED');

        return $helper->generateForm($fieldsForm);
    }

}

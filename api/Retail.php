<?php

// Подключим зависимые библиотеки (API RetailCRM)
require_once (__DIR__ . '/../vendor/autoload.php');

/**
 * Класс для работы с RetailCRM
 *
 * @copyright	2016 Oleg Ekhlakov
 * @author		Oleg Ekhlakov
 *
 */
 
class Retail extends Simpla
{
    const INTEGRATION_DIR = '/../integration';
    
    /**
     * Метод отправляет запрос в RetailCRM
     */
    public function request($method, $arData, $by = 'externalId')
    {
        $config = self::config($this->getIntegrationDir() . '/config.php');
        //self::logger('$arData: ' . print_r($arData, true), 'orders-error');
    	$clientRetailCRM = new \RetailCrm\ApiClient($config['urlRetail'], $config['keyRetail'], $config['siteCode']);
        try {
            if ($method == 'ordersCreate' || $method == 'customersCreate') {
                $response = $clientRetailCRM->$method($arData, $config['siteCode']);
            } else if ($method == 'ordersEdit' || $method == 'customersEdit') {
                $response = $clientRetailCRM->$method($arData, $by, $config['siteCode']);
            }
            $order_id = (int) $arData['externalId'];

            if ($response->isSuccessful() && (200 === $response->getStatusCode() || 201 === $response->getStatusCode())) {
                self::logger('RetailCRM_Api::' . $method . ' - Success. Response Id = ' . $response->id, 'connect');
                return true;
            } else {
                self::logger('RetailCRM_Api::' . $method . ' - Error. Status code: ' . $response->getStatusCode(), 'connect');
            }
        } catch (\RetailCrm\Exception\CurlException $e) {
            self::logger('RetailCRM_Api::' . $method . ' ' . $e->getMessage(), 'connect');
        }

        return false;
    }


    /**
     * Метод формирует данные по заказу из SimplaCMS для отправки в RetailCRM
     * @param integer $order_id Идентификатор заказа
     * @return array Массив данных по заказу в формате API v4 RetailCRM (http://www.retailcrm.ru/docs/Developers/ApiVersion4#post--api-v4-orders-upload)
     */
	public function getOrderRetailData($order_id)
	{
        $arOrderData = [];
        $order_id = (int) $order_id;
        if (!($order = $this->orders->get_order($order_id))) {
            return $arOrderData;
        }

        $config = self::config($this->getIntegrationDir() . '/config.php');

        $items = []; // Очищаем массив товаров для новой итерации
        // Собираем массив товаров из заказа
        if ($purchases = $this->orders->get_purchases(array('order_id' => $order->id))) {
            foreach ($purchases as $item) {
                $arItemData = array(
                    "initialPrice" => (float) $item->price,
                    "offer"        => array(
                        "externalId" => $item->variant_id,
                    ),
                    "productName"  => $item->product_name,
                    "quantity"     => (float) $item->amount,
                    //"properties"   => array (
                    //  "code"  => $item->variant_id,
                    //  "name"  => $item->variant_name,
                    //  "value" => $item->variant_id
                    //)
                );
                if ($product = $this->products->get_product($item->product_id)) {
                    if ($createdAt = $product->created) {
                        $arItemData["createdAt"] = $createdAt;
                    }
                }
                $items[] = $arItemData;
            }
        }

        $arOrderData = array(
            'externalId'      => $order->id,
            'createdAt'       => date("Y-m-d H:i:s", strtotime($order->date)),
            'discount'        => $order->coupon_discount, // Скидка в рублях
            'discountPercent' => $order->discount, // Скидка в процентах
            'phone'           => $order->phone,
            'email'           => $order->email,
            'customerComment' => $order->comment,
            'managerComment'  => $order->note,
            'contragent'      => [
                'contragentType' => 'individual' // Доступны только физ. лица
            ],
            'orderType'       => 'eshop-individual', // Тип заказа - обязательное поле. В нашем случае тип всегдя один - заказ от физ. лица через ИМ
            'orderMethod'     => 'shopping-cart', // Только один способ заказа - через корзину
            'items'           => $items, // Массив товаров из заказа
            'delivery'        => [
                'cost'    => $order->delivery_price,
                'address' => [
                    'text' => $order->address
                ]
            ]
        );

        // Если есть код клиента, то создадим привязку, иначе в RetailCRM будет создан клинт по данным из Заказа
        if (intval($order->user_id) != 0) {
            $arOrderData['customer']['externalId'] = $order->user_id;
        }

        // Конвертируем статусы заказов
        $retailOrderStatus = $this->convertOrderStatus($order->status, "simpla");
        if (false !== $retailOrderStatus) {
            $arOrderData['status'] = $retailOrderStatus;
        } else {
            self::logger('Нет соответствующего статуса заказа для RetailCRM. Код статуса заказа Simpla: ' . $order->status, 'orders-error');
        }

        // Конвертируем виды оплат
        if (isset($order->payment_method_id) && !empty($order->payment_method_id)) { // Код 0 в Simpla зарезервирован для невыбранного значения
            if (isset($config['paymentType'][$order->payment_method_id])) {
                $arOrderData['paymentType'] = $config['paymentType'][$order->payment_method_id];
            } else {
                self::logger('Нет соответствующего кода типа оплаты для RetailCRM. Код типа оплаты Simpla: ' . print_r($order->payment_method_id, true), 'orders-error');
            }
        }

        // Конвертируем виды доставок
        if (isset($order->delivery_id) && !empty($order->delivery_id)) { // Код 0 в Simpla зарезервирован для невыбранного значения
            if (isset($config['deliveryType'][$order->delivery_id])) {
                $arOrderData['delivery']['code'] = $config['deliveryType'][$order->delivery_id];
            } else {
                self::logger('Нет соответствующего кода типа доставки для RetailCRM. Код типа доставки Simpla: ' . print_r($order->delivery_id, true), 'orders-error');
                $delivery = '';
            }
        }

        // Конвертируем статусы оплат
        if (isset($order->paid) && ($order->paid != '' || !is_null($order->paid))) {
            if (isset($config['paymentStatus'][$order->paid])) {
                $arOrderData['paymentStatus'] = $config['paymentStatus'][$order->paid];
            } else {
                self::logger('Нет соответствующего статуса оплаты для RetailCRM. Код статуса оплаты Simpla: ' . print_r($order->paid, true), 'orders-error');
            }
        }

        // Добавляем данные по имени и фамилии клинта заказа
        if (isset($order->name) && !empty($order->name)) {
            $arCustomerName = explode(' ', $order->name);
            if (!empty($arCustomerName[0])) {
                $arOrderData['firstName'] = $arCustomerName[0];
            }
            if (!empty($arCustomerName[1])) {
                $arOrderData['lastName'] = $arCustomerName[1];
            }
        }

        return $arOrderData;
	}


    /**
     * Метод формирует данные по зарегистрированному пользователю для отправки в RetailCRM
     * @param integer $user_id Идентификатор пользователя
     * @return array Массив данных по пользователю в формате API v4 RetailCRM (http://www.retailcrm.ru/docs/Developers/ApiVersion4#post--api-v4-customers-upload)
     */
	public function getUserRetailData($user_id)
	{
        $arCustomerData = [];
        $user_id = (int) $user_id;
        if (!($user = $this->users->get_user($user_id))) {
            return $arCustomerData;
        }

        $arCustomerData = array(
            'externalId'       => $user_id,
            'email'            => $user->email,
            'createdAt'        => $user->created,
            'contragent'       => array(
                'contragentType' => 'individual' // Доступны только физ. лица
            )
        );
        $arCustomerName = explode(' ', $user->name);
        if (!empty($arCustomerName[0])) {
            $arCustomerData['firstName'] = $arCustomerName[0];
        }
        if (!empty($arCustomerName[1])) {
            $arCustomerData['lastName'] = $arCustomerName[1];
        }
        if ($group_id = $user->group_id) {
            if ($discount = $this->users->get_group($group_id)->discount) {
                $arCustomerData['personalDiscount'] = (float) $discount;
            }
            if ($groupName = $this->users->get_group($group_id)->name) {
                $arCustomerData['customFields']['group'] = $groupName;
            }
        }

        return $arCustomerData;
	}


    /**
     * Метод принимает данные по заказу из RetailCRM для обновления или изменения в SimplaCMS
     * @param string $orderId Идентификатор заказа в RetailCRM
     * @return boolen Статус выполнения
     */
	public function setOrderRetailData($orderId)
	{
        $config = self::config($this->getIntegrationDir() . '/config.php');
        //self::logger('setOrderRetailData. Данные, принятые из RetailCRM: ' . $orderId, 'orders-info');
        $clientRetailCRM = new \RetailCrm\ApiClient($config['urlRetail'], $config['keyRetail'], $config['siteCode']);
        try {
            $response = $clientRetailCRM->ordersGet($orderId, 'id', $config['siteCode']);
        } catch (\RetailCrm\Exception\CurlException $e) {
            self::logger('RetailCRM_Api::ordersGet ' . $e->getMessage(), 'connect');
        }

        if (isset($response) && $response->isSuccessful() && 200 === $response->getStatusCode()) {
            //self::logger('setOrderRetailData. RetailCRM_Api::ordersGet - Success. Receive data: ' . print_r($response->order, true), 'connect');
            $order = [];

            $order = [
                /*'separate_delivery' => '',*/
                /*'payment_date' => '',*/
                'discount' => $response->order['discountPercent'],
                /*'coupon_code' => '',*/
                'coupon_discount' => $response->order['discount'],
                /*'date' => '',*/
                'user_id' => (int) $response->order['customer']['externalId'],
                'name' => implode(' ', [$response->order['firstName'], $response->order['lastName']]),
                'phone' => $response->order['phone'],
                'email' => $response->order['email'],
                'comment' => $response->order['customerComment'],
                /*'url' => '',*/
                'total_price' => $response->order['totalSumm'],
                'note' => $response->order['managerComment']
            ];
            // Определяем код доставки
            if (isset($response->order['delivery']['code'])) {
                $deliveryId = array_search($response->order['delivery']['code'], $config['deliveryType']);
                if (false !== $deliveryId) {
                     $order['delivery_id'] = $deliveryId;
                }
            }
            // Определяем стоимость доставки
            if (isset($response->order['delivery']['cost'])) {
                $order['delivery_price'] = (float) $response->order['delivery']['cost'];
            }
            // Определяем код способа оплаты
            if (isset($response->order['paymentType']) && $response->order['paymentType'] != '') {
                $paymentId = array_search($response->order['paymentType'], $config['paymentType']);
                if (false !== $paymentId) {
                     $order['payment_method_id'] = $paymentId;
                }
            }
            // Определяем статус оплаты
            if (isset($response->order['paymentStatus'])) {
                $paymentStatus = array_search($response->order['paymentStatus'], $config['paymentStatus']);
                if (false !== $paymentId) {
                     $order['paid'] = $paymentStatus;
                }
            }

            if (isset($response->order['status'])) {
                $simplaOrderStatus = $this->convertOrderStatus($response->order['status'], "retail");
                // Определяем отменён ли заказ
                if ($simplaOrderStatus == '3') {
                    $order['closed'] = 1;
                } else {
                    $order['closed'] = 0;
                }
                // Определяем статус заказа
                if (false !== $simplaOrderStatus) {
                    $order['status'] = $simplaOrderStatus;
                }
            }
            // Определяем адрес доставки
            if (isset($response->order['customer']['address']['text']) && !empty($response->order['customer']['address']['text'])) {
                $order['address'] = $response->order['customer']['address']['text'];
            }

            if (isset($response->order['externalId']) && !empty($response->order['externalId'])) {
                $order_id = (int) $response->order['externalId'];
                //self::logger('Данные, принятые из RetailCRM и подготовленные к вставке: ' . print_r($order, true), 'orders-info');
                // Обновляем товары заказа
                // Сначала получим все текущие товары в заказе по данным SimplaCMS
                $purchases = $this->orders->get_purchases(array('order_id' => $order_id));
                $products_ids = array();
                $variants_ids = array();
                foreach ($purchases as $purchase) {
                    $products_ids[] = $purchase->product_id;
                    $variants_ids[] = $purchase->variant_id;
                    $purchaseVariant[$purchase->variant_id] = $purchase->id;
                    $purchaseAmount[$purchase->variant_id] = $purchase->amount;
                    $purchasePrice[$purchase->variant_id] = $purchase->price;
                }
                // Получим все товары заказа по новым данным
                foreach ($response->order['items'] as $itemData) {
                    if (in_array($itemData['offer']['externalId'], $variants_ids)) {
                        if ($purchaseAmount[$itemData['offer']['externalId']] != $itemData['quantity'] || $purchasePrice[$itemData['offer']['externalId']] != $itemData['initialPrice']) {
                            // Товар повторяется, а количество изменилось
                            $this->orders->update_purchase($purchaseVariant[$itemData['offer']['externalId']], array(
                                'amount' => (int) $itemData['quantity'],
                                'price'  => (float) $itemData['initialPrice']
                            ));
                        }
                        unset($purchaseVariant[$itemData['offer']['externalId']]);
                    } else {
                        // Это новый товар - добавляем
                        $this->orders->add_purchase(array(
                            'order_id' => $response->order['externalId'],
                            'variant_id' => intval($itemData['offer']['externalId']),
                            'amount' => intval($itemData['quantity'])
                        ));
                    }
                }
                if (!empty($purchaseVariant)) {
                    // Какие-то товары остались, значит, нужно удалить эти товары, - теперь их в заказе нет
                    foreach ($purchaseVariant as $purchase_id) {
                        $this->orders->delete_purchase($purchase_id);
                    }
                }
                $this->orders->update_order($order_id, $order);
            } else {
                $order_id = $this->orders->add_order($order);
                // Добавляем товары к заказу
                foreach ($response->order['items'] as $itemData) {
                    $this->orders->add_purchase(array(
                        'order_id' => $order_id,
                        'variant_id' => intval($itemData['offer']['externalId']),
                        'amount' => intval($itemData['quantity'])
                    ));
                }
            }
        } else {
            self::logger('RetailCRM_Api::ordersGet - Error. Status code: ' . $response->getStatusCode(), 'connect');
        }
	}


    /**
     * Метод конвертирует код статуса заказа из одной системы в соответствующий код из другой системы (SimplaCMS и RetailCRM)
     * @param string $code Код статуса заказа
     * @param string $fromSystem Название системы, код которой передан в предыдущем параметре.
     *     Допустимы два значения: "simpla" и "retail". По-умолчанию, "simpla"
     * @return mixed Код статуса заказа в другой системе. Либо ЛОЖЬ в случае невозможности определить статус
     */
    public function convertOrderStatus($code, $fromSystem = "simpla")
    {
        $fromSystem = trim(strval($fromSystem));
        if (empty($fromSystem)) {
            return false;
        }
        $config = self::config($this->getIntegrationDir() . '/config.php');

        if ($fromSystem == "simpla") {
            return array_search($code, $config['orderStatus']);
        } else if ($fromSystem == "retail") {
            if (isset($config['orderStatus'][$code])) {
                return $config['orderStatus'][$code];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }


    public static function getDate($log)
    {
        if (file_exists($log)) {
            return file_get_contents($log);
        } else {
            return date('Y-m-d H:i:s', strtotime('-1 days', strtotime(date('Y-m-d H:i:s'))));
        }
    }


    public static function logger($message, $type, $errors = null)
    {
        $format = "[" . date('Y-m-d H:i:s') . "]";
        if (!is_null($errors) && is_array($errors)) {
            $message .= ":\n";
            foreach ($errors as $error) {
                $message .= "\t" . $error . "\n";
            }
        } else {
            $message .= "\n";
        }

        $logDir = __DIR__ . self::INTEGRATION_DIR . '/log/';
        switch ($type) {
            case 'connect':
                $path = $logDir. "connect-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'customers':
                $path = $logDir . "customers-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'orders-info':
                $path = $logDir . "orders-info.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'orders-error':
                $path = $logDir . "orders-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'icml':
                $path = $logDir . "icml.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'history':
                $path = $logDir . "history-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'history-log':
                $path = $logDir . "history.log";
                file_put_contents($path, $message);
                break;
        }

    }
    
    
    public function getIntegrationDir()
    {
        return __DIR__ . self::INTEGRATION_DIR;
    }


    public static function config($configFile)
    {
        if (file_exists($configFile)) {
            return include($configFile);
        } else {
            return null;
        }
    }
}

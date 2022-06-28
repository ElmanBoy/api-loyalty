<?php
/*
 * Copyright (c) $originalComment.match("Copyright \(c\) (\d+)", 1, "-", "$today.year")2022. Elman Boyazitov flobus@mail.ru
 */

namespace Api;

use Db\Db;

/**
 * Класс для взаимодействия с источником данных по API
 */
class ApiHandler
{
    public int $categoryID = 0, $productID = 0;
    private array $config;
    private Db $db;

    /**
     * Конструктор класса.
     * @param array $config
     */
    public function __construct ( array $config )
    {
        $this->config = $config;
        $this->db = new Db($config);
    }

    /**
     * Метод определения окончания слова в зависимости от числа перед ним.
     *
     * @param int $number Число перед словом
     * @param string $one Окончание слова при крайнем числе 1
     * @param string $two Окончание слова при крайнем числе 2
     * @param string $five Окончание слова при крайнем числе 5
     * @return string Окончание слова
     */
    public function postfix ( int $number, string $one, string $two, string $five ): string
    {
        $number = intval($number);
        $out = $one;
        if ($number > 20) {
            $numArr = str_split($number);
            $number = $numArr[count($numArr) - 1];
            $out = $this->postfix($number, $one, $two, $five);
        } elseif ($number > 1 && $number < 5) {
            $out = $two;
        } elseif ($number >= 5 || $number == 0) {
            $out = $five;
        }
        return $out;
    }

    /**
     * Создание аутентификационной части запроса по API.
     *
     * @param string $method Метод запроса. Возможны два значения: GetCategories и GetProduct
     * @return string Код в формате XML
     */
    private function createXMLAuth ( string $method ): string
    {
        $login = $this->config['apilogin'];
        $transID = time();
        $hash = md5($transID . $method . $login . $this->config['apipass']);
        return '
        <Authentication>
        <Login>' . $login . '</Login>
        <TransactionID>' . $transID . '</TransactionID>
        <MethodName>' . $method . '</MethodName>
        <Hash>' . $hash . '</Hash>
        </Authentication>';
    }

    /**
     * Создание фрагмента запроса по API, определяющего параметры запроса.
     *
     * @param int $categoryId ID категории для получения ее товаров
     * @param int $productId ID товара для получения данных о конкретном товаре
     * @return string Код в формате XML
     */
    private function createXMLParams ( int $categoryId, int $productId ): string
    {
        $xml = '';

        if (intval($categoryId) > 0) {
            $xml .= '<Categories>
                <Category>' . $categoryId . '</Category>
            </Categories>';
        }

        if (intval($productId) > 0) {
            $xml .= '<Products>
                        <Product>' . $productId . '</Product>
                    </Products>';
        }

        return ($xml != '') ? '<Parameters>' . $xml . '</Parameters>' : '';
    }


    /**
     * Метод для запроса данных по API
     *
     * @param string $method Метод запроса данных по API. Возможные значения: GetCategories, GetProduct
     * @param int $categoryId ID категории товаров
     * @param int $productId ID товара
     * @return array Возвращает ассоциативный массив с категориями или товарами
     */
    public function getData ( string $method, int $categoryId = 0, int $productId = 0 ): array
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>
                <Request>';
        $xml .= $this->createXMLAuth($method);
        $xml .= $this->createXMLParams($categoryId, $productId);
        $xml .= "\n".'</Request>';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->config['apihost'].'v1/'.$method.'/');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: text/xml; charset=utf-8',
                'Content-Length: ' . strlen($xml))
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

        $result = curl_exec($ch);
        curl_close($ch);
        try {
            return json_decode(json_encode(simplexml_load_string($result)), true);
        } catch (\Exception $e) {
            echo "Ошибка разбора XML-данных: " . $e->getMessage();
        }
    }

    /**
     * Сохранение списка категорий, полученных через API, в базу данных.
     *
     * @return array Массив категорий
     */
    public function saveCategories(): array
    {
        $result = $this->getData('GetCategories');
        $categories = $result['Categories']['Category'];
        $values = [];
        $i = 0;
        $c = 0;

        if (intval($result['Status']) == 2) {

            $this->db->clearTable('categories');

            foreach ($categories as $key => $cat) {
                $parentID = intval($cat['@attributes']['parentId']);
                $catID = intval($cat['@attributes']['id']);

                $values[] = [
                    'id' => $catID,
                    'name' => $cat['name'],
                    'products' => $cat['totalProducts'],
                    'parent' => $parentID
                ];

                if ($i == 300 || $c == count($categories) - 1) {
                    $this->db->multiInsert(
                        'categories',
                        ['id', 'name', 'products', 'parent'],
                        $values
                    );
                    $values = [];
                    $i = 0;
                }

                $i++;
                $c++;

            }

            return ['result' => true, 'data' => $values];
        } else {
            return ['result' => false, 'data' => $result['Error']];
        }
    }

    /**
     * Сохранение списка товаров категории с ID $categoryID, полученных через API, в базу данных.
     *
     * @param int $categoryID ID запрашиваемой категории
     * @return array Массив товаров указанной категории
     */
    public function saveProducts( int $categoryID): array
    {
        $result = $this->getData('GetProduct', $categoryID);
        $products = $result['Products']['Product'];
        $values = [];
        $i = 0;
        $c = 0;

        if (intval($result['Status']) == 2) {

            foreach ($products as $key => $cat) {
                if(isset($cat['Name']) && strlen(trim($cat['Name'])) > 0) {
                    if (is_array($cat)) {
                        $values[] = [
                            'prodId' => $cat['Id'],
                            'catId' => $categoryID,
                            'name' => $cat['Name'],
                            'cover' => $cat['Picture'],
                            'photos' => json_encode($cat['Fotos']['Foto']),
                            'params' => json_encode($cat['Params']['Param']),
                            'price' => $cat['Price']
                        ];
                    }

                    if ($i == 300 || $c == count($products) - 1) {
                        $this->db->multiInsert(
                            'products',
                            ['prodId', 'catId', 'name', 'cover', 'photos', 'params', 'price'],
                            $values
                        );
                        $values = [];
                        $i = 0;
                    }

                    $i++;
                    $c++;
                }
            }
            if($c > 0) {
                return ['result' => true, 'data' => $values, 'count' => $c];
            }else{
                $this->db->setEmptyCategory($categoryID);
                return ['result' => false, 'data' => [], 'count' => 0];
            }
        } else {
            return ['result' => false, 'data' => $result['Error']];
        }
    }
}
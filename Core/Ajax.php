<?php
/*
 * Copyright (c) $originalComment.match("Copyright \(c\) (\d+)", 1, "-", "$today.year")2022. Elman Boyazitov flobus@mail.ru
 */

$time_start = microtime(true);

include '../config.php';
include './ApiHandler.php';
include './Db.php';

$result = false;
$output = [];

//Если заданы реквизиты доступа к БД и API
if (!empty($config)) {
    $api = new \Api\ApiHandler($config);
    $db = new \Db\Db($config);

    //POST-параметр action определяет ответ обработчика ajax-апросов в формате json
    switch ($_POST['action']) {
        case 'getCategories':
            //Получить массив категорий
            $output = $db->getCategories(intval($_POST['parentID']));
            if(count($output) > 0) {
                $result = true;
            }else{
                $output = '';
            }

            break;

        case 'getProducts':
            //Получить массив товаров указанной в $_POST['categoryID'] категории
            $output = $db->getProducts(intval($_POST['categoryID']));

            if(count($output) > 0) {
                $result = true;
            }else{
                $output = '';
            }

            break;

        case 'getProduct':
            //Получить массив данных конкретного товара по ID, указанном в $_POST['productID']
            $output = $db->getProducts(0, intval($_POST['productID']));

            if(count($output) > 0) {
                $result = true;
            }else{
                $output = '';
            }

            break;

        case 'refresh':
            //Обновление базы данных через запрос по API. Шаг 1 - получение списка не пустых категорий
            $api->saveCategories();
            $categories = $db->getNotEmptyCategories();

            $db->clearTable('products');
            $db->clearTable('load_queue');

            $db->multiInsert(
                'load_queue',
                ['catId', 'name'],
                $categories
            );

            $result = true;
            $output = 'Загружен список не пустых разделов.
                <script>app.loadNextCategory()</script>';

            break;

        case 'loadNext':
            //Получение списков товаров полученных на первом шаге категорий. Шаг 2 и далее - товары по одной категории за шаг.
            $cid = $db->getNextCategory();
            $db->setLoadedCategory($cid['id']);

            if($cid != []){

                $products = $api->saveProducts($cid['catId']);

                sleep(1);
                if(intval($products['count']) > 0) {

                    $output = 'Раздел &laquo;' . $cid['name'] . '&raquo; - ' .
                        $products['count'] . ' товар'.$api->postfix($products['count'], '', 'а', 'ов').'
                    <script>app.loadNextCategory()</script>';
                }else{
                    $output = 'Раздел &laquo;' . $cid['name'] . '&raquo; - нет товаров
                    <script>app.loadNextCategory()</script>';
                }
                $result = true;
            }else{
                $output = 'Загрузка завершена!
                <script>app.endLoadCategory()</script>';
                $result = true;
            }

            break;

    }

} else {

   $output = 'Не заданы конфигурационные параметры';
}
//Подсчет времени выполнения скрипта
$time_end = microtime(true);
$time = $time_end - $time_start;

echo json_encode(['result' => $result, 'data' => $output, 'lapse' => round($time, 3) . 'sec.']);
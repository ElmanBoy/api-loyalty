<?php
/*
 * Copyright (c) $originalComment.match("Copyright \(c\) (\d+)", 1, "-", "$today.year")2022. Elman Boyazitov flobus@mail.ru
 */

namespace Db;

use PDO, PDOException;

/**
 * Класс взаимодействия с базой данных
 */
class Db
{
    private PDO $conn;

    /**
     * Конструктор класса. Подключение к базе данных.
     *
     * @param array $config Массив с реквизитами подключения к базе данных
     */
    public function __construct ( array $config )
    {
        try {
            $this->conn = new PDO("mysql:host={$config['dbhost']};port={$config['dbport']};dbname={$config['dbname']}",
                $config['dbuser'], $config['dbpass']);
            // установка режима вывода ошибок
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("SET NAMES 'utf8'");
            //echo "Database connection established";
        } catch (PDOException $e) {
            echo "Ошибка подключения к базе данных: " . $e->getMessage();
        }
    }

    /**
     * Очистка таблицы $table.
     *
     * @param string $table Название таблицы
     * @return void
     */
    public function clearTable ( string $table )
    {
        $this->conn->exec("TRUNCATE $table");
    }

    /**
     * Метод множественной вставки в таблицу $table с полями $fields и данными $data.
     *
     * @param string $table Название таблицы
     * @param array $fields Одномерный массив с перечнем полей таблицы
     * @param array $data Массив данных (можно многомерный),
     * ключи которого совпадают со значениями в массиве $fields
     * @return void
     */
    public function multiInsert ( string $table, array $fields, array $data )
    {
        $i = 0;
        $param = [];
        $stm_texts = [];

        foreach ($data as $d) {
            $keys = [];

            foreach ($fields as $fn) {
                $key = ':i' . $i . $fn;
                $keys[] = $key;
                $param[$key] = $d[$fn];
            }
            $stm_texts[] = '(' . implode(',', $keys) . ')';
            $i++;
        }

        $stm_text = 'insert into ' . $table . ' (' . implode(',', $fields) . ') values ' . implode(',', $stm_texts);
        $stmt = $this->conn->prepare($stm_text);
        $stmt->execute($param);
        $this->conn->query("OPTIMIZE TABLE `$table`");
    }

    /**
     * Метод для получения из базы данных категорий витрины.
     *
     * @param int $parentID ID родительской категории
     * @return array Массив категорий
     */
    public function getCategories ( int $parentID = 0 ): array
    {
        $output = [];
        $result = $this->conn->query("SELECT *, 
       (SELECT COUNT(*) FROM `categories` chil WHERE chil.parent = par.id) AS isParent 
        FROM `categories` par WHERE parent = " . intval($parentID));
        if ($result->rowCount() > 0) {
            while ($row = $result->fetch()) {
                $output[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'products' => $row['products'],
                    'isParent' => $row['isParent']
                ];
            }
        }
        return $output;
    }

    /**
     * Метод для получения категорий, имеющих товары.
     *
     * @return array Массив категорий
     */
    public function getNotEmptyCategories (): array
    {
        $output = [];
        $result = $this->conn->query("SELECT * FROM `categories` WHERE products > 0 ");
        if ($result->rowCount() > 0) {
            while ($row = $result->fetch()) {
                $output[] = [
                    'catId' => $row['id'],
                    'name' => $row['name']
                ];
            }
        }
        return $output;
    }

    /**
     * Метод, отмечающий категорию, как загруженную. Применяется в пакетной загрузке данных по API.
     *
     * @param int $id ID категории, помечаемую, как загруженную
     * @return void
     */
    public function setLoadedCategory( int $id)
    {
        $this->conn->query("UPDATE `load_queue` SET loaded = 1 WHERE id =".intval($id));
    }

    /**
     * Метод для отметки категорий, не имеющих товаров. Применяется в пакетной загрузке данных по API.
     *
     * @param int $categoryID ID категории, помечаемую, как пустую
     * @return void
     */
    public function setEmptyCategory( int $categoryID)
    {
        $this->conn->query("UPDATE `categories` SET products = 0 WHERE id =".intval($categoryID));
    }

    /**
     * Метод для получения следующей категории для загрузки. Применяется в пакетной загрузке данных по API.
     * @return array
     */
    public function getNextCategory(): array
    {
        $result = $this->conn->query("SELECT * FROM `load_queue` WHERE loaded = 0 ORDER BY id LIMIT 1");
        if ($result->rowCount() > 0) {
            return $result->fetch();
        }else{
            return [];
        }
    }

    /**
     * Метод для получения списка товаров заданной в $categoryID категории из базы данных.
     * Если задан $productId, то будет получена информация о конкретном товаре.
     *
     * @param int $categoryID ID запрашиваемой категории
     * @param int $productId ID запрашиваемого товара
     * @return array Массив списка товаров или данных о конкретном товаре
     */
    public function getProducts ( int $categoryID, int $productId = 0 ): array
    {
        $output = [];
        $query = [];
        $sql = '';

        if (intval($categoryID) > 0) {
            $query[] = " catId = " . intval($categoryID);
        }
        if (intval($productId) > 0) {
            $query[] = " prodId = " . intval($productId);
        }
        if (count($query) > 0) {
            $sql = "WHERE " . implode(" AND ", $query);
        }

        $result = $this->conn->query("SELECT * FROM `products` $sql");
        if ($result->rowCount() > 0) {
            while ($row = $result->fetch()) {
                $output[] = [
                    'Id' => $row['prodId'],
                    'catId' => $row['catId'],
                    'Name' => $row['name'],
                    'Picture' => $row['cover'],
                    'Fotos' => json_decode($row['photos']),
                    'Params' => json_decode($row['params']),
                    'Price' => $row['price']
                ];

            }
        }
        return $output;
    }
}
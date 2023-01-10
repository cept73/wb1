<?php /** @noinspection PhpUnused, PhpSameParameterValueInspection, SqlNoDataSourceInspection, SqlDialectInspection */

/**
 * Changes prices in catalog
 *
 * @return void
 */

function main()
{
    try {
        $app = new App();

        // Recreate tables
        $app->run(App::SCENARIO_RECREATE);

        // Change prices in %
        $app->changePriceProc('green', 5);
        $app->changePriceProc('blue', 3);
    }
    catch (Exception $e) {
        print $e->getMessage() . PHP_EOL;
    }
}

class App
{
    private const SQL_DROP_TABLES = [
        'DROP TABLE `_products`',
        'DROP TABLE `colors`',
        'DROP TABLE `products`'
    ];

    private const SQL_CREATE_ORIGINAL_TABLES = [
        "CREATE TABLE `products` (
            `id` int(11) NOT NULL,
            `name` tinytext,
            `price` float(9,2) DEFAULT '0.00',
            `color` tinytext,
            UNIQUE KEY `id` (`id`)
        ) ENGINE=innoDB;"
    ];

    private const SQL_CREATE_NEW_TABLES = [
        'CREATE TABLE `colors` (
            `id` int(11) AUTO_INCREMENT,
            `name` varchar(64),
            PRIMARY KEY `id` (`id`),
            UNIQUE (`name`)
        ) ENGINE=innoDB',

        "CREATE TABLE `_products` (
            `id` int(11) NOT NULL,
            `name` varchar(255),
            `price` float(9, 2) DEFAULT '0.00',
            `color_id` int(11),
            UNIQUE KEY `id` (`id`),
            FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=innoDB"
    ];

    private const PRODUCT_SEED_COUNT = 500000;

    private const ROUND_PRICE = 10;

    private const COLORS_NAMES = [
        'black', 'blue', 'green', 'cyan', 'magenta', 'brown', 'white', 'grey', 'light blue', 'light green',
        'light cyan', 'light magenta', 'light brown', 'light yellow', 'light white'
    ];

    public const SCENARIO_RECREATE  = 1;
    public const SCENARIO_APPEND    = 2;
    public const SCENARIO_CONVERT   = 3;

    private $db;

    private array $collectedColors = [];

    /**
     * @throws Exception
     */
    public function run($scenario)
    {
        if ($scenario === self::SCENARIO_CONVERT) {
            $this->renameNewTable();
            return;
        }

        if ($scenario === self::SCENARIO_RECREATE) {
            $this->log('Drop tables');
            $this->dropTables();

            $this->log('Create tables');
            $this->createTables();

            $this->log('Create product table');
            $this->createProductTable();
        }

        $this->log('Seed product table with random values');
        $this->seedProductTableWithRandomValues(self::PRODUCT_SEED_COUNT);

        if ($scenario === self::SCENARIO_RECREATE) {
            $this->log('Write colors in separate table and store');
            $this->writeColorsInSeparateTableAndStore();
        } else {
            $this->collectStoredColors();
        }

        $this->log('Clone products table');
        $this->cloneProductsTable();
    }

    public function changePriceProc(string $color, float $deltaPercents)
    {
        $roundPrice = self::ROUND_PRICE;
        $formula = 1 + $deltaPercents / 100;

        $this->db->begin_transaction();

        $preparedQuery = $this->db->prepare("
            UPDATE _products SET price = CEILING(price * ? / ?) * ?
                WHERE color_id = (SELECT id FROM colors WHERE name = ?)
        ");
        $preparedQuery->bind_param('sd', $roundPrice, $roundPrice, $formula, $color);
        $preparedQuery->execute();

        $this->db->commit();
    }

    public function __construct()
    {
        $this->db = mysqli_connect('localhost', 'user', 'password', 'test');
    }

    private function dropTables()
    {
        $this->executeAndSuppressException(self::SQL_DROP_TABLES);
    }

    private function createTables()
    {
        $this->executeAndSuppressException(self::SQL_CREATE_NEW_TABLES);
    }

    private function createProductTable()
    {
        $this->executeAndSuppressException(self::SQL_CREATE_ORIGINAL_TABLES);
    }

    private function executeAndSuppressException($sqlList)
    {
        $this->db->begin_transaction();
        foreach ($sqlList as $sql) {
            try {
                $this->db->query($sql);
            } catch (Throwable $exception) {
                print 'WARNING: ' . $exception->getMessage() . PHP_EOL;
            }
        }
        $this->db->commit();
    }

    /**
     * @param array $array
     * @return string
     */
    private function randomFromArray(array $array): string
    {
        $index = rand(0, count($array) - 1);
        return $array[$index];
    }

    /**
     * @throws Exception
     */
    private function seedProductTableWithRandomValues(int $count = 1000000)
    {
        $result = $this->db->query('SELECT last(`id`) AS lastId FROM `products`');
        $row    = mysqli_fetch_array($result, MYSQLI_ASSOC);
        $id     = ($row['lastId'] ?? -1) + 1;

        for ($index = 0; $index < $count; $index ++) {
            $price  = rand(10000, 100000) / 100;
            $name   = substr(md5(random_bytes(20)), 0, 20);
            $color  = self::randomFromArray(self::COLORS_NAMES);

            $preparedQuery = $this->db->prepare("INSERT INTO `products` (`id`, `name`, `price`, `color`) VALUES (?, ?, ?, ?)");
            /** @noinspection SpellCheckingInspection */
            $preparedQuery->bind_param('dsds', $id, $name, $price, $color);
            $preparedQuery->execute();

            $id ++;
        }
    }

    private function writeColorsInSeparateTableAndStore()
    {
        $result = $this->db->query('SELECT DISTINCT(`color`) FROM `products`');
        while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
            $color = $row['color'];

            $preparedQuery = $this->db->prepare("INSERT INTO `colors` (`name`) VALUES (?)");
            $preparedQuery->bind_param('s', $color);
            $preparedQuery->execute();

            $this->collectedColors[$color] = $preparedQuery->insert_id;
        }
    }

    private function collectStoredColors()
    {
        $result = $this->db->query('SELECT `id`, `name` FROM `colors`');
        while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
            $this->collectedColors[ $row['name'] ] = $row['id'];
        }
    }

    private function cloneProductsTable()
    {
        $this->db->query('DELETE FROM `_products`');

        $this->db->query('INSERT INTO `_products` (`id`, `name`, `price`) SELECT `id`, `name`, `price` FROM `products`');

        foreach ($this->collectedColors as $color => $colorId) {
            $preparedQuery = $this->db->prepare(
                'UPDATE `_products` SET `color_id` = ? WHERE `id` IN (SELECT `id` FROM `products` WHERE `color` = ?)'
            );
            $preparedQuery->bind_param('ds', $colorId, $color);
            $preparedQuery->execute();
        }
    }

    private function renameNewTable()
    {
        $this->db->begin_transaction();
        $this->db->query('ALTER TABLE `products` RENAME TO `products_old`');
        $this->db->query('ALTER TABLE `_products` RENAME TO `products`');
        $this->db->commit();
    }

    public function __destruct()
    {
        $this->db->close();
    }

    private function log($part)
    {
        print "[$part]:" . PHP_EOL;
    }
}

main();

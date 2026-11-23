<?php

namespace Grocy\Services;

// Run with PHP 8.4+ and the Composer dependencies installed, or pass the
// LessQL src/LessQL directory as the first argument.
$root = dirname(__DIR__);
$lessql = $argv[1] ?? null;
if ($lessql === null)
{
	require $root . '/vendor/autoload.php';
}
else
{
	spl_autoload_register(static function ($class) use ($lessql) {
		if (str_starts_with($class, 'LessQL\\'))
		{
			require $lessql . '/' . substr($class, strlen('LessQL\\')) . '.php';
		}
	});
}

// The copy's name is the only dependency on translations.
class LocalizationService
{
	public static function GetInstance() { return new self(); }
	public function __t($text, ...$values) { return sprintf($text, ...$values); }
}

require_once $root . '/services/BaseService.php';
require_once $root . '/services/DatabaseService.php';
require_once $argv[2] ?? $root . '/services/StockService.php';

define('GROCY_MODE', 'production');
define('GROCY_DATAPATH', sys_get_temp_dir() . '/grocy-copy-' . bin2hex(random_bytes(8)));
mkdir(GROCY_DATAPATH, 0700);
register_shutdown_function(static function () {
	unlink(GROCY_DATAPATH . '/grocy.db');
	rmdir(GROCY_DATAPATH);
});

$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$db->createFunction('grocy_user_setting', static fn ($key) => null, 1);
foreach (glob($root . '/migrations/[0-9][0-9][0-9][0-9].sql') as $migration)
{
	$db->exec(file_get_contents($migration));
}

function check($condition, $message)
{
	if (!$condition)
	{
		throw new \RuntimeException($message);
	}
}

$db->exec("INSERT OR IGNORE INTO quantity_units (id, name, name_plural) VALUES
	(2, 'Piece', 'Pieces'), (3, 'Pack', 'Packs'), (11, 'Milliliter', 'Milliliters'), (40, 'Single', 'Singles');
	INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, product_group_id) VALUES
	(5, 'Eggs', 2, 3, 2, 6), (6, 'Orange Juice', 2, 2, 11, 6), (7, 'Same units', 2, 2, 2, 6);
	UPDATE quantity_unit_conversions SET factor = 10 WHERE product_id = 5 AND from_qu_id = 3 AND to_qu_id = 2;
	UPDATE quantity_unit_conversions SET factor = 600 WHERE product_id = 6 AND from_qu_id = 2 AND to_qu_id = 11;
	INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (2, 40, 3, 6), (2, 40, 4, 7)");

function conversions($db, $productId)
{
	return $db->query('SELECT from_qu_id, to_qu_id, CAST(factor AS REAL) AS factor FROM quantity_unit_conversions WHERE product_id = ' . (int)$productId . ' ORDER BY from_qu_id, to_qu_id')->fetchAll(\PDO::FETCH_ASSOC);
}

$stock = new StockService();
foreach ([5 => 'Eggs', 6 => 'Orange Juice', 7 => 'Same units'] as $id => $name)
{
	$original = conversions($db, $id);
	check(count($original) === [5 => 2, 6 => 4, 7 => 2][$id], 'Invalid fixture: ' . $name);
	$copyId = $stock->CopyProduct($id);
	check($db->query('SELECT name FROM products WHERE id = ' . (int)$copyId)->fetchColumn() === 'Copy of ' . $name, 'Wrong copy ID');
	check(conversions($db, $copyId) === $original, 'Copy did not preserve conversion pairs and factors: ' . $name);
	check(conversions($db, $id) === $original, 'Copy altered source conversions: ' . $name);
	fwrite(STDOUT, "PASS: $name\n");
}

$db->exec("UPDATE products SET name = 'First Orange Juice copy' WHERE name = 'Copy of Orange Juice'");
$db->exec("CREATE TEMP TRIGGER fail_copy BEFORE INSERT ON quantity_unit_conversions
	WHEN NEW.from_qu_id = 2 AND NEW.to_qu_id = 40 AND NEW.product_id > 7
	BEGIN SELECT RAISE(ABORT, 'injected conversion failure'); END");
$tables = ['products', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved'];
$snapshot = static fn () => array_map(static fn ($table) => $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), $tables);
$before = $snapshot();
try
{
	$stock->CopyProduct(6);
	throw new \RuntimeException('Copy unexpectedly succeeded');
}
catch (\PDOException $ex)
{
	check(str_contains($ex->getMessage(), 'injected conversion failure'), $ex->getMessage());
}
check(!$db->inTransaction() && $snapshot() === $before, 'Copy left partial database changes');
fwrite(STDOUT, "PASS: rollback after conversion failure\n");

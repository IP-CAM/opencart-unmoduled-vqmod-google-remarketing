<?php
// Version
define('VERSION', '2.1.0.1');

// Configuration
if (is_file('config.php')) {
	require_once('config.php');
}

// VirtualQMOD
require_once('./vqmod/vqmod.php');
VQMod::bootup();

// VQMODDED Startup
require_once(VQMod::modCheck(DIR_SYSTEM . 'startup.php'));

// Registry
$registry = new Registry();

// Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

// Config
$config = new Config();
$registry->set('config', $config);

// Database
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);

// Store
if (isset($_SERVER['HTTPS']) && (($_SERVER['HTTPS'] == 'on') || ($_SERVER['HTTPS'] == '1'))) {
	$store_query = $db->query("SELECT * FROM " . DB_PREFIX . "store WHERE REPLACE(`ssl`, 'www.', '') = '" . $db->escape('https://' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . rtrim(dirname($_SERVER['PHP_SELF']), '/.\\') . '/') . "'");
}
else {
	$store_query = $db->query("SELECT * FROM " . DB_PREFIX . "store WHERE REPLACE(`url`, 'www.', '') = '" . $db->escape('http://' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . rtrim(dirname($_SERVER['PHP_SELF']), '/.\\') . '/') . "'");
}

if ($store_query->num_rows) {
	$config->set('config_store_id', $store_query->row['store_id']);
}
else {
	$config->set('config_store_id', 0);
}

// Settings
$query = $db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' OR store_id = '" . (int)$config->get('config_store_id') . "' ORDER BY store_id ASC");

foreach ($query->rows as $result) {
	if (!$result['serialized']) {
		$config->set($result['key'], $result['value']);
	}
	else {
		$config->set($result['key'], json_decode($result['value'], true));
	}
}

if (!$store_query->num_rows) {
	$config->set('config_url', HTTP_SERVER);
	$config->set('config_ssl', HTTPS_SERVER);
}

// Url
$url = new Url($config->get('config_url'), $config->get('config_secure') ? $config->get('config_ssl') : $config->get('config_url'));
$registry->set('url', $url);

// Log
$log = new Log($config->get('config_error_filename'));
$registry->set('log', $log);

function error_handler($code, $message, $file, $line)
{
	global $log, $config;

	// error suppressed with @
	if (error_reporting() === 0) {
		return false;
	}

	switch ($code) {
		case E_NOTICE:
		case E_USER_NOTICE:
			$error = 'Notice';
			break;
		case E_WARNING:
		case E_USER_WARNING:
			$error = 'Warning';
			break;
		case E_ERROR:
		case E_USER_ERROR:
			$error = 'Fatal Error';
			break;
		default:
			$error = 'Unknown';
			break;
	}

	if ($config->get('config_error_display')) {
		echo '<b>' . $error . '</b>: ' . $message . ' in <b>' . $file . '</b> on line <b>' . $line . '</b>';
	}

	if ($config->get('config_error_log')) {
		$log->write('PHP ' . $error . ':  ' . $message . ' in ' . $file . ' on line ' . $line);
	}

	return true;
}

// Error Handler
set_error_handler('error_handler');

// Request
$request = new Request();
$registry->set('request', $request);

// Response
$response = new Response();
$response->addHeader('Content-Type: text/html; charset=utf-8');
$response->setCompression($config->get('config_compression'));
$registry->set('response', $response);

// Cache
$cache = new Cache('file');
$registry->set('cache', $cache);

// Session
if (isset($request->get['token']) && isset($request->get['route']) && substr($request->get['route'], 0, 4) == 'api/') {
	$db->query("DELETE FROM `" . DB_PREFIX . "api_session` WHERE TIMESTAMPADD(HOUR, 1, date_modified) < NOW()");

	$query = $db->query("SELECT DISTINCT * FROM `" . DB_PREFIX . "api` `a` LEFT JOIN `" . DB_PREFIX . "api_session` `as` ON (a.api_id = as.api_id) LEFT JOIN " . DB_PREFIX . "api_ip `ai` ON (as.api_id = ai.api_id) WHERE a.status = '1' AND as.token = '" . $db->escape($request->get['token']) . "' AND ai.ip = '" . $db->escape($request->server['REMOTE_ADDR']) . "'");

	if ($query->num_rows) {
		// Does not seem PHP is able to handle sessions as objects properly so so wrote my own class
		$session = new Session($query->row['session_id'], $query->row['session_name']);
		$registry->set('session', $session);

		// keep the session alive
		$db->query("UPDATE `" . DB_PREFIX . "api_session` SET date_modified = NOW() WHERE api_session_id = '" . $query->row['api_session_id'] . "'");
	}
}
else {
	$session = new Session();
	$registry->set('session', $session);
}

// Language Detection
$languages = array();

$query = $db->query("SELECT * FROM `" . DB_PREFIX . "language` WHERE status = '1'");

foreach ($query->rows as $result) {
	$languages[$result['code']] = $result;
}

if (isset($session->data['language']) && array_key_exists($session->data['language'], $languages)) {
	$code = $session->data['language'];
}
elseif (isset($request->cookie['language']) && array_key_exists($request->cookie['language'], $languages)) {
	$code = $request->cookie['language'];
}
else {
	$detect = '';

	if (isset($request->server['HTTP_ACCEPT_LANGUAGE']) && $request->server['HTTP_ACCEPT_LANGUAGE']) {
		$browser_languages = explode(',', $request->server['HTTP_ACCEPT_LANGUAGE']);

		foreach ($browser_languages as $browser_language) {
			foreach ($languages as $key => $value) {
				if ($value['status']) {
					$locale = explode(',', $value['locale']);

					if (in_array($browser_language, $locale)) {
						$detect = $key;
						break 2;
					}
				}
			}
		}
	}

	$code = $detect ? $detect : $config->get('config_language');
}

if (!isset($session->data['language']) || $session->data['language'] != $code) {
	$session->data['language'] = $code;
}

$config->set('config_language_id', $languages[$code]['language_id']);
$config->set('config_language', $languages[$code]['code']);

// Language
$language = new Language($languages[$code]['directory']);
$language->load($languages[$code]['directory']);
$registry->set('language', $language);

// Document
$registry->set('document', new Document());

// Customer
$customer = new Customer($registry);
$registry->set('customer', $customer);

// Customer Group
if ($customer->isLogged()) {
	$config->set('config_customer_group_id', $customer->getGroupId());
}
elseif (isset($session->data['customer']) && isset($session->data['customer']['customer_group_id'])) {
	// For API calls
	$config->set('config_customer_group_id', $session->data['customer']['customer_group_id']);
}
elseif (isset($session->data['guest']) && isset($session->data['guest']['customer_group_id'])) {
	$config->set('config_customer_group_id', $session->data['guest']['customer_group_id']);
}

// Affiliate
$registry->set('affiliate', new Affiliate($registry));

// Currency
$registry->set('currency', new Currency($registry));

// Tax
$registry->set('tax', new Tax($registry));

// Weight
$registry->set('weight', new Weight($registry));

// Length
$registry->set('length', new Length($registry));

// Cart
$registry->set('cart', new Cart($registry));

// Encryption
$registry->set('encryption', new Encryption($config->get('config_encryption')));

// OpenBay Pro
$registry->set('openbay', new Openbay($registry));

// Event
$event = new Event($registry);
$registry->set('event', $event);

$query = $db->query("SELECT * FROM " . DB_PREFIX . "event");

foreach ($query->rows as $result) {
	$event->register($result['trigger'], $result['action']);
}

// Front Controller
$controller = new Front($registry);

// Maintenance Mode
$controller->addPreAction(new Action('common/maintenance'));

// SEO URL's
$controller->addPreAction(new Action('common/seo_url'));

// Router
if (isset($request->get['route'])) {
	$action = new Action($request->get['route']);
}
else {
	$action = new Action('common/home');
}

// Dispatch
$controller->dispatch($action, new Action('error/not_found'));

$productsArray = array(array("ID", "ID2", "Item title", "Item subtitle", "Final URL", "Image URL", "Item description", "Item category", "Price", "Sale price", "Item address"));

if ( class_exists( 'ModelCatalogProduct' ) ) {

	$model_catalog_product = new ModelCatalogProduct( $registry );

	require(dirname(__FILE__) . '/catalog/controller/product/product.php');

	$product_controller = new ControllerProductProduct( $registry );

	$product_category = new ModelCatalogCategory( $registry );

	$results = $model_catalog_product->getProducts( array(
		'filter_status' => 1
	) );

	foreach ($results as $product) {

		$product_href = $product_controller->url->link( 'product/product', 'product_id=' . $product['product_id'] );

		$categories = $product_category->getCategories( $product["product_id"] );
		$item_description = substr( preg_replace( "/[\r\n\•]+/", "", strip_tags( str_replace( array( '&quot;', '&nbsp;', '&amp;', '&apos;' ), array( '"', ' ', '&', '\'' ), html_entity_decode( $product["description"], HTML_ENTITIES, 'UTF-8' ) ) ) ), 0, 25 );
		$item_image = HTTP_SERVER . 'image/' . $product["image"];
		$item_category = !is_null( $categories[0]['name'] ) ? $categories[0]['name'] : "";
		$item_price = str_replace( "-", "", number_format( $product["price"], 2 ) ) . " EUR";
		$item_sale = ( !is_null( $product["special"] ) ? str_replace( "-", "", number_format( $product["special"], 2 ) ) . " EUR" : "" );
		$meta_keywords = !empty( $product["meta_keyword"] ) ? str_replace( " ", ", ", $product["meta_keyword"] ) : "";

		$productsArray[] = array(
			"ID" => $product["product_id"], // ID
			"ID2" => $product["product_id"], // ID2
			"Item title" => substr( str_replace( array( '&quot;', '&nbsp;', '&amp;', '&apos;' ), array( '"', ' ', '&', '\'' ), html_entity_decode( $product["name"], HTML_ENTITIES, 'UTF-8' ) ), 0, 25 ), //Item title
			"Item subtitle" => substr( $product["name"], 0, 25 ), //Item title
			"Final URL" => html_entity_decode( $product_href, HTML_ENTITIES, 'UTF-8' ), // Final URL
			"Image URL" => html_entity_decode( $item_image, HTML_ENTITIES, 'UTF-8' ), // Image URL
			"Item description" => $item_description, // Item Description
			"Item category" => html_entity_decode( $item_category, HTML_ENTITIES, 'UTF-8' ), // Item Category
			"Price" => $item_price, // Price
			"Sale price" => $item_sale, // Sale Price
			"Item address" => html_entity_decode( $product["location"], HTML_ENTITIES, 'UTF-8' ) // Item address
		);


	}


	date_default_timezone_set('Europe/Vilnius');

	if (isset($_GET['format']) && $_GET['format'] == 'csv') {
		header('Content-Type: text/html; charset=UTF-8');
		header("Content-type: text/csv");
		header("Content-Disposition: attachment; filename=\"viskasplytelems_feed_" . date('Y-m-d_H:i:s') . ".csv\"");
		header("Pragma: no-cache");
		header("Expires: 0");
		$out = fopen("php://output", 'w');

		foreach ($productsArray as $row) {
			fputcsv($out, $row, ",");
		}
		fclose($out);


		exit();
	}

	require_once(dirname(__FILE__) . '/system/PHPExcel/Classes/PHPExcel.php');

	$doc = new PHPExcel();
	$doc->setActiveSheetIndex(0);
	$doc->getActiveSheet()->fromArray($productsArray, null, 'A1');
	header('Content-Type: text/html; charset=UTF-8');
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment;filename="viskasplytelems_feed_' . date('Y-m-d_His') . '.xls');
	header('Cache-Control: max-age=0');

// Do your stuff here
	$writer = PHPExcel_IOFactory::createWriter($doc, 'Excel5');

	$writer->save('php://output');

	exit();


}

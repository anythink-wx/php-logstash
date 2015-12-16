<?php
/**
 * php-logstash base configure
 */
require __DIR__ .'/logstash.php';
$cfg = [
	'host' => '127.0.0.1',
];


(new LogStash())->handler($cfg)->run();
?>
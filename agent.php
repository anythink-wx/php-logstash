<?php
/**
 * php-logstash base configure
 */
require __DIR__ .'/logstash.php';
$cfg = [
	'redis' => 'tcp://127.0.0.1:6379',
];


(new LogStash())->handler($cfg)->run();
?>
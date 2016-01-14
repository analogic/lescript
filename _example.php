<?php

if(!defined("PHP_VERSION_ID") || PHP_VERSION_ID < 50300 || !extension_loaded('openssl') || !extension_loaded('curl')) {
    die("You need at least PHP 5.3.0 with OpenSSL and curl extension\n");
}
require 'Lescript.php';

// you can use any logger according to Psr\Log\LoggerInterface
class Logger { function __call($name, $arguments) { echo date('Y-m-d H:i:s')." [$name] ${arguments[0]}\n"; }}
$logger = new Logger();

try {

    $le = new Analogic\ACME\Lescript('/certificate/storage', '/var/www/test.com', $logger);
    # or without logger:
    # $le = new Analogic\ACME\Lescript('/certificate/storage', '/var/www/test.com');
    $le->initAccount();
    $le->signDomains(array('test.com', 'www.test.com'));

} catch (\Exception $e) {

    $logger->error($e->getMessage());
    $logger->error($e->getTraceAsString());
}

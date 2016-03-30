<?php
// Lescript automatic updating script.
//
// This is an example of how Lescript can be used to automatically update
// expiring certificates.
//
// This code is based on FreePBX's LetsEncrypt integration
//
// Copyright (c) 2016 Rob Thomas <rthomas@sangoma.com>
// Licence:  AGPLv3.
//
// In addition, Stanislav Humplik <sh@analogic.cz> is explicitly granted permission
// to relicence this code under the open source licence of their choice.

if(!defined("PHP_VERSION_ID") || PHP_VERSION_ID < 50300 || !extension_loaded('openssl') || !extension_loaded('curl')) {
    die("You need at least PHP 5.3.0 with OpenSSL and curl extension\n");
}

// Configuration:
$domains = array('test.example.com', 'example.com');
$webroot = "/var/www/html";
$certlocation = "/usr/local/lescript";

require 'Lescript.php';

// Always use UTC
date_default_timezone_set("UTC");

// you can use any logger according to Psr\Log\LoggerInterface
class Logger { function __call($name, $arguments) { echo date('Y-m-d H:i:s')." [$name] ${arguments[0]}\n"; }}
$logger = new Logger();

// Make sure our cert location exists
if (!is_dir($certlocation)) {
	// Make sure nothing is already there.
	if (file_exists($certlocation)) {
		unlink($certlocation);
	}
	mkdir ($certlocation);
}

// Do we need to create or upgrade our cert? Assume no to start with.
$needsgen = false;

// Do we HAVE a certificate for all our domains?
foreach ($domains as $d) {
	$certfile = "$certlocation/$d/cert.pem";

	if (!file_exists($certfile)) {
		// We don't have a cert, so we need to request one.
		$needsgen = true;
	} else {
		// We DO have a certificate.
		$certdata = openssl_x509_parse(file_get_contents($certfile));

		// If it expires in less than a month, we want to renew it.
		$renewafter = $certdata['validTo_time_t']-(86400*30);
		if (time() > $renewafter) {
			// Less than a month left, we need to renew.
			$needsgen = true;
		}
	}
}

// Do we need to generate a certificate?
if ($needsgen) {
	try {
		$le = new Analogic\ACME\Lescript($certlocation, $webroot, $logger);
		# or without logger:
		# $le = new Analogic\ACME\Lescript($certlocation, $webroot);
		$le->initAccount();
		$le->signDomains($domains);

	} catch (\Exception $e) {
		$logger->error($e->getMessage());
		$logger->error($e->getTraceAsString());
		// Exit with an error code, something went wrong.
		exit(1);
	}
}

// Create a complete .pem file for use with haproxy or apache 2.4,
// and save it as domain.name.pem for easy reference. It doesn't
// matter that this is updated each time, as it'll be exactly
// the same. 
foreach ($domains as $d) {
	$pem = file_get_contents("$certlocation/$d/fullchain.pem")."\n".file_get_contents("$certlocation/$d/private.pem");
	file_put_contents("$certlocation/$d.pem", $pem);
}



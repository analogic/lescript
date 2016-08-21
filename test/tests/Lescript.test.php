<?php

namespace Analogic\ACME\test\tests;

use Analogic\ACME\Lescript;
use Analogic\ACME\test\core\TestCase;
use Psr\Log\NullLogger;

class Lescript_test extends TestCase
{

    public function test_initAccount()
    {

        $lescript = new Lescript($this->certdir, $this->root, new NullLogger());
        $lescript->initAccount();
        $this->assertFileExists($this->certdir . '/_account/private.pem');

    }

}
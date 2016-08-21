<?php

namespace Analogic\ACME\test\core;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{

    protected $certdir;
    protected $root;
    protected $hosts;

    protected function setUp()
    {
        parent::setUp();
        global $RUNNER;

        $this->root = $RUNNER->getRoot();
        $this->certdir = $RUNNER->getCertdir();
        $this->hosts = $RUNNER->getNgrokHosts();
    }

    protected function tearDown()
    {
        parent::tearDown();
        Runner::delTree($this->certdir);
        Runner::delTree($this->root);
    }

}
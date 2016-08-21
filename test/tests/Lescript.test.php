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
        $this->assertFileExists($this->certdir . '/_account/public.pem');

        // existing key should not be overwritten
        $md5 = md5(file_get_contents($this->certdir . '/_account/private.pem'));
        $lescript->initAccount();
        $this->assertEquals($md5, md5(file_get_contents($this->certdir . '/_account/private.pem')));
    }

    public function test_Sign() {
        $lescript = new Lescript($this->certdir, $this->root, new NullLogger());
        $lescript->initAccount();

        $lescript->signDomains(array($this->hosts[0]));

        $outdir = $this->certdir.'/'.$this->hosts[0];
        $this->assertFileExists($outdir.'/cert.pem');
        $this->assertFileExists($outdir.'/chain.pem');
        $this->assertFileExists($outdir.'/fullchain.pem');
        $this->assertFileExists($outdir.'/last.csr');
        $this->assertFileExists($outdir.'/private.pem');
        $this->assertFileExists($outdir.'/public.pem');

        $info = openssl_x509_parse(file_get_contents($outdir.'/cert.pem'));

        $this->assertEquals('Let\'s Encrypt', $info['issuer']['O']);
        $this->assertEquals("/CN={$this->hosts[0]}", $info['name']);
        $this->assertEquals($this->hosts[0], $info['subject']['CN']);
        $this->assertTrue(($info['validTo_time_t'] - time()) > 60*60*24*89, 'certificate should be valid for 90 days');

        $this->assertEquals('DNS:'.$this->hosts[0], $info['extensions']['subjectAltName']);
    }

    public function test_SAN() {
        $lescript = new Lescript($this->certdir, $this->root, new NullLogger());
        $lescript->initAccount();

        $lescript->signDomains($this->hosts);

        $outdir = $this->certdir.'/'.$this->hosts[0];
        $this->assertFileExists($outdir.'/cert.pem');
        $this->assertFileExists($outdir.'/chain.pem');
        $this->assertFileExists($outdir.'/fullchain.pem');
        $this->assertFileExists($outdir.'/last.csr');
        $this->assertFileExists($outdir.'/private.pem');
        $this->assertFileExists($outdir.'/public.pem');

        $info = openssl_x509_parse(file_get_contents($outdir.'/cert.pem'));

        $this->assertEquals('Let\'s Encrypt', $info['issuer']['O']);
        $this->assertEquals("/CN={$this->hosts[0]}", $info['name']);
        $this->assertEquals($this->hosts[0], $info['subject']['CN']);
        $this->assertTrue(($info['validTo_time_t'] - time()) > 60*60*24*89, 'certificate should be valid for 90 days');

        $san = array(
            'DNS:'.$this->hosts[0],
            'DNS:'.$this->hosts[1],
            'DNS:'.$this->hosts[2],
        );
        sort($san);
        $san = join(', ', $san);

        $this->assertEquals($san, $info['extensions']['subjectAltName']);
    }

}
<?php

namespace Analogic\ACME\test\tests;

use Analogic\ACME\test\core\TestCase;

class Runner_test extends TestCase
{

    /**
     * Ensure tunnels are up and local http is working and the test object is set up correct
     */
    public function test_tunnels()
    {
        $this->assertTrue(is_dir($this->root));
        $this->assertTrue(is_dir($this->certdir));
        $this->assertEquals(3, count($this->hosts));

        file_put_contents($this->root . '/test.txt', 'test');
        for ($host = 0; $host < 3; $host++) {
            $domain = $this->hosts[$host];
            $this->assertStringEndsWith('ngrok.io', $domain);
            $data = file_get_contents("http://$domain/test.txt");
            $this->assertEquals('test', $data);
        }
    }

}
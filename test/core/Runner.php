<?php

namespace Analogic\ACME\test\core;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Class Runner
 *
 * Manages the local http server and the ngrok tunnel connections. Also provides utility functions
 * for the tests
 */
class Runner
{
    /**
     * @var string[] list of tunnels
     */
    protected $ngrokhosts = array();

    /**
     * Run the servers
     */
    public function __construct()
    {
        $this->runNgrok(8080);
        $this->runNgrok(8080);
        $this->runNgrok(8080);
        $this->runHTTPServer(8080);
    }

    /**
     * Returns the webroot
     *
     * @return string
     */
    public function getRoot()
    {
        $dir = __DIR__ . '/../webroot/';
        if (!is_dir($dir)) {
            if (!mkdir($dir)) {
                throw new \RuntimeException("$dir not creatable");
            }
        }
        return $dir;
    }

    /**
     * Returns the webroot
     *
     * @return string
     */
    public function getCertdir()
    {
        $dir = __DIR__ . '/../certs/';
        if (!is_dir($dir)) {
            if (!mkdir($dir)) {
                throw new \RuntimeException("$dir not creatable");
            }
        }
        return $dir;
    }

    /**
     * Get the tunnel names
     *
     * @return string[]
     */
    public function getNgrokHosts()
    {
        return $this->ngrokhosts;
    }


    /**
     * Run ngrok in a forked process and get the host name via API
     *
     * @param int $port the http port to tunnel
     */
    protected function runNgrok($port)
    {
        static $apiport = 4040;

        echo "Starting ngrok tunnel...\n";
        $ngrok = $this->getNgrok();
        $pid = pcntl_fork();
        if ($pid == 1) {
            throw new \RuntimeException('Failed to fork');
        } else {
            if ($pid) {
                //child
                pcntl_exec($ngrok, array('http', $port, '--log', 'stderr', '--log-level', 'error'));
            } else {
                //parent
                sleep(2);
                $info = file_get_contents("http://localhost:$apiport/api/tunnels");
                $info = json_decode($info, true);
                if (!$info) {
                    throw new \RuntimeException('Failed to connect to ngrok API');
                }

                foreach ($info['tunnels'] as $tunnel) {
                    if ($tunnel['proto'] == 'https') {
                        continue;
                    }
                    $this->ngrokhosts[] = parse_url($tunnel['public_url'], PHP_URL_HOST);
                }
                // automatically shut down ngrok when the script ends
                register_shutdown_function(
                    function () use ($pid) {
                        posix_kill($pid, SIGINT);
                    }
                );

                $apiport++; // next call uses next port
            }
        }
    }

    /**
     * @param int $port The port to run on
     */
    protected function runHTTPServer($port)
    {
        echo "Starting http server...\n";
        $root = $this->getRoot();
        $pid = pcntl_fork();
        if ($pid) {
            // suppress output
            fclose(STDOUT);
            fclose(STDERR);
            //child
            pcntl_exec(PHP_BINARY, array('-S', "localhost:$port", '-t', $root));
        } else {
            //parent
            sleep(2);

            // automatically shut down httpd when the script ends
            register_shutdown_function(
                function () use ($pid) {
                    posix_kill($pid, SIGINT);
                }
            );
        }
    }

    /**
     * Downloads the correct ngrok binary, if none available, yet
     *
     * @return string path to the ngrok binary
     * @throws \RuntimeException
     */
    protected function getNgrok()
    {
        $ngrokdir = __DIR__ . '/ngrok';

        if (!is_dir($ngrokdir)) {
            if (!mkdir($ngrokdir)) {
                throw new \RuntimeException("$ngrokdir not cretable");
            }
        }

        $bin = $this->getBinaryName();
        $ngrok = $ngrokdir . '/' . $bin;
        $url = "https://bin.equinox.io/c/4VmDzA7iaHb/$bin";
        $url = preg_replace('/\.exe$/', '', $url);

        if (!file_exists($ngrok)) {
            echo "Downloading ngrok...\n";
            file_put_contents($ngrok, file_get_contents($url));
        }

        if (!file_exists($ngrok)) {
            throw new \RuntimeException("Couldn't download $ngrok");
        }

        if (!is_executable($ngrok)) {
            chmod($ngrok, 0755);
        }
        if (!is_executable($ngrok)) {
            throw new \RuntimeException("Can't execute $ngrok");
        }

        return $ngrok;
    }

    /**
     * Figure out what binary is needed on this platform
     *
     * @return string
     * @throws \RuntimeException when the platform can not be determined
     */
    protected function getBinaryName()
    {
        $ext = '';
        $os = php_uname('s');
        if (preg_match('/darwin/i', $os)) {
            $os = 'darwin';
        } elseif (preg_match('/win/i', $os)) {
            $os = 'windows';
            $ext = '.exe';
        } elseif (preg_match('/linux/i', $os)) {
            $os = 'linux';
        } elseif (preg_match('/freebsd/i', $os)) {
            $os = 'freebsd';
        } elseif (preg_match('/openbsd/i', $os)) {
            $os = 'openbsd';
        } elseif (preg_match('/netbsd/i', $os)) {
            $os = 'netbsd';
        } elseif (preg_match('/(solaris|netbsd)/i', $os)) {
            $os = 'freebsd';
        } else {
            throw new \RuntimeException('Unknown platform');
        }
        $arch = php_uname('m');
        if ($arch == 'x86_64') {
            $arch = 'amd64';
        } elseif (preg_match('/arm/i', $arch)) {
            $arch = 'amd';
        } else {
            $arch = '386';
        }
        return "ngrok-stable-$os-$arch$ext";
    }

    /**
     * recursively delete a directory
     *
     * @param $dir
     * @return bool
     * @link http://php.net/manual/en/function.rmdir.php#110489
     */
    public static function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}

$RUNNER = new Runner();

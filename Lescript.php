<?php

namespace Analogic\ACME;

class Lescript
{
    public $ca = 'https://acme-v01.api.letsencrypt.org';
    // public $ca = 'https://acme-staging.api.letsencrypt.org'; // testing
    public $license = 'https://letsencrypt.org/documents/LE-SA-v1.1.1-August-1-2016.pdf';
    public $countryCode = 'CZ';
    public $state = "Czech Republic";
    public $challenge = 'http-01'; // http-01 challange only
    public $contact = array(); // optional
    // public $contact = array("mailto:cert-admin@example.com", "tel:+12025551212")

    protected $certificatesDir;
    protected $webRootDir;

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;
    protected $client;
    protected $accountKeyPath;

    public function __construct($certificatesDir, $webRootDir, $logger = null, ClientInterface $client = null)
    {
        $this->certificatesDir = $certificatesDir;
        $this->webRootDir = $webRootDir;
        $this->logger = $logger;
        $this->client = $client ? $client : new Client($this->ca);
        $this->accountKeyPath = $certificatesDir . '/_account/private.pem';
    }

    public function initAccount()
    {
        if (!is_file($this->accountKeyPath)) {

            // generate and save new private key for account
            // ---------------------------------------------

            $this->log('Starting new account registration');
            $this->generateKey(dirname($this->accountKeyPath));
            $this->postNewReg();
            $this->log('New account certificate registered');

        } else {

            $this->log('Account already registered. Continuing.');

        }
    }

    public function signDomains(array $domains, $reuseCsr = false)
    {
        $this->log('Starting certificate generation process for domains');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        // start domains authentication
        // ----------------------------

        foreach ($domains as $domain) {

            // 1. getting available authentication options
            // -------------------------------------------

            $this->log("Requesting challenge for $domain");

            $response = $this->signedRequest(
                "/acme/new-authz",
                array("resource" => "new-authz", "identifier" => array("type" => "dns", "value" => $domain))
            );
            
            if(empty($response['challenges'])) {
                throw new \RuntimeException("HTTP Challenge for $domain is not available. Whole response: ".json_encode($response));
            }

            $self = $this;
            $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
                return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
            });
            if (!$challenge) throw new \RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));

            $this->log("Got challenge token for $domain");
            $location = $this->client->getLastLocation();


            // 2. saving authentication token for web verification
            // ---------------------------------------------------

            $directory = $this->webRootDir . '/.well-known/acme-challenge';
            $tokenPath = $directory . '/' . $challenge['token'];

            if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Couldn't create directory to expose challenge: ${tokenPath}");
            }

            $header = array(
                // need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

            file_put_contents($tokenPath, $payload);
            chmod($tokenPath, 0644);

            // 3. verification process itself
            // -------------------------------

            $uri = "http://${domain}/.well-known/acme-challenge/${challenge['token']}";

            $this->log("Token for $domain saved at $tokenPath and should be available at $uri");

            // simple self check
            if ($payload !== trim(@file_get_contents($uri))) {
                throw new \RuntimeException("Please check $uri - token not available");
            }

            $this->log("Sending request to challenge");

            // send request to challenge
            $result = $this->signedRequest(
                $challenge['uri'],
                array(
                    "resource" => "challenge",
                    "type" => $this->challenge,
                    "keyAuthorization" => $payload,
                    "token" => $challenge['token']
                )
            );

            // waiting loop
            do {
                if (empty($result['status']) || $result['status'] == "invalid") {
                    throw new \RuntimeException("Verification ended with error: " . json_encode($result));
                }
                $ended = !($result['status'] === "pending");

                if (!$ended) {
                    $this->log("Verification pending, sleeping 1s");
                    sleep(1);
                }

                $result = $this->client->get($location);

            } while (!$ended);

            $this->log("Verification ended with status: ${result['status']}");
            @unlink($tokenPath);
        }

        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        // generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        // load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr")?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

        // request certificates creation
        $result = $this->signedRequest(
            "/acme/new-cert",
            array('resource' => 'new-cert', 'csr' => $csr)
        );
        if ($this->client->getLastCode() !== 201) {
            throw new \RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($result));
        }
        $location = $this->client->getLastLocation();

        // waiting loop
        $certificates = array();
        while (1) {
            $this->client->getLastLinks();

            $result = $this->client->get($location);

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->log("Got certificate! YAY!");
                $certificates[] = $this->parsePemFromBody($result);


                foreach ($this->client->getLastLinks() as $link) {
                    $this->log("Requesting chained cert at $link");
                    $result = $this->client->get($link);
                    $certificates[] = $this->parsePemFromBody($result);
                }

                break;
            } else {

                throw new \RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

            }
        }

        if (empty($certificates)) throw new \RuntimeException('No certificates generated');

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

    protected function readPrivateKey($path)
    {
        if (($key = openssl_pkey_get_private('file://' . $path)) === FALSE) {
            throw new \RuntimeException(openssl_error_string());
        }

        return $key;
    }

    protected function parsePemFromBody($body)
    {
        $pem = chunk_split(base64_encode($body), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . $pem . "-----END CERTIFICATE-----\n";
    }

    protected function getDomainPath($domain)
    {
        return $this->certificatesDir . '/' . $domain . '/';
    }

    protected function postNewReg()
    {
        $this->log('Sending registration to letsencrypt server');

        $data = array('resource' => 'new-reg', 'agreement' => $this->license);
        if(!$this->contact) {
            $data['contact'] = $this->contact;
        }

        return $this->signedRequest(
            '/acme/new-reg',
            $data
        );
    }

    protected function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) {
            return "DNS:" . $dns;
        }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta = stream_get_meta_data($tmpConf);
        $tmpConfPath = $tmpConfMeta["uri"];

        // workaround to get SAN working
        fwrite($tmpConf,
            'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        $csr = openssl_csr_new(
            array(
                "CN" => $domain,
                "ST" => $this->state,
                "C" => $this->countryCode,
                "O" => "Unknown",
            ),
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) throw new \RuntimeException("CSR couldn't be generated! " . openssl_error_string());

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        $csrPath = $this->getDomainPath($domain) . "/last.csr";
        file_put_contents($csrPath, $csr);

        return $this->getCsrContent($csrPath);
    }

    protected function getCsrContent($csrPath) {
        $csr = file_get_contents($csrPath);

        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

    protected function generateKey($outputDirectory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if(!openssl_pkey_export($res, $privateKey)) {
            throw new \RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if(!is_dir($outputDirectory)) @mkdir($outputDirectory, 0700, true);
        if(!is_dir($outputDirectory)) throw new \RuntimeException("Cant't create directory $outputDirectory");

        file_put_contents($outputDirectory.'/private.pem', $privateKey);
        file_put_contents($outputDirectory.'/public.pem', $details['key']);
    }

    protected function signedRequest($uri, array $payload)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $header = array(
            "alg" => "RS256",
            "jwk" => array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            )
        );

        $protected = $header;
        $protected["nonce"] = $this->client->getLastNonce();


        $payload64 = Base64UrlSafeEncoder::encode(str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'header' => $header,
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending signed request to $uri");

        return $this->client->post($uri, json_encode($data));
    }

    protected function log($message)
    {
        if($this->logger) {
            $this->logger->info($message);
        } else {
            echo $message."\n";
        }
    }
}

interface ClientInterface
{
    /**
     * Constructor
     *
     * @param string $base the ACME API base all relative requests are sent to
     */
    public function __construct($base);
    /**
     * Send a POST request
     *
     * @param string $url URL to post to
     * @param array $data fields to sent via post
     * @return array|string the parsed JSON response, raw response on error
     */
    public function post($url, $data);
    /**
     * @param string $url URL to request via get
     * @return array|string the parsed JSON response, raw response on error
     */
    public function get($url);
    /**
     * Returns the Replay-Nonce header of the last request
     *
     * if no request has been made, yet. A GET on $base/directory is done and the
     * resulting nonce returned
     *
     * @return mixed
     */
    public function getLastNonce();
    /**
     * Return the Location header of the last request
     *
     * returns null if last request had no location header
     *
     * @return string|null
     */
    public function getLastLocation();
    /**
     * Return the HTTP status code of the last request
     *
     * @return int
     */
    public function getLastCode();
    /**
     * Get all Link headers of the last request
     *
     * @return string[]
     */
    public function getLastLinks();
}

class Client implements ClientInterface
{
    protected $lastCode;
    protected $lastHeader;

    protected $base;

    public function __construct($base)
    {
        $this->base = $base;
    }

    protected function curl($method, $url, $data = null)
    {
        $headers = array('Accept: application/json', 'Content-Type: application/json');
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, preg_match('~^http~', $url) ? $url : $this->base.$url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, true);

        // DO NOT DO THAT!
        // curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        // curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
                break;
        }
        $response = curl_exec($handle);

        if(curl_errno($handle)) {
            throw new \RuntimeException('Curl: '.curl_error($handle));
        }

        $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $this->lastHeader = $header;
        $this->lastCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        $data = json_decode($body, true);
        return $data === null ? $body : $data;
    }

    public function post($url, $data)
    {
        return $this->curl('POST', $url, $data);
    }

    public function get($url)
    {
        return $this->curl('GET', $url);
    }

    public function getLastNonce()
    {
        if(preg_match('~Replay\-Nonce: (.+)~i', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }

        $this->curl('GET', '/directory');
        return $this->getLastNonce();
    }

    public function getLastLocation()
    {
        if(preg_match('~Location: (.+)~i', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getLastCode()
    {
        return $this->lastCode;
    }

    public function getLastLinks()
    {
        preg_match_all('~Link: <(.+)>;rel="up"~', $this->lastHeader, $matches);
        return $matches[1];
    }
}

class Base64UrlSafeEncoder
{
    public static function encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    public static function decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}

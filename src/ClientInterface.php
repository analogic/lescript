<?php

namespace Analogic\ACME;

/**
 * Defines the features of the HTTP client to communicate with LE
 */
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

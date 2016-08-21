<?php

namespace Analogic\ACME;

/**
 * URL safe Base64 encoding/decoding as described in RFC 7515
 *
 * @link https://tools.ietf.org/html/rfc7515#appendix-C
 */
class Base64UrlSafeEncoder
{
    /**
     * @param string $input
     * @return string
     */
    public static function encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * @param string $input
     * @return string
     */
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

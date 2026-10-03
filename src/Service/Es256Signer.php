<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// The ES256 JSON Web Tokens the AT Protocol's OAuth wants - the client's assertion and every DPoP proof - signed with openssl alone rather than a JWT library, the two kinds of token being all this bundle ever signs
class Es256Signer
{
    // A new P-256 private key, as PEM
    public function generateKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (false === $key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('openssl could not generate a P-256 key.');
        }

        return $pem;
    }

    // The public half of a key, as the JWK a metadata document or a DPoP header carries
    /** @return array<string, string> */
    public function publicJwk(string $privateKeyPem): array
    {
        $details = openssl_pkey_get_details($this->key($privateKeyPem));
        if (false === $details || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('The key is not an EC key.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::base64Url(str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT)),
            'y' => self::base64Url(str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT)),
        ];
    }

    // A compact JWT of the given header and claims, "alg" set here
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public function sign(string $privateKeyPem, array $header, array $claims): string
    {
        $input = self::base64Url((string) json_encode(['alg' => 'ES256', ...$header], \JSON_UNESCAPED_SLASHES))
            . '.' . self::base64Url((string) json_encode($claims, \JSON_UNESCAPED_SLASHES));

        if (!openssl_sign($input, $der, $this->key($privateKeyPem), \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl could not sign the token.');
        }

        return $input . '.' . self::base64Url($this->derToRaw($der));
    }

    // The RFC 7638 thumbprint of a public JWK: its required members in lexicographic order, hashed - a key id that changes with the key itself
    /** @param array<string, string> $jwk */
    public static function thumbprint(array $jwk): string
    {
        return self::base64Url(hash('sha256', (string) json_encode(['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']], \JSON_UNESCAPED_SLASHES), true));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function key(string $privateKeyPem): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if (false === $key) {
            throw new \RuntimeException('The private key cannot be read.');
        }

        return $key;
    }

    // openssl signs as an ASN.1 sequence of two integers, JWS wants them as 32 bytes each, side by side
    private function derToRaw(string $der): string
    {
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\0");
            $raw .= str_pad($integer, 32, "\0", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}

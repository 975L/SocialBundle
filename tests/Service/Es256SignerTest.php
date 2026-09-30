<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\Es256Signer;
use PHPUnit\Framework\TestCase;

class Es256SignerTest extends TestCase
{
    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    // The raw signature turned back into the DER openssl reads, to check it with the public key alone
    private static function rawToDer(string $raw): string
    {
        $integers = '';
        foreach (str_split($raw, 32) as $part) {
            $part = ltrim($part, "\0");
            if (\ord($part[0]) > 0x7F) {
                $part = "\0" . $part;
            }
            $integers .= "\x02" . \chr(\strlen($part)) . $part;
        }

        return "\x30" . \chr(\strlen($integers)) . $integers;
    }

    public function testATokenIsSignedWithTheKeyAndReadableWithItsPublicHalf(): void
    {
        $signer = new Es256Signer();
        $key = $signer->generateKey();

        [$header, $claims, $signature] = explode('.', $signer->sign($key, ['typ' => 'dpop+jwt'], ['htm' => 'POST']));

        $this->assertSame(['alg' => 'ES256', 'typ' => 'dpop+jwt'], json_decode(self::base64UrlDecode($header), true));
        $this->assertSame(['htm' => 'POST'], json_decode(self::base64UrlDecode($claims), true));
        $this->assertSame(64, \strlen(self::base64UrlDecode($signature)));

        $privateKey = openssl_pkey_get_private($key);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $publicKey = openssl_pkey_get_details($privateKey)['key'] ?? '';
        $this->assertSame(1, openssl_verify($header . '.' . $claims, self::rawToDer(self::base64UrlDecode($signature)), $publicKey, \OPENSSL_ALGO_SHA256));
    }

    public function testThePublicJwkCarriesBothCoordinates(): void
    {
        $signer = new Es256Signer();
        $jwk = $signer->publicJwk($signer->generateKey());

        $this->assertSame(['kty', 'crv', 'x', 'y'], array_keys($jwk));
        $this->assertSame('P-256', $jwk['crv']);
        $this->assertSame(32, \strlen(self::base64UrlDecode($jwk['x'])));
    }
}

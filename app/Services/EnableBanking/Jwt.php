<?php

namespace App\Services\EnableBanking;

use RuntimeException;

/**
 * The credential for every API call: a self-signed JWT (RS256).
 *
 * NO LIBRARY FOR THIS. A JWT is three base64url segments joined by dots, and
 * openssl_sign does the signature - ext-openssl is a hard requirement of this
 * project anyway. A dependency pulled in for twenty lines would need watching on
 * every security advisory, which is out of proportion.
 *
 * THE CLAIMS LOOK SWAPPED AND ARE NOT. Normally `iss` names the issuer, i.e.
 * us. Enable Banking however requires:
 *
 *     iss = enablebanking.com
 *     aud = api.enablebanking.com
 *
 * "Correcting" this gets a 401 back. The reference to our own application sits
 * in the `kid` OF THE HEADER instead - that is the application id, and it is how
 * the service knows whose public key should verify the signature.
 *
 * NOT CACHED. A JWT is valid for an hour and signing costs well under a
 * millisecond. A cache would have to track expiry, stay valid across process
 * boundaries and keep the key in memory meanwhile - three ways to get something
 * wrong, for nothing.
 */
class Jwt
{
    /** How long a credential is valid. The docs name an hour as usual, 24 h as the maximum. */
    private const TTL_SECONDS = 3600;

    public function __construct(private readonly KeyVault $vault)
    {
    }

    /**
     * @throws RuntimeException when the key is missing or cannot sign
     */
    public function credential(?int $now = null): string
    {
        $now ??= time();

        $applicationId = $this->vault->applicationId();

        if (blank($applicationId)) {
            throw new RuntimeException('Ohne Anwendungskennung lässt sich kein Ausweis erzeugen.');
        }

        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $applicationId,
        ];

        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ];

        $signed = self::segment($header) . '.' . self::segment($payload);

        $key = @openssl_pkey_get_private($this->vault->key());

        if ($key === false) {
            /*
             * The openssl message is deliberately not passed through: it likes
             * naming paths and file names, and this message can end up in the
             * UI.
             */
            throw new RuntimeException(
                'Der private Schlüssel für Enable Banking ist unbrauchbar – erwartet wird ein '
                . 'RSA-Schlüssel im PEM-Format, so wie ihn das Control Panel ablegt.'
            );
        }

        $signature = '';

        if (! openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Der Ausweis für Enable Banking liess sich nicht signieren.');
        }

        return $signed . '.' . self::base64url($signature);
    }

    /** @param array<string, mixed> $data */
    private static function segment(array $data): string
    {
        return self::base64url((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * base64url WITHOUT padding.
     *
     * The trailing `=` are not allowed in a JWT (RFC 7515), and `+`/`/` would
     * mean something else in a URL. A credential with padding is rejected
     * without anyone saying why.
     */
    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

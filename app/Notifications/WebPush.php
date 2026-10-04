<?php

namespace App\Notifications;

/** RFC 8291/8292: P-256, HKDF-SHA256, aes128gcm и VAPID; без Composer. */
class WebPush
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+={0,2}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid base64url');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url');
        }
        return $decoded;
    }

    public static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $private = '';
        if (!$key || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('EC key generation failed');
        }
        $details = openssl_pkey_get_details($key);
        return ['private' => $private, 'public' => self::encode("\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))];
    }

    public static function publicPem(string $point): string
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new \InvalidArgumentException('Invalid P-256 public key');
        }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function expand(string $prk, string $info, int $length): string
    {
        $output = '';
        $previous = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($i), $prk, true);
            $output .= $previous;
        }
        return substr($output, 0, $length);
    }

    public static function encrypt(string $payload, string $receiverKey, string $auth): string
    {
        if (strlen($payload) > 3000 || strlen($auth) !== 16) {
            throw new \InvalidArgumentException('Invalid push payload');
        }
        $ephemeral = self::keyPair();
        $public = self::decode($ephemeral['public']);
        $peer = openssl_pkey_get_public(self::publicPem($receiverKey));
        $secret = $peer ? openssl_pkey_derive($peer, $ephemeral['private'], 32) : false;
        if (!$secret) {
            throw new \InvalidArgumentException('Invalid push receiver key');
        }
        $prkKey = hash_hmac('sha256', $secret, $auth, true);
        $ikm = self::expand($prkKey, "WebPush: info\0" . $receiverKey . $public, 32);
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = self::expand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::expand($prk, "Content-Encoding: nonce\0", 12);
        $tag = '';
        $encrypted = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($encrypted === false) {
            throw new \RuntimeException('Push encryption failed');
        }
        return $salt . pack('N', 4096) . chr(65) . $public . $encrypted . $tag;
    }

    private static function length(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if ($length < 128) {
            return $length;
        }
        $count = $length & 127;
        if ($count < 1 || $count > 2 || strlen($der) < $offset + $count) {
            throw new \RuntimeException('Invalid ECDSA DER');
        }
        $length = 0;
        while ($count--) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    }

    public static function signature(string $der): string
    {
        $offset = 0;
        if (strlen($der) < 8 || ord($der[$offset++]) !== 0x30) {
            throw new \RuntimeException('Invalid ECDSA signature');
        }
        $size = self::length($der, $offset);
        if ($size !== strlen($der) - $offset) {
            throw new \RuntimeException('Invalid ECDSA sequence');
        }
        $result = '';
        for ($i = 0; $i < 2; $i++) {
            if ($offset >= strlen($der) || ord($der[$offset++]) !== 2) {
                throw new \RuntimeException('Invalid ECDSA integer');
            }
            $length = self::length($der, $offset);
            if ($length < 1 || $offset + $length > strlen($der)) {
                throw new \RuntimeException('Invalid ECDSA integer size');
            }
            $integer = ltrim(substr($der, $offset, $length), "\0");
            $offset += $length;
            if (strlen($integer) > 32) {
                throw new \RuntimeException('Invalid ES256 size');
            }
            $result .= str_pad($integer, 32, "\0", STR_PAD_LEFT);
        }
        return $result;
    }

    public static function authorization(string $audience, array $keys, string $subject): string
    {
        $header = self::encode('{"typ":"JWT","alg":"ES256"}');
        $claims = self::encode(json_encode(['aud' => $audience, 'exp' => time() + 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($input, $signature, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID signing failed');
        }
        return 'vapid t=' . $input . '.' . self::encode(self::signature($signature)) . ', k=' . $keys['public'];
    }
}

<?php

final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 20): string
    {
        if ($length < 16) {
            $length = 16;
        }

        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }

    public static function verifyCode(string $base32Secret, string $code, int $window = 1, int $timeStep = 30, int $digits = 6): bool
    {
        $code = preg_replace('/\D+/', '', $code);
        if ($code === null) {
            return false;
        }

        $code = (string)$code;
        if ($code === '' || strlen($code) !== $digits) {
            return false;
        }

        $secret = self::base32Decode($base32Secret);
        if ($secret === '') {
            return false;
        }

        $time = time();
        $counter = intdiv($time, $timeStep);

        for ($i = -$window; $i <= $window; $i++) {
            $otp = self::hotp($secret, $counter + $i, $digits);
            if (hash_equals($otp, $code)) {
                return true;
            }
        }

        return false;
    }

    public static function buildOtpAuthUri(string $accountName, string $issuer, string $base32Secret, int $digits = 6, int $period = 30): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $issuerEnc = rawurlencode($issuer);
        $secretEnc = rawurlencode($base32Secret);

        return "otpauth://totp/{$label}?secret={$secretEnc}&issuer={$issuerEnc}&algorithm=SHA1&digits={$digits}&period={$period}";
    }

    private static function hotp(string $secret, int $counter, int $digits): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $secret, true);

        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        $mod = 10 ** $digits;
        $otp = (string)($value % $mod);
        return str_pad($otp, $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $out = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(trim($base32));
        $base32 = preg_replace('/[^A-Z2-7]/', '', $base32);
        if ($base32 === null || $base32 === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        $len = strlen($base32);

        for ($i = 0; $i < $len; $i++) {
            $ch = $base32[$i];
            $pos = strpos($alphabet, $ch);
            if ($pos === false) {
                return '';
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($binary, 8);
        $out = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $out .= chr(bindec($byte));
        }

        return $out;
    }
}

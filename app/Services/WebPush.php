<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Web Push (VAPID, RFC 8292) using only PHP's openssl extension - no extra Composer package.
 * We send an EMPTY push: the service worker wakes up and fetches the user's unread notifications itself,
 * which avoids payload encryption and keeps private text off the push service.
 * Keys: php artisan webpush:keys   (writes VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY to .env)
 */
class WebPush
{
    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    public function notifyUser(User $user): void
    {
        if (! $this->enabled()) {
            return;
        }
        foreach ($user->pushSubscriptions as $sub) {
            $this->send($sub);
        }
    }

    public function send(PushSubscription $sub): bool
    {
        try {
            $jwt = $this->jwt($sub->endpoint);
            $res = Http::timeout(8)->withHeaders([
                'Authorization' => 'vapid t='.$jwt.', k='.config('services.webpush.public_key'),
                'TTL' => '3600', 'Urgency' => 'normal', 'Content-Length' => '0',
            ])->withBody('', 'application/octet-stream')->post($sub->endpoint);

            if (in_array($res->status(), [404, 410], true)) {
                $sub->delete(); // the browser unsubscribed

                return false;
            }

            return $res->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Create a fresh P-256 key pair. @return array{public:string,private:string} base64url, as browsers and push services expect */
    public static function generateKeys(): array
    {
        $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($opts);
        if (! $key) {
            // Windows/XAMPP: OpenSSL cannot find its config file. Try the usual places.
            $cands = array_filter([
                getenv('OPENSSL_CONF') ?: null,
                dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
                dirname(PHP_BINARY).'/extras/openssl/openssl.cnf',
                dirname(PHP_BINARY).'/../apache/conf/openssl.cnf',
                'C:/xampp/php/extras/ssl/openssl.cnf',
                'C:/xampp/apache/conf/openssl.cnf',
                'C:/xampp/apache/bin/openssl.cnf',
                'C:/laragon/bin/php/openssl.cnf',
                '/etc/ssl/openssl.cnf',
                '/usr/lib/ssl/openssl.cnf',
            ]);
            foreach ($cands as $cnf) {
                if (is_file($cnf)) {
                    $key = @openssl_pkey_new($opts + ['config' => $cnf]);
                    if ($key) break;
                }
            }
        }
        if (! $key) {
            $err = '';
            while ($m = openssl_error_string()) $err .= $m.' ';
            throw new \RuntimeException('OpenSSL could not create the key ('.trim($err).'). Make sure extension=openssl is enabled in php.ini, or set the OPENSSL_CONF environment variable to your openssl.cnf file (XAMPP: C:\\xampp\\php\\extras\\ssl\\openssl.cnf).');
        }
        $d = openssl_pkey_get_details($key);

        return [
            'public' => self::b64u("\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            'private' => self::b64u(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    private function jwt(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $head = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => config('services.webpush.subject')]));
        $input = $head.'.'.$claims;

        $pem = $this->privatePem();
        openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64u($this->derToRaw($der));
    }

    /** Rebuild a PEM private key from the raw 32-byte secret + public point. */
    private function privatePem(): \OpenSSLAsymmetricKey
    {
        $d = self::unb64u(config('services.webpush.private_key'));
        $pub = self::unb64u(config('services.webpush.public_key'));
        // ASN.1: SEQUENCE { INTEGER 1, OCTET STRING d, [0] OID prime256v1, [1] BIT STRING pub }
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem) ?: throw new \RuntimeException('Invalid VAPID key');
    }

    private function derToRaw(string $der): string
    {
        $pos = 3;
        $rLen = ord($der[$pos]);
        $r = substr($der, $pos + 1, $rLen);
        $pos += $rLen + 2;
        $sLen = ord($der[$pos]);
        $s = substr($der, $pos + 1, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}

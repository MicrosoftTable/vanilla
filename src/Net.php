<?php
namespace Vanilla;

// every outside request goes through here so it can only reach public addresses on the hosts it is allowed to
final class Net {
    // why the last get returned null
    static $error = '';

    // opts: maxBytes hosts (list of allowed hostnames or null for any public host) redirects allowPrivate timeout
    static function get(string $url, array $opts): ?string {
        self::$error = '';
        $hops = max(0, (int)($opts['redirects'] ?? 0));
        for ($i = 0; $i <= $hops; $i++) {
            $t = self::target($url, $opts);
            if ($t === null) {
                self::$error = 'blocked or unresolvable ' . substr((string)parse_url($url, PHP_URL_HOST), 0, 60);
                return null;
            }
            $buf = '';
            $ch = curl_init();
            curl_setopt_array($ch, self::options($t, $buf, $opts));
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err = curl_error($ch);
            curl_close($ch);
            if ($ok && $code >= 200 && $code < 300) {
                return $buf;
            }
            if ($code >= 300 && $code < 400 && $loc !== '') {
                $url = $loc;
                continue;
            }
            self::$error = $code >= 300 ? 'http ' . $code : ($err !== '' ? substr($err, 0, 80) : 'http ' . $code);
            return null;
        }
        self::$error = 'too many redirects';
        return null;
    }

    // a checked url: [url, host, port, ip] or null when it is not allowed
    static function target(string $url, array $opts): ?array {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower(trim($p['host'], '[]'));
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        $hosts = $opts['hosts'] ?? null;
        if (is_array($hosts) && !in_array($host, $hosts, true)) {
            return null;
        }
        $ports = array_merge([80, 443], (array)($opts['ports'] ?? []));
        if (!in_array($port, $ports, true)) {
            return null;
        }
        $ip = self::resolve($host, !empty($opts['allowPrivate']));
        return $ip === null ? null : [$url, $host, $port, $ip];
    }

    // the address a host points at, only if every address it has is public
    static function resolve(string $host, bool $allowPrivate): ?string {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
                return null;
            }
            $ips = @gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                    if (isset($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!$allowPrivate && !self::isPublic($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    static function isPublic(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // ranges the filter above lets through
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96'] as $net) {
            if (self::inRange($ip, $net)) {
                return false;
            }
        }
        return true;
    }

    private static function inRange(string $ip, string $net): bool {
        [$base, $bits] = explode('/', $net);
        $a = @inet_pton($ip);
        $b = @inet_pton($base);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    // curl options for a checked target: the address is pinned so dns cannot change between the check and the connect
    static function options(array $t, string &$buf, array $opts): array {
        [$url, $host, $port, $ip] = $t;
        $max = (int)($opts['maxBytes'] ?? 65536);
        $pin = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        return [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pin],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 20),
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Vanilla/1.0',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => function ($ch, $d) use (&$buf, $max) {
                $buf .= $d;
                return strlen($buf) > $max ? 0 : strlen($d);
            },
        ];
    }
}

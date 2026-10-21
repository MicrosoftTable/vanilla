<?php
// php tests/fetch.php https://yoursite.com/asset/?id=123
// shows why vanilla can or cannot download a url from this server
require __DIR__ . '/../src/Vanilla.php';
$config = require __DIR__ . '/../config.php';
$url = $argv[1] ?? $config['assetBase'] . '1';
$host = strtolower((string)parse_url($url, PHP_URL_HOST));
echo "assetBase  {$config['assetBase']}\n";
echo "host       $host\n";
$ips = @gethostbynamel($host) ?: [];
echo "resolves   " . ($ips ? implode(', ', $ips) : 'nothing') . "\n";
foreach ($ips as $ip) {
    echo "  $ip " . (Vanilla\Net::isPublic($ip) ? 'public' : 'PRIVATE (blocked unless allowPrivateHosts is true)') . "\n";
}
$body = Vanilla\Net::get($url, ['maxBytes' => 65536, 'redirects' => 3, 'allowPrivate' => !empty($config['allowPrivateHosts'])]);
echo $body === null ? 'failed     ' . Vanilla\Net::$error . "\n" : 'ok         ' . strlen($body) . " bytes\n" . substr($body, 0, 120) . "\n";

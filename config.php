<?php
// server side settings that nothing a visitor sends can change
$config = [
    'assetBase' => 'https://example.com/asset/?id=',
    'allowedHosts' => [],
    // only for testing against a server on your own machine or network
    'allowPrivateHosts' => false,
    'cacheDir' => __DIR__ . '/cache',
    'cacheSeconds' => 86400,
    'cacheMaxMB' => 200,
    'maxConcurrent' => 2,
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}
return $config;

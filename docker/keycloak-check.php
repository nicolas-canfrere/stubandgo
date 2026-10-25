<?php

declare(strict_types=1);

// Reads the discovery document of each realm through the internal URL and
// fails unless the announced issuer is the public one.
$internal = getenv('KEYCLOAK_INTERNAL_URL') ?: 'http://keycloak:8080';
$public = getenv('KEYCLOAK_PUBLIC_URL') ?: 'http://localhost:8180';
$failed = false;

foreach (['stubandgo', 'stubandgo-staff'] as $realm) {
    $url = sprintf('%s/realms/%s/.well-known/openid-configuration', $internal, $realm);
    $expected = sprintf('%s/realms/%s', $public, $realm);
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    $issuer = false === $body ? null : (json_decode($body, true)['issuer'] ?? null);

    if ($issuer === $expected) {
        echo "OK   $realm: issuer $issuer\n";
        continue;
    }

    $failed = true;
    echo sprintf("FAIL %s: %s (expected issuer %s)\n", $realm, null === $issuer ? "no discovery document at $url" : "issuer $issuer", $expected);
}

exit($failed ? 1 : 0);

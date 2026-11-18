<?php

/**
 * The Weblate client must not return a Cloudflare Access login page as PO content.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PublishPress\Translations\CloudflareAccessException;
use PublishPress\Translations\WeblateClient;

$failures = 0;

/**
 * @param string $name
 * @param bool $ok
 * @param string $detail
 * @return void
 */
function check($name, $ok, $detail = '')
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL  {$name} {$detail}\n";
}

$login = '<!DOCTYPE html><title>Sign in ・ Cloudflare Access</title>';
$client = new WeblateClient(
    'https://weblate.publishpress.com/api/',
    'token',
    new MockHandler([
        new Response(200, ['Content-Type' => 'text/html;charset=UTF-8'], $login),
    ])
);

try {
    $client->downloadPo('project', 'component', 'de');
    check('login page throws', false, 'downloadPo returned');
} catch (CloudflareAccessException $e) {
    check(
        'login page throws',
        strpos($e->getMessage(), 'CF_ACCESS_CLIENT_ID') !== false,
        $e->getMessage()
    );
}

$po = "msgid \"\"\nmsgstr \"\"\n";
$client = new WeblateClient(
    'https://weblate.publishpress.com/api/',
    'token',
    new MockHandler([
        new Response(200, ['Content-Type' => 'text/x-gettext-catalog'], $po),
    ])
);
check('catalog is returned', $client->downloadPo('project', 'component', 'de') === $po);

$client = new WeblateClient(
    'https://weblate.publishpress.com/api/',
    'token',
    new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
    ])
);

try {
    $client->downloadPo('project', 'component', 'de');
    check('json is rejected', false, 'downloadPo returned');
} catch (Exception $e) {
    check('json is rejected', strpos($e->getMessage(), 'not a PO file') !== false, $e->getMessage());
}

if ($failures > 0) {
    exit(1);
}

echo "OK\n";

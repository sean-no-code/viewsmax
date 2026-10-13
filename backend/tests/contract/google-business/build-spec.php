<?php

/**
 * Builds one mockable API description (Swagger 2.0) from Google's own
 * discovery documents for the three Business Profile APIs we call, so a
 * Prism mock can stand in for Google:
 *
 *   php tests/contract/google-business/build-spec.php /tmp/gbp.json
 *   npx @stoplight/prism-cli@5.14.2 mock /tmp/gbp.json -p 4020
 *
 * Every object rejects fields Google doesn't define and every call needs an
 * Authorization header, so the mock refuses any request that breaks Google's
 * published schema. The three list/create calls we make answer with a demo
 * business (same shapes as Google's) so a local walkthrough looks real.
 *
 * Sources (all public, fetched fresh on each build):
 *   Account Management  https://mybusinessaccountmanagement.googleapis.com/$discovery/rest?version=v1
 *   Business Information https://mybusinessbusinessinformation.googleapis.com/$discovery/rest?version=v1
 *   My Business v4.9 (local posts) https://developers.google.com/my-business/samples/mybusiness_google_rest_v4p9.json
 */
$out = $argv[1] ?? null;
if (! $out) {
    fwrite(STDERR, "usage: php build-spec.php <output.json>\n");
    exit(1);
}

$sources = [
    'acct' => 'https://mybusinessaccountmanagement.googleapis.com/$discovery/rest?version=v1',
    'info' => 'https://mybusinessbusinessinformation.googleapis.com/$discovery/rest?version=v1',
    'v4' => 'https://developers.google.com/my-business/samples/mybusiness_google_rest_v4p9.json',
];

$spec = [
    'swagger' => '2.0',
    'info' => ['title' => 'Google Business Profile (merged from discovery documents)', 'version' => '1'],
    'host' => '127.0.0.1',
    'basePath' => '/',
    'schemes' => ['http'],
    'consumes' => ['application/json'],
    'produces' => ['application/json'],
    'securityDefinitions' => ['bearer' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'Authorization']],
    'security' => [['bearer' => []]],
    'paths' => [],
    'definitions' => [],
];

/** A discovery schema as a Swagger schema; $ref names get the API's prefix. */
$schema = function (array $s, string $prefix) use (&$schema): array {
    if (isset($s['$ref'])) {
        return ['$ref' => "#/definitions/{$prefix}_{$s['$ref']}"];
    }

    $o = array_intersect_key($s, array_flip(['description', 'enum', 'format', 'readOnly']));
    if (($s['type'] ?? 'any') !== 'any') {
        $o['type'] = $s['type'];
    }
    if (isset($s['items'])) {
        $o['items'] = $schema($s['items'], $prefix);
    }
    if (isset($s['properties'])) {
        $o['properties'] = array_map(fn ($p) => $schema($p, $prefix), $s['properties']);
        $o['additionalProperties'] = false;
    } elseif (isset($s['additionalProperties'])) {
        $o['additionalProperties'] = $schema($s['additionalProperties'], $prefix);
    }

    return $o;
};

$methods = function (array $resource) use (&$methods): iterable {
    foreach ($resource['methods'] ?? [] as $m) {
        yield $m;
    }
    foreach ($resource['resources'] ?? [] as $r) {
        yield from $methods($r);
    }
};

foreach ($sources as $prefix => $url) {
    $discovery = json_decode((string) file_get_contents($url), true, 512, JSON_THROW_ON_ERROR);

    foreach ($discovery['schemas'] as $name => $s) {
        $spec['definitions']["{$prefix}_{$name}"] = $schema($s, $prefix);
    }

    foreach ($methods($discovery) as $m) {
        // Google's {+name} paths hold slashes; Prism matches the flat form.
        $path = '/'.($m['flatPath'] ?? $m['path']);
        preg_match_all('/\{(\w+)\}/', $path, $placeholders);
        $params = array_map(fn ($p) => ['name' => $p, 'in' => 'path', 'required' => true, 'type' => 'string'], $placeholders[1]);

        foreach ($m['parameters'] ?? [] as $pname => $p) {
            if ($p['location'] !== 'query') {
                continue;
            }
            $type = in_array($p['type'], ['string', 'integer', 'boolean', 'number'], true) ? $p['type'] : 'string';
            $param = ['name' => $pname, 'in' => 'query', 'type' => $type];
            if (! empty($p['repeated'])) {
                $param = ['name' => $pname, 'in' => 'query', 'type' => 'array', 'items' => ['type' => $type], 'collectionFormat' => 'multi'];
            }
            $params[] = $param;
        }
        if (isset($m['request'])) {
            $params[] = ['name' => 'body', 'in' => 'body', 'required' => true, 'schema' => ['$ref' => "#/definitions/{$prefix}_{$m['request']['$ref']}"]];
        }

        $response = ['description' => 'OK'];
        if (isset($m['response'])) {
            $response['schema'] = ['$ref' => "#/definitions/{$prefix}_{$m['response']['$ref']}"];
        }

        $verb = strtolower($m['httpMethod']);
        if (isset($spec['paths'][$path][$verb])) {
            fwrite(STDERR, "Two methods map to {$verb} {$path}\n");
            exit(1);
        }
        $spec['paths'][$path][$verb] = ['operationId' => $m['id'], 'parameters' => $params, 'responses' => ['200' => $response]];
    }
}

$demo = [
    ['get', '/v1/accounts', ['accounts' => [
        ['name' => 'accounts/1010', 'accountName' => 'Corner Bakery Group', 'type' => 'LOCATION_GROUP'],
    ]]],
    ['get', '/v1/accounts/{accountsId}/locations', ['locations' => [
        [
            'name' => 'locations/2020', 'title' => 'Corner Bakery', 'languageCode' => 'en',
            'storefrontAddress' => ['regionCode' => 'US', 'addressLines' => ['12 Main St'], 'locality' => 'Springfield'],
            'websiteUri' => 'https://cornerbakery.example',
            'metadata' => ['canOperateLocalPost' => true, 'mapsUri' => 'https://maps.google.com/?cid=2020'],
        ],
        [
            'name' => 'locations/2021', 'title' => 'Corner Bakery Downtown', 'languageCode' => 'en',
            'storefrontAddress' => ['regionCode' => 'US', 'addressLines' => ['400 Market St'], 'locality' => 'Springfield'],
            'metadata' => ['canOperateLocalPost' => true, 'mapsUri' => 'https://maps.google.com/?cid=2021'],
        ],
    ]]],
    ['post', '/v4/accounts/{accountsId}/locations/{locationsId}/localPosts', [
        'name' => 'accounts/1010/locations/2020/localPosts/3030',
        'languageCode' => 'en',
        'state' => 'LIVE',
        'searchUrl' => 'https://local.google.com/place?id=2020&use=posts&lpsid=3030',
    ]],
];
foreach ($demo as [$verb, $path, $example]) {
    $spec['paths'][$path][$verb]['responses']['200']['examples'] = ['application/json' => $example];
}

file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($spec['paths'])." paths written to {$out}\n";

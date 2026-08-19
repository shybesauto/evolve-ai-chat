<?php
declare(strict_types=1);

/**
 * §2 — API discovery. Run this FIRST, and stop for review afterwards.
 *
 *   php tools/probe_shopmonkey.php --token=sk_live_xxx
 *   php tools/probe_shopmonkey.php --token=sk_live_xxx --write-findings
 *
 * What this answers (the four questions in §2):
 *   1. Is there a note/comment resource? What are its fields?
 *   2. Does the order object accept a notes property on PUT?
 *   3. Can a note be attributed to a specific userId?
 *   4. Is internal vs. customer-facing a flag, or separate resources?
 *
 * Undocumented endpoints frequently work, so this enumerates far past the
 * eleven documented objects and reports what actually responds.
 *
 * READ-ONLY BY DEFAULT. Question 2 cannot be answered by firing a speculative
 * PUT at a live shop's repair orders — that is how you discover an API by
 * corrupting a real ticket. Instead the probe reads an order and reports which
 * of its fields look note-shaped, and tells you exactly what to try by hand on
 * a scratch order if you want a definitive answer.
 */

require __DIR__ . '/../src/autoload.php';

use ShopVoice\Support\CurlHttpClient;
use ShopVoice\Support\Env;
use ShopVoice\Support\HttpResponse;

$options = getopt('', ['token::', 'base-url::', 'write-findings', 'order-id::', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1600), "\n";
    exit(0);
}

Env::load(dirname(__DIR__) . '/.env');
$token = (string) ($options['token'] ?? Env::get('SHOPMONKEY_TOKEN', ''));
$baseUrl = rtrim((string) ($options['base-url'] ?? Env::get('SHOPMONKEY_BASE_URL', 'https://api.shopmonkey.cloud/v3/')), '/') . '/';

if ($token === '') {
    fwrite(STDERR, "No token. Pass --token=... or set SHOPMONKEY_TOKEN in .env\n");
    exit(1);
}

$http = new CurlHttpClient(20);

/** Paths worth asking about: the documented eleven, plus every plausible note shape. */
$documented = [
    'appointment', 'customer', 'inspection', 'inventory', 'message', 'order',
    'payment', 'purchaseorder', 'purchase_order', 'user', 'vehicle', 'vendor',
];

$noteCandidates = [
    'note', 'notes', 'comment', 'comments', 'ordernote', 'order_note', 'orderNote',
    'internalnote', 'internal_note', 'activity', 'activities', 'timeline',
    'annotation', 'message', 'orderComment', 'order_comment',
];

$otherCandidates = ['service', 'labor', 'part', 'tire', 'fee', 'subcontract', 'attachment', 'file', 'location', 'shop'];

echo "Shopmonkey API probe\n";
echo "base: {$baseUrl}\n";
echo str_repeat('=', 72), "\n\n";

$findings = [
    'probed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'base_url' => $baseUrl,
    'endpoints' => [],
    'note_fields' => [],
    'order_note_fields' => [],
    'user_attribution' => null,
];

$call = static function (string $method, string $path, ?array $body = null) use ($http, $baseUrl, $token): HttpResponse {
    return $http->request(
        $method,
        $baseUrl . ltrim($path, '/'),
        [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        $body === null ? null : json_encode($body)
    );
};

$report = static function (string $method, string $path, HttpResponse $response) use (&$findings): void {
    $verdict = match (true) {
        $response->error !== null => 'network: ' . $response->error,
        $response->status === 200 || $response->status === 201 => 'RESPONDS',
        $response->status === 400 || $response->status === 422 => 'EXISTS (rejected our body)',
        $response->status === 401 || $response->status === 403 => 'auth/permission',
        $response->status === 404 => 'not found',
        $response->status === 405 => 'EXISTS (wrong method)',
        $response->status === 429 => 'rate limited',
        default => 'status ' . $response->status,
    };

    $interesting = in_array($response->status, [200, 201, 400, 405, 422], true);

    printf("  %-6s %-24s %-3d  %s\n", $method, $path, $response->status, $verdict);

    $findings['endpoints'][] = [
        'method' => $method,
        'path' => $path,
        'status' => $response->status,
        'verdict' => $verdict,
        'interesting' => $interesting,
        'sample_keys' => $interesting ? array_slice(array_keys($response->json() ?? []), 0, 12) : [],
    ];
};

// --- 1. documented objects, to confirm the token and the base path ---------
echo "Documented objects (sanity check on the token)\n";
foreach ($documented as $path) {
    $report('GET', $path, $call('GET', $path . '?limit=1'));
}

// --- 2. the actual question: is there a note resource? ---------------------
echo "\nNote-shaped resources (§2 question 1)\n";
echo "  Internal notes in their UI support attachments and @-mentions of shop\n";
echo "  users, which points at a note being its own record type rather than a\n";
echo "  text field on the order. Looking for that record type.\n\n";

foreach ($noteCandidates as $path) {
    $response = $call('GET', $path . '?limit=1');
    $report('GET', $path, $response);

    if ($response->ok()) {
        $payload = $response->json() ?? [];
        $records = $payload['data'] ?? $payload['results'] ?? $payload;
        if (is_array($records) && isset($records[0]) && is_array($records[0])) {
            $findings['note_fields'][$path] = array_keys($records[0]);
            echo "         fields: ", implode(', ', array_keys($records[0])), "\n";

            // §2 questions 3 and 4, answered straight off a real record.
            $keys = array_map('strtolower', array_keys($records[0]));
            $attribution = array_values(array_filter($keys, static fn (string $k): bool
                => str_contains($k, 'user') || str_contains($k, 'author') || str_contains($k, 'createdby')));
            $visibility = array_values(array_filter($keys, static fn (string $k): bool
                => str_contains($k, 'internal') || str_contains($k, 'public') || str_contains($k, 'visib') || str_contains($k, 'customer')));

            if ($attribution !== []) {
                echo "         attribution candidates (Q3): ", implode(', ', $attribution), "\n";
                $findings['user_attribution'] = $attribution;
            }
            if ($visibility !== []) {
                echo "         internal/customer flag candidates (Q4): ", implode(', ', $visibility), "\n";
            }
        }
    }

    // A 405 on GET with a 400/422 on POST is the classic signature of a
    // write-only collection, so it is worth one careful, empty POST.
    if (in_array($response->status, [404, 405], true)) {
        $post = $call('POST', $path, []);
        if (in_array($post->status, [400, 422], true)) {
            $report('POST', $path, $post);
            echo "         ^ rejected an empty body — this endpoint probably exists\n";
        }
    }
}

// --- 3. does the order itself carry notes? (§2 question 2) ----------------
echo "\nOrder shape (§2 question 2)\n";

$orderId = $options['order-id'] ?? null;
$orderResponse = $orderId !== null
    ? $call('GET', 'order/' . rawurlencode((string) $orderId))
    : $call('GET', 'order?limit=1');

if ($orderResponse->ok()) {
    $payload = $orderResponse->json() ?? [];
    $records = $payload['data'] ?? $payload['results'] ?? $payload;
    $order = isset($records[0]) && is_array($records[0]) ? $records[0] : (is_array($records) ? $records : []);

    $noteish = array_values(array_filter(
        array_keys($order),
        static fn (string $k): bool => str_contains(strtolower($k), 'note')
            || str_contains(strtolower($k), 'comment')
            || str_contains(strtolower($k), 'complaint')
    ));

    $findings['order_note_fields'] = $noteish;

    echo "  order fields: ", implode(', ', array_slice(array_keys($order), 0, 25)), "\n";
    echo "  note-shaped fields on the order: ", $noteish === [] ? '(none)' : implode(', ', $noteish), "\n";

    if ($noteish !== []) {
        echo "\n  A field exists, but whether PUT accepts it is a separate question and\n";
        echo "  this probe will not answer it by writing to a live order. To settle it:\n";
        echo "    1. create a throwaway RO in Shopmonkey by hand\n";
        echo "    2. PUT that order id with only the note field changed\n";
        echo "    3. confirm in their UI whether it landed as an INTERNAL note\n";
        echo "       (a value that surfaces to the customer is a different field)\n";
    }
} else {
    echo "  could not read an order (status {$orderResponse->status})\n";
}

// --- 4. anything else that answers --------------------------------------
echo "\nOther undocumented paths\n";
foreach ($otherCandidates as $path) {
    $report('GET', $path, $call('GET', $path . '?limit=1'));
}

// --- summary -------------------------------------------------------------
$responding = array_values(array_filter($findings['endpoints'], static fn (array $e): bool => $e['interesting']));

echo "\n", str_repeat('=', 72), "\n";
echo count($responding), " endpoint(s) responded in a way worth following up.\n";

if (isset($options['write-findings'])) {
    $path = dirname(__DIR__) . '/docs/shopmonkey-api-findings.md';
    file_put_contents($path, renderFindings($findings));
    echo "Wrote {$path}\n";
}

echo "\nSTOP HERE. §2 says write up the findings and stop for review before any\n";
echo "note-writing code is built. Until config/shopmonkey_endpoints.php marks\n";
echo "note.create as verified, ShopmonkeyNoteStore refuses to send anything and\n";
echo "notes stay in our own table — which is a working fallback, not an outage.\n";

/** @param array<string,mixed> $findings */
function renderFindings(array $findings): string
{
    $lines = [];
    $lines[] = '# Shopmonkey API findings';
    $lines[] = '';
    $lines[] = '_Generated by `tools/probe_shopmonkey.php` at ' . $findings['probed_at'] . '._';
    $lines[] = '';
    $lines[] = 'Base URL probed: `' . $findings['base_url'] . '`';
    $lines[] = '';
    $lines[] = '## What responded';
    $lines[] = '';
    $lines[] = '| Method | Path | Status | Verdict | Sample keys |';
    $lines[] = '|---|---|---|---|---|';

    foreach ($findings['endpoints'] as $endpoint) {
        $lines[] = sprintf(
            '| %s | `%s` | %d | %s | %s |',
            $endpoint['method'],
            $endpoint['path'],
            $endpoint['status'],
            $endpoint['verdict'],
            $endpoint['sample_keys'] === [] ? '—' : '`' . implode('`, `', $endpoint['sample_keys']) . '`'
        );
    }

    $lines[] = '';
    $lines[] = '## The four questions';
    $lines[] = '';
    $lines[] = '**1. Is there a note/comment resource? What are its fields?**';
    $lines[] = '';
    if ($findings['note_fields'] === []) {
        $lines[] = 'No note-shaped resource responded. Keep `NOTE_STORE=local`.';
    } else {
        foreach ($findings['note_fields'] as $path => $fields) {
            $lines[] = sprintf('- `%s` — fields: `%s`', $path, implode('`, `', $fields));
        }
    }
    $lines[] = '';
    $lines[] = '**2. Does the order object accept a notes property on PUT?**';
    $lines[] = '';
    $lines[] = $findings['order_note_fields'] === []
        ? 'No note-shaped field found on the order object.'
        : 'Note-shaped fields on the order: `' . implode('`, `', $findings['order_note_fields'])
          . '`. Whether PUT accepts them is UNTESTED — settle it on a throwaway RO by hand, not here.';
    $lines[] = '';
    $lines[] = '**3. Can a note be attributed to a specific `userId`?**';
    $lines[] = '';
    $lines[] = $findings['user_attribution'] === null
        ? 'Undetermined. This matters legally: MCL 257.1313b puts the mechanic\'s name and certification number on the invoice, so if the API cannot attribute a note, attribution has to be carried in the note body.'
        : 'Candidate fields: `' . implode('`, `', $findings['user_attribution']) . '`.';
    $lines[] = '';
    $lines[] = '**4. Is internal vs. customer-facing a flag, or separate resources?**';
    $lines[] = '';
    $lines[] = 'Fill in from the field lists above. Getting this wrong prints a technician\'s raw diagnostic language on a customer invoice.';
    $lines[] = '';
    $lines[] = '## Decision';
    $lines[] = '';
    $lines[] = '- [ ] Reviewed by Russ';
    $lines[] = '- [ ] `config/shopmonkey_endpoints.php` corrected';
    $lines[] = '- [ ] `note.create` marked `verified => true`';
    $lines[] = '- [ ] `NOTE_STORE=shopmonkey` in `.env`';
    $lines[] = '';

    return implode("\n", $lines) . "\n";
}

<?php
/**
 * Adversarial tests for the serialization-safe search-replace (no WordPress
 * needed):  php tests/serialized-replace.php
 *
 * The decisive check for serialized inputs is that the OUTPUT re-unserializes to
 * the correctly-replaced structure, that catches any byte-length mistake, which
 * is the whole point of doing this safely.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}

require __DIR__ . '/../src/Engine/Transform/SerializedReplacer.php';

use Migrator\Engine\Transform\SerializedReplacer;

$FROM = 'https://old.example';
$TO   = 'https://new-site.example.com';

$failures = 0;
function ok(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  ok   " : "  FAIL ") . $label . "\n";
    if (! $cond) {
        $failures++;
    }
}

/** Replace in $input and return [result, count]. Fresh replacer each time. */
function run(mixed $input): array
{
    global $FROM, $TO;
    $r   = new SerializedReplacer($FROM, $TO);
    $out = $r->replace($input);
    return [$out, $r->replacements()];
}

// 1. Plain string.
[$out] = run('go to https://old.example/page');
ok('plain string replaced', $out === 'go to https://new-site.example.com/page');

// 2. Plain string, no match → untouched, byte-identical.
[$out, $n] = run('nothing here');
ok('non-matching string untouched', $out === 'nothing here' && $n === 0);

// 3. Serialized string, length changes (longer). Must re-unserialize correctly.
$ser = serialize('https://old.example/path');
[$out] = run($ser);
ok('serialized string re-unserializes to replaced value',
    unserialize($out) === 'https://new-site.example.com/path');
ok('serialized string output is itself valid serialized', @unserialize($out) !== false);

// 4. Serialized array with nested URLs (theme-mods shape).
$arr = ['logo' => 'https://old.example/logo.png', 'count' => 7, 'on' => true, 'nested' => ['u' => 'https://old.example']];
$ser = serialize($arr);
[$out] = run($ser);
$expected = ['logo' => 'https://new-site.example.com/logo.png', 'count' => 7, 'on' => true, 'nested' => ['u' => 'https://new-site.example.com']];
ok('serialized array re-unserializes with all URLs replaced + scalars intact',
    unserialize($out) === $expected);

// 5. Serialized string nested INSIDE a serialized array (double layer).
$inner = serialize('https://old.example/deep');
$outerArr = ['blob' => $inner, 'x' => 1];
$ser = serialize($outerArr);
[$out] = run($ser);
$decoded = unserialize($out);
ok('outer array valid + inner blob still serialized',
    is_array($decoded) && is_string($decoded['blob']) && @unserialize($decoded['blob']) !== false);
ok('inner serialized blob got its URL replaced with correct length',
    unserialize($decoded['blob']) === 'https://new-site.example.com/deep');

// 6. Serialized stdClass object with a URL property.
$obj = new stdClass();
$obj->home = 'https://old.example';
$obj->n    = 42;
$ser = serialize($obj);
[$out] = run($ser);
$ro = unserialize($out);
ok('serialized object re-unserializes with property replaced',
    $ro instanceof stdClass && $ro->home === 'https://new-site.example.com' && $ro->n === 42);

// 7. Unknown-class object (incomplete on unserialize) is left BYTE-IDENTICAL.
$incomplete = 'O:7:"Unknown":1:{s:3:"url";s:19:"https://old.example";}';
[$out, $n] = run($incomplete);
ok('unknown-class serialized object left untouched (no corruption)',
    $out === $incomplete && $n === 0);

// 7b. A serialized array CONTAINING an unknown-class object is skipped wholesale.
$mixed = 'a:2:{s:4:"safe";s:19:"https://old.example";s:3:"obj";O:7:"Unknown":0:{}}';
[$out, $n] = run($mixed);
ok('array containing unknown-class object left untouched', $out === $mixed && $n === 0);

// 8. JSON value containing a URL (WooCommerce-style meta), plain JSON.
$json = '{"shipping":{"url":"https://old.example/x"},"id":5}';
[$out, $n] = run($json);
$jd = json_decode($out, true);
ok('JSON url replaced and still decodes',
    is_array($jd) && $jd['shipping']['url'] === 'https://new-site.example.com/x' && $jd['id'] === 5 && $n >= 1);

// 9. JSON containing a SERIALIZED string with a URL (length must be fixed).
$serInside = serialize(['link' => 'https://old.example/y']);
$json = wp_json_encode(['meta' => $serInside]);
[$out] = run($json);
$jd = json_decode($out, true);
ok('serialized string embedded in JSON: outer JSON valid',
    is_array($jd) && isset($jd['meta']) && is_string($jd['meta']));
ok('serialized string embedded in JSON: inner re-unserializes correctly',
    unserialize($jd['meta']) === ['link' => 'https://new-site.example.com/y']);

// 10. JSON with no match → returned intact (not reformatted).
$json = '{"a":"keep me","b":[1,2,3]}';
[$out, $n] = run($json);
ok('non-matching JSON returned byte-identical', $out === $json && $n === 0);

// 10b. JSON whose types a decode/encode round trip would change.
$json = '{"url":"https:\\/\\/old.example\\/z","empty":{},"list":[],"price":1.0,"id":9007199254740993123,"name":"\\u0141\\u00f3d\\u017a"}';
[$out, $n] = run($json);
ok('JSON keeps {}, 1.0, bigints and escapes while the URL is replaced',
    $out === '{"url":"https:\\/\\/new-site.example.com\\/z","empty":{},"list":[],"price":1.0,"id":9007199254740993123,"name":"\\u0141\\u00f3d\\u017a"}' && 1 === $n);
[$out] = run('{"u":"https://old.example/a","o":{}}');
ok('JSON with unescaped slashes is replaced in place', $out === '{"u":"https://new-site.example.com/a","o":{}}');

// 11. Looks-like-JSON but isn't (shortcode) → plain replace, no corruption.
[$out] = run('[gallery link="https://old.example"]');
ok('bracket string that is not JSON handled as plain string',
    $out === '[gallery link="https://new-site.example.com"]');

// 12. Multibyte: byte-length must be recomputed, not char-length.
$ser = serialize('Ścieżka https://old.example/ąęś');
[$out] = run($ser);
ok('multibyte serialized value re-unserializes correctly',
    unserialize($out) === 'Ścieżka https://new-site.example.com/ąęś');

// 13. Multiple from/to pairs (URL + filesystem path), independent.
$r = new SerializedReplacer(['https://old.example', '/var/www/old'], ['https://new-site.example.com', '/srv/new']);
$ser = serialize(['url' => 'https://old.example', 'path' => '/var/www/old/wp-content']);
$out = $r->replace($ser);
ok('multiple replacement pairs applied to serialized array',
    unserialize($out) === ['url' => 'https://new-site.example.com', 'path' => '/srv/new/wp-content']);

// 14. Shorter replacement (new shorter than old) still length-correct.
$r = new SerializedReplacer('https://old.example', 'https://x.io');
$ser = serialize('https://old.example/abc');
$out = $r->replace($ser);
ok('shorter replacement keeps serialized length correct',
    unserialize($out) === 'https://x.io/abc');

// 15. Deeply nested mixed structure round-trips.
$deep = serialize(['a' => ['b' => ['c' => serialize(['d' => 'https://old.example'])]]]);
[$out] = run($deep);
$top = unserialize($out);
ok('deeply nested + inner-serialized structure replaced correctly',
    unserialize($top['a']['b']['c']) === ['d' => 'https://new-site.example.com']);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);

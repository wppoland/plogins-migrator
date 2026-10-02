<?php

declare(strict_types=1);

namespace Migrator\Engine\Transform;

defined('ABSPATH') || exit;

/**
 * Search-and-replace that is safe across PHP-serialized data.
 *
 * A naive `str_replace` on a database dump corrupts serialized values: changing
 * "https://old" to "https://new.example" leaves the byte-length prefix
 * (`s:10:"https://old"`) pointing at the wrong length, and WordPress then fails
 * to unserialize the value. This walks the *decoded* structure instead -
 * unserialize, replace inside, re-serialize, so lengths are always recomputed
 * correctly. It never rewrites raw serialized strings with a regex.
 *
 * Safety rules:
 *  - Serialized blobs containing an object of an *unknown* class
 *    (`__PHP_Incomplete_Class`) are left completely untouched, because
 *    re-serializing one would corrupt it. We skip the replacement rather than
 *    risk the data.
 *  - JSON is replaced as text, in each escaping an encoder could have used,
 *    so its formatting and number types survive. It is only decoded and
 *    re-encoded when it wraps a serialized PHP string that needs the change
 *    (WooCommerce stores those inside JSON meta).
 *
 * Usage:
 *   $r   = new SerializedReplacer($fromUrl, $toUrl);          // or arrays
 *   $new = $r->replace($cellValue);
 *   $n   = $r->replacements();  // count, for dry-run reporting
 */
final class SerializedReplacer
{
    /** @var string[] */
    private array $from;

    /** @var string[] */
    private array $to;

    private int $count = 0;

    /**
     * @param string|string[] $from
     * @param string|string[] $to
     */
    public function __construct(string|array $from, string|array $to)
    {
        $this->from = array_values((array) $from);
        $this->to   = array_values((array) $to);
    }

    /**
     * Apply the replacement to one value (typically a database cell), returning
     * the transformed value with serialized lengths kept consistent.
     */
    public function replace(mixed $data): mixed
    {
        return $this->process($data, false);
    }

    /**
     * Number of individual string replacements performed since construction.
     * Use it to drive a dry-run report or to decide whether a row changed.
     */
    public function replacements(): int
    {
        return $this->count;
    }

    public function resetCount(): void
    {
        $this->count = 0;
    }

    /**
     * @param bool $serialised When true, the caller unwrapped a serialized
     *                         string to reach this value, so the result is
     *                         re-serialized before returning.
     */
    private function process(mixed $data, bool $serialised): mixed
    {
        // Restrict unserialize to stdClass: arrays and plain objects round-trip,
        // but a crafted archive cannot instantiate arbitrary classes (which could
        // run __wakeup/__destruct, PHP object injection). Unknown classes decode
        // to __PHP_Incomplete_Class and are left untouched below.
        if (is_string($data) && '' !== $data && $this->isSerialized($data) && false !== ($un = @unserialize($data, ['allowed_classes' => ['stdClass']]))) {
            if (! $this->hasIncompleteClass($un)) {
                $data = $this->process($un, true);
            }
            // Incomplete class present: leave the original serialized string as-is.
        } elseif (is_array($data)) {
            $tmp = [];
            foreach ($data as $key => $value) {
                $tmp[$key] = $this->process($value, false);
            }
            $data = $tmp;
        } elseif (is_object($data)) {
            if (! ($data instanceof \__PHP_Incomplete_Class)) {
                $clone = clone $data;
                foreach (get_object_vars($data) as $key => $value) {
                    $clone->$key = $this->process($value, false);
                }
                $data = $clone;
            }
        } elseif (is_string($data)) {
            $data = $this->replaceInString($data);
        }

        if ($serialised) {
            return serialize($data);
        }

        return $data;
    }

    /**
     * Replace inside a plain string. JSON is edited as text wherever possible:
     * decoding and re-encoding it turned {} into [], 1.0 into 1 and a 64-bit id
     * into a float that lost its last digits, and changed every escape in the
     * document, all for one URL. Only JSON that carries a serialized PHP string
     * needing the replacement is decoded, because that string's byte length has
     * to be recomputed.
     */
    private function replaceInString(string $data): string
    {
        $trimmed = ltrim($data);
        if ('' !== $trimmed && ('{' === $trimmed[0] || '[' === $trimmed[0])) {
            $decoded = json_decode($data, false, 512, JSON_BIGINT_AS_STRING);
            if ((is_array($decoded) || is_object($decoded)) && JSON_ERROR_NONE === json_last_error()) {
                if (! $this->holdsSerializedMatch($decoded)) {
                    return $this->replaceJsonText($data);
                }

                // ponytail: this path re-encodes, which keeps {} and 1.0 but
                // writes a bigint as a quoted string. It only runs for JSON
                // wrapping serialized PHP; a token-level rewriter would lift
                // that ceiling if it ever matters.
                $processed = $this->process($decoded, false);
                $encoded   = wp_json_encode($processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                if (is_string($encoded)) {
                    return $encoded;
                }
            }
        }

        $replaced     = 0;
        $out          = str_replace($this->from, $this->to, $data, $replaced);
        $this->count += $replaced;

        return $out;
    }

    /**
     * Replace in JSON text, matching each search string in every escaping a
     * JSON encoder produces for it ("https:\/\/old" as well as "https://old"),
     * and writing the replacement in the same escaping.
     */
    private function replaceJsonText(string $json): string
    {
        $from = [];
        $to   = [];
        foreach ($this->from as $i => $search) {
            foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, 0] as $flags) {
                $f = substr((string) json_encode($search, $flags), 1, -1);
                if ('' === $f || in_array($f, $from, true)) {
                    continue;
                }
                $from[] = $f;
                $to[]   = substr((string) json_encode($this->to[$i] ?? '', $flags), 1, -1);
            }
        }

        $replaced     = 0;
        $out          = str_replace($from, $to, $json, $replaced);
        $this->count += $replaced;

        return $out;
    }

    /**
     * Whether a decoded JSON value holds a serialized PHP string that contains
     * one of the search strings, the one case plain text replacement breaks.
     */
    private function holdsSerializedMatch(mixed $data): bool
    {
        if (is_string($data)) {
            if (! $this->isSerialized($data)) {
                return false;
            }
            foreach ($this->from as $search) {
                if ('' !== $search && str_contains($data, $search)) {
                    return true;
                }
            }

            return false;
        }
        if (is_array($data) || is_object($data)) {
            foreach ((array) $data as $value) {
                if ($this->holdsSerializedMatch($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasIncompleteClass(mixed $data): bool
    {
        if ($data instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($data)) {
            foreach ($data as $value) {
                if ($this->hasIncompleteClass($value)) {
                    return true;
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                if ($this->hasIncompleteClass($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a string is PHP-serialized data. Faithful port of WordPress core's
     * is_serialized(), so the engine stays portable outside WordPress (tests,
     * future package extraction) without depending on WP being loaded.
     */
    private function isSerialized(string $data): bool
    {
        $data = trim($data);

        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if (':' !== $data[1]) {
            return false;
        }

        $semicolon = strpos($data, ';');
        $brace     = strpos($data, '}');
        if (false === $semicolon && false === $brace) {
            return false;
        }
        if (false !== $semicolon && $semicolon < 3) {
            return false;
        }
        if (false !== $brace && $brace < 4) {
            return false;
        }

        $token = $data[0];
        switch ($token) {
            case 's':
                if ('"' !== substr($data, -2, 1)) {
                    return false;
                }
                // fall through.
            case 'a':
            case 'O':
            case 'E':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;/", $data);
        }

        return false;
    }
}

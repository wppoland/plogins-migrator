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
 * to unserialize the value.
 *
 * This implementation uses a non-instantiating token parser (modeled after
 * All-in-One WP Migration's approach) that reads serialized tokens (s, a, O,
 * o, i, d, b, N, C, E, r, R), replaces strings using strtr, and recalculates
 * byte lengths via strlen() in raw 8-bit bytes (preserving multi-byte UTF-8).
 *
 * Safety rules and benefits:
 *  - 0 PHP Object Injection risk: classes are never loaded or instantiated
 *    during search and replace.
 *  - 100% data preservation: custom plugin classes (WooCommerce, Elementor,
 *    RankMath) are fully traversed and their URLs and paths updated, without
 *    turning them into __PHP_Incomplete_Class or skipping them.
 *  - JSON values are edited as text wherever possible so formatting, bigints,
 *    and float precision survive. JSON wrapping serialized PHP strings is
 *    decoded only to update the inner serialized string's byte lengths.
 *
 * Usage:
 *   $r   = new SerializedReplacer($fromUrl, $toUrl); // or arrays
 *   $new = $r->replace($cellValue);
 *   $n   = $r->replacements(); // count, for dry-run reporting
 */
final class SerializedReplacer
{
    /** @var string[] */
    private array $from;

    /** @var string[] */
    private array $to;

    /** @var array<string, string> */
    private array $pairs = [];

    private int $count = 0;

    /**
     * @param string|string[] $from
     * @param string|string[] $to
     */
    public function __construct(string|array $from, string|array $to)
    {
        $this->from = array_values((array) $from);
        $this->to   = array_values((array) $to);

        foreach ($this->from as $i => $search) {
            if ('' !== $search && isset($this->to[$i])) {
                $this->pairs[$search] = $this->to[$i];
            }
        }

        uksort($this->pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Apply the replacement to one value (typically a database cell), returning
     * the transformed value with serialized lengths kept consistent.
     */
    public function replace(mixed $data): mixed
    {
        return $this->process($data);
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

    private function process(mixed $data): mixed
    {
        if (is_string($data) && '' !== $data) {
            if ($this->isSerialized($data)) {
                $trimmed    = trim($data);
                $pos        = 0;
                $countStart = $this->count;
                $parsed     = $this->parseSerialized($trimmed, $pos);
                if (null !== $parsed && $pos === strlen($trimmed)) {
                    if ($trimmed === $data) {
                        return $parsed;
                    }

                    $leading  = substr($data, 0, (int) strpos($data, $trimmed));
                    $trailing = substr($data, (int) strpos($data, $trimmed) + strlen($trimmed));

                    return $leading . $parsed . $trailing;
                }

                $this->count = $countStart;
            }

            return $this->replaceInString($data);
        }

        if (is_array($data)) {
            $tmp = [];
            foreach ($data as $key => $value) {
                $tmp[$key] = $this->process($value);
            }

            return $tmp;
        }

        if (is_object($data)) {
            if (! ($data instanceof \__PHP_Incomplete_Class)) {
                $clone = clone $data;
                foreach (get_object_vars($data) as $key => $value) {
                    $clone->$key = $this->process($value);
                }

                return $clone;
            }

            return $data;
        }

        return $data;
    }

    /**
     * Parse one serialized token from $data at $pos, replacing targets in strings
     * and recomputing byte lengths without instantiating objects.
     *
     * @param string $data Serialized data.
     * @param int    $pos  Byte position within $data (passed by reference).
     *
     * @return string|null Re-encoded token string or null on parse error.
     */
    private function parseSerialized(string $data, int &$pos): ?string
    {
        $length = strlen($data);
        if ($pos >= $length) {
            return null;
        }

        $startPos = $pos;
        $type     = $data[$pos];
        $pos++;

        switch ($type) {
            case 's':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $lenEnd = strpos($data, ':', $pos);
                if (false === $lenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $lenStr = substr($data, $pos, $lenEnd - $pos);
                if (! ctype_digit($lenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $strLength = (int) $lenStr;

                $pos = $lenEnd + 1;
                if (! isset($data[$pos]) || '"' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($pos + $strLength + 2 > $length) {
                    $pos = $startPos;
                    return null;
                }

                $str = substr($data, $pos, $strLength);
                $pos += $strLength;

                if ('"' !== $data[$pos] || ';' !== $data[$pos + 1]) {
                    $pos = $startPos;
                    return null;
                }
                $pos += 2;

                if ($strLength >= 4 && $this->isSerialized($str)) {
                    $innerPos  = 0;
                    $parsedStr = $this->parseSerialized($str, $innerPos);
                    if (null !== $parsedStr && $innerPos === strlen($str)) {
                        $newStr = $parsedStr;
                    } else {
                        $newStr = $this->replaceInString($str);
                    }
                } else {
                    $newStr = $this->replaceInString($str);
                }

                return 's:' . strlen($newStr) . ':"' . $newStr . '";';

            case 'i':
            case 'b':
            case 'd':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $end = strpos($data, ';', $pos);
                if (false === $end) {
                    $pos = $startPos;
                    return null;
                }

                $value = substr($data, $pos, $end - $pos);
                $pos   = $end + 1;

                return $type . ':' . $value . ';';

            case 'N':
                if (! isset($data[$pos]) || ';' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                return 'N;';

            case 'a':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $lenEnd = strpos($data, ':', $pos);
                if (false === $lenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $lenStr = substr($data, $pos, $lenEnd - $pos);
                if (! ctype_digit($lenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $arrayLength = (int) $lenStr;

                $pos = $lenEnd + 1;
                if (! isset($data[$pos]) || '{' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $result        = 'a:' . $arrayLength . ':{';
                $totalElements = $arrayLength * 2;
                for ($i = 0; $i < $totalElements; $i++) {
                    $element = $this->parseSerialized($data, $pos);
                    if (null === $element) {
                        $pos = $startPos;
                        return null;
                    }
                    $result .= $element;
                }

                if (! isset($data[$pos]) || '}' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;
                $result .= '}';

                return $result;

            case 'O':
            case 'o':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $classLenEnd = strpos($data, ':', $pos);
                if (false === $classLenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $classLenStr = substr($data, $pos, $classLenEnd - $pos);
                if (! ctype_digit($classLenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $classLength = (int) $classLenStr;

                $pos = $classLenEnd + 1;
                if (! isset($data[$pos]) || '"' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($pos + $classLength + 2 > $length) {
                    $pos = $startPos;
                    return null;
                }

                $className = substr($data, $pos, $classLength);
                $pos += $classLength;

                if ('"' !== $data[$pos] || ':' !== $data[$pos + 1]) {
                    $pos = $startPos;
                    return null;
                }
                $pos += 2;

                $propLenEnd = strpos($data, ':', $pos);
                if (false === $propLenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $propLenStr = substr($data, $pos, $propLenEnd - $pos);
                if (! ctype_digit($propLenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $propCount = (int) $propLenStr;

                $pos = $propLenEnd + 1;
                if (! isset($data[$pos]) || '{' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $result        = $type . ':' . strlen($className) . ':"' . $className . '":' . $propCount . ':{';
                $totalElements = $propCount * 2;
                for ($i = 0; $i < $totalElements; $i++) {
                    $element = $this->parseSerialized($data, $pos);
                    if (null === $element) {
                        $pos = $startPos;
                        return null;
                    }
                    $result .= $element;
                }

                if (! isset($data[$pos]) || '}' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;
                $result .= '}';

                return $result;

            case 'C':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $classLenEnd = strpos($data, ':', $pos);
                if (false === $classLenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $classLenStr = substr($data, $pos, $classLenEnd - $pos);
                if (! ctype_digit($classLenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $classLength = (int) $classLenStr;

                $pos = $classLenEnd + 1;
                if (! isset($data[$pos]) || '"' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($pos + $classLength + 2 > $length) {
                    $pos = $startPos;
                    return null;
                }

                $className = substr($data, $pos, $classLength);
                $pos += $classLength;

                if ('"' !== $data[$pos] || ':' !== $data[$pos + 1]) {
                    $pos = $startPos;
                    return null;
                }
                $pos += 2;

                $dataLenEnd = strpos($data, ':', $pos);
                if (false === $dataLenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $dataLenStr = substr($data, $pos, $dataLenEnd - $pos);
                if (! ctype_digit($dataLenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $dataLength = (int) $dataLenStr;

                $pos = $dataLenEnd + 1;
                if (! isset($data[$pos]) || '{' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($pos + $dataLength + 1 > $length) {
                    $pos = $startPos;
                    return null;
                }

                $customData = substr($data, $pos, $dataLength);
                $pos += $dataLength;

                if ('}' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($dataLength >= 4 && $this->isSerialized($customData)) {
                    $innerPos    = 0;
                    $parsedInner = $this->parseSerialized($customData, $innerPos);
                    if (null !== $parsedInner && $innerPos === strlen($customData)) {
                        $newData = $parsedInner;
                    } else {
                        $newData = $this->replaceInString($customData);
                    }
                } else {
                    $newData = $this->replaceInString($customData);
                }

                return 'C:' . strlen($className) . ':"' . $className . '":' . strlen($newData) . ':{' . $newData . '}';

            case 'E':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $lenEnd = strpos($data, ':', $pos);
                if (false === $lenEnd) {
                    $pos = $startPos;
                    return null;
                }

                $lenStr = substr($data, $pos, $lenEnd - $pos);
                if (! ctype_digit($lenStr)) {
                    $pos = $startPos;
                    return null;
                }
                $caseLength = (int) $lenStr;

                $pos = $lenEnd + 1;
                if (! isset($data[$pos]) || '"' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                if ($pos + $caseLength + 2 > $length) {
                    $pos = $startPos;
                    return null;
                }

                $caseName = substr($data, $pos, $caseLength);
                $pos += $caseLength;

                if ('"' !== $data[$pos] || ';' !== $data[$pos + 1]) {
                    $pos = $startPos;
                    return null;
                }
                $pos += 2;

                return 'E:' . strlen($caseName) . ':"' . $caseName . '";';

            case 'r':
            case 'R':
                if (! isset($data[$pos]) || ':' !== $data[$pos]) {
                    $pos = $startPos;
                    return null;
                }
                $pos++;

                $end = strpos($data, ';', $pos);
                if (false === $end) {
                    $pos = $startPos;
                    return null;
                }

                $ref = substr($data, $pos, $end - $pos);
                $pos = $end + 1;

                return $type . ':' . $ref . ';';

            default:
                $pos = $startPos;
                return null;
        }
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

                $processed = $this->process($decoded);
                $encoded   = wp_json_encode($processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                if (is_string($encoded)) {
                    return $encoded;
                }
            }
        }

        return $this->replacePlainString($data);
    }

    private function replacePlainString(string $data): string
    {
        if ([] === $this->pairs) {
            return $data;
        }

        $count = 0;
        foreach (array_keys($this->pairs) as $search) {
            if (str_contains($data, $search)) {
                $count += substr_count($data, $search);
            }
        }

        if (0 === $count) {
            return $data;
        }

        $this->count += $count;

        return strtr($data, $this->pairs);
    }

    /**
     * Replace in JSON text, matching each search string in every escaping a
     * JSON encoder produces for it ("https:\/\/old" as well as "https://old"),
     * and writing the replacement in the same escaping.
     */
    private function replaceJsonText(string $json): string
    {
        $pairs = [];
        foreach ($this->from as $i => $search) {
            if ('' === $search || ! isset($this->to[$i])) {
                continue;
            }
            foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, 0] as $flags) {
                $f = substr((string) json_encode($search, $flags), 1, -1);
                if ('' === $f || isset($pairs[$f])) {
                    continue;
                }
                $pairs[$f] = substr((string) json_encode($this->to[$i], $flags), 1, -1);
            }
        }

        if ([] === $pairs) {
            return $json;
        }

        uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $count = 0;
        foreach (array_keys($pairs) as $search) {
            if (str_contains($json, $search)) {
                $count += substr_count($json, $search);
            }
        }

        if (0 === $count) {
            return $json;
        }

        $this->count += $count;

        return strtr($json, $pairs);
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

        $lastc = substr($data, -1);
        if (';' !== $lastc && '}' !== $lastc) {
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
            case 'o':
            case 'C':
            case 'E':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;/", $data);
        }

        return false;
    }
}

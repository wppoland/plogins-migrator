<?php

use WordPress\DataLiberation\URL\WPURL;

/**
 * Prepares URL mappings used for cautious byte replacement.
 *
 * Preparing a mapping parses and validates every source and target, builds the
 * pattern for each supported pair, and sorts longer source bases first. A
 * database value may contain many text leaves, but all leaves use the same URL
 * mapping. Create this object once and share it with each text processor.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class CautiousURLBaseRewriteMapping {
    /**
     * @var array<int, array{
     *     source_authority: string,
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
     *     unicode_source_path_segments_in_nfd: array<int, string>,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }>
     */
    private array $entries = [];

    /**
     * Host => decoded, ASCII-lowercase child-site path set and its longest key in bytes.
     * For example, /shop/news is a hash key, with longest_path_bytes=10.
     * The cached length stops a deep URL from hashing ever-longer prefixes.
     * These are hash keys, not rewrite rules: a million sites must not mean a million regexes
     * or a million comparisons for each URL. HTTP and HTTPS share each set.
     *
     * @var array<string, array{paths: array<string, true>, longest_path_bytes: int}>
     */
    private array $excluded_paths = [];

    /**
     * Prepares source URL base => target URL pairs.
     *
     * A source may include an initial valid UTF-8 path without whitespace or
     * control characters. A target must be an HTTP(S) URL with a supported
     * domain, optional port, and optional restricted path:
     *
     * ```
     * [
     *     'https://source.example/media' => 'https://destination.example/assets',
     * ]
     * ```
     *
     * Invalid pairs are skipped as a whole. They cannot produce a partial
     * domain replacement.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     * @param array<string, string[]> $excluded_paths Source HTTP(S) origin =>
     *     child-site paths, such as ['https://network.test' => ['/shop/news/']].
     *     The importer checks this list at the preflight response boundary.
     */
    public function __construct(array $url_mapping, array $excluded_paths = [])
    {
        foreach ($excluded_paths as $origin => $paths) {
            $path_set = [];
            $longest_path_bytes = 0;
            foreach ($paths as $path) {
                // WordPress's normal site-directory collation treats /news/
                // and /NEWS/ alike. Fold lookup keys, never the emitted URL.
                $path = strtolower(rtrim(rawurldecode($path), '/'));
                $path_set[$path] = true;
                $longest_path_bytes = max($longest_path_bytes, strlen($path));
            }
            // Use the same URL parser as HTML rewriting. Also retain the
            // literal authority for the cautious scanner (e.g. an explicit
            // :443). Assignments share the set through PHP copy-on-write.
            $hosts = [];
            foreach (['http:', 'https:'] as $protocol) {
                $url = $protocol . substr($origin, strpos($origin, ':') + 1);
                $parsed = WPURL::parse($url);
                $parts = parse_url($url);
                $authority = strtolower($parts['host']) . ( isset($parts['port']) ? ':' . $parts['port'] : '' );
                $hosts[] = $parsed->host;
                $hosts[] = $authority;
            }
            if ($path_set !== []) {
                foreach (array_unique($hosts) as $host) {
                    $this->excluded_paths[$host] = [
                        'paths' => isset($this->excluded_paths[$host])
                            ? $this->excluded_paths[$host]['paths'] + $path_set : $path_set,
                        'longest_path_bytes' => max($longest_path_bytes, $this->excluded_paths[$host]['longest_path_bytes'] ?? 0),
                    ];
                }
            }
        }
        foreach ($url_mapping as $source_url => $target_url) {
            $entry = $this->create_entry($source_url, $target_url);
            if ($entry !== null) {
                // Child paths may be stored under network.test while this rule
                // matches network.test:443. Give that literal spelling the same
                // lookup set. PHP shares its array until a write; no path list
                // is copied or scanned here. Parse once per rule, not per URL.
                if ($this->excluded_paths !== []) {
                    $parsed = WPURL::parse($source_url);
                    if ($parsed && !isset($this->excluded_paths[$entry['source_authority']])
                        && isset($this->excluded_paths[$parsed->host])) {
                        $this->excluded_paths[$entry['source_authority']] = $this->excluded_paths[$parsed->host];
                    }
                }
                $this->entries[] = $entry;
            }
        }

        usort(
            $this->entries,
            static function (array $first, array $second): int {
                return strlen($second['source_base']) <=> strlen($first['source_base']);
            }
        );
    }

    /**
     * Returns the prepared mappings in longest-source-first order.
     *
     * @return array<int, array{
     *     source_authority: string,
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
     *     unicode_source_path_segments_in_nfd: array<int, string>,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }>
     */
    public function get_entries(): array
    {
        return $this->entries;
    }

    /** Let the text scanner skip path extraction when this host has no child sites. */
    public function has_excluded_paths_for_host(string $host): bool
    {
        return isset($this->excluded_paths[strtolower($host)]);
    }

    /**
     * Return true if the URL points to a child site and must stay on the source.
     *
     * For a child site at /shop/news/ on this host:
     * - /shop/news returns true.
     * - /shop/news/article returns true.
     * - /shop/newsletter returns false.
     *
     * Look up path prefixes in this host's child-path set, without scanning all
     * sites. Skip prefixes longer than the longest stored child path.
     *
     * The caller must resolve "." and ".." with the URL parser first.
     * Decode percent escapes and ignore ASCII case for comparison only.
     * This method does not change the URL.
     */
    public function excludes_path(string $host, string $path): bool
    {
        $host = strtolower($host);
        if ($path === '' || !isset($this->excluded_paths[$host])) {
            return false;
        }
        $path = strtolower(rawurldecode($path));
        $longest_path_bytes = $this->excluded_paths[$host]['longest_path_bytes'];
        for ($offset = strpos($path, '/', 1); $offset !== false && $offset <= $longest_path_bytes; $offset = strpos($path, '/', $offset + 1)) {
            if (isset($this->excluded_paths[$host]['paths'][substr($path, 0, $offset)])) {
                return true;
            }
        }
        return strlen($path) <= $longest_path_bytes && isset($this->excluded_paths[$host]['paths'][$path]);
    }

    /**
     * @return array{
     *     source_authority: string,
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
     *     unicode_source_path_segments_in_nfd: array<int, string>,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }|null
     */
    private function create_entry(string $source_url, string $target_url): ?array
    {
        $source = $this->get_supported_url_parts($source_url, true);
        // A same-URL rule keeps sibling pages or media at the source. Reuse
        // the source parts: this rule does not insert a new host or path.
        $target = $source_url === $target_url ? $source : $this->get_supported_url_parts($target_url, false);
        if ($source === null || $target === null) {
            return null;
        }

        // A source URL ending at its authority uses / as the URL separator,
        // not as an initial path to remove. Leave its original spelling alone.
        $source_path = $source['path'] === '/' ? '' : $source['path'];
        $unicode_source_path_segments_in_nfd = [];
        foreach (explode('/', substr($source_path, 1)) as $source_path_segment) {
            if (preg_match('/[\x80-\xFF]/', $source_path_segment) !== 1) {
                continue;
            }

            // NFD writes é as e followed by a combining accent. Store every
            // configured Unicode segment in NFD. The scanner converts each
            // captured candidate segment to NFD before comparing the two.
            $source_path_segment_in_nfd = Normalizer::normalize(
                $source_path_segment,
                Normalizer::FORM_D
            );
            if (!is_string($source_path_segment_in_nfd)) {
                return null;
            }
            $unicode_source_path_segments_in_nfd[] =
                $source_path_segment_in_nfd;
        }

        $source_authority_pattern = '(?i:' . preg_quote($source['authority'], '~') . ')';
        if ($source['host_uses_idn']) {
            // This branch locates Unicode authority candidates. IDNA below
            // decides whether each candidate names the configured host.
            $unicode_host_character = "[^\\s<>@/\\\\:?#,!;()\\[\\]{}>\"']";
            $source_authority_pattern =
                '(?:' . $source_authority_pattern
                . '|(?<unicode_host>(?=' . $unicode_host_character . '*[\\x80-\\xFF])'
                . $unicode_host_character . '+)'
                . ( $source['port'] === null ? '' : ':' . $source['port'] )
                . ')';
        }

        $target_path = $source_url === $target_url ? $source_path : $target['path'];

        return [
            'source_authority'     => $source['authority'],
            'source_ascii_host'    => $source['ascii_host'],
            'source_host_uses_idn' => $source['host_uses_idn'],
            'unicode_source_path_segments_in_nfd' =>
                $unicode_source_path_segments_in_nfd,
            'source_path'          => $source_path,
            'source_base'          => $source['authority'] . $source_path,
            // A same-URL rule compares its target with the ASCII source
            // authority, so an IDN exclusion keeps the matched bytes.
            'target_domain'        => $source_url === $target_url ? $source['ascii_host'] : $target['host'],
            'target_scheme'        => $target['scheme'],
            'target_path'          => $target_path,
            'target_port'          => $target['port'],
            'pattern'              => $this->create_url_candidate_pattern(
                $source['scheme'],
                $source_authority_pattern,
                $source_path,
                $target_path !== ''
            ),
        ];
    }

    /**
     * Build a candidate pattern adapted from URLInTextProcessor's URL finder.
     *
     * The pattern recognizes one mapping's absolute, protocol-relative, and
     * scheme-less forms. It captures the first slash before the authority and
     * the first slash in or after the configured source base. The first
     * available capture supplies the spelling for a target path.
     */
    private function create_url_candidate_pattern(
        string $source_scheme,
        string $source_authority_pattern,
        string $source_path,
        bool $requires_path_slash
    ): string
    {
        $separator_escape = '\\\\{0,8}';
        $source_path_pattern = '';
        if ($source_path !== '') {
            $source_path_suffix_pattern = '';
            $unicode_path_segment_index = 0;
            foreach (
                explode('/', substr($source_path, 1))
                as $source_path_segment_index => $source_path_segment
            ) {
                if ($source_path_segment_index > 0) {
                    $source_path_suffix_pattern .= $separator_escape . '/';
                }
                // Capture Unicode segments and compare them in NFD after
                // matching. One regexp then handles any mixture of composed
                // and decomposed characters without listing every combination.
                if (preg_match('/[\x80-\xFF]/', $source_path_segment) !== 1) {
                    $source_path_suffix_pattern .= preg_quote(
                        $source_path_segment,
                        '~'
                    );
                    continue;
                }

                $source_path_suffix_pattern .=
                    '(?<unicode_path_segment_' . $unicode_path_segment_index . '>'
                    . '(?=[^/?#\x00-\x20\x7F]*[\x80-\xFF])'
                    . '[^/?#\x00-\x20\x7F]+?'
                    . ')';
                ++$unicode_path_segment_index;
            }

            $source_path_pattern =
                '(?<path_slash>' . $separator_escape . '/)'
                . $source_path_suffix_pattern;
        }
        $candidate_boundary_pattern = '(?=
            $
            | ' . $separator_escape . '/
            | [/?# \t\r\n,!;)\]}>"\']
        )';
        if ($requires_path_slash && $source_path === '') {
            $candidate_boundary_pattern = '(?(url_slash)
                ' . $candidate_boundary_pattern . '
                |
                (?=(?<path_slash>' . $separator_escape . '/))
            )';
        }

        return '~
            (?<![A-Za-z0-9._%+\\/@-])
            (?:
                (?:
                    (?<scheme>(?i:' . preg_quote($source_scheme, '~') . '))
                    (?<scheme_colon>' . $separator_escape . ':)
                    |
                    (?<!:)
                )
                (?<url_slash>(?<url_slash_escape>' . $separator_escape . ')/)
                \k<url_slash_escape>/
                (?:[^\s<>@/\\\\]+@)?
            )?
            (?<base>
                (?<authority>' . $source_authority_pattern . ')
                ' . $source_path_pattern . '
            )
            ' . $candidate_boundary_pattern . '
        ~x';
    }

    /**
     * @return array{
     *     scheme: string,
     *     host: string,
     *     ascii_host: string,
     *     host_uses_idn: bool,
     *     authority: string,
     *     path: string,
     *     port: int|null
     * }|null
     */
    private function get_supported_url_parts(string $url, bool $is_source_url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $unsupported_part) {
            if (array_key_exists($unsupported_part, $parts)) {
                return null;
            }
        }

        $parsed = WPURL::parse($url);
        if (!$parsed) {
            return null;
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        $host = (string) $parts['host'];
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($is_source_url) {
            // parse_url() replaces control-valued bytes with underscores.
            // Some UTF-8 continuation bytes fall in that range. Read the
            // source authority and path from the configured URL after
            // parse_url() has checked its structure.
            $authority_separator_at = strpos($url, '://');
            if ($authority_separator_at === false) {
                return null;
            }
            $authority_starts_at = $authority_separator_at + 3;
            $path_starts_at = strpos($url, '/', $authority_starts_at);
            $source_authority = $path_starts_at === false
                ? substr($url, $authority_starts_at)
                : substr(
                    $url,
                    $authority_starts_at,
                    $path_starts_at - $authority_starts_at
                );
            if (isset($parts['port'])) {
                $port_suffix = ':' . $parts['port'];
                if (substr($source_authority, -strlen($port_suffix)) !== $port_suffix) {
                    return null;
                }
                $host = substr($source_authority, 0, -strlen($port_suffix));
            } else {
                $host = $source_authority;
            }
            $path = $path_starts_at === false ? '' : substr($url, $path_starts_at);
        }

        // A target base's final slash is supplied by the candidate suffix.
        // Remove only one: an empty component such as /scope:123// is still
        // unsupported. Keep source paths literal for matching and exclusions.
        if (!$is_source_url && substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }
        // These limits apply only to literal replacement in unknown text.
        // A parsed HTTP host can contain quotes, but inserting one could end
        // the surrounding value. Hostname syntax keeps output to letters,
        // digits, hyphens and dots; IPv4 and a trailing dot need no escaping.
        // IPv6 sources can be matched and removed without inserting brackets.
        // Format-aware rewriters accept the full URL and use their serializers.
        $host_is_ascii_domain = $this->is_literal_hostname($host);
        $host_is_ip_address =
            $is_source_url
            && !$host_is_ascii_domain
            && $this->is_ip_address($host);
        $host_contains_punycode_label =
            $host_is_ascii_domain
            && stripos('.' . $host, '.xn--') !== false;
        $host_uses_idn =
            $is_source_url
            && (
                $host_contains_punycode_label
                || ( !$host_is_ascii_domain && !$host_is_ip_address )
            );
        $ascii_host = $host;
        if ($host_uses_idn) {
            if (
                !$host_is_ascii_domain
                && preg_match('/^[\p{L}\p{M}\p{N}.-]+$/u', $host) !== 1
            ) {
                return null;
            }
            $ascii_host = self::to_ascii_idn_hostname($host);
            if ($ascii_host === null) {
                return null;
            }
        }

        $has_unsupported_target_path =
            !$is_source_url
            && $path !== ''
            && preg_match('#^/[A-Za-z0-9_:-]+(?:/[A-Za-z0-9_:-]+)*$#', $path) !== 1;
        // Source paths may contain Unicode segments, which the scanner
        // compares in NFD. Inserted target paths stay printable ASCII.
        $path_is_supported = $is_source_url
            ? preg_match('/^[^\p{Z}\p{C}]*$/u', $path) === 1
            : $this->contains_only_exclamation_mark_through_tilde_bytes($path);
        $host_is_supported =
            $host_is_ascii_domain
            || $host_is_ip_address
            || (
                $host_uses_idn
                && $this->is_literal_hostname($ascii_host)
            );
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$is_source_url && $has_unsupported_target_path )
            || !$host_is_supported
            || !$path_is_supported) {
            return null;
        }

        return [
            'scheme'        => $scheme,
            'host'          => $host,
            'ascii_host'    => $ascii_host,
            'host_uses_idn' => $host_uses_idn,
            'authority'     => $ascii_host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
            'path'          => $path,
            'port'          => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    /**
     * Converts a Unicode or Punycode hostname to its lowercase ASCII form.
     *
     * Returns null when IDNA rejects the hostname.
     */
    public static function to_ascii_idn_hostname(string $hostname): ?string
    {
        $idn_info = [];
        $ascii_hostname = idn_to_ascii(
            $hostname,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES,
            INTL_IDNA_VARIANT_UTS46,
            $idn_info
        );
        if (
            !is_string($ascii_hostname)
            || $ascii_hostname === ''
            || ( $idn_info['errors'] ?? 0 ) !== 0
        ) {
            return null;
        }

        return strtolower($ascii_hostname);
    }

    private function is_ip_address(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /** Letters, digits, hyphens and dots, including IPv4 and a trailing dot. */
    private function is_literal_hostname(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function contains_only_exclamation_mark_through_tilde_bytes(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}

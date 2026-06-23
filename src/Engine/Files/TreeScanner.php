<?php

declare(strict_types=1);

namespace Migrator\Engine\Files;

defined('ABSPATH') || exit;

/**
 * Builds a size-annotated tree of a directory (typically wp-content) for the
 * admin "scan files to exclude" explorer. Each directory carries its total
 * recursive size and node count so the merchant can spot what bloats a backup
 * (a large cache, logs, or a single big data file) and tick it for exclusion.
 *
 * The full subtree is measured for accurate folder sizes, but only nodes worth
 * showing are emitted: directories down to a depth limit, plus individual files
 * at or above a size threshold. That keeps the payload bounded on sites with
 * tens of thousands of files while still surfacing the items that matter.
 *
 * Paths are reported relative to the scanned base, matching the wp-content
 * relative paths the exporter's exclusion option expects.
 */
final class TreeScanner
{
    /** Basenames never walked (also pruned by the exporter). */
    private const PRUNE = ['node_modules', '.git', '.DS_Store'];

    /**
     * @param string[] $skipAbs        Absolute paths to skip entirely (e.g. the backup workspace).
     * @param int      $maxDepth       Deepest directory level emitted (sizes are still measured below it).
     * @param int      $fileThreshold  Minimum size in bytes for an individual file to be listed.
     * @return array{name:string,rel:string,dir:bool,size:int,nodes:int,children:array<int,array<string,mixed>>}
     */
    public function tree(string $base, array $skipAbs = [], int $maxDepth = 4, int $fileThreshold = 1048576): array
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $skip = array_map(static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/'), $skipAbs);

        [$size, $nodes, $children] = $this->walk($base, '', 1, $maxDepth, $fileThreshold, $skip);

        return [
            'name'     => basename($base),
            'rel'      => '',
            'dir'      => true,
            'size'     => $size,
            'nodes'    => $nodes,
            'children' => $children,
        ];
    }

    /**
     * @param string[] $skip
     * @return array{0:int,1:int,2:array<int,array<string,mixed>>} [totalSize, totalNodes, emittedChildren]
     */
    private function walk(string $abs, string $rel, int $depth, int $maxDepth, int $fileThreshold, array $skip): array
    {
        $totalSize  = 0;
        $totalNodes = 0;
        $emit       = [];

        $entries = @scandir($abs);
        if (false === $entries) {
            return [0, 0, []];
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || in_array($entry, self::PRUNE, true)) {
                continue;
            }

            $childAbs = $abs . '/' . $entry;
            $childRel = '' === $rel ? $entry : $rel . '/' . $entry;

            if (is_link($childAbs) || in_array($childAbs, $skip, true)) {
                continue;
            }

            if (is_dir($childAbs)) {
                [$cSize, $cNodes, $cChildren] = $this->walk($childAbs, $childRel, $depth + 1, $maxDepth, $fileThreshold, $skip);
                $totalSize  += $cSize;
                $totalNodes += $cNodes + 1;

                if ($depth <= $maxDepth) {
                    $emit[] = [
                        'name'     => $entry,
                        'rel'      => $childRel,
                        'dir'      => true,
                        'size'     => $cSize,
                        'nodes'    => $cNodes,
                        'children' => $cChildren,
                    ];
                }
            } elseif (is_file($childAbs)) {
                $fSize = (int) @filesize($childAbs);
                $totalSize  += $fSize;
                $totalNodes += 1;

                if ($depth <= $maxDepth && $fSize >= $fileThreshold) {
                    $emit[] = [
                        'name'     => $entry,
                        'rel'      => $childRel,
                        'dir'      => false,
                        'size'     => $fSize,
                        'nodes'    => 0,
                        'children' => [],
                    ];
                }
            }
        }

        usort($emit, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return [$totalSize, $totalNodes, $emit];
    }
}

<?php

declare(strict_types=1);

namespace Migrator\Engine\Pipeline;

defined('ABSPATH') || exit;

/**
 * A wall-clock budget for one request's slice of work. Steps check {@see expired()}
 * frequently and return before the request hits its time limit, so the browser
 * can re-invoke and resume. Wall-clock (not a row/file counter) is what keeps
 * this robust across hosts with very different speeds.
 */
final class TimeBudget
{
    private float $deadline;

    public function __construct(float $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    /**
     * Derive a sensible budget from max_execution_time: a fraction of it, clamped
     * to a floor and ceiling. Defaults to the ceiling when execution time is
     * unlimited (0).
     */
    public static function forRequest(float $fraction = 0.7, float $floor = 8.0, float $ceil = 20.0): self
    {
        $max = (float) ini_get('max_execution_time');
        $seconds = $max > 0 ? $max * $fraction : $ceil;

        /**
         * Seconds one browser step may work before it hands back to the next
         * request. Lower it on a host whose proxy cuts requests short.
         *
         * @param float $seconds Derived from max_execution_time, 8 to 20.
         */
        $seconds = (float) apply_filters('migrator_step_seconds', max($floor, min($ceil, $seconds)));

        return new self(max(0.0, $seconds));
    }

    public function expired(): bool
    {
        return microtime(true) >= $this->deadline;
    }
}

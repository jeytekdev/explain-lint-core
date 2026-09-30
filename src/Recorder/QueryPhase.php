<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Recorder;

/**
 * Which lifecycle phase of a test a captured query belongs to. Only
 * `Test` is analyzed by default — queries run during fixture setup or
 * teardown are usually out of scope for a "did this test's own queries
 * regress" check and would otherwise flood reports with migration/seeder
 * noise (see also config `ignore_paths`).
 */
enum QueryPhase
{
    case Setup;
    case Test;
    case Teardown;
    case Unspecified;
}

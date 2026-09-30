<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber as ErroredSubscriberInterface;

/**
 * See FailedSubscriber — same belt-and-suspenders rationale.
 */
final class ErroredSubscriber implements ErroredSubscriberInterface
{
    public function __construct(private readonly TestAnalysisRunner $analysisRunner)
    {
    }

    public function notify(Errored $event): void
    {
        $test = $event->test();
        $this->analysisRunner->analyze(TestValueAdapter::id($test), TestValueAdapter::name($test));
    }
}

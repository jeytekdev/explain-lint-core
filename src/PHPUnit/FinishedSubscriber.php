<?php

declare(strict_types=1);

namespace ExplainLint\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber as FinishedSubscriberInterface;

final class FinishedSubscriber implements FinishedSubscriberInterface
{
    public function __construct(private readonly TestAnalysisRunner $analysisRunner)
    {
    }

    public function notify(Finished $event): void
    {
        $test = $event->test();
        $this->analysisRunner->analyze(TestValueAdapter::id($test), TestValueAdapter::name($test));
    }
}

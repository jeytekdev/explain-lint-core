<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber as FailedSubscriberInterface;

/**
 * Belt-and-suspenders alongside FinishedSubscriber: TestAnalysisRunner
 * drains the recorder via flush(), so if Finished also fires for this test
 * (as it does on every supported PHPUnit version), the second call simply
 * finds an empty buffer and is a no-op.
 */
final class FailedSubscriber implements FailedSubscriberInterface
{
    public function __construct(private readonly TestAnalysisRunner $analysisRunner)
    {
    }

    public function notify(Failed $event): void
    {
        $test = $event->test();
        $this->analysisRunner->analyze(TestValueAdapter::id($test), TestValueAdapter::name($test));
    }
}

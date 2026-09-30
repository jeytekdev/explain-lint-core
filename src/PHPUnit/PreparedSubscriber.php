<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber as PreparedSubscriberInterface;

/**
 * Fires once setUp() (and any before-hooks) have completed, marking the
 * start of the test method body itself.
 */
final class PreparedSubscriber implements PreparedSubscriberInterface
{
    public function __construct(private readonly QueryRecorder $recorder)
    {
    }

    public function notify(Prepared $event): void
    {
        $this->recorder->setPhase(QueryPhase::Test);
    }
}

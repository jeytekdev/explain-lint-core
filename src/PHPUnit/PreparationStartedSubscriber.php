<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber as PreparationStartedSubscriberInterface;

final class PreparationStartedSubscriber implements PreparationStartedSubscriberInterface
{
    public function __construct(private readonly QueryRecorder $recorder)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->recorder->clear();
        $this->recorder->setPhase(QueryPhase::Setup);
    }
}

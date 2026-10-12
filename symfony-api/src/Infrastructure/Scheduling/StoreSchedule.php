<?php

namespace App\Infrastructure\Scheduling;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
final readonly class StoreSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron(
                '0 * * * *',
                new RunCommandMessage('commerce:refresh-exchange-rates'),
            ))
            ->add(RecurringMessage::cron(
                '*/5 * * * *',
                new RunCommandMessage('storefront:cache-warm'),
            ))
            ->add(RecurringMessage::cron(
                '*/5 * * * *',
                new RunCommandMessage('shop-pay:reconcile'),
            ))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}

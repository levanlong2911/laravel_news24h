<?php

namespace Tests\Feature\Video;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class VideoSchedulingTest extends TestCase
{
    private function eventFor(string $commandName): ?Event
    {
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $commandName)) {
                return $event;
            }
        }

        return null;
    }

    public function test_reclaim_expired_leases_is_scheduled_every_five_minutes(): void
    {
        $event = $this->eventFor('video:reclaim-expired-leases');

        $this->assertNotNull($event, 'video:reclaim-expired-leases khong duoc dang ky trong Kernel::schedule()');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_prune_runner_logs_is_scheduled_daily(): void
    {
        $event = $this->eventFor('video:prune-runner-logs');

        $this->assertNotNull($event, 'video:prune-runner-logs khong duoc dang ky trong Kernel::schedule()');
        $this->assertSame('30 3 * * *', $event->expression);
    }
}

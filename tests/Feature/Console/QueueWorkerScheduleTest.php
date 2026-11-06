<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Hospedagem compartilhada, fila `database` e sem worker permanente: o agendador (cron
 * `schedule:run` por minuto) precisa esvaziar a fila, senão os jobs ficam parados.
 */
class QueueWorkerScheduleTest extends TestCase
{
    public function test_the_scheduler_drains_the_queue_every_minute_and_never_overlaps(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command, 'queue:work'));

        $this->assertCount(1, $events);

        /** @var Event $event */
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=55', $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }
}

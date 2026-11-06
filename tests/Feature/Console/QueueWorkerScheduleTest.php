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

    public function test_empty_drafts_are_swept_every_fifteen_minutes_with_a_bounded_mutex(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'sales:discard-empty-drafts'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt, 'Mutex com prazo: se o processo morrer, o comando volta a rodar.');
    }
}

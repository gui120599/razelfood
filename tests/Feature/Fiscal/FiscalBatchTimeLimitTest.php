<?php

namespace Tests\Feature\Fiscal;

use App\Filament\Tenant\Support\FiscalDocumentActions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * As ações em lote ampliam o tempo de execução só em request web: no CLI (testes, workers) o
 * limite é ilimitado e `set_time_limit(300)` o imporia ao processo inteiro.
 */
class FiscalBatchTimeLimitTest extends TestCase
{
    public function test_extending_the_time_limit_does_not_cap_a_cli_process(): void
    {
        $original = ini_get('max_execution_time');
        ini_set('max_execution_time', '0');

        try {
            (new ReflectionMethod(FiscalDocumentActions::class, 'extendWebTimeLimit'))->invoke(null);

            $this->assertSame('0', ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', (string) $original);
        }
    }
}

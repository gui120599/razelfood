<?php

namespace App\Console\Commands;

use App\Actions\Fiscal\ApplyFiscalProviderStatus;
use App\Console\Concerns\RunsForEachTenant;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\NfeIoException;
use App\Models\FiscalDocument;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;
use App\Support\FeatureKey;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliação das NFC-e "paradas" (webhook perdido): notas em processamento ou com
 * cancelamento solicitado, com identificador da NFE.io, pendentes há mais de N minutos e
 * dentro de N dias. Tenant a tenant (só quem tem a feature), com falha isolada. O log só
 * ganha linha quando houve mudança (roda agendado). `--simular` consulta e lista, sem gravar.
 */
class SyncFiscalDocumentsCommand extends Command
{
    use RunsForEachTenant;

    protected $signature = 'fiscal:sync-status
        {--simular : Consulta a NFE.io e lista as mudanças, sem gravar}
        {--dias=7 : Só notas dos últimos N dias}
        {--minutos=10 : Só notas pendentes há mais de N minutos (não compete com uma emissão em curso)}
        {--limite=100 : Máximo de notas a consultar POR TENANT em cada execução (0 = sem limite)}
        {--pausa=150 : Pausa entre consultas, em milissegundos}';

    protected $description = 'Atualiza o status das NFC-e pendentes consultando a NFE.io (reconciliação do webhook)';

    public function handle(NfeIoClient $client, ApplyFiscalProviderStatus $apply): int
    {
        $simulate = (bool) $this->option('simular');
        $days = max(1, (int) $this->option('dias'));
        $minutes = max(0, (int) $this->option('minutos'));
        $limit = (int) $this->option('limite');
        $pause = max(0, (int) $this->option('pausa'));

        $changes = [];
        $failures = 0;

        $result = $this->forEachTenantWithFeature(FeatureKey::NFE_EMISSAO, function (Tenant $tenant) use ($client, $apply, $simulate, $days, $minutes, $limit, $pause, &$changes, &$failures): void {
            $settings = TenantFiscalSettings::query()->first();

            if ($settings === null || ! $settings->hasCredentials()) {
                return;
            }

            $cutoff = now()->subMinutes($minutes);
            $since = now()->subDays($days);

            // A janela olha o momento em que o estado pendente começou (envio / pedido de cancelamento),
            // e a mais antiga vai primeiro: nota presa não monopoliza a rodada e cada tenant tem o seu limite.
            $documents = FiscalDocument::query()
                ->whereNotNull('provider_id')
                ->where(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $q): Builder => $q->where('status', FiscalDocumentStatus::Processing->value)->whereBetween('sent_at', [$since, $cutoff]))
                    ->orWhere(fn (Builder $q): Builder => $q->where('status', FiscalDocumentStatus::CancelRequested->value)->whereBetween('cancel_requested_at', [$since, $cutoff])))
                ->orderByRaw('COALESCE(cancel_requested_at, sent_at) asc')
                ->orderBy('id')
                ->when($limit > 0, fn (Builder $query): Builder => $query->limit($limit))
                ->get();

            foreach ($documents as $document) {
                $before = $document->status->value;

                try {
                    $body = $client->find($settings, $document->provider_id);
                } catch (NfeIoException $exception) {
                    $failures++;
                    $this->components->warn("Tenant {$tenant->slug}, nota #{$document->id}: {$exception->getMessage()}");
                    usleep($pause * 1000);

                    continue;
                }

                $providerStatus = is_string($body['status'] ?? null) ? $body['status'] : null;

                if ($simulate) {
                    $after = $apply->resolve($document, $providerStatus)?->value ?? $before;
                    $changed = $after !== $before;
                } else {
                    $changed = $apply($document, $providerStatus, $body);
                    $after = $document->status->value;
                }

                if ($changed && $after !== $before) {
                    $changes[] = [$tenant->slug, $document->id, $before, $after];
                }

                usleep($pause * 1000);
            }
        });

        $failures += $result['failed'];

        if ($changes === []) {
            $this->components->info('Nenhuma NFC-e mudou de status.');
        } else {
            $this->table(['Tenant', 'Nota', 'Antes', ($simulate ? 'NFE.io' : 'Agora')], $changes);
        }

        // Agendado, o log só ganha linha quando houve o que contar.
        if (! $simulate && $changes !== []) {
            Log::info('fiscal:sync-status atualizou o status de NFC-e paradas', ['atualizadas' => count($changes), 'notas' => array_column($changes, 1)]);
        }

        if ($failures > 0) {
            Log::warning('fiscal:sync-status teve falhas ao consultar a NFE.io', ['falhas' => $failures]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

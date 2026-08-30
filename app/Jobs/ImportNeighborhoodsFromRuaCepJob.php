<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\User;
use App\Notifications\RuaCepImportCompleted;
use App\Notifications\RuaCepImportFailed;
use App\Services\Address\RuaCepBairroScraper;
use App\Support\NeighborhoodNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Importa os bairros de uma cidade a partir do RuaCEP (fonte extra, além do
 * sweep de CEP via ViaCEP em App\Jobs\ProcessLocationSyncChunkJob). Mais
 * simples que aquele: são só dezenas de requisições por cidade, então roda
 * inteiro numa única execução — não precisa de checkpoint/self-redispatch.
 */
class ImportNeighborhoodsFromRuaCepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $cityId) {}

    public function handle(RuaCepBairroScraper $scraper): void
    {
        $city = City::with('state')->findOrFail($this->cityId);
        $names = $scraper->bairrosOf($city->state->uf, $city->name);

        if ($names === []) {
            // 0 resultados na 1ª página quase sempre é slug errado ou o site
            // mudou de estrutura — não "cidade sem bairro". Avisa em vez de
            // silenciosamente não fazer nada.
            $this->notifySuperAdmins(new RuaCepImportFailed($city));

            return;
        }

        $found = count($names);
        $created = 0;

        foreach (array_unique($names) as $name) {
            $neighborhood = $city->neighborhoods()->firstOrCreate(
                ['normalized_name' => NeighborhoodNormalizer::normalize($name)],
                ['name' => $name],
            );

            if ($neighborhood->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->notifySuperAdmins(new RuaCepImportCompleted($city, $found, $created));
    }

    private function notifySuperAdmins(Notification $notification): void
    {
        User::query()->whereNull('tenant_id')->get()
            ->each(fn (User $admin) => $admin->notify($notification));
    }
}

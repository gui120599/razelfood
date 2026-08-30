<?php

namespace App\Notifications;

use App\Models\LocationSync;
use Illuminate\Notifications\Notification;

/**
 * Notificação de banco (não Filament\Notifications) porque é enviada de
 * dentro de App\Jobs\ProcessLocationSyncChunkJob, fora de um ciclo de
 * request Livewire — o painel exibe do mesmo jeito via
 * CentralPanelProvider::databaseNotifications().
 */
class LocationSyncCompleted extends Notification
{
    public function __construct(private readonly LocationSync $sync) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Sincronização concluída: {$this->sync->city->name}/{$this->sync->state->uf}",
            'body' => "{$this->sync->ceps_processed} CEPs processados, {$this->sync->neighborhoods_created} bairros novos, {$this->sync->errors_count} erros.",
            'location_sync_id' => $this->sync->id,
        ];
    }
}

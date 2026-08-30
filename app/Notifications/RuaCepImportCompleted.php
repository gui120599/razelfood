<?php

namespace App\Notifications;

use App\Models\City;
use Illuminate\Notifications\Notification;

class RuaCepImportCompleted extends Notification
{
    public function __construct(
        private readonly City $city,
        private readonly int $found,
        private readonly int $created,
    ) {}

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
            'title' => "Importação RuaCEP concluída: {$this->city->name}/{$this->city->state->uf}",
            'body' => "{$this->found} bairros encontrados no RuaCEP, {$this->created} novos criados no catálogo.",
            'city_id' => $this->city->id,
        ];
    }
}

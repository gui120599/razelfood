<?php

namespace App\Notifications;

use App\Models\City;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Enviada quando App\Services\Address\RuaCepBairroScraper não encontra
 * nenhum bairro na 1ª página — quase sempre indica slug de cidade errado ou
 * o site ter mudado de estrutura, não "cidade sem bairro".
 */
class RuaCepImportFailed extends Notification
{
    public function __construct(private readonly City $city) {}

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
        $url = 'https://www.ruacep.com.br/'.strtolower($this->city->state->uf).'/'.Str::slug($this->city->name).'/bairros/';

        return [
            'title' => "Importação RuaCEP falhou: {$this->city->name}/{$this->city->state->uf}",
            'body' => "Nenhum bairro encontrado. Confira manualmente se a URL ainda existe: {$url}",
            'city_id' => $this->city->id,
        ];
    }
}

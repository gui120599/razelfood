<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Widgets\AccountWidget as BaseAccountWidget;

/**
 * AccountWidget do painel do tenant ocupando a largura total do grid de 5
 * colunas da Dashboard. O AccountWidget do Filament herda `$columnSpan = 1`
 * de `Widget` e não expõe isso por configuração — precisa de subclasse.
 * NÃO editar o vendor direto: o `composer install` do deploy sobrescreve.
 */
class AccountWidget extends BaseAccountWidget
{
    protected int|string|array $columnSpan = '5';
}

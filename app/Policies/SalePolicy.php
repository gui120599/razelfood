<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sale;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Leitura de vendas segue o padrão Shield do projeto (ViewAny/View:Sale).
 * Venda não é criada nem editada pelo Resource: nasce em FinalizeSale e só
 * pode ser cancelada pela ação "Cancelar venda" (permissão `manage_sales`).
 */
class SalePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Sale');
    }

    public function view(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('View:Sale');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Sale $sale): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Sale $sale): bool
    {
        return false;
    }
}

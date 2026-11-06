<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FiscalDocument;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Leitura de documentos fiscais segue o padrão Shield do projeto (ViewAny/View:FiscalDocument).
 * O documento nasce e muda só pelas Actions de App\Actions\Fiscal (emitir, cancelar, status):
 * o Resource nunca cria, edita nem exclui. As ações fiscais têm permissões próprias
 * (`emit_fiscal_document`, `cancel_fiscal_document`).
 */
class FiscalDocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FiscalDocument');
    }

    public function view(AuthUser $authUser, FiscalDocument $fiscalDocument): bool
    {
        return $authUser->can('View:FiscalDocument');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, FiscalDocument $fiscalDocument): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, FiscalDocument $fiscalDocument): bool
    {
        return false;
    }
}

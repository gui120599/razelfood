<?php

namespace App\Filament\Tenant\Pages\Auth;

use App\Support\CurrentTenant;
use Filament\Auth\Pages\Login as BaseLogin;
use SensitiveParameter;

class Login extends BaseLogin
{
    /**
     * Mesmo com o TenantScope ativo no model User, reforça explicitamente
     * no fluxo de autenticação que um usuário só loga no painel do próprio
     * tenant — não depende só do global scope (seção 4.8 da modelagem).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'email' => $data['email'],
            'password' => $data['password'],
            'tenant_id' => CurrentTenant::id(),
        ];
    }
}

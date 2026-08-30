<?php

namespace Tests;

use App\Enums\CentralRole;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cria (e autentica) um usuário da equipe interna da Razel Tec com o
     * papel "Plataforma" — acesso total ao painel central (RN-44).
     */
    protected function actingAsPlatformAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id' => null,
            'central_role' => CentralRole::Platform,
        ]);

        $this->actingAs($user);

        return $user;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\CentralRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            FeatureSeeder::class,
            PlanSeeder::class,
        ]);

        // Super admin da Razel Tec — acessa o painel central (tenant_id null,
        // ver User::canAccessPanel()) com acesso total (central_role Plataforma,
        // RN-44). Idempotente.
        User::query()->updateOrCreate(
            ['email' => 'admin@razeltec.com.br'],
            [
                'name' => 'Razel Tec',
                'tenant_id' => null,
                'central_role' => CentralRole::Platform,
                'password' => 'password',
            ],
        );
    }
}

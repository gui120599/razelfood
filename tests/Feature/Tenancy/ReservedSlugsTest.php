<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Rules\ValidTenantSlug;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MT-09: prefixos das próximas fases (webhooks, painel do garçom, KDS, mesa)
 * ficam reservados ANTES de existirem as rotas, para nenhum tenant ocupar o
 * slug e colidir com elas.
 */
class ReservedSlugsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function newlyReservedSlugs(): array
    {
        return [
            'webhooks' => ['webhooks'],
            'garcom' => ['garcom'],
            'cozinha' => ['cozinha'],
            'mesa' => ['mesa'],
        ];
    }

    #[DataProvider('newlyReservedSlugs')]
    public function test_slug_is_in_the_reserved_list_and_refused_by_the_rule(string $slug): void
    {
        $this->assertContains($slug, config('tenancy.reserved_slugs'));

        $validator = Validator::make(['slug' => $slug], ['slug' => [new ValidTenantSlug]]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('palavra reservada', $validator->errors()->first('slug'));
    }

    public function test_regular_and_similar_slugs_are_still_accepted(): void
    {
        foreach (['emporiodapizza', 'lazzopizza', 'mesa-do-chef', 'garcom-pizzaria', 'webhooks2'] as $slug) {
            $validator = Validator::make(['slug' => $slug], ['slug' => [new ValidTenantSlug]]);

            $this->assertTrue($validator->passes(), "O slug \"{$slug}\" deveria ser aceito.");
        }
    }

    #[DataProvider('newlyReservedSlugs')]
    public function test_reserved_slug_never_resolves_as_a_tenant_menu_even_if_the_row_exists(string $slug): void
    {
        // A validação barra a criação, mas mesmo que o banco tivesse esse slug
        // a rota /{tenant} não pode capturar o primeiro segmento.
        Tenant::create([
            'name' => 'Intruso',
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        $this->get("/{$slug}")->assertNotFound();
        $this->get("/{$slug}/checkout")->assertNotFound();
        $this->assertNull(CurrentTenant::get());
    }
}

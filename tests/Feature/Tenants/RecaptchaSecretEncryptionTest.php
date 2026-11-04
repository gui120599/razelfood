<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MT-04: o segredo do reCAPTCHA não pode ficar em texto puro no banco
 * (nem no cache de resolução do tenant). Cobre o cast `encrypted` e a
 * migration de dados reversível que criptografa o que já existia.
 */
class RecaptchaSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

    private function makeTenant(string $slug, ?string $secret): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'recaptcha_secret_key' => $secret,
        ]);
    }

    private function rawSecret(Tenant $tenant): ?string
    {
        return DB::table('tenants')->where('id', $tenant->id)->value('recaptcha_secret_key');
    }

    private function writeRawSecret(Tenant $tenant, ?string $value): void
    {
        DB::table('tenants')->where('id', $tenant->id)->update(['recaptcha_secret_key' => $value]);
    }

    private function backfillMigration(): Migration
    {
        return require base_path('database/migrations/2026_11_04_162813_encrypt_recaptcha_secret_key_on_tenants_table.php');
    }

    public function test_secret_is_stored_encrypted_and_readable_through_the_model(): void
    {
        $tenant = $this->makeTenant('lazzopizza', self::SECRET);

        $raw = $this->rawSecret($tenant);

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertSame(self::SECRET, Crypt::decryptString($raw));
        $this->assertSame(self::SECRET, $tenant->fresh()->recaptcha_secret_key);
    }

    public function test_null_secret_stays_null(): void
    {
        $tenant = $this->makeTenant('lazzopizza', null);

        $this->assertNull($this->rawSecret($tenant));
        $this->assertNull($tenant->fresh()->recaptcha_secret_key);
    }

    public function test_column_is_text_so_the_encrypted_payload_never_gets_truncated(): void
    {
        $column = collect(Schema::getColumns('tenants'))->firstWhere('name', 'recaptcha_secret_key');

        $this->assertSame('text', $column['type_name']);

        $long = str_repeat('x', 200);
        $tenant = $this->makeTenant('lazzopizza', $long);

        $this->assertGreaterThan(255, strlen($this->rawSecret($tenant)));
        $this->assertSame($long, $tenant->fresh()->recaptcha_secret_key);
    }

    public function test_backfill_encrypts_existing_plaintext_values_and_is_idempotent(): void
    {
        $tenant = $this->makeTenant('lazzopizza', null);
        $this->writeRawSecret($tenant, self::SECRET); // como estava antes do cast

        $this->backfillMigration()->up();

        $raw = $this->rawSecret($tenant);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, $tenant->fresh()->recaptcha_secret_key);

        $this->backfillMigration()->up();

        $this->assertSame($raw, $this->rawSecret($tenant), 'Rodar de novo não pode criptografar duas vezes.');
        $this->assertSame(self::SECRET, $tenant->fresh()->recaptcha_secret_key);
    }

    public function test_backfill_handles_null_empty_and_soft_deleted_tenants(): void
    {
        $nullTenant = $this->makeTenant('semsegredo', null);
        $emptyTenant = $this->makeTenant('vazio', null);
        $this->writeRawSecret($emptyTenant, '');
        $deleted = $this->makeTenant('excluido', null);
        $this->writeRawSecret($deleted, self::SECRET);
        $deleted->delete();

        $this->backfillMigration()->up();

        $this->assertNull($this->rawSecret($nullTenant));
        $this->assertNull($this->rawSecret($emptyTenant), 'String vazia quebraria o cast; vira null.');
        $this->assertStringNotContainsString(self::SECRET, $this->rawSecret($deleted));
        $this->assertSame(self::SECRET, Tenant::withTrashed()->find($deleted->id)->recaptcha_secret_key);
    }

    public function test_backfill_down_restores_plaintext_and_keeps_unreadable_values(): void
    {
        $tenant = $this->makeTenant('lazzopizza', self::SECRET);
        $legacy = $this->makeTenant('legado', null);
        $this->writeRawSecret($legacy, 'ja-em-texto-puro');

        $this->backfillMigration()->down();

        $this->assertSame(self::SECRET, $this->rawSecret($tenant));
        $this->assertSame('ja-em-texto-puro', $this->rawSecret($legacy));

        // Ida e volta completa: up() de novo deixa tudo criptografado.
        $this->backfillMigration()->up();

        $this->assertStringNotContainsString(self::SECRET, $this->rawSecret($tenant));
        $this->assertSame(self::SECRET, $tenant->fresh()->recaptcha_secret_key);
        $this->assertSame('ja-em-texto-puro', $legacy->fresh()->recaptcha_secret_key);
    }

    public function test_backfill_forgets_the_cached_tenant_of_the_slug(): void
    {
        $tenant = $this->makeTenant('lazzopizza', null);
        $this->writeRawSecret($tenant, self::SECRET);
        Cache::put('tenant:slug:lazzopizza', 'entrada-antiga', 300);

        $this->backfillMigration()->up();

        $this->assertFalse(Cache::has('tenant:slug:lazzopizza'));
    }

    public function test_cached_tenant_never_carries_the_plaintext_secret(): void
    {
        $tenant = $this->makeTenant('lazzopizza', self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, serialize(Tenant::find($tenant->id)));
    }
}

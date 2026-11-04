<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfill de dados (MT-04): criptografa `tenants.recaptcha_secret_key`
     * com o MESMO formato do cast `encrypted` do model (`Crypt::encryptString`,
     * sem serialização). Opera por `DB::table` para não passar pelo cast, é
     * idempotente (valor já criptografado é mantido) e inclui tenants
     * excluídos (soft delete).
     *
     * - `null` fica como está; `''` vira `null` (o cast não decifra string
     *   vazia — lançaria DecryptException em toda leitura).
     * - Depende de APP_KEY estável: se a chave for rotacionada, os segredos
     *   criptografados ficam irrecuperáveis (o tenant precisa recadastrar).
     */
    public function up(): void
    {
        $this->rewriteSecrets(function (string $value): ?string {
            if ($value === '') {
                return null;
            }

            return $this->isEncrypted($value) ? $value : Crypt::encryptString($value);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Decifra de volta para texto puro. Um valor que não decifra (já em texto
     * puro, ou criptografado com outra APP_KEY) é mantido como está.
     */
    public function down(): void
    {
        $this->rewriteSecrets(function (string $value): ?string {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return $value;
            }
        });
    }

    /**
     * @param  callable(string): ?string  $transform
     */
    private function rewriteSecrets(callable $transform): void
    {
        DB::table('tenants')
            ->whereNotNull('recaptcha_secret_key')
            ->orderBy('id')
            ->select('id', 'slug', 'recaptcha_secret_key')
            ->chunkById(100, function ($tenants) use ($transform): void {
                foreach ($tenants as $tenant) {
                    $new = $transform((string) $tenant->recaptcha_secret_key);

                    if ($new !== $tenant->recaptcha_secret_key) {
                        DB::table('tenants')->where('id', $tenant->id)->update(['recaptcha_secret_key' => $new]);
                    }

                    // O Tenant resolvido por slug fica em cache com os atributos
                    // crus; uma entrada antiga seria lida pelo cast com o formato
                    // errado até o TTL expirar.
                    Cache::forget("tenant:slug:{$tenant->slug}");
                }
            });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};

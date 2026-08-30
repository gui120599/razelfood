<?php

namespace App\Actions\Tenants;

use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use App\Models\Tenant;

/**
 * Semeia opções básicas de entrega e pagamento pra um tenant poder operar o
 * cardápio/checkout desde o primeiro dia, sem depender do Admin cadastrar
 * tudo manualmente antes de qualquer pedido ser possível (RF-20/RF-24).
 *
 * Idempotente via firstOrCreate (não updateOrCreate) por nome: pode ser
 * rodada de novo a qualquer momento sem duplicar E sem sobrescrever taxas
 * ou configurações que o Admin já tenha customizado numa opção existente
 * — só cria o que ainda não existe, nunca atualiza o que já está lá.
 *
 * Usa withoutGlobalScopes() nas duas queries: DeliveryOption/PaymentOption
 * estendem TenantScopedModel, cujo global scope filtra por
 * CurrentTenant::id() — ambiente (ex.: um valor de CurrentTenant deixado
 * por outro código no mesmo processo/request) poderia fazer o firstOrCreate
 * não encontrar um registro já existente e duplicá-lo. Esta Action já
 * recebe o tenant explicitamente, então não deve depender desse estado
 * global pra decidir o que já existe.
 */
class SeedDefaultTenantOptions
{
    private const DELIVERY_OPTIONS = [
        ['name' => 'Retirada', 'requires_address' => false],
        ['name' => 'Entregar', 'requires_address' => true],
        ['name' => 'Comer no local', 'requires_address' => false],
    ];

    private const PAYMENT_OPTIONS = [
        ['name' => 'Dinheiro', 'is_cash' => true],
        ['name' => 'Cartão Débito', 'is_cash' => false],
        ['name' => 'Cartão Crédito', 'is_cash' => false],
        ['name' => 'Pix', 'is_cash' => false],
    ];

    public function __invoke(Tenant $tenant): void
    {
        foreach (self::DELIVERY_OPTIONS as $option) {
            DeliveryOption::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $option['name']],
                ['requires_address' => $option['requires_address'], 'delivery_fee' => 0],
            );
        }

        foreach (self::PAYMENT_OPTIONS as $option) {
            PaymentOption::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $option['name']],
                ['is_cash' => $option['is_cash']],
            );
        }
    }
}

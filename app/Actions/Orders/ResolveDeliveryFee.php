<?php

namespace App\Actions\Orders;

use App\Exceptions\CheckoutException;
use App\Models\DeliveryOption;
use App\Models\DeliveryZoneNeighborhood;
use App\Support\CurrentTenant;
use App\Support\NeighborhoodNormalizer;

/**
 * RN-37/RN-38: resolve a taxa de entrega a partir do bairro do cliente,
 * sempre no servidor (reforça RN-13) — nunca aceitar a taxa vinda do
 * navegador. Chamada só quando uma DeliveryOption foi escolhida (retirada
 * não passa por aqui: fica com taxa 0, resolvido pelo chamador).
 *
 * RN-37 caso 2 (decidido em 20/08/2026): a taxa de bairro não configurado
 * não substitui mais a taxa normal — ela é SOMADA à taxa de entrega do
 * tipo/opção escolhida (RN-30), depois de aplicada a isenção por pedido
 * mínimo (RN-38) sobre essa parte. Ou seja: se o pedido não atinge o
 * mínimo, o cliente paga taxa da opção + taxa de bairro não configurado;
 * se atinge o mínimo, a parte normal zera e sobra só a taxa de bairro não
 * configurado. Isso é sempre exposto ao checkout para informar o cliente
 * (base_fee/unlisted_surcharge no retorno).
 */
class ResolveDeliveryFee
{
    /**
     * @return array{fee: float, delivery_zone_id: ?int, is_unlisted_neighborhood: bool, base_fee: float, unlisted_surcharge: float}
     *
     * @throws CheckoutException quando o bairro não está mapeado e o tenant não atende bairros não configurados (RN-37, caso 3).
     */
    public function __invoke(DeliveryOption $deliveryOption, ?string $neighborhood, ?string $city, float $itemsTotal): array
    {
        $tenant = CurrentTenant::get();

        // Onboarding: tenant ainda não cadastrou nenhum setor de entrega —
        // mantém o comportamento anterior (taxa fixa da opção de entrega),
        // para não bloquear o checkout de quem ainda não migrou para a
        // taxa por bairro (RN-34).
        if ($tenant->deliveryZones()->doesntExist()) {
            $baseFee = $this->applyFreeDeliveryThreshold((float) $deliveryOption->delivery_fee, $deliveryOption, $itemsTotal);

            return [
                'fee' => $baseFee,
                'delivery_zone_id' => null,
                'is_unlisted_neighborhood' => false,
                'base_fee' => $baseFee,
                'unlisted_surcharge' => 0.0,
            ];
        }

        $normalizedNeighborhood = NeighborhoodNormalizer::normalize($neighborhood);
        $normalizedCity = NeighborhoodNormalizer::normalize($city);

        $match = $normalizedNeighborhood
            ? DeliveryZoneNeighborhood::query()
                ->with('deliveryZone')
                ->where('neighborhood', $normalizedNeighborhood)
                ->when($normalizedCity, fn ($query) => $query->where('city', $normalizedCity))
                ->first()
            : null;

        if ($match) {
            $baseFee = $this->applyFreeDeliveryThreshold((float) $match->deliveryZone->delivery_fee, $deliveryOption, $itemsTotal);

            return [
                'fee' => $baseFee,
                'delivery_zone_id' => $match->delivery_zone_id,
                'is_unlisted_neighborhood' => false,
                'base_fee' => $baseFee,
                'unlisted_surcharge' => 0.0,
            ];
        }

        if (! $tenant->serves_unlisted_neighborhoods) {
            throw new CheckoutException('A entrega não está disponível para o bairro informado.');
        }

        // RN-37 caso 2: taxa da opção de entrega (já com isenção por pedido
        // mínimo aplicada) + taxa específica de bairro não configurado —
        // somadas, não substituídas.
        $baseFee = $this->applyFreeDeliveryThreshold((float) $deliveryOption->delivery_fee, $deliveryOption, $itemsTotal);
        $unlistedSurcharge = (float) $tenant->unlisted_neighborhood_fee;

        return [
            'fee' => round($baseFee + $unlistedSurcharge, 2),
            'delivery_zone_id' => null,
            'is_unlisted_neighborhood' => true,
            'base_fee' => $baseFee,
            'unlisted_surcharge' => $unlistedSurcharge,
        ];
    }

    private function applyFreeDeliveryThreshold(float $fee, DeliveryOption $deliveryOption, float $itemsTotal): float
    {
        if ($deliveryOption->min_order_for_free_delivery !== null && $itemsTotal >= $deliveryOption->min_order_for_free_delivery) {
            return 0.0;
        }

        return $fee;
    }
}

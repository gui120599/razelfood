<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Client;
use App\Models\Sale;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Vincula (ou desvincula, com null) o cliente do rascunho. Client é tenant-scoped: cliente de
 * outro estabelecimento nunca é encontrado. O cliente da venda é o comprador da NFC-e. Escolher
 * ou limpar é decisão do operador (`client_manual`): daí em diante os pedidos lançados não o mudam.
 */
class SetSaleClient
{
    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, ?int $clientId): Sale
    {
        return DB::transaction(function () use ($sale, $clientId): Sale {
            $locked = OpenSale::lock($sale);

            if ($clientId !== null && Client::query()->whereKey($clientId)->doesntExist()) {
                throw new SaleException('Cliente não encontrado.');
            }

            $locked->update(['client_id' => $clientId, 'client_manual' => true]);

            return $locked;
        }, attempts: 3);
    }
}

---
paths:
  - 'app/Filament/Resources/LocationSyncs/**,app/Services/Address/LocationCatalogTransfer.php'
---

# Address

## Exportar/importar catálogo de localidades entre ambientes
A sincronização de localidades (IBGE/ViaCEP/RuaCEP) é lenta; pra reaproveitar em produção uma sync já feita em dev, use as actions "Exportar localidades" / "Importar localidades" no header de ListLocationSyncs (painel central, grupo Localidades). Toda a lógica fica em App\Services\Address\LocationCatalogTransfer.

- Payload JSON agrupado por chave NATURAL (uf, ibge_code, nome normalizado), nunca por id — ids autoincrement divergem entre ambientes.
- import() é idempotente: updateOrCreate em states/cities + Neighborhood::upsert em lotes de 500. Nunca apaga nada.
- upsert() NÃO dispara o mutator de `name` de City/Neighborhood (que preencheria normalized_name); a chave normalizada é calculada à mão via NeighborhoodNormalizer no service.
- ValidationException do service usa chave crua "file"; a action reprefixa com $schema->getStatePath() (mesmo padrão da action "sync").

Testes: tests/Feature/Central/LocationCatalogTransferTest.php.

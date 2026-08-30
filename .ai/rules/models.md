---
paths:
  - 'app/Models/*.php'
---

# Models

## BelongsToMany usada em RelationManager precisa da relação inversa definida no model relacionado
Filament\Actions\AttachAction infere o nome da relação inversa a partir do model pai (ex.: FlashPromotion -> "flashPromotions") e chama esse método no model relacionado (Product) para excluir da lista de opções os registros já anexados (whereDoesntHave). Se o model relacionado não tiver esse método BelongsToMany de volta, abrir o select do Attach quebra com BadMethodCallException. Sempre que criar uma relação BelongsToMany usada por um RelationManager com AttachAction, definir também a relação inversa no model do outro lado (mesma tabela pivot, mesmo ->using()/->withPivot()). Achado real: App\Models\Product não tinha flashPromotions() enquanto FlashPromotion::products() já existia — corrigido adicionando o inverso.

Segunda instância confirmada (22/08/2026): `AddonsRelationManager` ($relationship='addons') exigiu `Product::addons()` E `Addon::products()` — a mesma regra vale em ambos os sentidos, mesmo quando só uma relação é usada diretamente pelo RelationManager.

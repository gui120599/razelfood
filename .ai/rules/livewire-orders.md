---
paths:
  - 'app/Filament/Tenant/Resources/Categories/**,app/Livewire/Menu.php,app/Actions/Menu/ResolvePriceForCartLine.php,app/Filament/Tenant/Livewire/Orders/FlavorPickerModal.php'
---

# Livewire Orders

## Herança de quantidades de sabores pai→subcategoria
Subcategoria (`categories.parent_id` não-nulo) pode herdar as `flavor_quantity_options` da categoria pai via a coluna boolean `categories.inherit_flavor_options` (toggle "Herdar quantidades de sabores da categoria pai" no CategoryForm — visível só quando é subcategoria + allows_flavors + pai elegível; `->default(true)`).

- `Category::resolvedFlavorQuantityOptions()` é a FONTE ÚNICA das opções de sabor no cardápio/checkout/PDV. Nunca ler `->flavorQuantityOptions` direto para lógica de combo — sempre o helper. `Category::inheritsFlavorOptions()` = `parent_id !== null && inherit_flavor_options`.
- O helper precisa de `parent` + `parent.flavorQuantityOptions` carregados. Call sites já fazem eager-load (`Menu::categories()` faz `$child->setRelation('parent', $category)`; `menuCategory()`, `viewingProduct()`, `searchResults()`, `bestsellers()`, `ResolvePriceForCartLine::resolveCombo`, `FlavorPickerModal::loadCategory` carregam `parent.flavorQuantityOptions`).
- `Menu::menuCategory(int $id): ?Category` resolve a categoria de um combo INCLUINDO subcategorias — a coleção `categories()` só tem raízes. `startCombo()`/`selectFlavorQuantity()` e o `$comboCategory` do menu.blade usam esse helper, não `$this->categories->firstWhere('id', ...)`.

Painel: subcategoria agora tem página de edição completa do Resource. `SubcategoriesRelationManager::EditAction` usa `->url(CategoryResource::getUrl('edit', ...))`; o filtro `whereNull('parent_id')` saiu de `CategoryResource::getEloquentQuery()` (removido) e vive em `CategoriesTable::modifyQueryUsing()` (só a listagem). `SubcategoriesRelationManager::canViewForRecord()` retorna `parent_id === null` (trava 1 nível — sem sub-subcategoria). `FlavorQuantityOptionsRelationManager::canViewForRecord()` = `allows_flavors && ! inheritsFlavorOptions()`.

Testes: tests/Feature/CategoryFlavorInheritanceTest.php, MenuSubcategoryFlavorInheritanceTest.php, SubcategoryEditPageTest.php.

# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| {app/Actions/Sales/FinalizeSale.php,app/Actions/Fiscal/ApplyFiscalProviderStatus.php,routes/console.php} | .ai/rules/actions-fiscal.md |
| app/Filament/Resources/LocationSyncs/**,app/Services/Address/LocationCatalogTransfer.php | .ai/rules/address.md |
| app/Livewire/{Menu.php,Checkout.php,OrderStatusTimeline.php,Concerns/EstablishesTenantContext.php} | .ai/rules/app-livewire.md |
| {app/Models/Client.php,app/Filament/Tenant/Resources/Clients/**} | .ai/rules/clients.md |
| app/Console/Commands/** | .ai/rules/commands.md |
| app/{Support/Tenancy/**,Jobs/**,Console/Concerns/**,Console/Commands/**} | .ai/rules/concerns-commands.md |
| config/filament-shield.php, config/*.php | .ai/rules/config.md |
| {app/Actions/Sales/SyncPdvActivation.php,app/Support/Sales/PdvCutoff.php,app/Jobs/CancelUnpaidSalesJob.php,app/Console/Commands/SyncPdvActivationCommand.php} | .ai/rules/console-commands.md |
| app/{Support/Fiscal/FiscalReportQuery.php,Filament/Tenant/Pages/FiscalReport.php,Http/Controllers/Reports/FiscalReport*.php,Models/FiscalDocumentItem.php} | .ai/rules/controllers-reports.md |
| app/Filament/Tenant/Resources/DeliveryZones/RelationManagers/NeighborhoodsRelationManager.php | .ai/rules/delivery-zones-relation-managers.md |
| tests/Feature/**/*.php | .ai/rules/feature.md |
| app/Filament/Resources/** | .ai/rules/filament-resources.md |
| app/Filament/Support/EstablishmentDocumentFields.php, app/Filament/Support/InputMasks.php | .ai/rules/filament-support.md |
| resources/css/filament/**/*.css | .ai/rules/filament.md |
| app/{Actions/Fiscal/BuildNfcePayload.php,Support/Fiscal/BuyerResolver.php,Support/Fiscal/FlavorFraction.php} | .ai/rules/fiscal-fiscal.md |
| {app/Support/Fiscal/**,app/Models/TenantFiscalSettings.php} | .ai/rules/fiscal-models.md |
| app/{Actions/Fiscal/**,Services/Fiscal/**,Models/FiscalDocument.php,Models/FiscalNumberSequence.php}, app/{Actions/Fiscal/BuildNfcePayload.php,Enums/CardBrand.php,Support/Fiscal/BuyerResolver.php} | .ai/rules/fiscal.md |
| ** | .ai/rules/general.md |
| app/Filament/Tenant/Resources/Categories/**,app/Livewire/Menu.php,app/Actions/Menu/ResolvePriceForCartLine.php,app/Filament/Tenant/Livewire/Orders/FlavorPickerModal.php | .ai/rules/livewire-orders.md |
| app/Actions/Menu/{ResolvePriceForProduct.php,ResolvePriceForCartLine.php,ResolvedPrice.php},app/Livewire/Menu.php | .ai/rules/livewire.md |
| app/{Livewire/Menu.php,Actions/Menu/ResolvePriceForCartLine.php,Models/Category.php,Models/FlavorQuantityOption.php} | .ai/rules/menu.md |
| app/Http/Middleware/ResolveTenantFromPath.php, app/Http/Middleware/ApplyTenantScopes.php | .ai/rules/middleware.md |
| app/{Models/User.php,Providers/AppServiceProvider.php,Providers/Filament/*.php} | .ai/rules/models-filament.md |
| {app/Actions/Sales/**,app/Actions/Orders/CancelOrder.php,app/Actions/Orders/UpdateOrderFromCart.php,app/Models/Order.php,app/Models/OrderItem.php} | .ai/rules/models-models.md |
| app/Models/*.php, app/Models/Tenant.php | .ai/rules/models.md |
| resources/views/{orders/ticket.blade.php,components/reports/print-layout.blade.php,reports/*-print.blade.php} | .ai/rules/orders-reports.md |
| app/Filament/Tenant/{Pages,Livewire}/Orders/** | .ai/rules/orders.md |
| app/Filament/Tenant/Pages/Sales/** | .ai/rules/pages-sales.md |
| app/Filament/Tenant/Pages/**/*.php | .ai/rules/pages.md |
| {app/Filament/Tenant/Pages/Sales/**,app/Filament/Tenant/Resources/Sales/**,app/Policies/SalePolicy.php} | .ai/rules/policies.md |
| app/{Actions/Menu/ResolvePriceForCartLine.php,Actions/Orders/Support/CartStockAndPromotionLedger.php,Models/ProductGift.php,Filament/Tenant/Resources/Products/RelationManagers/GiftsRelationManager.php} | .ai/rules/products-relation-managers.md |
| app/Filament/Tenant/Resources/Categories/RelationManagers/FlavorQuantityOptionsRelationManager.php | .ai/rules/relation-managers.md |
| app/Filament/Tenant/Pages/{Dashboard.php,Reports.php},app/Filament/Tenant/Widgets/Reports/** | .ai/rules/reports.md |
| app/Filament/Tenant/Resources/**/*Resource.php | .ai/rules/resources.md |
| routes/web.php | .ai/rules/routes.md |
| app/{Actions/Fiscal/**,Actions/Sales/CancelSaleWithFiscalDocument.php,Jobs/CompleteSaleCancellationJob.php,Console/Commands/SyncFiscalDocumentsCommand.php} | .ai/rules/sales-commands.md |
| {app/Actions/Sales/**,app/Support/Sales/**,app/Models/Sale.php,app/Models/Order.php} | .ai/rules/sales-models-models.md |
| {app/Filament/Tenant/Pages/Sales/NewSale.php,app/Filament/Tenant/Livewire/Sales/**,resources/views/filament/tenant/pages/sales/new-sale*,resources/views/filament/tenant/livewire/sales/**,app/Support/Sales/SaleCartLines.php} | .ai/rules/sales.md |
| scripts/promote-producao.sh | .ai/rules/scripts.md |
| app/{Actions/Menu/ResolvePriceForCartLine.php,Actions/Orders/Support/CartStockAndPromotionLedger.php,Models/ProductGift.php,Support/Orders/GiftLineLabel.php} | .ai/rules/support-orders.md |
| app/Actions/Orders/Support/CartStockAndPromotionLedger.php | .ai/rules/support.md |
| app/{Actions/Orders/AdvanceOrderStatus.php,Filament/Tenant/Support/OrderStatusActions.php,Filament/Tenant/Pages/Kitchen.php,Models/Order.php} | .ai/rules/tenant-pages.md |
| {app/Filament/Support/FiscalSettingsFields.php,app/Filament/Tenant/Pages/FiscalSettings.php,app/Filament/Resources/Tenants/**} | .ai/rules/tenants.md |
| app/Filament/Tenant/Resources/Users/** | .ai/rules/users.md |
| app/{Http/Controllers/Webhooks/**,Support/Webhooks/**} | .ai/rules/webhooks-webhooks.md |
| {routes/webhooks.php,config/webhooks.php,app/Http/Middleware/ResolveTenantFromWebhookToken.php,app/Http/Controllers/Webhooks/**,app/Support/Webhooks/**} | .ai/rules/webhooks.md |

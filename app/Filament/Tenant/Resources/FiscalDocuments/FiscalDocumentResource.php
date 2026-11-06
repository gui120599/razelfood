<?php

namespace App\Filament\Tenant\Resources\FiscalDocuments;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Tenant\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Filament\Tenant\Resources\FiscalDocuments\Schemas\FiscalDocumentInfolist;
use App\Filament\Tenant\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Models\FiscalDocument;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Documentos fiscais (NFC-e) do estabelecimento (Fase 1.4). Só leitura + as ações fiscais da
 * venda (reenviar, atualizar status, cancelar, DANFE): o documento nasce e muda só pelas Actions
 * de App\Actions\Fiscal. Desvio da paridade com o Pizzaria-App (que acompanha as notas na tabela
 * de Vendas), pedido pelo usuário.
 */
class FiscalDocumentResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'documento fiscal';

    protected static ?string $pluralModelLabel = 'documentos fiscais';

    protected static ?string $navigationLabel = 'Documentos fiscais';

    protected static ?string $slug = 'documentos-fiscais';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return FiscalDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalDocuments::route('/'),
            'view' => ViewFiscalDocument::route('/{record}'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::NFE_EMISSAO;
    }
}

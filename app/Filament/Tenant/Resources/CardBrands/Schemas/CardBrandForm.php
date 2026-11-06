<?php

namespace App\Filament\Tenant\Resources\CardBrands\Schemas;

use App\Enums\CardBrand as CardBrandEnum;
use App\Filament\Support\InputMasks;
use App\Rules\ValidCnpj;
use App\Support\CurrentTenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class CardBrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bandeira de cartão')
                ->description('A bandeira e o CNPJ da credenciadora (adquirente) entram no bloco de cartão da NFC-e.')
                ->columns(2)
                ->schema([
                    Select::make('brand')
                        ->label('Bandeira na nota')
                        ->options(collect(CardBrandEnum::cases())->mapWithKeys(
                            fn (CardBrandEnum $brand): array => [$brand->value => $brand->label()]
                        )->all())
                        ->required()
                        ->live(),
                    TextInput::make('name')
                        ->label('Nome exibido')
                        ->required()
                        ->maxLength(60)
                        ->unique(
                            table: 'card_brands',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('tenant_id', CurrentTenant::id())
                                ->where('brand', $get('brand')),
                        )
                        ->validationMessages(['unique' => 'Já existe uma bandeira com este nome (inclusive uma já excluída). Use outro nome.']),
                    InputMasks::cnpj(TextInput::make('acquirer_cnpj')->label('CNPJ da credenciadora'))
                        ->rule(new ValidCnpj)
                        ->maxLength(18)
                        ->helperText('Opcional. Ex.: CNPJ da Stone, Cielo, Rede...')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}

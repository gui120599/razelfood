<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Enums\TenantStatus;
use App\Filament\Support\EstablishmentDocumentFields;
use App\Filament\Support\InputMasks;
use App\Models\Plan;
use App\Rules\ValidTenantSlug;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do estabelecimento')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome comercial')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->label('Slug (endereço)')
                        ->required()
                        ->maxLength(255)
                        ->rules([new ValidTenantSlug])
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation !== 'create')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Endereço do cardápio: razelfood.com.br/{slug} (e painel em /painel/{slug}). Praticamente imutável após a publicação — ver ação "Alterar slug" na edição.'),
                    Select::make('status')
                        ->label('Status')
                        ->options(collect(TenantStatus::cases())->mapWithKeys(
                            fn (TenantStatus $status) => [$status->value => $status->label()]
                        ))
                        ->required()
                        ->default(TenantStatus::Active->value),
                    Select::make('plan_id')
                        ->label('Plano')
                        ->relationship('plan', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->default(fn (): ?int => Plan::query()->where('slug', 'essencial')->value('id'))
                        ->helperText('Define o catálogo de features disponível para o tenant (RN-40). Overrides pontuais ficam na aba "Overrides de feature", após salvar.'),
                    InputMasks::phone(TextInput::make('whatsapp_number')->label('WhatsApp para pedidos'))
                        ->required()
                        ->maxLength(20),
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->image()
                        ->disk('public')
                        ->directory('tenants')
                        ->visibility('public')
                        ->imageEditor()
                        ->maxSize(2048),
                    FileUpload::make('favicon_path')
                        ->label('Favicon do cardápio')
                        ->image()
                        ->acceptedFileTypes(['image/png'])
                        ->disk('public')
                        ->directory('tenants/favicons')
                        ->visibility('public')
                        ->imageEditor()
                        ->imageEditorAspectRatioOptions(['1:1'])
                        ->maxSize(512)
                        ->helperText('PNG quadrado (idealmente 512×512). Aparece na aba do navegador do cardápio público.'),
                    ColorPicker::make('primary_color')
                        ->label('Cor de destaque do cardápio'),
                    TextInput::make('watermark_height')
                        ->label('Altura da marca d\'água (px)')
                        ->numeric()
                        ->minValue(80)
                        ->maxValue(800)
                        ->default(288)
                        ->required()
                        ->suffix('px')
                        ->helperText('Altura da logo exibida como marca d\'água de fundo no cardápio público. A largura acompanha a proporção da imagem — pode ultrapassar a coluna (mais estreita) de listagem de produtos.'),
                ]),
            EstablishmentDocumentFields::section(),
            Section::make('reCAPTCHA')
                ->columns(3)
                ->schema([
                    Toggle::make('recaptcha_enabled')
                        ->label('Ativado')
                        ->live(),
                    TextInput::make('recaptcha_site_key')
                        ->label('Chave do site')
                        ->visible(fn (Get $get): bool => (bool) $get('recaptcha_enabled')),
                    TextInput::make('recaptcha_secret_key')
                        ->label('Chave secreta')
                        ->password()
                        ->revealable()
                        ->visible(fn (Get $get): bool => (bool) $get('recaptcha_enabled')),
                ]),
            Section::make('Primeiro usuário (Admin)')
                ->description('Criado junto com o tenant, já com o papel Admin atribuído.')
                ->columns(2)
                ->visibleOn('create')
                ->schema([
                    TextInput::make('admin_name')
                        ->label('Nome')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    TextInput::make('admin_email')
                        ->label('E-mail')
                        ->email()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    TextInput::make('admin_password')
                        ->label('Senha')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->minLength(8)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}

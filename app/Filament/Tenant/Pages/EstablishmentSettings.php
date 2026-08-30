<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Support\EstablishmentDocumentFields;
use App\Filament\Support\InputMasks;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Dados do estabelecimento que o próprio Admin do tenant mantém — nome
 * comercial, WhatsApp de pedidos, logo e cor do cardápio (RF-30, RF-34).
 * Slug, status e plano continuam exclusivos do painel central da Razel Tec
 * (RN-05, RN-44) — não aparecem aqui.
 */
class EstablishmentSettings extends Page
{
    // Alias em vez de sobrescrever direto: combina a checagem de feature
    // (RN-43) com a permissão do Shield — ver .ai/rules/pages.md.
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Estabelecimento';

    protected static ?string $title = 'Estabelecimento';

    protected static ?string $slug = 'estabelecimento';

    protected static ?int $navigationSort = -10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CONFIGURACOES_ESTABELECIMENTO) ?? false)
            && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CONFIGURACOES_ESTABELECIMENTO) ?? false)
            && static::pageShieldShouldRegisterNavigation();
    }

    public function mount(): void
    {
        $this->form->fill(
            CurrentTenant::get()->only([
                'name',
                'whatsapp_number',
                'logo_path',
                'favicon_path',
                'primary_color',
                'watermark_height',
                ...EstablishmentDocumentFields::names(),
            ])
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade do estabelecimento')
                    ->description('Como o cardápio público se apresenta ao cliente e para onde os pedidos são enviados no WhatsApp.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome comercial')
                            ->required()
                            ->maxLength(255),
                        InputMasks::phone(TextInput::make('whatsapp_number')->label('WhatsApp para pedidos'))
                            ->required()
                            ->maxLength(20)
                            ->helperText('Com DDD. É o número que recebe os pedidos do cardápio.'),
                        ColorPicker::make('primary_color')
                            ->label('Cor de destaque do cardápio'),
                        TextInput::make('watermark_height')
                            ->label('Altura da marca d\'água (px)')
                            ->numeric()
                            ->minValue(80)
                            ->maxValue(800)
                            ->required()
                            ->suffix('px')
                            ->helperText('Altura da logo exibida como marca d\'água de fundo no cardápio público.'),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory('tenants')
                            ->visibility('public')
                            ->imageEditor()
                            ->maxSize(2048)
                            ->columnSpanFull(),
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
                            ->helperText('PNG quadrado (idealmente 512×512). Aparece na aba do navegador do cardápio público.')
                            ->columnSpanFull(),
                    ]),
                EstablishmentDocumentFields::section(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $tenant = CurrentTenant::get();

        $tenant->update($this->form->getState());

        // O middleware IdentifyTenant cacheia o tenant por slug — sem
        // invalidar, o cardápio público mostra logo/cor/nome antigos até o
        // TTL expirar.
        Cache::forget("tenant:slug:{$tenant->slug}");

        Notification::make()
            ->title('Configurações salvas')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->submit('save'),
        ];
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment(Alignment::Start)
                    ->key('form-actions'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }
}

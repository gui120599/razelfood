<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Support\InputMasks;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Limites de atraso (seção 9 da spec da Central de Pedidos), configuráveis
 * por tenant — não existe hoje nenhuma tela de configurações self-service
 * pro tenant (TenantResource é exclusivo do painel Central/Razel Tec).
 */
class OrderSettings extends Page
{
    // Alias em vez de sobrescrever direto: precisamos combinar a checagem de
    // feature (RN-43) com a permissão do Shield — ver Kitchen.php pro mesmo padrão.
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?string $navigationLabel = 'Configurações de Pedidos';

    protected static ?string $title = 'Configurações de Pedidos';

    protected static ?string $slug = 'configuracoes-de-pedidos';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CONFIGURACOES_PEDIDOS) ?? false) && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CONFIGURACOES_PEDIDOS) ?? false) && static::pageShieldShouldRegisterNavigation();
    }

    public function mount(): void
    {
        $this->form->fill(
            CurrentTenant::get()->only([
                'order_attention_after_minutes',
                'order_late_after_minutes',
                'serves_unlisted_neighborhoods',
                'unlisted_neighborhood_fee',
                'allow_free_form_address',
                'uses_in_transit_stage',
                'assigns_delivery_couriers',
                'require_client_cpf',
            ])
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Alerta de atraso')
                    ->description('Minutos decorridos na etapa atual do pedido a partir dos quais a Central de Pedidos sinaliza atenção/atraso.')
                    ->schema([
                        TextInput::make('order_attention_after_minutes')
                            ->label('Alerta de atenção após (minutos)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('order_late_after_minutes')
                            ->label('Alerta de atraso após (minutos)')
                            ->numeric()
                            ->minValue(1)
                            ->gte('order_attention_after_minutes')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Entregas')
                    ->description('Como o pedido de entrega avança na Central de Pedidos, de "Pronto" em diante.')
                    ->schema([
                        Toggle::make('uses_in_transit_stage')
                            ->label('Pedidos de entrega passam pela etapa "Em Transporte"')
                            ->default(true)
                            ->live()
                            ->helperText('Desligado: ao marcar "Pronto", o pedido de entrega é finalizado direto e a coluna "Em Entrega" some da Central.'),
                        Toggle::make('assigns_delivery_couriers')
                            ->label('Escolher um entregador ao despachar o pedido')
                            ->default(true)
                            ->visible(fn (Get $get) => (bool) $get('uses_in_transit_stage'))
                            ->helperText('Desligado: o pedido avança para "Em Transporte" sem selecionar um entregador, e o filtro/campo de entregador some da Central.'),
                    ])
                    ->columns(1),
                Section::make('Dados do cliente')
                    ->description('O que o cliente precisa informar para finalizar um pedido pelo cardápio online.')
                    ->schema([
                        Toggle::make('require_client_cpf')
                            ->label('Exigir CPF do cliente no checkout online')
                            ->default(false)
                            ->helperText('Ligado: o cliente precisa informar um CPF válido para finalizar o pedido pelo cardápio público. Não afeta pedidos lançados pela Central de Pedidos.'),
                    ])
                    ->columns(1),
                Section::make('Endereço no checkout')
                    ->description('Como o cliente informa cidade e bairro ao finalizar um pedido de entrega pelo cardápio online.')
                    ->schema([
                        Toggle::make('allow_free_form_address')
                            ->label('Permitir que o cliente digite cidade e bairro livremente')
                            ->default(true)
                            ->helperText('Desligado: o cliente escolhe a cidade e o bairro em listas — cidades limitadas aos seus setores de entrega e bairros vindos da base oficial, o que evita erro de digitação de bairro. Só tem efeito quando você já tem setores de entrega cadastrados.'),
                    ])
                    ->columns(1),
                Section::make('Bairros não configurados')
                    ->description('Vale para pedidos cujo bairro não está em nenhum setor de entrega cadastrado (RN-36/RN-37).')
                    ->schema([
                        Toggle::make('serves_unlisted_neighborhoods')
                            ->label('Atender bairros não cadastrados em nenhum setor')
                            ->live()
                            ->helperText('Desligado: o checkout bloqueia o pedido para esses endereços, avisando o cliente. Ligado: o checkout segue com a taxa abaixo, e o atendente confirma a viabilidade da entrega depois.'),
                        InputMasks::money(
                            TextInput::make('unlisted_neighborhood_fee')
                                ->label('Taxa para bairro não configurado')
                                ->prefix('R$')
                                ->helperText('Normalmente maior que a taxa dos setores mapeados.')
                                ->required(fn (Get $get) => (bool) $get('serves_unlisted_neighborhoods'))
                                ->visible(fn (Get $get) => (bool) $get('serves_unlisted_neighborhoods'))
                        ),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        CurrentTenant::get()->update($this->form->getState());

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

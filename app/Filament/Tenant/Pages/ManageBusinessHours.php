<?php

namespace App\Filament\Tenant\Pages;

use App\Models\BusinessHour;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

class ManageBusinessHours extends Page
{
    // Alias em vez de sobrescrever direto: combina a checagem de feature
    // (RN-43) com a permissão do Shield — ver Kitchen.php/OrderSettings.php
    // pro mesmo padrão (.ai/rules/pages.md).
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Horários de Funcionamento';

    protected static ?string $title = 'Horários de Funcionamento';

    protected static ?string $slug = 'horarios';

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

    /** @var array<int, string> */
    private const WEEKDAYS = [
        0 => 'Domingo',
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
    ];

    public function mount(): void
    {
        $hoursByWeekday = BusinessHour::orderBy('opens_at')->get()->groupBy('weekday');

        $state = [];

        foreach (self::WEEKDAYS as $weekday => $label) {
            $state["weekday_{$weekday}"] = $hoursByWeekday->get($weekday, collect())
                ->map(fn (BusinessHour $hour): array => [
                    'opens_at' => $hour->opens_at?->format('H:i'),
                    'closes_at' => $hour->closes_at?->format('H:i'),
                    'is_active' => $hour->is_active,
                ])
                ->all();
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(
                collect(self::WEEKDAYS)
                    ->map(fn (string $label, int $weekday) => Section::make($label)
                        ->schema([
                            Repeater::make("weekday_{$weekday}")
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make('Abre')
                                        ->markAsRequired(),
                                    TableColumn::make('Fecha')
                                        ->markAsRequired(),
                                    TableColumn::make('Ativo'),
                                ])
                                ->schema([
                                    TimePicker::make('opens_at')
                                        ->label('Abre')
                                        ->seconds(false)
                                        ->required(),
                                    TimePicker::make('closes_at')
                                        ->label('Fecha')
                                        ->seconds(false)
                                        ->required(),
                                    Toggle::make('is_active')
                                        ->label('Ativo')
                                        ->default(true),
                                ])
                                ->addActionLabel('+ Adicionar turno')
                                ->defaultItems(0),
                        ]))
                    ->values()
                    ->all()
            )
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        DB::transaction(function () use ($state): void {
            foreach (array_keys(self::WEEKDAYS) as $weekday) {
                BusinessHour::where('weekday', $weekday)->delete();

                foreach ($state["weekday_{$weekday}"] ?? [] as $shift) {
                    BusinessHour::create([
                        'weekday' => $weekday,
                        'opens_at' => $shift['opens_at'],
                        'closes_at' => $shift['closes_at'],
                        'is_active' => $shift['is_active'] ?? true,
                    ]);
                }
            }
        });

        Notification::make()
            ->title('Horários salvos')
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

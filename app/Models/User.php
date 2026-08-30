<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CentralRole;
use App\Support\CurrentTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'central_role',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'central_role' => CentralRole::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Usuários com a role "Entregador" do tenant atual, para o Select de
     * atribuição de entregador na Central de Pedidos. User não estende
     * TenantScopedModel (é Authenticatable puro), então o filtro por
     * tenant_id aqui é defesa em profundidade além do escopo por team_id
     * que o spatie/permission já aplica em model_has_roles.
     */
    public function scopeDeliveryPersonnel(Builder $query): Builder
    {
        return $query->where('tenant_id', CurrentTenant::id())->role('Entregador');
    }

    /**
     * Checagem explícita além do global scope: usuário do tenant só acessa
     * o painel do próprio tenant, e a equipe Razel Tec (tenant_id null) só
     * acessa o painel central (seção 4.7 da modelagem). No painel central,
     * o que cada resource libera é decidido pelo `central_role` nas policies
     * (App\Policies\Concerns\CentralPanelPolicy).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'tenant' => $this->tenant_id !== null && $this->tenant_id === CurrentTenant::id(),
            'central' => $this->tenant_id === null,
            default => false,
        };
    }

    public function isCentralUser(): bool
    {
        return $this->tenant_id === null;
    }

    public function hasCentralRole(CentralRole $role): bool
    {
        return $this->isCentralUser() && $this->central_role === $role;
    }
}

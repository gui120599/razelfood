<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CentralRole;
use App\Support\CurrentTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
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
     *
     * O match fino "este usuário pode ver ESTE tenant" fica em
     * canAccessTenant() (contrato HasTenants) — o Filament chama os dois:
     * canAccessPanel() antes de qualquer rota do painel, e canAccessTenant()
     * antes de servir qualquer rota tenant-aware.
     *
     * A equipe Razel Tec com papel "Plataforma" (super admin) acessa TAMBÉM
     * o painel de qualquer tenant (supervisão/suporte) — RN-44. Como central
     * e tenant compartilham o cookie de sessão (domínio único), sem esta
     * liberação o super admin logado em /admin levava 403 ao abrir
     * /painel/{slug}. A autorização por Resource do Shield é liberada para
     * esse usuário via Gate::before em AppServiceProvider.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'tenant' => $this->tenant_id !== null || $this->hasCentralRole(CentralRole::Platform),
            'central' => $this->tenant_id === null,
            default => false,
        };
    }

    /**
     * Contrato HasTenants — tenancy nativa do Filament no painel do tenant.
     * Usuário de tenant: 0 ou 1 item (o switcher fica escondido,
     * ->tenantMenu(false)). Super admin "Plataforma": todos os tenants, para
     * o redirect de /painel funcionar (o switcher continua escondido).
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->hasCentralRole(CentralRole::Platform)) {
            return Tenant::query()->get();
        }

        return $this->tenant ? collect([$this->tenant]) : collect();
    }

    /**
     * Barreira explícita contra troca de slug na URL do painel: o Filament
     * chama isto ao resolver o `{tenant}` da rota, antes de servir qualquer
     * página tenant-aware. Um usuário do tenant A que force
     * `/painel/{slug-de-B}` recebe 404 aqui — sem depender do global scope.
     * O super admin "Plataforma" passa por qualquer tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->hasCentralRole(CentralRole::Platform)) {
            return true;
        }

        return $this->tenant_id !== null && $tenant->getKey() === $this->tenant_id;
    }

    /**
     * "Este usuário pode operar no tenant resolvido na requisição atual?"
     * Mesmo critério de canAccessTenant() (tenant do próprio usuário OU super
     * admin Plataforma), para os controllers das rotas públicas do painel
     * (comanda de cozinha, relatórios imprimíveis) que rodam fora do Filament
     * e não têm o canAccessTenant() nativo.
     */
    public function canOperateInCurrentTenant(): bool
    {
        $tenant = CurrentTenant::get();

        return $tenant !== null && $this->canAccessTenant($tenant);
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

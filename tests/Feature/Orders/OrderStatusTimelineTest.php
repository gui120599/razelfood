<?php

namespace Tests\Feature\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Livewire\OrderStatusTimeline;
use App\Models\Client;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RN-28: status "em tempo real" na página de acompanhamento. O componente
 * filho reidrata o Order a cada poll (Livewire\ModelSynth refaz a query do
 * zero), então mudar o status "por fora" entre dois polls deve refletir sem
 * precisar remontar o componente.
 */
class OrderStatusTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_reflects_status_changed_after_initial_mount(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        $component = Livewire::test(OrderStatusTimeline::class, ['order' => $order]);
        $component->assertSee(OrderStatus::Open->label());

        $order->update(['status' => OrderStatus::Preparing]);

        // Simula o próximo ciclo de wire:poll — ModelSynth reidrata o Order do banco.
        $component->call('$refresh');
        $component->assertSee(OrderStatus::Preparing->label());
    }

    public function test_timeline_shows_all_step_labels_and_pulses_the_current_step(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Preparing,
            'opened_at' => now(),
            'accepted_at' => now(),
            'preparing_at' => now(),
        ]);

        Livewire::test(OrderStatusTimeline::class, ['order' => $order])
            ->assertSee(OrderStatus::Open->label())
            ->assertSee(OrderStatus::Preparing->label())
            ->assertSee(OrderStatus::Ready->label())
            ->assertSee(OrderStatus::InTransit->label())
            ->assertSee(OrderStatus::Finished->label())
            ->assertSeeHtml('animate-ping');
    }

    public function test_cancelled_order_shows_reason_instead_of_the_step_timeline(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste 2',
            'slug' => 'tenant-teste-2',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990001',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Cancelled,
            'cancellation_reason' => CancellationReason::CustomerGaveUp,
            'opened_at' => now(),
        ]);

        Livewire::test(OrderStatusTimeline::class, ['order' => $order])
            ->assertSee('Cancelado')
            ->assertSee(CancellationReason::CustomerGaveUp->label());
    }
}

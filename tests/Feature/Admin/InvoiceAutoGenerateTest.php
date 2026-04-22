<?php

use App\Models\Employee;
use App\Models\Client;
use App\Models\Plan;
use App\Models\ClientPlan;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can generate automatic invoices successfully', function () {
    // Arrange: Create employee
    $employee = Employee::factory()->create();

    // Arrange: Create active plan for client
    $client = Client::factory()->create();
    $plan = Plan::factory()->create(['monthly_price' => 100]);
    $clientPlan = ClientPlan::factory()->create([
        'client_id' => $client->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'next_billing_date' => now()->subDay(),
        'billing_cycle' => 'monthly',
        'current_price' => 100
    ]);

    // Act: Call the endpoint
    $response = $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/invoices/generate-auto');

    // Assert: Check response
    $response->assertStatus(200)
             ->assertJsonStructure(['message', 'count', 'invoices']);
    
    expect($response->json('count'))->toBeGreaterThan(0);

    // Verify invoice was created in DB
    $this->assertDatabaseHas('invoices', [
        'client_id' => $client->id,
        'client_plan_id' => $clientPlan->id,
        'status' => Invoice::STATUS_PENDING,
    ]);
});

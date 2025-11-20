<?php

namespace Database\Seeders;

use App\Models\Support;
use Illuminate\Database\Seeder;

class SupportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Support::factory()->count(150)->create();
        $this->command->info('Tickets de soporte creados: 150');
    }
}
<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\PortfolioEntry;
use App\Models\PriceAlert;
use Tests\TestCase;

/**
 * Doesn't touch the database: it only checks what fill() lets through.
 */
class MassAssignmentTest extends TestCase
{
    public function test_ownership_and_server_managed_columns_cannot_be_mass_assigned(): void
    {
        $entry = (new PortfolioEntry)->fill(['user_id' => 99, 'quantity' => 2]);
        $alert = (new PriceAlert)->fill(['user_id' => 99, 'threshold' => 5, 'condition_met' => true, 'last_triggered_at' => now()]);
        $asset = (new Asset)->fill(['name' => 'X', 'last_known_price_usd' => 1, 'last_price_synced_at' => now()]);

        $this->assertNull($entry->user_id);
        $this->assertNull($alert->user_id);
        $this->assertNull($alert->condition_met);
        $this->assertNull($alert->last_triggered_at);
        $this->assertNull($asset->last_known_price_usd);
        $this->assertNull($asset->last_price_synced_at);
        // Legitimate fields still go through.
        $this->assertEquals(2, $entry->quantity);
        $this->assertEquals(5, $alert->threshold);
        $this->assertSame('X', $asset->name);
    }
}

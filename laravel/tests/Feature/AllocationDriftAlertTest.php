<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Http\Requests\StorePriceAlertRequest;
use App\Http\Requests\UpdatePriceAlertRequest;
use App\Models\PriceAlert;
use App\Models\User;
use App\Notifications\PriceAlertTriggered;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Validation and message of the allocation_drift alert. Users and alerts
 * are in-memory models (no DB here, see ExchangeRatesTest); the hourly
 * evaluation itself was checked live.
 */
class AllocationDriftAlertTest extends TestCase
{
    private function user(?array $targets): User
    {
        $user = new User(['email' => 'user@example.com']);
        $user->id = 1;
        $user->allocation_targets = $targets;

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeValidator(array $data, ?array $targets = ['crypto' => 60, 'etf' => 40]): \Illuminate\Contracts\Validation\Validator
    {
        $request = StorePriceAlertRequest::create('/api/price-alerts', 'POST', $data);
        $request->setUserResolver(fn () => $this->user($targets));

        return Validator::make($data, $request->rules())->after($request->after());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateValidator(array $data, AlertType $type): \Illuminate\Contracts\Validation\Validator
    {
        $request = UpdatePriceAlertRequest::create('/api/price-alerts/1', 'PATCH', $data);
        $route = (new Route('PATCH', 'api/price-alerts/{price_alert}', []))->bind($request);
        $route->setParameter('price_alert', new PriceAlert(['type' => $type]));
        $request->setRouteResolver(fn () => $route);

        return Validator::make($data, $request->rules());
    }

    public function test_a_drift_alert_needs_only_a_threshold_in_points(): void
    {
        $this->assertFalse($this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 5])->fails());
        $this->assertTrue($this->storeValidator(['type' => 'allocation_drift'])->fails());
        $this->assertTrue($this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 150])->fails());
        $this->assertTrue($this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 5, 'window' => '24h'])->fails());
        $this->assertTrue($this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 5, 'threshold' => 1000])->fails());
        $this->assertTrue($this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 5, 'asset_id' => 1])->fails());
    }

    public function test_a_drift_alert_is_refused_without_allocation_targets(): void
    {
        $validator = $this->storeValidator(['type' => 'allocation_drift', 'percent_threshold' => 5], targets: null);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('objectifs de répartition', $validator->errors()->first('type'));
    }

    public function test_percent_change_keeps_its_own_upper_bound(): void
    {
        $this->assertFalse($this->storeValidator(['type' => 'percent_change', 'asset_id' => null, 'percent_threshold' => 500, 'window' => '24h'])->errors()->has('percent_threshold'));
    }

    public function test_updating_a_drift_alert_only_accepts_points_and_activation(): void
    {
        $this->assertFalse($this->updateValidator(['percent_threshold' => 8, 'is_active' => false], AlertType::AllocationDrift)->fails());
        $this->assertTrue($this->updateValidator(['percent_threshold' => 101], AlertType::AllocationDrift)->fails());
        $this->assertTrue($this->updateValidator(['window' => '24h'], AlertType::AllocationDrift)->fails());
        $this->assertTrue($this->updateValidator(['threshold' => 10], AlertType::AllocationDrift)->fails());
        // Other alert types keep their previous rules.
        $this->assertFalse($this->updateValidator(['percent_threshold' => 500], AlertType::PercentChange)->fails());
    }

    public function test_the_message_names_the_type_furthest_from_target(): void
    {
        $alert = new PriceAlert(['type' => AlertType::AllocationDrift, 'percent_threshold' => 5]);
        $notification = new PriceAlertTriggered($alert, 27.38, context: ['type' => 'crypto', 'percent' => 77.38, 'target_percent' => 50.0]);

        $this->assertSame(
            '🔔 La répartition de votre portefeuille s\'écarte de vos objectifs de 27,38 points (seuil : 5 points). Le plus gros écart : la crypto, à 77,38 % pour un objectif de 50 %.',
            $notification->toDiscord($this->user(null)),
        );
    }
}

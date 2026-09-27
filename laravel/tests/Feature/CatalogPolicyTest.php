<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest): the "used by another user" lookup is
 * the only query the policy makes, so it's stubbed on the model instance.
 */
class CatalogPolicyTest extends TestCase
{
    private function user(int $id, bool $admin = false): User
    {
        $user = new User(['name' => "u{$id}", 'email' => "u{$id}@example.com"]);
        $user->id = $id;
        $user->is_admin = $admin;

        return $user;
    }

    private function asset(?int $createdBy, bool $usedByOthers): Asset
    {
        $asset = new class extends Asset
        {
            public bool $usedByOthers = false;

            public function isUsedByOtherUserThan(User $user): bool
            {
                return $this->usedByOthers;
            }
        };
        $asset->created_by = $createdBy;
        $asset->usedByOthers = $usedByOthers;

        return $asset;
    }

    public function test_the_creator_can_edit_while_nobody_else_uses_the_entry(): void
    {
        $this->assertTrue(Gate::forUser($this->user(1))->allows('update', $this->asset(1, false)));
        $this->assertTrue(Gate::forUser($this->user(1))->allows('delete', $this->asset(1, false)));
    }

    public function test_the_creator_is_locked_out_once_another_user_relies_on_it(): void
    {
        $this->assertTrue(Gate::forUser($this->user(1))->denies('update', $this->asset(1, true)));
        $this->assertTrue(Gate::forUser($this->user(1))->denies('delete', $this->asset(1, true)));
    }

    public function test_a_regular_user_cannot_edit_someone_elses_or_an_unattributed_entry(): void
    {
        $this->assertTrue(Gate::forUser($this->user(2))->denies('update', $this->asset(1, false)));
        $this->assertTrue(Gate::forUser($this->user(2))->denies('update', $this->asset(null, false)));
    }

    public function test_an_admin_can_edit_anything(): void
    {
        $this->assertTrue(Gate::forUser($this->user(9, admin: true))->allows('update', $this->asset(1, true)));
        $this->assertTrue(Gate::forUser($this->user(9, admin: true))->allows('update', $this->asset(null, true)));
    }

    public function test_the_policy_also_covers_platforms(): void
    {
        $platform = new Platform(['name' => 'X']);
        $platform->created_by = 1;

        $this->assertTrue(Gate::forUser($this->user(2))->denies('update', $platform));
    }

    public function test_admin_rights_and_ownership_cannot_be_mass_assigned(): void
    {
        $user = (new User)->fill(['name' => 'x', 'is_admin' => true]);
        $asset = (new Asset)->fill(['name' => 'x', 'created_by' => 5]);
        $platform = (new Platform)->fill(['name' => 'x', 'created_by' => 5]);

        $this->assertNull($user->is_admin);
        $this->assertNull($asset->created_by);
        $this->assertNull($platform->created_by);
        $this->assertArrayNotHasKey('created_by', (new Asset(['name' => 'x']))->forceFill(['created_by' => 5])->toArray());
    }
}

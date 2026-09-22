<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Regression for a bug the frontend session hit live: name/email were
 * unconditionally 'required', so PATCHing just {"currency":"EUR"} (or any
 * other single field) failed with "name field is required" /
 * "email field is required". No DB here (see ExchangeRatesTest) — the user
 * is an in-memory model, never saved, just enough for rules() to read
 * ->id/->email off it. The email-change + current_password interaction is
 * covered live instead (Rule::unique would need a real DB connection).
 */
class UpdateProfileRequestTest extends TestCase
{
    private function rulesFor(array $data, string $currentEmail = 'user@example.com'): \Illuminate\Contracts\Validation\Validator
    {
        $user = new User(['email' => $currentEmail]);
        $user->id = 1;
        $user->exists = true;

        $request = UpdateProfileRequest::create('/user', 'PATCH', $data);
        $request->setUserResolver(fn () => $user);

        return Validator::make($data, $request->rules());
    }

    public function test_currency_alone_does_not_require_name_or_email(): void
    {
        $validator = $this->rulesFor(['currency' => 'EUR']);

        $this->assertFalse($validator->fails());
    }

    public function test_name_alone_does_not_require_email_or_currency(): void
    {
        $validator = $this->rulesFor(['name' => 'New Name']);

        $this->assertFalse($validator->fails());
    }

    public function test_an_empty_payload_is_valid(): void
    {
        $validator = $this->rulesFor([]);

        $this->assertFalse($validator->fails());
    }

    public function test_current_password_is_not_required_when_email_is_absent(): void
    {
        $validator = $this->rulesFor(['currency' => 'CHF']);

        $this->assertFalse($validator->errors()->has('current_password'));
    }
}

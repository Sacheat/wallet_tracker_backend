<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The current password is only asked when the email actually changes, so
     * editing the name stays frictionless. Without it, whoever holds a
     * session (a stolen cookie, an unattended browser) could swap in their
     * own address, verify it, then use "forgot password" to take the account.
     * Same exact-string comparison as ProfileController's isDirty('email').
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'current_password' => Rule::when(
                $this->input('email') !== $this->user()->email,
                ['required', 'current_password'],
            ),
            'currency' => ['sometimes', Rule::enum(Currency::class)],
        ];
    }
}

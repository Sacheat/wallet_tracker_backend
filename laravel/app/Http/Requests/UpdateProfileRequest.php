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
     * Every field here is independently optional (sometimes): a caller can
     * PATCH just currency, just name, or any subset, without resending the
     * others. The current password is only asked when the email is actually
     * present in the request AND differs from the current one, so editing
     * name/currency alone stays frictionless. Without the has() check, an
     * omitted email would compare as null against the real one and wrongly
     * demand current_password on every partial update. Without the
     * password check at all, whoever holds a session (a stolen cookie, an
     * unattended browser) could swap in their own address, verify it, then
     * use "forgot password" to take the account. Same exact-string
     * comparison as ProfileController's isDirty('email').
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'current_password' => Rule::when(
                $this->has('email') && $this->input('email') !== $this->user()->email,
                ['required', 'current_password'],
            ),
            'currency' => ['sometimes', Rule::enum(Currency::class)],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * A new address hasn't proven anything: without this, a verified account
     * could switch to an email it doesn't own and stay "verified". So an
     * email change un-verifies the account (the 'verified' middleware then
     * blocks /api/* until the new address is confirmed) and sends a fresh
     * verification mail. Links already sent to the old address stop working
     * on their own — they're signed with a hash of the email.
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->fill($request->safe()->except('current_password'));

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->noContent();
    }

    /**
     * Portfolio entries and price alerts cascade-delete with the user
     * (foreign keys), audit logs keep their history with user_id set to
     * null — both handled at the database level, nothing to do here beyond
     * the file the database doesn't know about (the avatar) and the session.
     */
    public function destroy(DeleteAccountRequest $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->delete();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

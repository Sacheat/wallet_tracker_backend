<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The only way to grant or revoke admin: deliberately not exposed through
 * the API (is_admin isn't fillable), so no request — not even an admin's —
 * can escalate a privilege. Needs shell access to the server.
 */
#[Signature('user:admin {email : Email of the account} {--revoke : Remove admin rights instead of granting them}')]
#[Description('Grant (or revoke with --revoke) admin rights over the shared catalog and the global audit log')]
class ManageAdmin extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Aucun compte avec cet email.');

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('revoke');
        $user->save();

        $this->info($user->is_admin ? "{$user->email} est maintenant administrateur." : "{$user->email} n'est plus administrateur.");

        return self::SUCCESS;
    }
}

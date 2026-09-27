<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Shared by Asset and Platform (registered for both in AppServiceProvider).
 * Reading and creating stay open to every verified user — the frontend's
 * search → add flow depends on it. Changing an existing entry (fields,
 * logo, deletion) is what affects other users, so it's limited to:
 * - an admin, for anything;
 * - the entry's creator, only while no other user holds or watches it.
 * Once someone else relies on it, a regular user can no longer repoint its
 * price source or rename it under them.
 */
class CatalogPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function update(User $user, Asset|Platform $entry): Response
    {
        if ($entry->created_by !== $user->id) {
            return Response::deny("Seul un administrateur peut modifier un élément du catalogue créé par quelqu'un d'autre.");
        }

        if ($entry->isUsedByOtherUserThan($user)) {
            return Response::deny("Cet élément est utilisé par d'autres utilisateurs : seul un administrateur peut encore le modifier.");
        }

        return Response::allow();
    }

    public function delete(User $user, Asset|Platform $entry): Response
    {
        return $this->update($user, $entry);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            // 'image' checks actual file content, not just the extension.
            // svg is deliberately excluded from 'mimes' even though 'image'
            // allows it: an SVG opened directly can execute embedded script.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        // ->store() generates a random filename — the uploaded name is never
        // trusted (no path traversal, no extension-spoofing surface).
        $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        $user->save();

        return $user;
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        return response()->noContent();
    }
}

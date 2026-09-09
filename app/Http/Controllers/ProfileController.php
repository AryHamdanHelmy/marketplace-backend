<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    // GET /api/profile
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'joined_at' => $user->created_at,
            ],
        ]);
    }

    // PUT /api/profile
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                // Ignore this user's own row, and treat deleted accounts as
                // not occupying the address — same rule as registration.
                Rule::unique('users', 'email')
                    ->ignore($user->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $emailChanged = $validated['email'] !== $user->email;

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => $emailChanged
                ? 'Profile updated. Use your new email next time you sign in.'
                : 'Profile updated',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    // PUT /api/profile/password
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|min:8|confirmed',
        ]);

        // Proving they know the current password is what stops someone who
        // walked up to an unlocked laptop from taking the account over.
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'That current password is wrong.',
                'errors' => [
                    'current_password' => ['That current password is wrong.'],
                ],
            ], 422);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose a password different from the current one.',
                'errors' => [
                    'password' => ['Choose a password different from the current one.'],
                ],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Every other session ends. The one making this request keeps its
        // token, so the person isn't logged out of the device they're using.
        $currentTokenId = $request->user()->currentAccessToken()?->id;

        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed. Other devices have been signed out.',
        ]);
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    // POST /api/auth/forgot-password
    public function sendResetLink(Request $request)
    {
        $validated = $request->validate([
            "email" => "required|email",
        ]);

        $status = Password::sendResetLink($validated);

        // Deliberately uniform: whether or not the address is on file, the
        // caller sees the same thing. Only the throttle case differs, because
        // silence there would look like the button is broken.
        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                "success" => false,
                "message" => "You asked recently. Wait a minute before trying again.",
            ], 429);
        }

        return response()->json([
            "success" => true,
            "message" => "If that email is registered, a reset link is on its way.",
        ]);
    }

    // POST /api/auth/reset-password
    public function reset(Request $request)
    {
        $validated = $request->validate([
            "token" => "required|string",
            "email" => "required|email",
            "password" => "required|min:8|confirmed",
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->password = Hash::make($password);
            $user->save();

            // A password change ends every existing session. Otherwise someone
            // who took the account keeps their token after the owner recovers.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                "success" => true,
                "message" => "Password updated. You can sign in now.",
            ]);
        }

        $message = match ($status) {
            Password::INVALID_TOKEN => "This reset link has expired or was already used.",
            Password::INVALID_USER  => "This reset link has expired or was already used.",
            Password::RESET_THROTTLED => "Too many attempts. Wait a minute and try again.",
            default => "We couldn't reset your password. Request a new link.",
        };

        return response()->json([
            "success" => false,
            "message" => $message,
        ], 422);
    }
}
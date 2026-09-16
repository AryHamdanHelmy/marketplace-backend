<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function index(Request $request)
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        $perPage = $request->query("per_page", 10);
        $users = User::select("id", "name", "email", "role")
            ->paginate($perPage);

        return response()->json([
            "success" => true,
            "message" => "Users retrieved",
            "data" => $users->items(),
            "meta" => [
                "current_page" => $users->currentPage(),
                "last_page" => $users->lastPage(),
                "per_page" => $users->perPage(),
                "total" => $users->total(),
            ],
        ], 200);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            // Only active accounts block a signup. A deleted account still
            // occupies the address at the database level until it is released
            // below, so the rule has to ignore trashed rows.
            "email" => [
                "required",
                "email",
                Rule::unique("users", "email")->whereNull("deleted_at"),
            ],
            "password" => "required|min:8|confirmed",
            "role" => "required|in:buyer,seller",
        ]);

        $user = DB::transaction(function () use ($validated) {
            // A deleted account may still be holding this address — from before
            // release-on-delete existed. Free it, then start a genuinely new
            // account. The old row keeps its own history and is never revived.
            $trashed = User::onlyTrashed()
                ->where("email", $validated["email"])
                ->lockForUpdate()
                ->first();

            if ($trashed) {
                $trashed->email = $this->releasedEmail($trashed->id, $trashed->email);
                $trashed->saveQuietly();
            }

            return User::create([
                "name" => $validated["name"],
                "email" => $validated["email"],
                "password" => Hash::make($validated["password"]),
                "role" => $validated["role"],
            ]);
        });

        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json([
            "user" => $user,
            "token" => $token,
        ], 201);
    }

    // Percobaan gagal dihitung per akun, bukan hanya per IP.
    //
    // Throttle di route terikat IP. Penyerang dengan 100 IP karena itu
    // mendapat 500 percobaan per menit pada satu akun yang sama — throttle
    // route-nya tidak pernah tersentuh. Dua kunci di bawah menutup itu: satu
    // mengikat kombinasi email+IP, satu lagi mengikat email saja sehingga
    // serangan yang tersebar di banyak IP tetap terbentur batas.
    private const LOGIN_MAX_PER_IP = 5;
    private const LOGIN_MAX_PER_ACCOUNT = 20;

    public function login(Request $request)
    {
        $validated = $request->validate([
            "email" => "required|email",
            "password" => "required",
        ]);

        [$ipKey, $accountKey] = $this->loginKeys($request, $validated["email"]);

        foreach ([[$ipKey, self::LOGIN_MAX_PER_IP], [$accountKey, self::LOGIN_MAX_PER_ACCOUNT]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json([
                    "message" => "Too many sign-in attempts. Try again in "
                        . RateLimiter::availableIn($key) . " seconds.",
                ], 429);
            }
        }

        $user = User::where("email", $validated["email"])->first();

        if (!$user || !Hash::check($validated["password"], $user->password)) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($accountKey, 900);

            return response()->json([
                "message" => "That email and password don't match.",
            ], 401);
        }

        // Login yang berhasil membersihkan hitungannya. Kalau tidak, orang
        // yang salah ketik beberapa kali lalu berhasil masuk tetap terkunci
        // pada percobaan berikutnya.
        RateLimiter::clear($ipKey);
        RateLimiter::clear($accountKey);

        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json([
            "user" => $user,
            "token" => $token,
        ]);
    }

    /**
     * @return array{0: string, 1: string} kunci email+IP dan kunci email saja
     */
    private function loginKeys(Request $request, string $email): array
    {
        $email = Str::transliterate(Str::lower(trim($email)));

        return [
            "login:{$email}|" . $request->ip(),
            "login:{$email}",
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(["message" => "Signed out"]);
    }

    public function destroy($id)
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        if ((int) $id === auth()->id()) {
            return response()->json([
                "success" => false,
                "message" => "You can't delete your own account.",
            ], 422);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "User not found",
            ], 404);
        }

        DB::transaction(function () use ($user) {
            // Kill every active session immediately.
            $user->tokens()->delete();

            // Release the address so the person can sign up again, while the
            // row itself stays behind for the audit trail.
            $user->email = $this->releasedEmail($user->id, $user->email);
            $user->saveQuietly();

            $user->delete();
        });

        return response()->json([
            "success" => true,
            "message" => "User deleted",
        ]);
    }

    public function show($id)
    {
        if ($deny = $this->denyIfNotAdmin()) return $deny;

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "User not found",
            ], 404);
        }

        return response()->json([
            "success" => true,
            "message" => "User retrieved",
            "data" => [
                "id" => $user->id,
                "name" => $user->name,
                "email" => $user->email,
                "role" => $user->role,
            ],
        ]);
    }

    // PUT /api/users/{id}/role
    // Only an admin may change roles, including promoting another admin.
    public function updateRole(Request $request, $id)
    {
        if ($request->user()->role !== "admin") {
            return response()->json([
                "success" => false,
                "message" => "You don't have permission to change roles.",
            ], 403);
        }

        $validated = $request->validate([
            "role" => "required|in:buyer,seller,admin",
        ]);

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "User not found",
            ], 404);
        }

        $user->role = $validated["role"];
        $user->save();

        return response()->json([
            "success" => true,
            "message" => "Role updated",
            "data" => [
                "id" => $user->id,
                "name" => $user->name,
                "email" => $user->email,
                "role" => $user->role,
            ],
        ]);
    }

    public function checkEmail(Request $request)
    {
        $validated = $request->validate([
            "email" => "required|email",
        ]);

        $exists = User::where("email", $validated["email"])->exists();

        return response()->json([
            "exists" => $exists,
        ]);
    }

    // Builds the parked form of a deleted account's address, e.g.
    // "deleted+12+ary@gmail.com". The id keeps it unique and the original
    // address stays readable for support and audit purposes.
    private function releasedEmail(int $id, string $email): string
    {
        $prefix = "deleted+{$id}+";

        if (str_starts_with($email, "deleted+")) {
            return $email;
        }

        return substr($prefix . $email, 0, 255);
    }

    private function denyIfNotAdmin()
    {
        if (auth()->user()?->role !== "admin") {
            return response()->json([
                "success" => false,
                "message" => "You don't have access to this.",
            ], 403);
        }

        return null;
    }
}
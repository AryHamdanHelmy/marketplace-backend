<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|in:buyer,seller',
        ]);
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),

            'role' => $validated['role'] ?? 'buyer',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
    public function login(Request $request){
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);
        $user = User::where('email',$validated['email'])->first();
        if (!$user || !Hash::check($validated['password'], $user->password)){
            return response()->json(['massage' => 'invlid credentials'], 401);
        }
        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }
    public function logout(Request $request){
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'Logged out']);
    }
    public function show($id)
{
    $user = User::find($id);

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Data tidak ditemukan'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Detail user berhasil diambil',
        'data' => [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ],
    ]);
}
    public function me(Request $request){
            return response()->json($request->user());
    }
    }


<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    private function useSkillhubSql(): void
    {
        Config::set('database.connections.mysql.database', 'skillhubsql');
        Config::set('database.connections.mysql.url', null);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function issueApiToken(User $user, string $label = 'dashboard-access'): string
    {
        return $user->createToken($label)->plainTextToken;
    }

    public function register(Request $request): JsonResponse
    {
        $this->useSkillhubSql();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['nullable', 'string', 'in:formateur'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'role' => $request->input('role', 'formateur'),
        ]);

        $token = $this->issueApiToken($user, 'register-access');

        return response()->json([
            'message' => 'Inscription reussie',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->useSkillhubSql();

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (!$user || !Hash::check((string) $request->input('password'), (string) $user->password)) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        $token = $this->issueApiToken($user, 'login-access');

        return response()->json([
            'message' => 'Connexion reussie',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user && method_exists($user, 'currentAccessToken')) {
            $currentToken = $user->currentAccessToken();
            if ($currentToken) {
                $currentToken->delete();
            }
        }

        return response()->json(['message' => 'Deconnexion reussie']);
    }

    public function token(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        $token = $this->issueApiToken($user, 'dashboard-access');

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }
}

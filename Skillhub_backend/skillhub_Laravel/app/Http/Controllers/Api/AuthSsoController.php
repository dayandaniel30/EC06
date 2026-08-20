<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthSsoController extends Controller
{
    public function __construct(private readonly SsoClient $ssoClient)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $this->ssoClient->login(
            (string) $request->input('username'),
            (string) $request->input('password'),
        );

        if ($payload === null || empty($payload['accessToken'])) {
            return response()->json([
                'message' => 'Identifiants SSO invalides',
            ], 401);
        }

        $email = (string) $request->input('username');
        $role = strtolower((string) ($payload['role'] ?? 'apprenant'));
        $this->syncLocalUser($email, $role, null);

        return response()->json([
            'message' => 'Authentification SSO reussie',
            'access_token' => $payload['accessToken'],
            'token_type' => $payload['tokenType'] ?? 'Bearer',
            'expires_in' => $payload['expiresIn'] ?? null,
            'role' => $role,
            'user' => [
                'name' => User::where('email', $email)->value('name'),
                'email' => $email,
                'role' => $role,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['nullable', 'string', 'in:apprenant,formateur'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = (string) $request->input('username');
        $name = (string) $request->input('name');
        $role = strtolower((string) ($request->input('role') ?? 'apprenant'));

        $result = $this->ssoClient->register($email, (string) $request->input('password'), strtoupper($role));

        if ($result['status'] === 409) {
            return response()->json(['message' => 'Cet utilisateur existe deja'], 409);
        }
        if ($result['status'] >= 400 || empty($result['body']['accessToken'])) {
            return response()->json([
                'message' => $result['body']['message'] ?? 'Inscription SSO impossible',
            ], $result['status'] ?: 500);
        }

        $this->syncLocalUser($email, $role, $name);

        return response()->json([
            'message' => 'Inscription SSO reussie',
            'access_token' => $result['body']['accessToken'],
            'token_type' => $result['body']['tokenType'] ?? 'Bearer',
            'expires_in' => $result['body']['expiresIn'] ?? null,
            'role' => $role,
            'user' => [
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Route protegee SSO - acces autorise',
            'subject' => $request->attributes->get('sso_subject'),
            'role' => $request->attributes->get('sso_role'),
            'claims' => $request->attributes->get('sso_claims'),
        ]);
    }

    private function syncLocalUser(string $email, string $role, ?string $name): User
    {
        $user = User::firstOrNew(['email' => $email]);
        if (!$user->exists) {
            $user->password = Hash::make(Str::random(40));
        }
        if ($name !== null && $name !== '') {
            $user->name = $name;
        } elseif (!$user->name) {
            $user->name = strstr($email, '@', true) ?: $email;
        }
        $user->role = $role;
        $user->save();

        return $user;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

        return response()->json([
            'message' => 'Authentification SSO reussie',
            'access_token' => $payload['accessToken'],
            'token_type' => $payload['tokenType'] ?? 'Bearer',
            'expires_in' => $payload['expiresIn'] ?? null,
            'role' => $payload['role'] ?? null,
        ]);
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
}

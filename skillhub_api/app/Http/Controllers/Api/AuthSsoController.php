<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthSsoController extends Controller
{
    public function __construct(private readonly SsoClient $ssoClient) {}

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

    /**
     * Retourne l'identite courante resolue par le SSO.
     *
     * `$request->user()` repond ici grace a `Auth::setUser()` appele par
     * {@see \App\Http\Middleware\SpringSsoAuthenticate} : l'utilisateur local
     * est synchronise le temps de la requete, sans session persistee.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Route protegee SSO - acces autorise',
            'subject' => $request->attributes->get('sso_subject'),
            'role' => $request->attributes->get('sso_role'),
            'claims' => $request->attributes->get('sso_claims'),
            'user' => $request->user(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $this->ssoClient->forgotPassword((string) $request->input('username'));

        if ($payload === null) {
            return response()->json([
                'message' => 'Service SSO indisponible',
            ], 503);
        }

        return response()->json($payload);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $this->ssoClient->resetPassword(
            (string) $request->input('token'),
            (string) $request->input('newPassword'),
        );

        if ($payload === null) {
            return response()->json([
                'message' => 'Token invalide, expire ou service SSO indisponible',
            ], 400);
        }

        return response()->json($payload);
    }
}

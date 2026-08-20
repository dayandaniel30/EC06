<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SsoClient
{
    public function login(string $username, string $password): ?array
    {
        $response = Http::timeout((int) config('services.sso.timeout', 5))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('services.sso.base_url'), '/') . '/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function register(string $username, string $password, ?string $role = null): array
    {
        $response = Http::timeout((int) config('services.sso.timeout', 5))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('services.sso.base_url'), '/') . '/auth/register', [
                'username' => $username,
                'password' => $password,
                'role' => $role,
            ]);

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    public function validate(string $bearerToken): ?array
    {
        try {
            $response = Http::timeout((int) config('services.sso.timeout', 5))
                ->acceptJson()
                ->withHeaders(['Authorization' => 'Bearer ' . $bearerToken])
                ->get(rtrim((string) config('services.sso.base_url'), '/') . '/auth/validate');
        } catch (ConnectionException $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();

        if (!is_array($data) || ($data['valid'] ?? false) !== true) {
            return null;
        }

        return $data;
    }
}

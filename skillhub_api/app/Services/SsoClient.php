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

    public function forgotPassword(string $username): ?array
    {
        try {
            $response = Http::timeout((int) config('services.sso.timeout', 5))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('services.sso.base_url'), '/') . '/auth/forgot-password', [
                    'username' => $username,
                ]);
        } catch (ConnectionException $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function resetPassword(string $token, string $newPassword): ?array
    {
        try {
            $response = Http::timeout((int) config('services.sso.timeout', 5))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('services.sso.base_url'), '/') . '/auth/reset-password', [
                    'token' => $token,
                    'newPassword' => $newPassword,
                ]);
        } catch (ConnectionException $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }
}

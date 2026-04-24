<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TrainerLearnerController extends Controller
{
    private function canManage(Request $request): bool
    {
        $user = $request->user();
        return $user && in_array((string) $user->role, ['formateur', 'admin'], true);
    }

    private function nameColumn(): string
    {
        return Schema::hasColumn('users', 'name') ? 'name' : 'prenom';
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        $nameColumn = $this->nameColumn();
        $rows = DB::table('users')
            ->select(['id', $nameColumn . ' as name', 'email', 'role'])
            ->where('role', 'apprenant')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Apprenants recuperes',
            'data' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nameColumn = $this->nameColumn();
        $user = new User();
        $user->{$nameColumn} = $request->string('name')->toString();
        $user->email = $request->string('email')->toString();
        $user->password = Hash::make($request->string('password')->toString());
        $user->role = 'apprenant';
        $user->save();

        return response()->json([
            'message' => 'Apprenant cree',
            'data' => [
                'id' => (int) $user->id,
                'name' => (string) ($user->{$nameColumn} ?? ''),
                'email' => (string) $user->email,
                'role' => (string) ($user->role ?? 'apprenant'),
            ],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (!$this->canManage($request)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        if ((string) ($user->role ?? '') !== 'apprenant') {
            return response()->json(['message' => 'Apprenant introuvable'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . (int) $user->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nameColumn = $this->nameColumn();

        if ($request->has('name')) {
            $user->{$nameColumn} = $request->string('name')->toString();
        }

        if ($request->has('email')) {
            $user->email = $request->string('email')->toString();
        }

        if ($request->filled('password')) {
            $user->password = Hash::make((string) $request->input('password'));
        }

        $user->save();

        return response()->json([
            'message' => 'Apprenant mis a jour',
            'data' => [
                'id' => (int) $user->id,
                'name' => (string) ($user->{$nameColumn} ?? ''),
                'email' => (string) $user->email,
                'role' => (string) ($user->role ?? 'apprenant'),
            ],
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (!$this->canManage($request)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        if ((string) ($user->role ?? '') !== 'apprenant') {
            return response()->json(['message' => 'Apprenant introuvable'], 404);
        }

        DB::transaction(function () use ($user): void {
            DB::table('enrollments')->where('user_id', (int) $user->id)->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'Apprenant supprime',
        ]);
    }
}

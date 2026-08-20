<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class FormationController extends Controller
{
    private function hasFormationsColumn(string $column): bool
    {
        static $columns = [];

        if (! array_key_exists($column, $columns)) {
            $columns[$column] = Schema::hasColumn('formations', $column);
        }

        return (bool) $columns[$column];
    }

    private function userOwnsFormation(object $user, Formation $formation): bool
    {
        return (int) $formation->user_id === (int) $user->id;
    }

    private function serializeFormation(Formation $formation): array
    {
        return [
            'id' => (int) $formation->id,
            'title' => (string) ($formation->title ?? ''),
            'description' => (string) ($formation->short_description ?? $formation->full_description ?? $formation->description ?? ''),
            'price' => $this->hasFormationsColumn('price') && $formation->price !== null ? (float) $formation->price : 0.0,
            'duration' => (string) ($formation->duration ?? ''),
            'level' => (string) ($formation->level ?? ''),
            'user_id' => $formation->user_id !== null ? (int) $formation->user_id : null,
            'instructor_id' => $formation->instructor_id !== null ? (int) $formation->instructor_id : null,
            'status' => $this->hasFormationsColumn('status') ? (string) ($formation->status ?? '') : '',
            'created_at' => (string) ($formation->created_at ?? ''),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['formateur', 'admin'], true)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        $rows = Formation::query()
            ->when($user->role !== 'admin', function ($query) use ($user): void {
                $query->where('user_id', (int) $user->id);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Formations recuperees',
            'data' => $rows->map(function (Formation $formation): array {
                return $this->serializeFormation($formation);
            })->values(),
        ]);
    }

    public function myFormations(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || (string) $user->role !== 'formateur') {
            return response()->json(['message' => 'Action reservee aux formateurs'], 403);
        }

        $query = Formation::query()->where('user_id', (int) $user->id);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('level', 'like', '%'.$search.'%');

                if ($this->hasFormationsColumn('description')) {
                    $builder->orWhere('description', 'like', '%'.$search.'%');
                }
                if ($this->hasFormationsColumn('short_description')) {
                    $builder->orWhere('short_description', 'like', '%'.$search.'%');
                }
                if ($this->hasFormationsColumn('full_description')) {
                    $builder->orWhere('full_description', 'like', '%'.$search.'%');
                }
            });
        }

        if ($this->hasFormationsColumn('price') && $request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }
        if ($this->hasFormationsColumn('price') && $request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        $formations = $query->orderByDesc('id')->get();

        $formations = $formations
            ->map(function (Formation $formation): array {
                return $this->serializeFormation($formation);
            })
            ->values();

        return response()->json([
            'message' => 'Mes formations recuperees',
            'data' => $formations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['formateur', 'admin'], true)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:1'],
            'level' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = [
            'title' => $request->string('title')->toString(),
            'duration' => (string) $request->input('duration', 3),
            'level' => $request->input('level', 'Tous niveaux'),
            'user_id' => (int) $user->id,
        ];
        if ($this->hasFormationsColumn('price')) {
            $payload['price'] = $request->input('price', 0);
        }
        if ($this->hasFormationsColumn('status')) {
            $payload['status'] = 'validated';
        }

        $description = (string) $request->input('description', 'Atelier pratique anime par un expert SkillHub.');
        if ($this->hasFormationsColumn('short_description')) {
            $payload['short_description'] = $description;
        }
        if ($this->hasFormationsColumn('full_description')) {
            $payload['full_description'] = $description;
        }
        if ($this->hasFormationsColumn('description')) {
            $payload['description'] = $description;
        }

        $formation = Formation::create($payload);

        return response()->json([
            'message' => 'Formation creee',
            'data' => $this->serializeFormation($formation),
        ], 201);
    }

    public function update(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['formateur', 'admin'], true)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        if ((string) $user->role !== 'admin' && ! $this->userOwnsFormation($user, $formation)) {
            return response()->json(['message' => 'Vous ne pouvez modifier que vos formations'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'level' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updates = [];
        if ($request->has('title')) {
            $updates['title'] = $request->string('title')->toString();
        }
        if ($request->has('description')) {
            $description = (string) $request->input('description', '');
            if ($this->hasFormationsColumn('short_description')) {
                $updates['short_description'] = $description;
            }
            if ($this->hasFormationsColumn('full_description')) {
                $updates['full_description'] = $description;
            }
            if ($this->hasFormationsColumn('description')) {
                $updates['description'] = $description;
            }
        }
        if ($request->has('duration')) {
            $updates['duration'] = $request->input('duration') !== null
                ? (string) $request->input('duration')
                : null;
        }
        if ($this->hasFormationsColumn('price') && $request->has('price')) {
            $updates['price'] = $request->input('price');
        }
        if ($request->has('level')) {
            $updates['level'] = $request->input('level');
        }

        $formation->fill($updates);
        $formation->save();

        return response()->json([
            'message' => 'Formation mise a jour',
            'data' => $this->serializeFormation($formation->fresh()),
        ]);
    }

    public function destroy(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['formateur', 'admin'], true)) {
            return response()->json(['message' => 'Action reservee aux formateurs/admins'], 403);
        }

        if ((string) $user->role !== 'admin' && ! $this->userOwnsFormation($user, $formation)) {
            return response()->json(['message' => 'Vous ne pouvez supprimer que vos formations'], 403);
        }

        $formation->delete();

        return response()->json([
            'message' => 'Formation supprimee',
        ]);
    }
}

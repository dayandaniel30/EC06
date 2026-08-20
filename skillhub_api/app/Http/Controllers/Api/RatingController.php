<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RatingController extends Controller
{
    public function index(Request $request, Formation $formation): JsonResponse
    {
        $ratings = Rating::query()
            ->where('formation_id', $formation->id)
            ->with('user:id,name,role')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Rating $rating): array => $this->serializeRating($rating))
            ->values();

        return response()->json([
            'message' => 'Avis recuperes',
            'data' => $ratings,
        ]);
    }

    public function summary(Request $request, Formation $formation): JsonResponse
    {
        // `toBase()` renvoie un stdClass plutot qu'un modele Rating : un agregat
        // n'est pas une ligne de la table et n'a pas a etre hydrate comme telle.
        $aggregate = Rating::query()
            ->where('formation_id', $formation->id)
            ->selectRaw('AVG(score) as avg_score, COUNT(*) as total')
            ->toBase()
            ->first();

        $count = (int) ($aggregate->total ?? 0);
        $avg = $count > 0 ? round((float) $aggregate->avg_score, 2) : 0.0;

        return response()->json([
            'message' => 'Synthese des avis',
            'data' => [
                'formation_id' => (int) $formation->id,
                'average' => $avg,
                'count' => $count,
            ],
        ]);
    }

    public function store(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (! $user || (string) $user->role !== 'apprenant') {
            return response()->json([
                'message' => 'Seuls les apprenants peuvent noter une formation',
            ], 403);
        }

        if (! $this->learnerIsEnrolled((int) $user->id, (int) $formation->id)) {
            return response()->json([
                'message' => 'Vous devez etre inscrit a la formation pour la noter',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $existing = Rating::query()
            ->where('user_id', (int) $user->id)
            ->where('formation_id', (int) $formation->id)
            ->first();

        if ($existing) {
            $existing->fill([
                'score' => (int) $request->input('score'),
                'comment' => $request->input('comment'),
            ])->save();

            return response()->json([
                'message' => 'Avis mis a jour',
                'data' => $this->serializeRating($existing->fresh('user')),
            ], 200);
        }

        $rating = Rating::create([
            'user_id' => (int) $user->id,
            'formation_id' => (int) $formation->id,
            'score' => (int) $request->input('score'),
            'comment' => $request->input('comment'),
        ]);

        return response()->json([
            'message' => 'Avis enregistre',
            'data' => $this->serializeRating($rating->fresh('user')),
        ], 201);
    }

    public function destroy(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifie'], 401);
        }

        $rating = Rating::query()
            ->where('user_id', (int) $user->id)
            ->where('formation_id', (int) $formation->id)
            ->first();

        if (! $rating) {
            return response()->json([
                'message' => 'Aucun avis a supprimer',
            ], 404);
        }

        $rating->delete();

        return response()->json([
            'message' => 'Avis supprime',
        ]);
    }

    private function learnerIsEnrolled(int $userId, int $formationId): bool
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('formation_id', $formationId)
            ->exists();
    }

    private function serializeRating(Rating $rating): array
    {
        return [
            'id' => (int) $rating->id,
            'formation_id' => (int) $rating->formation_id,
            'user_id' => (int) $rating->user_id,
            'user_name' => $rating->user ? (string) $rating->user->name : null,
            'score' => (int) $rating->score,
            'comment' => $rating->comment !== null ? (string) $rating->comment : null,
            'created_at' => (string) $rating->created_at,
            'updated_at' => (string) $rating->updated_at,
        ];
    }
}

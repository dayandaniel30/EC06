<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Formation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LearnerEnrollmentController extends Controller
{
    public function availableFormations(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        // Inscriptions existantes de l'apprenant : le catalogue indique ce a quoi
        // il est deja inscrit, plutot que de laisser l'API repondre 409 apres coup.
        $enrolledFormationIds = Enrollment::query()
            ->where('user_id', (int) $user->id)
            ->pluck('formation_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $rows = Formation::query()
            ->orderByDesc('id')
            ->get()
            ->map(function (Formation $formation) use ($enrolledFormationIds): array {
                return [
                    'id' => (int) $formation->id,
                    'title' => (string) ($formation->title ?? 'Formation'),
                    // `description` est la colonne reellement presente en base :
                    // short_description / full_description n'ont jamais existe et
                    // renvoyaient donc systematiquement une chaine vide.
                    'description' => (string) ($formation->description ?? ''),
                    'level' => (string) ($formation->level ?? 'Niveau non precise'),
                    'duration' => (string) ($formation->duration ?? 'Duree non precise'),
                    'is_enrolled' => in_array((int) $formation->id, $enrolledFormationIds, true),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Formations disponibles recuperees',
            'data' => $rows,
            'meta' => self::learnerMeta(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        $rows = Enrollment::query()
            ->with(['formation:id,title,level,duration'])
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (Enrollment $enrollment) use ($user): array {
                // Toutes ces inscriptions appartiennent a l'utilisateur courant :
                // rattacher la relation evite une requete par ligne dans
                // lastActivityAt(), qui lit users.last_activity_at.
                $enrollment->setRelation('user', $user);

                return [
                    'id' => (int) $enrollment->id,
                    'user_id' => (int) $enrollment->user_id,
                    'formation_id' => (int) $enrollment->formation_id,
                    'progress' => (int) ($enrollment->progress ?? 0),
                    'enrolled_at' => (string) ($enrollment->enrolled_at ?? ''),
                    'title' => (string) ($enrollment->formation->title ?? 'Formation'),
                    'level' => (string) ($enrollment->formation->level ?? 'Niveau non precise'),
                    'duration' => (string) ($enrollment->formation->duration ?? 'Duree non precise'),

                    // L'apprenant voit venir sa propre desinscription automatique.
                    'inactive_days' => $enrollment->inactiveDays(),
                    'days_before_unenrollment' => $enrollment->daysBeforeUnenrollment(),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Inscriptions recuperees',
            'data' => $rows,
            'meta' => self::learnerMeta(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        $validator = Validator::make($request->all(), [
            'formation_id' => ['required', 'integer', 'exists:formations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $formationId = (int) $request->input('formation_id');

        $activeEnrollmentsCount = Enrollment::query()
            ->where('user_id', (int) $user->id)
            ->where(function ($query) {
                $query->whereNull('progress')->orWhere('progress', '<', 100);
            })
            ->count();

        $maxActive = self::maxActiveEnrollments();

        if ($activeEnrollmentsCount >= $maxActive) {
            return response()->json([
                'message' => "Vous ne pouvez pas etre inscrit a plus de {$maxActive} formations simultanement",
            ], 400);
        }

        $existing = Enrollment::query()
            ->where('user_id', (int) $user->id)
            ->where('formation_id', $formationId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Vous etes deja inscrit a cette formation'], 409);
        }

        $enrollment = Enrollment::create([
            'user_id' => (int) $user->id,
            'formation_id' => $formationId,
            'progress' => 0,
            'enrolled_at' => now(),
        ]);
        $enrollment->load('formation:id,title,level,duration');

        return response()->json([
            'message' => 'Inscription effectuee',
            'data' => [
                'id' => (int) $enrollment->id,
                'user_id' => (int) $enrollment->user_id,
                'formation_id' => (int) $enrollment->formation_id,
                'progress' => (int) ($enrollment->progress ?? 0),
                'enrolled_at' => (string) ($enrollment->enrolled_at ?? ''),
                'title' => (string) ($enrollment->formation->title ?? 'Formation'),
                'level' => (string) ($enrollment->formation->level ?? 'Niveau non precise'),
                'duration' => (string) ($enrollment->formation->duration ?? 'Duree non precise'),
            ],
        ], 201);
    }

    public function complete(Request $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        if ((int) $enrollment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Vous ne pouvez modifier que vos inscriptions'], 403);
        }

        $enrollment->progress = 100;
        $enrollment->save();

        return response()->json([
            'message' => 'Formation marquee comme terminee',
            'data' => [
                'id' => (int) $enrollment->id,
                'progress' => (int) ($enrollment->progress ?? 0),
            ],
        ]);
    }

    /**
     * Nombre maximum de formations suivies simultanement.
     */
    private static function maxActiveEnrollments(): int
    {
        return (int) config('skillhub.enrollment.max_active', 5);
    }

    /**
     * Regles renvoyees au client pour qu'il n'ait pas a les coder en dur.
     *
     * @return array{max_active: int, unenroll_after_days: int}
     */
    private static function learnerMeta(): array
    {
        return [
            'max_active' => self::maxActiveEnrollments(),
            'unenroll_after_days' => Enrollment::unenrollThresholdDays(),
        ];
    }

    public function destroy(Request $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur non connecte'], 401);
        }

        if ((int) $enrollment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Vous ne pouvez supprimer que vos inscriptions'], 403);
        }

        $enrollment->delete();

        return response()->json([
            'message' => 'Desinscription effectuee',
        ]);
    }
}

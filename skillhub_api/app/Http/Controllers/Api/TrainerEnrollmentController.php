<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Formation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerEnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || (string) $user->role !== 'formateur') {
            return response()->json([
                'message' => 'Action reservee aux formateurs',
            ], 403);
        }

        $formationIds = Formation::query()
            ->where('user_id', (int) $user->id)
            ->pluck('id')
            ->all();

        if ($formationIds === []) {
            return response()->json([
                'message' => 'Apprenants inscrits recuperes',
                'data' => [],
            ]);
        }

        $rows = Enrollment::query()
            ->whereIn('formation_id', $formationIds)
            ->with(['user:id,name,email,role', 'formation:id,title'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Enrollment $enrollment): array => $this->serializeEnrollment($enrollment))
            ->values();

        return response()->json([
            'message' => 'Apprenants inscrits recuperes',
            'data' => $rows,
        ]);
    }

    public function showByFormation(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (!$user || (string) $user->role !== 'formateur') {
            return response()->json([
                'message' => 'Action reservee aux formateurs',
            ], 403);
        }

        if ((int) $formation->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez consulter que les apprenants de vos formations',
            ], 403);
        }

        $rows = Enrollment::query()
            ->where('formation_id', (int) $formation->id)
            ->with('user:id,name,email,role')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Enrollment $enrollment): array => $this->serializeEnrollment($enrollment, $formation->title))
            ->values();

        return response()->json([
            'message' => 'Apprenants inscrits a la formation recuperes',
            'data' => [
                'formation_id' => (int) $formation->id,
                'formation_title' => (string) ($formation->title ?? ''),
                'count' => $rows->count(),
                'learners' => $rows,
            ],
        ]);
    }

    private function serializeEnrollment(Enrollment $enrollment, ?string $formationTitleOverride = null): array
    {
        $user = $enrollment->user;
        $formation = $enrollment->formation;

        return [
            'enrollment_id' => (int) $enrollment->id,
            'formation_id' => (int) $enrollment->formation_id,
            'formation_title' => $formationTitleOverride !== null
                ? (string) $formationTitleOverride
                : (string) ($formation->title ?? ''),
            'user_id' => (int) $enrollment->user_id,
            'user_name' => $user ? (string) ($user->name ?? '') : null,
            'user_email' => $user ? (string) ($user->email ?? '') : null,
            'progress' => $enrollment->progress !== null ? (int) $enrollment->progress : 0,
            'enrolled_at' => (string) ($enrollment->enrolled_at ?? ''),
        ];
    }
}

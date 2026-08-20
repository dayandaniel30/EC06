<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\DesinscriptionInactive;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Formation;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TrainerEnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || (string) $user->role !== 'formateur') {
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
                'meta' => $this->inactivityMeta(),
            ]);
        }

        $rows = Enrollment::query()
            ->whereIn('formation_id', $formationIds)
            ->with(['user:id,name,email,role,last_activity_at', 'formation:id,title'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Enrollment $enrollment): array => $this->serializeEnrollment($enrollment))
            ->values();

        return response()->json([
            'message' => 'Apprenants inscrits recuperes',
            'data' => $rows,
            'meta' => $this->inactivityMeta(),
        ]);
    }

    public function showByFormation(Request $request, Formation $formation): JsonResponse
    {
        $user = $request->user();

        if (! $user || (string) $user->role !== 'formateur') {
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
            ->with('user:id,name,email,role,last_activity_at')
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
            'meta' => $this->inactivityMeta(),
        ]);
    }

    private function serializeEnrollment(Enrollment $enrollment, ?string $formationTitleOverride = null): array
    {
        $user = $enrollment->user;
        $formation = $enrollment->formation;

        $progress = $enrollment->progress !== null ? (int) $enrollment->progress : 0;
        $lastActivity = $this->resolveLastActivity($enrollment);
        $inactiveDays = $lastActivity?->diffInDays(Carbon::now());

        return [
            'enrollment_id' => (int) $enrollment->id,
            'formation_id' => (int) $enrollment->formation_id,
            'formation_title' => $formationTitleOverride !== null
                ? (string) $formationTitleOverride
                : (string) ($formation->title ?? ''),
            'user_id' => (int) $enrollment->user_id,
            'user_name' => $user ? (string) ($user->name ?? '') : null,
            'user_email' => $user ? (string) ($user->email ?? '') : null,
            'progress' => $progress,
            'enrolled_at' => (string) ($enrollment->enrolled_at ?? ''),

            // Suivi de la desinscription automatique pour inactivite : le
            // formateur voit venir le retrait avant qu'il ne soit applique.
            'last_activity_at' => $user?->last_activity_at?->toDateTimeString(),
            'inactive_days' => $inactiveDays !== null ? (int) $inactiveDays : null,
            'days_before_unenrollment' => $this->daysBeforeUnenrollment($progress, $inactiveDays),
        ];
    }

    /**
     * Nombre de jours restants avant la desinscription automatique.
     *
     * `null` quand la regle ne s'applique pas : formation terminee (la commande
     * ne touche jamais aux inscriptions a 100 %) ou inactivite indeterminable.
     */
    private function daysBeforeUnenrollment(int $progress, int|float|null $inactiveDays): ?int
    {
        if ($progress >= 100 || $inactiveDays === null) {
            return null;
        }

        $threshold = (int) config(
            'skillhub.inactivity.unenroll_after_days',
            DesinscriptionInactive::DEFAULT_INACTIVITY_DAYS
        );

        return max(0, $threshold - (int) $inactiveDays);
    }

    /**
     * Date de reference servant a mesurer l'inactivite de l'apprenant.
     *
     * Meme regle que la commande `app:desinscription-inactive` : sans activite
     * enregistree, la date d'inscription sert de repere.
     */
    private function resolveLastActivity(Enrollment $enrollment): ?CarbonInterface
    {
        $lastActivityAt = $enrollment->user?->last_activity_at;

        if ($lastActivityAt !== null) {
            return Carbon::parse($lastActivityAt);
        }

        if (! empty($enrollment->enrolled_at)) {
            return Carbon::parse($enrollment->enrolled_at);
        }

        return null;
    }

    /**
     * Seuil de la regle, renvoye au client pour qu'il n'ait pas a le coder en dur.
     *
     * @return array{unenroll_after_days: int}
     */
    private function inactivityMeta(): array
    {
        return [
            'unenroll_after_days' => (int) config(
                'skillhub.inactivity.unenroll_after_days',
                DesinscriptionInactive::DEFAULT_INACTIVITY_DAYS
            ),
        ];
    }
}

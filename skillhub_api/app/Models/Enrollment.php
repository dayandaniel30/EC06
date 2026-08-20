<?php

namespace App\Models;

use App\Console\Commands\DesinscriptionInactive;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inscription d'un apprenant a une formation.
 *
 * @property int $id
 * @property int $user_id
 * @property int $formation_id
 * @property int|null $progress
 * @property string|null $enrolled_at
 * @property-read User|null $user
 * @property-read Formation|null $formation
 */
class Enrollment extends Model
{
    use HasFactory;

    protected $table = 'enrollments';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'formation_id',
        'progress',
        'enrolled_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Formation, $this>
     */
    public function formation(): BelongsTo
    {
        return $this->belongsTo(Formation::class);
    }

    /**
     * Seuil d'inactivite, en jours, au-dela duquel l'inscription est supprimee.
     */
    public static function unenrollThresholdDays(): int
    {
        return (int) config(
            'skillhub.inactivity.unenroll_after_days',
            DesinscriptionInactive::DEFAULT_INACTIVITY_DAYS
        );
    }

    /**
     * Indique si l'inscription echappe a la desinscription automatique.
     *
     * Une formation terminee n'est jamais retiree, quelle que soit l'inactivite.
     */
    public function isCompleted(): bool
    {
        return $this->progress !== null && (int) $this->progress >= 100;
    }

    /**
     * Date de reference servant a mesurer l'inactivite de l'apprenant.
     *
     * Sans activite enregistree sur le compte, la date d'inscription sert de
     * repere : c'est la seule preuve de presence dont on dispose.
     */
    public function lastActivityAt(): ?CarbonInterface
    {
        $lastActivityAt = $this->user?->last_activity_at;

        if ($lastActivityAt !== null) {
            return Carbon::parse($lastActivityAt);
        }

        if (! empty($this->enrolled_at)) {
            return Carbon::parse($this->enrolled_at);
        }

        return null;
    }

    /**
     * Nombre de jours ecoules depuis la derniere activite, ou null si indeterminable.
     */
    public function inactiveDays(): ?int
    {
        $lastActivity = $this->lastActivityAt();

        return $lastActivity === null
            ? null
            : (int) $lastActivity->diffInDays(Carbon::now());
    }

    /**
     * Jours restants avant la desinscription automatique.
     *
     * `null` quand la regle ne s'applique pas : formation terminee, ou
     * inactivite indeterminable.
     */
    public function daysBeforeUnenrollment(): ?int
    {
        $inactiveDays = $this->inactiveDays();

        if ($this->isCompleted() || $inactiveDays === null) {
            return null;
        }

        return max(0, self::unenrollThresholdDays() - $inactiveDays);
    }
}

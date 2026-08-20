<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte utilisateur du domaine metier Laravel.
 *
 * Les mots de passe primaires vivent dans le SSO Spring Boot : la colonne
 * `password` ne sert que pour les comptes historiques authentifies via Sanctum.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $role
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property bool|null $is_active
 * @property \Illuminate\Support\Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Formation> $formations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Enrollment> $enrollments
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Motif enregistre dans `deactivation_reason` par la purge d'inactivite.
     *
     * @var string
     */
    public const DEACTIVATION_REASON_INACTIVITY = 'inactivity';

    /**
     * This project uses an existing users table without created_at/updated_at.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'last_activity_at',
        'is_active',
        'deactivated_at',
        'deactivation_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function formations(): HasMany
    {
        return $this->hasMany(Formation::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Restreint la requete aux comptes encore actifs.
     *
     * Les comptes crees avant l'ajout de la colonne ont `is_active` a NULL :
     * ils sont consideres actifs tant qu'ils n'ont pas ete explicitement desactives.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where('is_active', true)->orWhereNull('is_active');
        });
    }

    /**
     * Restreint la requete aux comptes actifs sans activite depuis `$threshold`.
     *
     * Un compte qui n'a jamais eu d'activite enregistree (`last_activity_at` NULL)
     * n'est jamais purge : on ne peut pas prouver son inactivite, et le desactiver
     * romprait l'acces de comptes crees juste avant la mise en place du suivi.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeInactiveSince(Builder $query, Carbon $threshold): Builder
    {
        return $query->active()
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<', $threshold);
    }

    /**
     * Indique si le compte est utilisable pour ouvrir une session.
     */
    public function isActive(): bool
    {
        return $this->is_active === null || $this->is_active === true;
    }

    /**
     * Revoque les acces du compte et le desactive.
     *
     * L'operation est idempotente : rejouer la purge sur un compte deja desactive
     * ne modifie rien et ne reproduit pas de journal.
     *
     * @param  string  $reason  motif court stocke en base
     * @return int nombre de jetons Sanctum revoques
     */
    public function deactivate(string $reason = self::DEACTIVATION_REASON_INACTIVITY): int
    {
        $revokedTokens = $this->tokens()->delete();

        $this->forceFill([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ])->save();

        Log::info('Compte desactive', [
            'user_id' => (int) $this->getKey(),
            'email' => (string) $this->email,
            'reason' => $reason,
            'last_activity_at' => $this->last_activity_at?->toDateTimeString(),
            'revoked_tokens' => $revokedTokens,
        ]);

        return $revokedTokens;
    }
}

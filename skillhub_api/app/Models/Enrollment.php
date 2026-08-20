<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

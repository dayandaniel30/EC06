<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Formation du catalogue Skillhub.
 *
 * La table existe sous deux formes : celle creee par les migrations
 * (`description`) et celle de la base historique importee depuis phpMyAdmin
 * (`short_description`, `full_description`, `price`, `category`, `status`,
 * `instructor_id`). Les colonnes propres a la seconde sont donc declarees
 * nullables, et leur presence est verifiee au runtime avant lecture.
 *
 * @property int $id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $full_description
 * @property string|null $duration
 * @property string|null $level
 * @property float|string|null $price
 * @property string|null $category
 * @property string|null $status
 * @property int|null $instructor_id
 * @property int|null $user_id
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property-read User|null $user
 */
class Formation extends Model
{
    use HasFactory;

    protected $table = 'formations';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'short_description',
        'full_description',
        'duration',
        'level',
        'price',
        'category',
        'status',
        'instructor_id',
        'user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

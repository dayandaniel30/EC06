<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

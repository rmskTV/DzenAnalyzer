<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rubric extends Model
{
    protected $fillable = ['name', 'created_by', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

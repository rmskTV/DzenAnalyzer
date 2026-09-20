<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Format extends Model
{
    protected $fillable = ['name', 'is_evergreen', 'is_active'];

    protected function casts(): array
    {
        return ['is_evergreen' => 'boolean', 'is_active' => 'boolean'];
    }
}

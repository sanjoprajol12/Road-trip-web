<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'author_id', 'author_staff_id', 'kind', 'body'];
}

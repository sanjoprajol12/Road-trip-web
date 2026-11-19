<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaUsage extends Model
{
    protected $fillable = ['media_asset_id', 'owner_type', 'owner_id', 'field'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TandaMember extends Model
{
    protected $fillable = [
        'tanda_id',
        'user_id',
        'guest_name',
        'turn_order',
        'has_received',
        'received_at',
    ];

    protected $casts = [
        'has_received' => 'boolean',
        'received_at'  => 'date',
    ];

    public function tanda()
    {
        return $this->belongsTo(Tanda::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

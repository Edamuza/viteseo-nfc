<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcProfileEdit extends Model
{
    protected $fillable = ['ip_address', 'user_agent', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}

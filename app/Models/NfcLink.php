<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcLink extends Model
{
    use HasFactory;

    protected $fillable = ['card_id', 'title', 'url', 'icon', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}

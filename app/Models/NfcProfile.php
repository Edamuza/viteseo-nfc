<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcProfile extends Model
{
    use HasFactory;

    /** The page background when no colour has been derived from an avatar. */
    public const DEFAULT_BRAND_COLOR = '#0f1713';

    /**
     * Explicit allow-list. card_id, avatar_path and brand_color are deliberately
     * absent: the first would let request data re-point a profile at another
     * card, the second would let it name a file, and the third is derived from
     * the avatar rather than chosen by anyone.
     */
    protected $fillable = [
        'display_name', 'headline', 'username', 'location', 'tagline',
        'welcome_eyebrow', 'welcome_heading', 'welcome_body',
        'welcome_cta_label', 'welcome_cta_url',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * Is there enough here to show a visitor?
     *
     * A profile page with no name has nothing to be a page about — the headline
     * heads nothing and the avatar fallback has no initials to draw. This gates
     * Profile mode the way NfcContactProfile::hasName() gates Contact mode.
     */
    public function isMeaningful(): bool
    {
        return trim((string) $this->display_name) !== '';
    }
}

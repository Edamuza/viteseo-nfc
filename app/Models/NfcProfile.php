<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * SQL twin of isMeaningful().
     *
     * test_the_meaningful_scope_agrees_with_is_meaningful is what keeps the two
     * from drifting. `trim(coalesce(...))` mirrors the PHP `trim((string) ...)`:
     * coalesce collapses NULL to '' first, because `NULL != ''` is UNKNOWN in
     * SQL rather than true, which would drop every unset row.
     *
     * One known limit, shared with NfcContactProfile::scopeMeaningful(): SQL
     * trim() removes spaces while PHP trim() also removes tabs and newlines, so
     * a name of "\t" would differ. It is unreachable today because every write
     * path strips control characters first.
     */
    public function scopeMeaningful(Builder $query): Builder
    {
        return $query->whereRaw("trim(coalesce(display_name, '')) != ''");
    }

    /** "UP" for the avatar placeholder — first and last word, at most two letters. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $letters = count($words) === 1
            ? [$words[0]]
            : [$words[0], $words[count($words) - 1]];

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), $letters)));
    }

    /**
     * The page background. Always a valid hex colour, because it is
     * interpolated into a style attribute.
     *
     * DominantColor normalises every derived value into a dark lightness band,
     * which is why --on-brand can be a fixed light value with no luminance
     * branch. A stored value that is not a hex colour is treated as absent
     * rather than trusted.
     */
    public function brandColor(): string
    {
        $stored = (string) $this->brand_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $stored) === 1
            ? strtolower($stored)
            : self::DEFAULT_BRAND_COLOR;
    }

    /** The same colour at low alpha, for the cover band. */
    public function brandColorSoftRgba(): string
    {
        [$r, $g, $b] = sscanf($this->brandColor(), '#%02x%02x%02x');

        return sprintf('rgba(%d,%d,%d,.34)', $r, $g, $b);
    }
}

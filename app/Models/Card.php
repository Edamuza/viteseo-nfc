<?php

namespace App\Models;

use App\Enums\NfcMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_number', 'public_id', 'activation_pin_hash', 'activation_pin_encrypted',
        'qr_destination', 'qr_configured_at', 'nfc_configured_at',
        'nfc_page_title', 'nfc_page_subtitle', 'nfc_logo_path',
        'nfc_mode', 'nfc_phone_number',
        'nfc_email_address', 'nfc_email_subject', 'nfc_email_body',
    ];

    /**
     * The PIN ciphertext and hash must never reach a view or JSON response by
     * accident. Admin PIN reveal goes through an explicit, authenticated action.
     */
    protected $hidden = ['activation_pin_hash', 'activation_pin_encrypted'];

    protected function casts(): array
    {
        return [
            'activation_pin_encrypted' => 'encrypted',
            'qr_configured_at'  => 'datetime',
            'nfc_configured_at' => 'datetime',
            'pin_personalized_at' => 'datetime',
            'nfc_mode'          => NfcMode::class,
        ];
    }

    /**
     * Has the owner replaced the universal activation PIN with their own?
     * Until they have, every setup and unlock flow demands a personal PIN
     * alongside the universal one — a card protected by a PIN every customer
     * knows is not protected at all.
     */
    public function pinIsPersonalized(): bool
    {
        return $this->pin_personalized_at !== null;
    }

    /** Public routes resolve by public_id, never by the internal numeric id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function nfcLinks(): HasMany
    {
        return $this->hasMany(NfcLink::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeNfcLinks(): HasMany
    {
        return $this->nfcLinks()->where('is_active', true);
    }

    public function contactProfile(): HasOne
    {
        return $this->hasOne(NfcContactProfile::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(NfcProfile::class);
    }

    public function profileEdits(): HasMany
    {
        return $this->hasMany(NfcProfileEdit::class)->latest('created_at')->latest('id');
    }

    // --- NFC mode -----------------------------------------------------------

    public function nfcMode(): NfcMode
    {
        return $this->nfc_mode instanceof NfcMode ? $this->nfc_mode : NfcMode::Links;
    }

    /**
     * Does the CURRENT mode have enough data to show something to a visitor?
     *
     * Deliberately per-mode: a card can hold contact details AND links at the
     * same time, and switching modes must never destroy the other mode's data.
     */
    public function nfcModeIsConfigured(): bool
    {
        return match ($this->nfcMode()) {
            NfcMode::Links   => $this->activeNfcLinkCount() > 0,
            NfcMode::Contact => (bool) $this->contactProfile?->isMeaningful(),
            NfcMode::Phone   => (string) $this->nfc_phone_number !== '',
            NfcMode::Email   => (string) $this->nfc_email_address !== '',
            NfcMode::Profile => (bool) $this->profile?->isMeaningful(),
        };
    }

    /**
     * SQL twin of nfcModeIsConfigured().
     *
     * The dashboard needs this as a countable query, and each mode stores its
     * payload somewhere different, so the branch has to be reproduced in SQL.
     * test_configured_scope_agrees_with_nfc_mode_is_configured is what keeps
     * the two implementations honest.
     *
     * When a mode is added, both this scope and nfcModeIsConfigured() must
     * learn about it, or a card actively serving that mode reads as
     * "Unconfigured" on the dashboard.
     */
    public function scopeWhereNfcModeConfigured(Builder $query, bool $configured = true): Builder
    {
        $matches = function (Builder $q) {
            $q->where(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Links->value)
                    ->whereHas('nfcLinks', fn ($l) => $l->where('is_active', true)))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Contact->value)
                    ->whereHas('contactProfile', fn ($p) => $p->meaningful()))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Phone->value)
                    ->whereRaw("coalesce(nfc_phone_number, '') != ''"))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Email->value)
                    ->whereRaw("coalesce(nfc_email_address, '') != ''"))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Profile->value)
                    ->whereHas('profile', fn ($p) => $p->meaningful()));
        };

        return $configured ? $query->where($matches) : $query->whereNot($matches);
    }

    /**
     * SQL twin of qrIsConfigured(). Mirrors it exactly, including treating an
     * empty string as unconfigured — the previous stats() used a bare
     * whereNotNull and so counted a blank destination as active.
     *
     * qr_destination is nullable, and `NULL != ''` is UNKNOWN in SQL, so a
     * naive negation would drop every NULL row from both branches. coalesce
     * collapses NULL to '' first, which makes the predicate two-valued and the
     * two branches an exact partition of the table.
     */
    public function scopeWhereQrConfigured(Builder $query, bool $configured = true): Builder
    {
        return $configured
            ? $query->whereRaw("coalesce(qr_destination, '') != ''")
            : $query->whereRaw("coalesce(qr_destination, '') = ''");
    }

    // --- Permanent URLs (never change for the life of the card) --------------

    public function qrUrl(): string
    {
        return rtrim(config('app.url'), '/').'/q/'.$this->public_id;
    }

    public function nfcUrl(): string
    {
        return rtrim(config('app.url'), '/').'/n/'.$this->public_id;
    }

    // --- Status helpers ------------------------------------------------------

    public function qrIsConfigured(): bool
    {
        return $this->qr_destination !== null && $this->qr_destination !== '';
    }

    public function activeNfcLinkCount(): int
    {
        // The dashboard selects this aggregate once for the whole page as a
        // correlated subquery. Honouring it here is what keeps a 25-row table at
        // a constant query count instead of one count per row.
        if (array_key_exists('active_link_count', $this->attributes)) {
            return (int) $this->attributes['active_link_count'];
        }

        return $this->relationLoaded('activeNfcLinks')
            ? $this->activeNfcLinks->count()
            : $this->activeNfcLinks()->count();
    }

    /**
     * Links-mode configuration state.
     *
     * Kept name-compatible with the pre-modes behaviour so existing callers and
     * dashboard statistics continue to mean exactly what they meant before.
     */
    public function nfcIsConfigured(): bool
    {
        return $this->activeNfcLinkCount() > 0;
    }

    /** Base filename used for QR downloads: CARD-0001_X7K92LM */
    public function assetBasename(): string
    {
        return $this->card_number.'_'.$this->public_id;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcContactProfile extends Model
{
    use HasFactory;

    /** Explicit allow-list: card_id is deliberately absent to block mass assignment. */
    protected $fillable = [
        'first_name', 'middle_name', 'last_name', 'suffix', 'job_title', 'company',
        'mobile_phone', 'work_phone', 'landline', 'other_phone',
        'personal_email', 'work_email', 'website',
        'street', 'city', 'state', 'postal_code', 'country',
        'birthday', 'related_name', 'notes',
        'facebook_url', 'instagram_url', 'linkedin_url', 'tiktok_url',
        'x_url', 'youtube_url', 'other_social_url',
        // photo_path is set only by the controller from a server-generated name.
    ];

    protected function casts(): array
    {
        return ['birthday' => 'date'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** "John Q. Doe Jr." - collapses cleanly when parts are missing. */
    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->last_name, $this->suffix,
        ])));
    }

    public function hasName(): bool
    {
        return $this->displayName() !== '' || (string) $this->company !== '';
    }

    /** The name used for the .vcf filename; falls back to the company. */
    public function fileBaseName(): string
    {
        $base = $this->displayName() ?: (string) $this->company ?: 'contact';
        $base = preg_replace('/[^A-Za-z0-9]+/', '-', $base) ?? 'contact';

        return trim($base, '-') ?: 'contact';
    }

    /** @return array<string, string> label => phone, populated only */
    public function phones(): array
    {
        return array_filter([
            'Mobile'   => (string) $this->mobile_phone,
            'Work'     => (string) $this->work_phone,
            'Landline' => (string) $this->landline,
            'Other'    => (string) $this->other_phone,
        ], fn ($v) => $v !== '');
    }

    /** @return array<string, string> label => email, populated only */
    public function emails(): array
    {
        return array_filter([
            'Personal' => (string) $this->personal_email,
            'Work'     => (string) $this->work_email,
        ], fn ($v) => $v !== '');
    }

    /** @return array<string, string> label => url, populated only */
    public function socials(): array
    {
        return array_filter([
            'Facebook'  => (string) $this->facebook_url,
            'Instagram' => (string) $this->instagram_url,
            'LinkedIn'  => (string) $this->linkedin_url,
            'TikTok'    => (string) $this->tiktok_url,
            'X'         => (string) $this->x_url,
            'YouTube'   => (string) $this->youtube_url,
            'Website'   => (string) $this->other_social_url,
        ], fn ($v) => $v !== '');
    }

    /** Single-line address for display; the vCard uses the structured ADR field. */
    public function addressLine(): string
    {
        return implode(', ', array_filter([
            $this->street, $this->city, $this->state, $this->postal_code, $this->country,
        ]));
    }

    public function hasAddress(): bool
    {
        return $this->addressLine() !== '';
    }

    /** The single best phone for the primary "Call" action. */
    public function primaryPhone(): ?string
    {
        foreach ([$this->mobile_phone, $this->work_phone, $this->landline, $this->other_phone] as $p) {
            if ((string) $p !== '') {
                return (string) $p;
            }
        }

        return null;
    }

    public function primaryEmail(): ?string
    {
        foreach ([$this->work_email, $this->personal_email] as $e) {
            if ((string) $e !== '') {
                return (string) $e;
            }
        }

        return null;
    }

    /** Enough information to be worth showing at all. */
    public function isMeaningful(): bool
    {
        return $this->hasName();
    }

    /**
     * SQL twin of isMeaningful().
     *
     * The dashboard counts configured cards across the whole table and cannot
     * instantiate a model per row, so this rule exists in both PHP and SQL.
     * test_meaningful_scope_agrees_with_is_meaningful is what keeps the two
     * from drifting apart.
     *
     * Name columns are trimmed to mirror displayName(); company is left
     * untrimmed to mirror hasName(). That asymmetry is inherited from those
     * methods — do not "tidy" it here or the dashboard count will change.
     *
     * Column names come from a hardcoded literal array below, not user input,
     * so building the expression as raw SQL carries no injection risk.
     *
     * A NULL column does not satisfy the checks, which is exactly the wanted
     * behaviour: unset is not meaningful.
     */
    public function scopeMeaningful(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $column) {
                $q->orWhereRaw("trim(coalesce({$column}, '')) != ''");
            }
            $q->orWhereRaw("coalesce(company, '') != ''");
        });
    }
}

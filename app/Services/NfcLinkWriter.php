<?php

namespace App\Services;

use App\Models\Card;
use App\Rules\SafeExternalUrl;
use Illuminate\Validation\Rule;

/**
 * The one way a card's links are written.
 *
 * Every editor that touches links - the admin table, the customer's profile
 * editor, the setup form - submits the COMPLETE list and gets it stored in the
 * order it arrived. That is what lets a row be dragged: position in the array
 * is the sort order, so reordering needs no request of its own, and a row left
 * out of the submission is a row the author deleted.
 */
class NfcLinkWriter
{
    public const MAX = 12;

    /**
     * Replace a card's links with exactly these rows, in this order.
     *
     * Replace rather than merge: merging would need ids that survive a drag,
     * and would quietly keep a row alive whenever a request lost it.
     *
     * @param  array<int, array{title: string, url: string, icon?: ?string, is_active?: bool}>  $rows
     */
    public function replace(Card $card, array $rows): void
    {
        $card->nfcLinks()->delete();

        foreach (array_values($rows) as $order => $row) {
            $card->nfcLinks()->create([
                'title'      => trim((string) $row['title']),
                'url'        => trim((string) $row['url']),
                'icon'       => $row['icon'] ?? null,
                'sort_order' => $order,
                'is_active'  => (bool) ($row['is_active'] ?? true),
            ]);
        }
    }

    /**
     * Drop the editor's blank rows before validation sees them.
     *
     * Every links editor keeps a spare empty row at the bottom for the next
     * link. Submitting one is not an attempt to save a link with no URL, so
     * it must not fail the form; a row with SOMETHING in it is, and is left
     * alone for the rules below to reject properly.
     *
     * @return array<int, mixed>
     */
    public function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $clean[] = $row;

                continue;
            }

            $title = is_string($row['title'] ?? null) ? trim($row['title']) : ($row['title'] ?? null);
            $url   = is_string($row['url'] ?? null) ? trim($row['url']) : ($row['url'] ?? null);
            $icon  = is_string($row['icon'] ?? null) ? (trim($row['icon']) ?: null) : null;

            if (($title === null || $title === '') && ($url === null || $url === '')) {
                continue;
            }

            $clean[] = [
                'title'     => $title,
                'url'       => $url,
                'icon'      => $icon,
                // Absent means active. The customer's editors have no such
                // control and everything they save is meant to be live; the
                // admin table, which does have one, pairs its checkbox with a
                // hidden "0" so an unticked box says so rather than saying
                // nothing.
                'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            ];
        }

        return $clean;
    }

    /**
     * @param  string  $key  the request key the rows arrived under
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $key = 'links'): array
    {
        return [
            $key             => ['array', 'max:'.self::MAX],
            $key.'.*'        => ['array'],
            $key.'.*.title'  => ['required', 'string', 'max:120'],
            $key.'.*.url'    => ['required', 'string', 'max:500', new SafeExternalUrl()],
            $key.'.*.icon'   => ['nullable', 'string', Rule::in(array_keys(LinkPlatform::iconChoices()))],
            $key.'.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(string $key = 'links'): array
    {
        return [
            $key.'.*.title.required' => 'Every link needs a label.',
            $key.'.*.url.required'   => 'Every link needs a URL.',
        ];
    }
}

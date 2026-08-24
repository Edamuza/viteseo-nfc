<?php

namespace App\Services;

use App\Models\NfcContactProfile;
use App\Models\NfcProfile;

/**
 * Builds a vCard 3.0 document from a contact profile.
 *
 * WHY 3.0 RATHER THAN 4.0
 * -----------------------
 * 3.0 (RFC 2426) is what iOS Contacts and Android/Google Contacts both import
 * most reliably. Apple in particular still handles 4.0 inconsistently, and this
 * file's entire job is to be imported by a phone. Nothing here needs a 4.0-only
 * feature, so 3.0 is the compatible choice.
 *
 * SOCIAL PROFILES
 * ---------------
 * Written as X-SOCIALPROFILE (Apple) AND as plain URL lines, so a platform that
 * ignores the extension still receives the links rather than silently dropping
 * them.
 *
 * ESCAPING
 * --------
 * Per RFC 2426 every value escapes backslash, comma, semicolon and newline.
 * Structured values (N, ADR) escape each component before joining with ';'.
 * All CR/LF is removed from inputs first, so no field can inject a vCard line.
 */
class VCardBuilder
{
    private const EOL = "\r\n";   // RFC 2426 requires CRLF

    public function build(NfcContactProfile $p): string
    {
        $lines = [];
        $lines[] = 'BEGIN:VCARD';
        $lines[] = 'VERSION:3.0';

        // N: Family;Given;Additional;Prefix;Suffix
        $lines[] = 'N:'.$this->structured([
            $p->last_name, $p->first_name, $p->middle_name, '', $p->suffix,
        ]);

        // FN is REQUIRED in 3.0. Fall back to the company so the card is never nameless.
        $fn = $p->displayName() ?: (string) $p->company ?: 'Contact';
        $lines[] = 'FN:'.$this->esc($fn);

        if ($p->company)   { $lines[] = 'ORG:'.$this->esc($p->company); }
        if ($p->job_title) { $lines[] = 'TITLE:'.$this->esc($p->job_title); }

        // TEL - TYPE values chosen for broad recognition on both platforms.
        if ($p->mobile_phone) { $lines[] = 'TEL;TYPE=CELL,VOICE:'.$this->esc($p->mobile_phone); }
        if ($p->work_phone)   { $lines[] = 'TEL;TYPE=WORK,VOICE:'.$this->esc($p->work_phone); }
        if ($p->landline)     { $lines[] = 'TEL;TYPE=HOME,VOICE:'.$this->esc($p->landline); }
        if ($p->other_phone)  { $lines[] = 'TEL;TYPE=VOICE:'.$this->esc($p->other_phone); }

        if ($p->work_email)     { $lines[] = 'EMAIL;TYPE=INTERNET,WORK:'.$this->esc($p->work_email); }
        if ($p->personal_email) { $lines[] = 'EMAIL;TYPE=INTERNET,HOME:'.$this->esc($p->personal_email); }

        if ($p->website) { $lines[] = 'URL:'.$this->esc($p->website); }

        // ADR: PO;Ext;Street;City;Region;PostalCode;Country
        if ($p->hasAddress()) {
            $lines[] = 'ADR;TYPE=WORK:'.$this->structured([
                '', '', $p->street, $p->city, $p->state, $p->postal_code, $p->country,
            ]);
            $lines[] = 'LABEL;TYPE=WORK:'.$this->esc($p->addressLine());
        }

        if ($p->birthday) { $lines[] = 'BDAY:'.$p->birthday->format('Y-m-d'); }

        // RELATED is 4.0-only; X-ABRELATEDNAMES is what Apple reads. Also folded
        // into NOTE below so the information survives on platforms ignoring both.
        if ($p->related_name) {
            $lines[] = 'X-ABRELATEDNAMES;TYPE=pref:'.$this->esc($p->related_name);
        }

        foreach ($p->socials() as $label => $url) {
            $lines[] = 'X-SOCIALPROFILE;TYPE='.$this->esc($label).':'.$this->esc($url);
            // Duplicate as a plain URL so non-Apple importers still get the link.
            $lines[] = 'URL;TYPE='.$this->esc($label).':'.$this->esc($url);
        }

        $note = $this->composeNote($p);
        if ($note !== '') { $lines[] = 'NOTE:'.$this->esc($note); }

        $lines[] = 'REV:'.gmdate('Y-m-d\TH:i:s\Z');
        $lines[] = 'END:VCARD';

        return implode(self::EOL, $lines).self::EOL;
    }

    public function buildProfile(NfcProfile $profile, iterable $links): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:'.$this->structured(['', $profile->display_name, '', '', '']),
            'FN:'.$this->esc($profile->display_name),
        ];

        $headline = trim((string) $profile->headline);
        if ($headline !== '') {
            [$title, $organisation] = array_pad(explode(',', $headline, 2), 2, '');
            $title = trim($title);
            $organisation = trim($organisation);

            if ($title !== '') {
                $lines[] = 'TITLE:'.$this->esc($title);
            }
            if ($organisation !== '') {
                $lines[] = 'ORG:'.$this->esc($organisation);
            }
        }

        foreach ($links as $link) {
            $url = trim((string) $link->url);
            if ($url !== '') {
                $lines[] = 'URL:'.$this->esc($url);
            }
        }

        $tagline = trim((string) $profile->tagline);
        if ($tagline !== '') {
            $lines[] = 'NOTE:'.$this->esc($tagline);
        }

        $lines[] = 'REV:'.gmdate('Y-m-d\TH:i:s\Z');
        $lines[] = 'END:VCARD';

        return implode(self::EOL, $lines).self::EOL;
    }

    /**
     * Notes plus anything carried in an extension, so a platform that drops
     * X-* fields still receives the information in a field it definitely reads.
     */
    private function composeNote(NfcContactProfile $p): string
    {
        $parts = [];

        if ($p->notes)        { $parts[] = (string) $p->notes; }
        if ($p->related_name) { $parts[] = 'Related: '.$p->related_name; }

        foreach ($p->socials() as $label => $url) {
            $parts[] = $label.': '.$url;
        }

        return implode("\n", $parts);
    }

    /** Escapes each component, then joins with the structured-value separator. */
    private function structured(array $parts): string
    {
        return implode(';', array_map(fn ($v) => $this->esc((string) $v), $parts));
    }

    /**
     * RFC 2426 §5: escape \ , ; and newlines. Raw CR/LF is stripped first so a
     * value can never terminate its line and inject a new vCard property.
     */
    private function esc(?string $v): string
    {
        $v = (string) $v;
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace(',', '\,', $v);
        $v = str_replace(';', '\;', $v);
        $v = str_replace("\n", '\n', $v);

        return $v;
    }
}

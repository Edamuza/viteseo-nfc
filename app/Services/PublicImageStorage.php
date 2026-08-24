<?php

namespace App\Services;

use App\Models\Card;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removing a publicly served image from the public disk.
 *
 * A contact photo is served from a guessable /storage/... URL. Once the row
 * pointing at it is gone the app can no longer render it, but the file itself is
 * still fetchable by anyone who saw that URL while the card was live. A delete
 * that quietly fails therefore leaves the previous holder's face public on a
 * card that has been re-issued to someone else - the same privacy defect the
 * reset was written to fix, in a place nobody looks.
 *
 * So the failure is not swallowed. Storage::delete() also reports false for a
 * file that was already absent, which is a success for our purposes, so the
 * check is whether the file is still there afterwards.
 */
class PublicImageStorage
{
    public function forget(?string $path, Card $card): void
    {
        if ((string) $path === '') {
            return;
        }

        $disk = Storage::disk('public');
        $disk->delete($path);

        if ($disk->exists($path)) {
            // Logged rather than thrown: the database change has already been
            // committed by every caller, so failing here would report a reset
            // that did happen as an error. An operator needs the path.
            Log::error('Public image survived deletion and is still publicly reachable while the database says it is gone.', [
                'card' => $card->public_id,
                'path' => $path,
            ]);
        }
    }
}

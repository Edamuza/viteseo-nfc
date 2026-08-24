<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\CardReviewStickerService;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ExportController extends Controller
{
    public function __construct(
        private readonly QrCodeService $qr,
        private readonly CardReviewStickerService $stickers,
    ) {}

    /**
     * POST /admin/export/qr-zip
     *
     * Builds a print-ready ZIP of SVG QR codes plus a CSV manifest. Deliberately
     * POST: it is an expensive, state-adjacent action and must not be triggerable
     * by a stray <img> tag or a prefetching browser.
     *
     * Activation PINs are NEVER included here - see activationManifest().
     */
    public function qrZip(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'public_ids'   => ['nullable', 'array', 'max:2000'],
            'public_ids.*' => ['string', 'max:32'],
            'format'       => ['nullable', 'in:svg,png,both'],
        ]);

        $format = $data['format'] ?? 'svg';

        $cards = Card::query()
            ->when(! empty($data['public_ids']), fn ($q) => $q->whereIn('public_id', $data['public_ids']))
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            abort(404, 'No cards matched that selection.');
        }

        // Temp file lives inside the app's own storage, which is the only writable
        // path allowed by the pool's open_basedir.
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0750, true);
        }

        $zipPath = $tmpDir.'/qr-export-'.bin2hex(random_bytes(8)).'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        $csv = "card_number,public_id,qr_url,nfc_url\n";

        foreach ($cards as $card) {
            $base = $card->assetBasename();

            if ($format === 'svg' || $format === 'both') {
                $zip->addFromString('svg/'.$base.'.svg', $this->qr->svgForCard($card));
            }

            if ($format === 'png' || $format === 'both') {
                $zip->addFromString('png/'.$base.'.png', $this->qr->pngForCard($card));
            }

            $csv .= implode(',', [
                $this->csv($card->card_number),
                $this->csv($card->public_id),
                $this->csv($card->qrUrl()),
                $this->csv($card->nfcUrl()),
            ])."\n";
        }

        $zip->addFromString('manifest.csv', $csv);
        $zip->addFromString('README.txt', $this->readme($cards->count(), $format));
        $zip->close();

        $filename = 'nfc-qr-export-'.now()->format('Ymd-His').'.zip';

        // deleteFileAfterSend keeps the temp directory from accumulating archives.
        return response()
            ->download($zipPath, $filename, [
                'Content-Type'  => 'application/zip',
                'Cache-Control' => 'no-store, private',
            ])
            ->deleteFileAfterSend(true);
    }

    public function stickerTemplates(): Response
    {
        $cards = Card::query()->orderBy('id')->get();

        if ($cards->isEmpty()) {
            abort(404, 'No cards are available for template export.');
        }

        return response($this->stickers->zipForCards($cards), 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="nfc-card-templates-'.now()->format('Ymd-His').'.zip"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    /**
     * POST /admin/export/activation-manifest
     *
     * Separate, deliberate action because this CSV contains activation PINs.
     * Streamed rather than written to disk so no plaintext PIN ever touches the
     * filesystem.
     */
    public function activationManifest(): Response
    {
        $csv = "card_number,public_id,activation_pin,qr_url,nfc_url\n";

        Card::query()->orderBy('id')->chunk(200, function ($chunk) use (&$csv) {
            foreach ($chunk as $card) {
                $csv .= implode(',', [
                    $this->csv($card->card_number),
                    $this->csv($card->public_id),
                    $this->csv((string) $card->activation_pin_encrypted),
                    $this->csv($card->qrUrl()),
                    $this->csv($card->nfcUrl()),
                ])."\n";
            }
        });

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="activation-manifest-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control'       => 'no-store, private, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
            'X-Robots-Tag'        => 'noindex, nofollow',
        ]);
    }

    /** Minimal RFC4180 quoting. */
    private function csv(string $v): string
    {
        return '"'.str_replace('"', '""', $v).'"';
    }

    private function readme(int $count, string $format): string
    {
        return <<<TXT
        ViteSEO NFC/QR Card Export
        ==========================

        Cards in this archive : {$count}
        Format                : {$format}

        SVG is the preferred format for printing - it is vector and scales to any
        card size with no loss. PNG is 1200px-class raster for design tools that
        cannot place SVG.

        Each QR encodes that card's PERMANENT URL:
            https://nfc.viteseo.ph/q/{public_id}

        This URL never changes for the life of the card. You can print these QR
        codes before any partner destination has been configured - scanning an
        unconfigured card simply shows its setup page.

        manifest.csv lists card_number, public_id, qr_url and nfc_url.
        Activation PINs are NOT in this archive. They are available from the
        admin dashboard as a separate "Activation Manifest" download.
        TXT;
    }
}

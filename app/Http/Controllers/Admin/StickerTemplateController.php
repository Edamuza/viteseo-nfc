<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\StickerTemplate;
use App\Services\CardReviewStickerService;
use App\Services\QrHoleDetector;
use App\Services\StickerArtworkIngest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StickerTemplateController extends Controller
{
    public function __construct(
        private readonly StickerArtworkIngest $artwork,
        private readonly QrHoleDetector $holes,
    ) {}

    public function index(): Response
    {
        return response()->view('admin.sticker-templates', [
            'templates' => StickerTemplate::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ])->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Both sides arrive in ONE multi-select input, in either order. The side
     * carrying the QR is not a field name any more — it is whichever file has
     * the fully transparent hole. That file is stored as the QR side
     * (back_path); the other becomes the design side (front_path).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:sticker_templates,name'],
            'files' => ['required', 'array', 'size:2'],
            'files.*' => $this->pngRules(),
        ], [
            'files.size' => 'Select exactly two PNGs: the design side and the QR side.',
        ]);

        $paths = [];
        $scans = [];

        try {
            foreach ($request->file('files') as $file) {
                $path = $this->artwork->store($file);
                $paths[] = $path;
                $scans[] = $this->scanArtwork($path);
            }
        } catch (UnreadableImageException $exception) {
            $this->forgetAll($paths);

            return back()->withErrors(['files' => $exception->getMessage()])->withInput($request->except('files'));
        }

        $holed = array_keys(array_filter(array_column($scans, 'qualifying')));

        if (count($holed) !== 1) {
            $this->forgetAll($paths);

            return back()->withErrors([
                'files' => $holed === []
                    ? $this->missingHoleMessage($scans)
                    : 'Only one of the two PNGs may carry the transparent QR hole.',
            ])->withInput($request->except('files'));
        }

        $qrSide = $holed[0];
        $box = $scans[$qrSide]['qualifying'];

        $template = new StickerTemplate(['name' => trim($data['name'])]);
        $template->forceFill([
            'front_path' => $paths[1 - $qrSide],
            'back_path' => $paths[$qrSide],
            'qr_x' => $box['x'],
            'qr_y' => $box['y'],
            'qr_size' => $box['size'],
            'is_active' => false,
        ])->save();

        return redirect()->route('admin.sticker-templates.index')->with('status', 'Sticker template saved.');
    }

    /**
     * Replacements use the same one-input contract as store(): each uploaded
     * file is routed by its content — a file with a transparent hole replaces
     * the QR side and re-detects the box, a file without one replaces the
     * design side. One file of each kind at most, in either order.
     */
    public function update(Request $request, StickerTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('sticker_templates', 'name')->ignore($template)],
            'files' => ['sometimes', 'array', 'min:1', 'max:2'],
            'files.*' => ['file', 'image', 'mimes:png', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        if (isset($data['name'])) {
            $template->fill(['name' => trim($data['name'])]);
        }

        $stored = [];

        try {
            foreach ($request->file('files', []) as $file) {
                $path = $this->artwork->store($file);
                $stored[] = ['path' => $path] + $this->scanArtwork($path);
            }
        } catch (UnreadableImageException $exception) {
            $this->forgetAll(array_column($stored, 'path'));

            return back()->withErrors(['files' => $exception->getMessage()])->withInput($request->except('files'));
        }

        $holed = array_values(array_filter($stored, fn (array $side) => $side['qualifying'] !== null));
        $plain = array_values(array_filter($stored, fn (array $side) => $side['qualifying'] === null));

        if (count($holed) > 1 || count($plain) > 1) {
            $this->forgetAll(array_column($stored, 'path'));

            return back()->withErrors([
                'files' => count($plain) > 1
                    ? 'Both PNGs would replace the design side. '.$this->missingHoleMessage($stored)
                    : 'Only one of the PNGs may carry the transparent QR hole.',
            ])->withInput($request->except('files'));
        }

        $old = [];

        if ($plain !== []) {
            $old[] = $template->front_path;
            $template->forceFill(['front_path' => $plain[0]['path']]);
        }

        if ($holed !== []) {
            $old[] = $template->back_path;
            $template->forceFill([
                'back_path' => $holed[0]['path'],
                'qr_x' => $holed[0]['qualifying']['x'],
                'qr_y' => $holed[0]['qualifying']['y'],
                'qr_size' => $holed[0]['qualifying']['size'],
            ]);
        }

        $template->save();
        $this->forgetAll($old);

        return back()->with('status', 'Sticker template updated.');
    }

    public function activate(StickerTemplate $template): RedirectResponse
    {
        DB::transaction(function () use ($template): void {
            StickerTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            StickerTemplate::query()->update(['is_active' => false]);
            $template->forceFill(['is_active' => true])->save();
        });

        return redirect()->route('admin.sticker-templates.index')->with('status', $template->name.' is now the active sticker template.');
    }

    public function destroy(StickerTemplate $template): RedirectResponse
    {
        if ($template->is_active || StickerTemplate::query()->count() === 1) {
            return redirect()
                ->route('admin.sticker-templates.index')
                ->withErrors(['template' => 'Activate another template before deleting this one.']);
        }

        $paths = [$template->front_path, $template->back_path];
        $template->delete();
        foreach ($paths as $path) {
            $this->forget($path);
        }

        return back()->with('status', 'Sticker template deleted.');
    }

    /** @return array<int, string> */
    private function pngRules(): array
    {
        return ['required', 'file', 'image', 'mimes:png', 'max:2048', 'dimensions:max_width=4000,max_height=4000'];
    }

    /**
     * @return array{qualifying: array{x: int, y: int, size: int}|null, largest: array{x: int, y: int, size: int}|null}
     */
    private function scanArtwork(string $path): array
    {
        $bytes = Storage::disk('public')->get($path);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            return ['qualifying' => null, 'largest' => null];
        }

        try {
            return $this->holes->scan($image);
        } finally {
            unset($image);
        }
    }

    /**
     * Says what the detector actually saw, not just what it wanted. Uploads
     * are scaled to the print canvas before detection, so a designer whose
     * source file holds a generous hole can still fail the minimum — telling
     * them "no hole" reads as nonsense, while "found 75px, need 120px" tells
     * them exactly how much bigger to cut it.
     *
     * @param  array<int, array{qualifying: array{x: int, y: int, size: int}|null, largest: array{x: int, y: int, size: int}|null}>  $scans
     */
    private function missingHoleMessage(array $scans): string
    {
        $found = max(array_map(fn (array $scan) => $scan['largest']['size'] ?? 0, $scans));

        if ($found === 0) {
            return 'Neither PNG contains fully transparent pixels. Export the QR side as a PNG with transparency kept'
                .' (no flattening, no background layer) and cut the QR hole fully out of the design.';
        }

        return sprintf(
            'The largest transparent area found was %1$d×%1$dpx once the artwork was scaled to the %2$d×%3$d print canvas'
                .' — the QR hole must be at least %4$d×%4$dpx at that scale (about one fifth of the design width).'
                .' Enlarge the hole in the source design.',
            $found,
            CardReviewStickerService::WIDTH,
            CardReviewStickerService::HEIGHT,
            QrHoleDetector::MIN_SIZE
        );
    }

    private function forget(?string $path): void
    {
        if ((string) $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /** @param  array<int, string|null>  $paths */
    private function forgetAll(array $paths): void
    {
        foreach ($paths as $path) {
            $this->forget($path);
        }
    }
}

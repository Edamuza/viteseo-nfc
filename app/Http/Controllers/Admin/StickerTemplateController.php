<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\StickerTemplate;
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:sticker_templates,name'],
            'front' => $this->pngRules(),
            'back' => $this->pngRules(),
        ]);

        try {
            $frontPath = $this->artwork->store($request->file('front'));
            $backPath = $this->artwork->store($request->file('back'));
        } catch (UnreadableImageException $exception) {
            return back()->withErrors(['front' => $exception->getMessage()])->withInput($request->except(['front', 'back']));
        }

        $box = $this->detectBox($backPath);
        if ($box === null) {
            $this->forget($frontPath);
            $this->forget($backPath);

            return back()->withErrors([
                'back' => 'The back PNG needs a fully transparent hole at least 200 pixels square.',
            ])->withInput($request->except(['front', 'back']));
        }

        $template = new StickerTemplate(['name' => trim($data['name'])]);
        $template->forceFill([
            'front_path' => $frontPath,
            'back_path' => $backPath,
            'qr_x' => $box['x'],
            'qr_y' => $box['y'],
            'qr_size' => $box['size'],
            'is_active' => false,
        ])->save();

        return redirect()->route('admin.sticker-templates.index')->with('status', 'Sticker template saved.');
    }

    public function update(Request $request, StickerTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('sticker_templates', 'name')->ignore($template)],
            'front' => ['nullable', 'file', 'image', 'mimes:png', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
            'back' => ['nullable', 'file', 'image', 'mimes:png', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        if (isset($data['name'])) {
            $template->fill(['name' => trim($data['name'])]);
        }

        $newFront = null;
        $newBack = null;
        $oldFront = null;
        $oldBack = null;

        try {
            if ($request->file('front')) {
                $newFront = $this->artwork->store($request->file('front'));
                $oldFront = $template->front_path;
                $template->forceFill(['front_path' => $newFront]);
            }

            if ($request->file('back')) {
                $newBack = $this->artwork->store($request->file('back'));
                $box = $this->detectBox($newBack);
                if ($box === null) {
                    $this->forget($newBack);
                    if ($newFront !== null) {
                        $this->forget($newFront);
                    }

                    return back()->withErrors([
                        'back' => 'The back PNG needs a fully transparent hole at least 200 pixels square.',
                    ])->withInput($request->except(['front', 'back']));
                }

                $oldBack = $template->back_path;
                $template->forceFill([
                    'back_path' => $newBack,
                    'qr_x' => $box['x'],
                    'qr_y' => $box['y'],
                    'qr_size' => $box['size'],
                ]);
            }
        } catch (UnreadableImageException $exception) {
            if ($newFront !== null) {
                $this->forget($newFront);
            }
            if ($newBack !== null) {
                $this->forget($newBack);
            }

            return back()->withErrors(['front' => $exception->getMessage()])->withInput($request->except(['front', 'back']));
        }

        $template->save();
        $this->forget($oldFront);
        $this->forget($oldBack);

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
     * @return array{x: int, y: int, size: int}|null
     */
    private function detectBox(string $path): ?array
    {
        $bytes = Storage::disk('public')->get($path);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            return null;
        }

        try {
            return $this->holes->inscribedSquare($image);
        } finally {
            unset($image);
        }
    }

    private function forget(?string $path): void
    {
        if ((string) $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}

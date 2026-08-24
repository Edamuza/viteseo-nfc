<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SitePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitePageController extends Controller
{
    public function index(): Response
    {
        $order = ['about', 'terms', 'privacy'];
        $pages = SitePage::query()
            ->whereIn('key', $order)
            ->get()
            ->sortBy(fn (SitePage $page) => array_search($page->key, $order, true))
            ->values();

        return response()->view('admin.site-pages', ['pages' => $pages])
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }

    public function update(Request $request, SitePage $sitePage): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string'],
        ]);

        $sitePage->update($data);

        return redirect()
            ->route('admin.site-pages.index')
            ->with('status', $sitePage->title.' was updated.');
    }
}

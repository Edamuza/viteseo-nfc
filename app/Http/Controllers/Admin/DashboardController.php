<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\CardGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private readonly CardGenerationService $cards) {}

    public function index(Request $request): mixed
    {
        $activeLinkCount = DB::raw(
            '(select count(*) from nfc_links where nfc_links.card_id = cards.id and nfc_links.is_active = 1) as active_link_count'
        );

        $query = Card::query()
            ->select('cards.*')
            ->addSelect($activeLinkCount)
            // Contact-mode status needs the name columns and nothing else.
            // Selecting a subset keeps a 25-row page at a constant query count
            // without hydrating whole profiles, including notes and addresses.
            ->with(['contactProfile:id,card_id,first_name,middle_name,last_name,suffix,company']);

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('card_number', 'like', '%'.$search.'%')
                  ->orWhere('public_id', 'like', '%'.$search.'%');
            });
        }

        $cards = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return response()->view('admin.dashboard', [
            'cards'  => $cards,
            'search' => $search,
            'stats'  => $this->stats(),
        ])->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }

    /** POST /admin/cards/generate */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CardGenerationService::MAX_PER_REQUEST],
        ], [
            'quantity.max' => 'You can generate at most '.CardGenerationService::MAX_PER_REQUEST.' cards in one request.',
        ]);

        $created = $this->cards->generate((int) $data['quantity']);

        return redirect()->route('admin.dashboard')->with(
            'status',
            'Generated '.$created->count().' card(s): '.$created->first()->card_number.' - '.$created->last()->card_number
        );
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $total = Card::count();

        $qrConfigured = Card::query()->whereQrConfigured()->count();

        $nfcConfigured = Card::query()->whereNfcModeConfigured()->count();

        $fullyConfigured = Card::query()
            ->whereQrConfigured()
            ->whereNfcModeConfigured()
            ->count();

        $fullyUnconfigured = Card::query()
            ->whereQrConfigured(false)
            ->whereNfcModeConfigured(false)
            ->count();

        return compact('total', 'qrConfigured', 'nfcConfigured', 'fullyConfigured', 'fullyUnconfigured');
    }
}

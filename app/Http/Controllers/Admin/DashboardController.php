<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\StickerTemplate;
use App\Services\CardGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Value => label, in the order the filter select shows them. */
    public const STATUS_FILTERS = [
        'all'          => 'All statuses',
        'full'         => 'Fully configured',
        'qr'           => 'QR configured',
        'nfc'          => 'NFC configured',
        'unconfigured' => 'Unconfigured',
    ];

    /**
     * Value => label, in the order the sort select shows them.
     *
     * There is no "by card number" option: numbers are assigned sequentially
     * at generation, so it would be a duplicate of "Oldest first".
     */
    public const SORTS = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
    ];

    public function __construct(private readonly CardGenerationService $cards) {}

    public function index(Request $request): mixed
    {
        $activeLinkCount = DB::raw(
            '(select count(*) from nfc_links where nfc_links.card_id = cards.id and nfc_links.is_active = 1) as active_link_count'
        );

        $query = Card::query()
            ->select('cards.*')
            ->addSelect($activeLinkCount)
            ->with([
                // Contact-mode status needs the name columns and nothing else.
                // Selecting a subset keeps a 25-row page at a constant query
                // count without hydrating whole profiles, including notes and
                // addresses.
                'contactProfile:id,card_id,first_name,middle_name,last_name,suffix,company',
                // Profile-mode status reads display_name alone.
                'profile:id,card_id,display_name',
            ]);

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('card_number', 'like', '%'.$search.'%')
                  ->orWhere('public_id', 'like', '%'.$search.'%');
            });
        }

        // Unknown values fall back to the default instead of erroring: filter
        // links are shared around and an admin may land here with a stale URL.
        $status = (string) $request->query('status', 'all');
        if (! array_key_exists($status, self::STATUS_FILTERS)) {
            $status = 'all';
        }

        match ($status) {
            'unconfigured' => $query->whereQrConfigured(false)->whereNfcModeConfigured(false),
            'qr'           => $query->whereQrConfigured(),
            'nfc'          => $query->whereNfcModeConfigured(),
            'full'         => $query->whereQrConfigured()->whereNfcModeConfigured(),
            default        => null,
        };

        $sort = (string) $request->query('sort', 'newest');
        if (! array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            default  => $query->orderByDesc('id'),
        };

        $cards = $query->paginate(25)->withQueryString();

        $numbers = Card::query()->pluck('card_number')
            ->map(function (string $number) {
                return preg_match('/^CARD-0*(\d+)$/i', $number, $matches) === 1 ? (int) $matches[1] : null;
            })
            ->filter()
            ->sort()
            ->values();

        return response()->view('admin.dashboard', [
            'cards'  => $cards,
            'search' => $search,
            'status' => $status,
            'sort'   => $sort,
            'stats'  => $this->stats(),
            'stickerTemplates' => StickerTemplate::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'stickerFrom' => $numbers->first() ?? 1,
            'stickerTo' => $numbers->last() ?? 1,
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

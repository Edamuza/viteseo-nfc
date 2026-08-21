<?php

namespace App\Console\Commands;

use App\Services\CardGenerationService;
use Illuminate\Console\Command;

class GenerateCards extends Command
{
    protected $signature = 'nfc:cards:generate {quantity=50 : How many cards to create}';

    protected $description = 'Generate unconfigured NFC/QR cards (same service the admin UI uses)';

    public function handle(CardGenerationService $cards): int
    {
        $quantity = (int) $this->argument('quantity');

        if ($quantity < 1 || $quantity > CardGenerationService::MAX_PER_REQUEST) {
            $this->error('Quantity must be between 1 and '.CardGenerationService::MAX_PER_REQUEST.'.');

            return self::FAILURE;
        }

        $created = $cards->generate($quantity);

        $this->info('Created '.$created->count().' card(s).');
        $this->line('  Range: '.$created->first()->card_number.' - '.$created->last()->card_number);
        $this->newLine();

        $this->table(
            ['Card', 'Public ID', 'QR URL', 'NFC URL'],
            $created->take(5)->map(fn ($c) => [$c->card_number, $c->public_id, $c->qrUrl(), $c->nfcUrl()])->all()
        );

        if ($created->count() > 5) {
            $this->line('  ... and '.($created->count() - 5).' more.');
        }

        $this->newLine();
        $this->comment('All cards start unconfigured. QR codes can be printed immediately -');
        $this->comment('their permanent URLs already exist.');

        return self::SUCCESS;
    }
}

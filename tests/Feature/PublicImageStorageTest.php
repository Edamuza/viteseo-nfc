<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Services\CardResetService;
use App\Services\CardGenerationService;
use App\Services\PublicImageStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A contact photo is served from a guessable /storage/... URL, so a file that
 * outlives the row pointing at it is still fetchable by anyone who saw that URL
 * while the card was live. On a re-issued card that is the previous holder's
 * face. These tests pin that such a failure is recorded rather than swallowed.
 */
class PublicImageStorageTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    /** A disk whose delete does nothing, as a full disk or a permission error would. */
    private function fakeUndeletableDisk(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(true);

        Storage::set('public', $disk);
    }

    public function test_a_deleted_photo_is_not_logged(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contact-photos/old.jpg', 'x');
        Log::spy();

        app(PublicImageStorage::class)->forget('contact-photos/old.jpg', $this->card());

        Storage::disk('public')->assertMissing('contact-photos/old.jpg');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_photo_that_survives_deletion_is_logged_with_its_path(): void
    {
        $card = $this->card();
        $this->fakeUndeletableDisk();
        Log::spy();

        app(PublicImageStorage::class)->forget('contact-photos/old.jpg', $card);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'publicly reachable')
                && $context['path'] === 'contact-photos/old.jpg'
                && $context['card'] === $card->public_id
        );
    }

    public function test_nothing_is_attempted_when_there_is_no_photo(): void
    {
        $this->fakeUndeletableDiskIsNotUsed();

        app(PublicImageStorage::class)->forget(null, $this->card());
        app(PublicImageStorage::class)->forget('', $this->card());

        $this->addToAssertionCount(1);
    }

    /** A disk that fails the test if it is touched at all. */
    private function fakeUndeletableDiskIsNotUsed(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldNotReceive('delete');

        Storage::set('public', $disk);
    }

    public function test_a_reset_whose_photo_delete_fails_still_reports_the_leftover_file(): void
    {
        $card = $this->card();
        $profile = $card->contactProfile()->make(['first_name' => 'John']);
        $profile->photo_path = 'contact-photos/old.jpg';
        $card->contactProfile()->save($profile);

        $this->fakeUndeletableDisk();
        Log::spy();

        app(CardResetService::class)->resetNfcSide($card);

        // The reset itself must still complete: the row is what the public page
        // reads, and leaving it in place to match the stuck file would keep the
        // previous holder's contact page live.
        $this->assertNull($card->fresh()->contactProfile);
        Log::shouldHaveReceived('error')->once();
    }
}

<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CardController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\NfcToolsController;
use App\Http\Controllers\Public\NfcController;
use App\Http\Controllers\Public\QrController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public card routes
|--------------------------------------------------------------------------
| These are the only routes a person who scans or taps a card ever reaches.
| They expose no card inventory, no admin surface and no internal IDs.
| Cards bind by public_id (see Card::getRouteKeyName), so an unknown or
| guessed id yields a plain 404.
*/

Route::get('/', fn () => response()->view('public.index'))->name('home');

// QR - exactly one destination, ever.
Route::get('/q/{card}',       [QrController::class, 'show'])->name('qr.show');
Route::post('/q/{card}',      [QrController::class, 'store'])->middleware('throttle:20,1')->name('qr.store');
Route::get('/q/{card}/done',  [QrController::class, 'done'])->name('qr.done');

// NFC - zero / one / many links.
Route::get('/n/{card}',       [NfcController::class, 'show'])->name('nfc.show');
Route::post('/n/{card}',      [NfcController::class, 'store'])->middleware('throttle:20,1')->name('nfc.store');
Route::get('/n/{card}/done',  [NfcController::class, 'done'])->name('nfc.done');

// Digital contact card download. Generated from the database on every request,
// so contact details can change without ever rewriting the physical NFC tag.
Route::get('/n/{card}/contact.vcf', [NfcController::class, 'vcard'])->name('nfc.vcard');

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
| No registration route exists. Accounts come from `php artisan nfc:admin:create`.
| Every state change is POST/PATCH/DELETE - there are no state-changing GETs.
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/cards/generate', [DashboardController::class, 'generate'])->name('cards.generate');

        Route::get('/nfc-tools', [NfcToolsController::class, 'index'])->name('nfc-tools');

        Route::post('/export/qr-zip',              [ExportController::class, 'qrZip'])->name('export.qr-zip');
        Route::post('/export/activation-manifest', [ExportController::class, 'activationManifest'])->name('export.activation');

        Route::prefix('cards/{card}')->name('cards.')->group(function () {
            Route::get('/', [CardController::class, 'show'])->name('show');

            Route::get('/qr.svg', [CardController::class, 'downloadSvg'])->name('qr.svg');
            Route::get('/qr.png', [CardController::class, 'downloadPng'])->name('qr.png');

            Route::patch('/qr',  [CardController::class, 'setQrDestination'])->name('qr.set');
            Route::delete('/qr', [CardController::class, 'resetQr'])->name('qr.reset');

            // NFC mode + per-mode payloads. Switching mode never deletes another
            // mode's data; each mode has its own explicit delete action.
            Route::patch('/nfc-mode', [CardController::class, 'setNfcMode'])->name('nfc.mode');

            Route::patch('/contact',        [CardController::class, 'updateContactProfile'])->name('contact.update');
            Route::delete('/contact',       [CardController::class, 'deleteContactProfile'])->name('contact.delete');
            Route::delete('/contact/photo', [CardController::class, 'deleteContactPhoto'])->name('contact.photo.delete');

            Route::patch('/profile',  [CardController::class, 'updateProfile'])->name('profile.update');
            Route::delete('/profile', [CardController::class, 'deleteProfile'])->name('profile.delete');

            Route::patch('/phone',  [CardController::class, 'updatePhone'])->name('phone.update');
            Route::delete('/phone', [CardController::class, 'deletePhone'])->name('phone.delete');

            Route::patch('/email',  [CardController::class, 'updateEmail'])->name('email.update');
            Route::delete('/email', [CardController::class, 'deleteEmail'])->name('email.delete');

            Route::post('/nfc-links',                    [CardController::class, 'addNfcLink'])->name('nfc.add');
            Route::patch('/nfc-links/{link}',            [CardController::class, 'updateNfcLink'])->name('nfc.update');
            Route::delete('/nfc-links/{link}',           [CardController::class, 'deleteNfcLink'])->name('nfc.delete');
            Route::patch('/nfc-links/{link}/move',       [CardController::class, 'moveNfcLink'])->name('nfc.move');
            Route::delete('/nfc',                        [CardController::class, 'resetNfc'])->name('nfc.reset');

            // No PIN regeneration route: every card uses the universal PIN.
            Route::post('/pin/reveal', [CardController::class, 'revealPin'])->name('pin.reveal');

            Route::delete('/reset', [CardController::class, 'resetCard'])->name('reset');
        });
    });
});

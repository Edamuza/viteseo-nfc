# viteseo-nfc

NFC card platform built with Laravel. It has a public side and an admin side, and manages NFC cards, the links and profiles behind them, and the sticker templates used for printing.

## Tech stack

- PHP 8.3 and Laravel 13
- Tailwind CSS 4 and Vite
- `bacon/bacon-qr-code` for QR codes
- PHPUnit 12 for tests, Laravel Pint for code style

## What is in the data model

The migrations and models in `app/Models` cover:

- Cards, including an NFC mode and an activation PIN (with a personalized-at timestamp)
- NFC links, with an icon for each link
- NFC profiles, with contact details, a call to action and a cover image
- NFC contact profiles
- A history of profile edits
- Site pages managed from the admin
- Sticker templates
- Users

Controllers are split into `app/Http/Controllers/Admin` and `app/Http/Controllers/Public`, and views follow the same split under `resources/views`.

## Getting started

You need PHP 8.3 or newer, Composer, Node.js and a database.

```bash
composer install
cp .env.example .env
php artisan key:generate
# set your database in .env, then:
php artisan migrate
npm install
npm run dev            # Vite dev server
php artisan serve      # in a second terminal
```

For a production build, run `npm run build`.

## Testing and code style

```bash
php artisan test
./vendor/bin/pint
```

## Project structure

```
app/         Models, controllers, services, enums and rules
config/      Application configuration
database/    Migrations, factories and seeders
resources/   Blade views, CSS and JavaScript
routes/      Route definitions
public/      Web root and built assets
tests/       PHPUnit tests
```

## Changelog

See `CHANGELOG.md` for the history of changes.

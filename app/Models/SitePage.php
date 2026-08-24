<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SitePage extends Model
{
    protected $fillable = ['title', 'body'];

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        return array_values(array_filter(
            preg_split('/\R[ \t]*\R+/', trim((string) $this->body)) ?: [],
            fn (string $paragraph) => $paragraph !== ''
        ));
    }
}

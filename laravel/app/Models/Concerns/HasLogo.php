<?php

namespace App\Models\Concerns;

use App\Services\LogoStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Exposes 'logo_url' (read-only, same field name as before so the frontend's
 * display code is unchanged) computed from the server-managed 'logo_path'.
 * 'logo_path' is deliberately not fillable: it can only be set through the
 * upload endpoints, never through a regular create/update payload.
 */
trait HasLogo
{
    public function initializeHasLogo(): void
    {
        $this->append('logo_url');
        $this->makeHidden('logo_path');
    }

    public static function bootHasLogo(): void
    {
        static::deleted(function (Model $model) {
            app(LogoStorage::class)->deleteFile($model->logo_path);
        });
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
        );
    }
}

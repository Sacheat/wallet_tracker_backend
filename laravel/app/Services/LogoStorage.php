<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Upload pipeline for shared-catalog logos (assets and platforms), modeled
 * on AvatarController but stricter: a logo is shown to every user, not
 * just its uploader.
 */
class LogoStorage
{
    /**
     * 'image' + 'mimes' check the actual file content, not the extension.
     * Raster formats only: SVG can carry script, and GIF adds nothing for a
     * logo. 512 KB / 1024 px is plenty for an icon displayed at ~32 px.
     */
    public const RULES = ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=1024,max_height=1024'];

    public function replace(Model $model, UploadedFile $file): Model
    {
        $previous = $model->logo_path;

        // ->store() generates a random filename and derives the extension
        // from the detected content type — the uploaded name is never used.
        $model->logo_path = $file->store('logos', 'public');
        $model->save();

        // Deleted only once the new path is saved, so a failed save never
        // leaves the record pointing at a missing file.
        $this->deleteFile($previous);

        return $model;
    }

    public function remove(Model $model): void
    {
        $previous = $model->logo_path;

        if ($previous) {
            $model->logo_path = null;
            $model->save();
            $this->deleteFile($previous);
        }
    }

    public function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}

<?php

namespace App\Actions\Contributions;

use App\Models\Contribution;
use App\Models\ContributionMedia;
use App\Support\Images;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts only images that actually decode as JPEG, PNG or WebP (the extension and the browser's MIME type
 * are not trusted), then re-encodes them to WebP: this strips EXIF data including GPS position.
 * Files live on the private disk and are served through an authorized endpoint.
 */
class StoreContributionPhoto
{
    public function __invoke(Contribution $c, UploadedFile $file, string $alt): ContributionMedia
    {
        $img = Images::reencode($file);

        $path = "contributions/{$c->id}/".Str::uuid().'.webp';
        $disk = Images::disk();
        Storage::disk($disk)->put($path, $img['bytes']);

        return $c->media()->create(['disk' => $disk, 'path' => $path, 'width' => $img['width'], 'height' => $img['height'], 'size_bytes' => strlen($img['bytes']), 'alt' => trim($alt)]);
    }
}

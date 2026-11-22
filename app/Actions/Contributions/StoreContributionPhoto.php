<?php

namespace App\Actions\Contributions;

use App\Models\Contribution;
use App\Models\ContributionMedia;
use App\Support\Images;
use Illuminate\Http\UploadedFile;

/**
 * Accepts only images that actually decode as JPEG, PNG or WebP (the extension and the browser's MIME type
 * are not trusted), then re-encodes them to WebP: this strips EXIF data including GPS position.
 * Files live on the private disk and are served through an authorized endpoint.
 */
class StoreContributionPhoto
{
    public function __invoke(Contribution $c, UploadedFile $file, string $alt): ContributionMedia
    {
        $stored = Images::store($file, "contributions/{$c->id}");
        try {
            return $c->media()->create(['disk' => $stored['disk'], 'path' => $stored['path'], 'width' => $stored['width'], 'height' => $stored['height'], 'size_bytes' => $stored['bytes_size'], 'alt' => trim($alt)]);
        } catch (\Throwable $e) {
            Images::discard($stored); // no file without its row

            throw $e;
        }
    }
}

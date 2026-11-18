<?php

namespace App\Actions\Contributions;

use App\Models\Contribution;
use App\Models\ContributionMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accepts only images that actually decode as JPEG, PNG or WebP (the extension and the browser's MIME type
 * are not trusted), then re-encodes them to WebP: this strips EXIF data including GPS position.
 * Files live on the private disk and are served through an authorized endpoint.
 */
class StoreContributionPhoto
{
    public function __invoke(Contribution $c, UploadedFile $file, string $alt): ContributionMedia
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['photo' => ['Upload a JPEG, PNG or WebP photo.']]);
        }
        [$w, $h] = $info;
        if ($w < 200 || $h < 200 || $w * $h > 40_000_000) {
            throw ValidationException::withMessages(['photo' => ['Photos must be at least 200 pixels on each side and under 40 megapixels.']]);
        }
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages(['photo' => ['This photo could not be read.']]);
        }
        $max = config('mrn.uploads.max_edge');
        $scale = min(1, $max / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagewebp($dst, null, 80);
        $bytes = (string) ob_get_clean();

        $path = "contributions/{$c->id}/".Str::uuid().'.webp';
        Storage::disk('local')->put($path, $bytes);

        return $c->media()->create(['path' => $path, 'width' => $nw, 'height' => $nh, 'alt' => trim($alt)]);
    }
}

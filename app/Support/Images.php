<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * One image pipeline for every upload: decode as a real image of an allowed type, check dimensions,
 * scale down and re-encode to WebP. Re-encoding drops EXIF/GPS and anything hidden in the original file.
 */
class Images
{
    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    /** @return array{bytes: string, width: int, height: int} */
    public static function reencode(UploadedFile $file, string $field = 'photo'): array
    {
        $maxKb = min((int) Settings::get('storage.max_upload_kb'), (int) config('mrn.uploads.max_kb'));
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([$field => ["Images can be up to {$maxKb} KB."]]);
        }
        $allowed = array_intersect_key(self::TYPES, array_flip(Settings::get('storage.image_types')));
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], $allowed, true)) {
            $names = implode(', ', array_map(fn ($m) => strtoupper(substr($m, 6)), array_keys($allowed)));
            throw ValidationException::withMessages([$field => ["Upload a {$names} image."]]);
        }
        [$w, $h] = $info;
        if ($w < 200 || $h < 200 || $w * $h > 40_000_000) {
            throw ValidationException::withMessages([$field => ['Images must be at least 200 pixels on each side and under 40 megapixels.']]);
        }
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages([$field => ['This image could not be read.']]);
        }
        $scale = min(1, config('mrn.uploads.max_edge') / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagewebp($dst, null, 80);

        return ['bytes' => (string) ob_get_clean(), 'width' => $nw, 'height' => $nh];
    }

    /** The Laravel disk for new files. Only `local` exists until a storage provider is chosen (decision D1). */
    public static function disk(): string
    {
        return Settings::get('storage.driver');
    }
}

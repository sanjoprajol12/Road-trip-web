<?php

namespace Tests\Feature;

use App\Support\Images;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Session 22 production-readiness audit: behaviours that had no automated proof before. */
class SecurityPostureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_response_carries_the_security_headers_and_hsts_only_over_https(): void
    {
        foreach (['/api/v1/home', '/api/v1/trips', '/api/v1/no-such-route'] as $url) {
            $r = $this->getJson($url);
            $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'same-origin')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            $this->assertFalse($r->headers->has('Strict-Transport-Security'), "{$url}: no HSTS over plain HTTP");
        }
        $this->getJson('https://localhost/api/v1/home')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_production_errors_say_nothing_about_the_cause(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/__audit-boom', fn () => throw new \RuntimeException('db password is hunter2 at /var/www/secret.php'));
        $r = $this->getJson('/api/v1/__audit-boom')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('hunter2', $r->getContent());
        $this->assertStringNotContainsString('secret.php', $r->getContent());
    }

    public function test_uploaded_photos_lose_their_metadata_including_gps(): void
    {
        // A real JPEG with an EXIF (APP1) segment carrying a GPS-like marker, inserted right after the SOI marker.
        $img = imagecreatetruecolor(320, 240);
        imagefilledrectangle($img, 0, 0, 319, 239, imagecolorallocate($img, 40, 120, 200));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0GPSLatitude=28.2096 GPSLongitude=83.9856 Owner=private-rider";
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
        $this->assertStringContainsString('GPSLatitude', (string) file_get_contents($path), 'the fixture really carries the metadata');

        $out = Images::reencode(new UploadedFile($path, 'holiday.jpg', 'image/jpeg', null, true));
        $this->assertStringStartsWith('RIFF', $out['bytes']);
        $this->assertStringNotContainsString('GPSLatitude', $out['bytes']);
        $this->assertStringNotContainsString('private-rider', $out['bytes']);
        $this->assertStringNotContainsString('Exif', $out['bytes']);
        @unlink($path);
    }
}

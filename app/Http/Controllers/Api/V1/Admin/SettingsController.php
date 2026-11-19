<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** One route per settings group (routes/api.php), each behind its own settings.<group>.manage permission. */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => Settings::group($request->route('group'))]);
    }

    public function update(Request $request): JsonResponse
    {
        $group = $request->route('group');
        Settings::update($group, $request->except('group'), $request->user());
        if ($group === 'mail') {
            \Illuminate\Support\Facades\Artisan::call('queue:restart'); // long-running workers pick up the new mail settings
        }

        return response()->json(['data' => Settings::group($group)]);
    }

    /** Writes, reads back and deletes a small probe file on the configured disk, and reports usage. */
    public function testStorage(): JsonResponse
    {
        $disk = Settings::get('storage.driver');
        $probe = 'probes/'.Str::uuid().'.txt';
        $payload = 'my-ride-nepal storage probe '.now()->toIso8601String();
        try {
            Storage::disk($disk)->put($probe, $payload);
            $ok = Storage::disk($disk)->get($probe) === $payload;
            Storage::disk($disk)->delete($probe);
            $error = $ok ? null : 'The file read back did not match what was written.';
        } catch (Throwable $e) {
            $ok = false;
            $error = 'The storage could not be written or read: '.class_basename($e).'.'; // no paths or credentials
        }
        Audit::record('settings.storage.tested', 'settings', null, ['disk' => $disk, 'ok' => $ok], 'storage');

        return response()->json(['ok' => $ok, 'disk' => $disk, 'error' => $error, 'usage' => [
            'library' => ['files' => MediaAsset::count(), 'bytes' => (int) MediaAsset::sum('size_bytes')],
            'rider_photos' => ['files' => DB::table('contribution_media')->count(), 'bytes' => (int) DB::table('contribution_media')->sum('size_bytes')],
            'by_disk' => DB::table('media_assets')->select('disk', DB::raw('count(*) as files'))->groupBy('disk')->pluck('files', 'disk')
                ->mergeRecursive(DB::table('contribution_media')->select('disk', DB::raw('count(*) as files'))->groupBy('disk')->pluck('files', 'disk')),
        ]], $ok ? 200 : 502);
    }

    /** Opens and closes an SMTP connection with the current mail settings (or .env when override is off). */
    public function testMailConnection(): JsonResponse
    {
        try {
            \App\Support\MailSettings::apply(); // the settings saved just now
            app('mail.manager')->forgetMailers();
            $transport = \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport();
            if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
                $transport->start();
                $transport->stop();
                [$ok, $message] = [true, 'The mail server accepted the connection.'];
            } else {
                [$ok, $message] = [false, 'The current mailer ('.config('mail.default').') does not send over SMTP, so there is no connection to test.'];
            }
        } catch (Throwable $e) {
            [$ok, $message] = [false, 'The mail server could not be reached or refused the sign-in: '.class_basename($e).'.'];
        }
        Audit::record('settings.mail.tested', 'settings', null, ['ok' => $ok, 'kind' => 'connection'], 'mail');

        return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 502);
    }

    /** Sends a test email to the signed-in staff member only (never to an address typed in: no open relay). */
    public function sendTestMail(Request $request): JsonResponse
    {
        $to = $request->user()->email;
        try {
            \App\Support\MailSettings::apply();
            app('mail.manager')->forgetMailers();
            \Illuminate\Support\Facades\Mail::raw('This is a test email from the My Ride Nepal staff portal. If you can read it, outgoing mail works.', fn ($m) => $m->to($to)->subject('My Ride Nepal test email'));
            [$ok, $message] = [true, "Test email sent to {$to}. Check that it arrived."];
        } catch (Throwable $e) {
            [$ok, $message] = [false, 'The test email could not be sent: '.class_basename($e).'.'];
        }
        Audit::record('settings.mail.tested', 'settings', null, ['ok' => $ok, 'kind' => 'email'], 'mail');

        return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 502);
    }

    public function testProvider(Request $request): JsonResponse
    {
        $which = $request->validate(['which' => ['required', 'in:geocoding,routing']])['which'];
        $result = \App\Support\ProviderProbe::run($which);
        Audit::record('settings.maps.tested', 'settings', null, ['which' => $which, 'ok' => $result['ok'], 'status' => $result['status']], 'maps');

        return response()->json($result);
    }

    public function clearCache(): JsonResponse
    {
        Settings::forget();
        Audit::record('system.cache_cleared', 'settings', null, ['scope' => 'settings'], 'cache');

        return response()->json(['message' => 'Settings cache cleared.']);
    }
}

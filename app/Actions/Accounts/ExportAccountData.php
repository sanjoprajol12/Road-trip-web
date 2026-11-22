<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The rider's own data as one ZIP (design §5), built in a temporary file and streamed once in the response: no export
 * is stored, so there is no download URL to guess or expire. Holds only what the rider can already see in the app.
 * Other riders appear by display name only, and never with their emails, emergency contacts or private notes of
 * their own. Staff-only notes, audit logs and other riders' reports are left out.
 */
final class ExportAccountData
{
    /** @return string path of the temporary ZIP (the caller deletes it after sending) */
    public function build(User $u): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mrn-export-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
        $json = fn (string $name, $data) => $zip->addFromString($name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $rows = fn ($q) => $q->get()->map(fn ($r) => (array) $r)->all();

        $json('profile.json', [
            'account' => $u->only(['id', 'display_name', 'username', 'email', 'bio', 'home_city']) + [
                'email_confirmed_at' => $u->email_verified_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String(),
                'suspended_until' => $u->suspended_until?->toIso8601String(), 'suspension_reason' => $u->suspension_reason,
                'deletion_scheduled_for' => $u->deletion_scheduled_for?->toIso8601String(),
            ],
            'roles' => $rows(DB::table('user_roles')->where('user_id', $u->id)->select('role', 'granted_at')),
            'verifications' => $rows(DB::table('user_verifications')->where('user_id', $u->id)->select('kind', 'scope', 'status', 'statement', 'review_note', 'reviewed_at', 'created_at')),
            'contribution_pauses' => $rows(DB::table('contribution_suspensions')->where('user_id', $u->id)->select('until', 'reason', 'lifted_at', 'lift_note', 'appeal', 'appealed_at', 'appeal_decision', 'created_at')),
        ]);

        $contributions = DB::table('contributions')->where('author_id', $u->id)->orderBy('created_at')
            ->select('id', 'kind', 'title', 'body', 'observed_on', 'status', 'details', 'source_url', 'moderation_note', 'correction_request', 'author_correction', 'author_corrected_at', 'created_at', 'updated_at')->get();
        $photos = DB::table('contribution_media')->whereIn('contribution_id', $contributions->pluck('id'))->orderBy('created_at')->get();
        $json('contributions.json', $contributions->map(fn ($c) => ['details' => json_decode($c->details, true)] + (array) $c + [
            'photos' => $photos->where('contribution_id', $c->id)->map(fn ($m) => ['file' => "photos/{$m->id}.webp", 'alt' => $m->alt])->values()->all(),
        ])->all());
        foreach ($photos as $m) {
            $bytes = rescue(fn () => Storage::disk($m->disk)->get($m->path), null, false);
            if (is_string($bytes)) {
                $zip->addFromString("photos/{$m->id}.webp", $bytes);
            }
        }
        $json('confirmations.json', $rows(DB::table('contribution_confirmations as cc')->join('contributions as c', 'c.id', '=', 'cc.contribution_id')
            ->where('cc.user_id', $u->id)->select('cc.confirmed_on', 'c.title as contribution_title')));
        $json('reports_filed.json', $rows(DB::table('contribution_reports as r')->join('contributions as c', 'c.id', '=', 'r.contribution_id')
            ->where('r.reporter_id', $u->id)->select('r.category', 'r.reason', 'r.created_at', 'r.resolved_at', 'r.resolution', 'c.title as contribution_title')));

        $tripIds = DB::table('trip_members')->where('user_id', $u->id)->pluck('trip_id');
        $json('trips.json', DB::table('trips')->whereIn('id', $tripIds)->orderBy('starts_on')->get()->map(fn ($t) => [
            'title' => $t->title, 'starts_on' => $t->starts_on, 'ends_on' => $t->ends_on, 'motorcycle' => $t->motorcycle, 'notes' => $t->notes,
            'your_role' => DB::table('trip_members')->where(['trip_id' => $t->id, 'user_id' => $u->id])->value('role'),
            'members' => DB::table('trip_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.trip_id', $t->id)->select('u.display_name', 'm.role')->get(),
            'stops' => DB::table('trip_stops')->where('trip_id', $t->id)->orderBy('position')->get(['position', 'day', 'name', 'overnight', 'note', 'route_part']),
            'costs' => DB::table('trip_expenses as e')->leftJoin('users as p', 'p.id', '=', 'e.paid_by')->where('e.trip_id', $t->id)
                ->get(['e.label', 'e.category', 'e.amount_npr', 'e.status', 'p.display_name as paid_by']),
            'checklist' => DB::table('trip_checklist_items')->where('trip_id', $t->id)->orderBy('section')->orderBy('position')->get(['section', 'label', 'done']),
        ])->all());

        $json('events.json', [
            'organized' => $rows(DB::table('events')->where('organizer_id', $u->id)->orderBy('starts_at')
                ->select('title', 'category', 'subtype', 'description', 'starts_at', 'ends_at', 'meeting_point', 'visibility', 'join_method', 'capacity', 'status', 'summary', 'created_at')),
            'registrations' => $rows(DB::table('event_registrations as r')->join('events as e', 'e.id', '=', 'r.event_id')->where('r.user_id', $u->id)
                ->select('e.title', 'e.starts_at', 'r.status', 'r.created_at')),
        ]);
        $json('places.json', [
            'suggested' => $rows(DB::table('places')->where('submitted_by', $u->id)->select('name', 'category', 'summary', 'description', 'latitude', 'longitude', 'status', 'moderation_note', 'created_at')),
            'corrections' => $rows(DB::table('place_corrections as pc')->join('places as p', 'p.id', '=', 'pc.place_id')->where('pc.user_id', $u->id)
                ->select('p.name as place', 'pc.body', 'pc.latitude', 'pc.longitude', 'pc.status', 'pc.review_note', 'pc.created_at')),
        ]);
        $json('saved.json', $rows(DB::table('saved_items')->where('user_id', $u->id)->select('item_type', 'item_id', 'created_at')));
        $json('emergency_contacts.json', $rows(DB::table('emergency_contacts')->where('user_id', $u->id)->select('name', 'phone', 'relation', 'created_at')));
        $json('notifications.json', DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $u->id)->orderBy('created_at')
            ->get(['data', 'read_at', 'created_at'])->map(fn ($n) => ['data' => json_decode($n->data, true), 'read_at' => $n->read_at, 'created_at' => $n->created_at])->all());
        $json('sessions.json', $rows(DB::table('sessions')->where('user_id', $u->id)->select('ip_address', 'user_agent', 'last_activity')));
        $zip->addFromString('README.txt', implode("\n", [
            'Your data from My Ride Nepal, exported '.now()->toIso8601String().'.',
            '',
            'profile.json             your account, roles, verification claims and any contribution pauses',
            'contributions.json       your reports and experiences, with staff notes shown to you; photos/ holds their images',
            'confirmations.json       reports you confirmed as still accurate',
            'reports_filed.json       contributions you reported to moderators',
            'trips.json               trips you belong to; other members appear by display name only',
            'events.json              events you organize and events you signed up for',
            'places.json              places you suggested and corrections you sent',
            'saved.json               your saved items',
            'emergency_contacts.json  the emergency contacts you entered',
            'notifications.json       notifications sent to you in the app',
            'sessions.json            devices currently signed in',
            '',
            'Not included: audit logs, staff-only notes, and other riders\' private data.',
        ])."\n");
        $zip->close();

        return $path;
    }
}

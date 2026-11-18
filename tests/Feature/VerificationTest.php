<?php

namespace Tests\Feature;

use App\Enums\VerificationKind;
use App\Models\AuditLog;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ported from the Supabase-era RLS suite (supabase/tests/rls.test.sql), plus API-level checks. */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    private function apply($user, string $kind = 'event_organizer', ?string $scope = null)
    {
        return $this->actingAs($user)->postJson('/api/v1/users/me/verifications', array_filter([
            'kind' => $kind, 'scope' => $scope, 'statement' => 'I have organised weekend rides around Kathmandu for three years.',
        ]));
    }

    public function test_rider_applies_once_per_claim_and_it_starts_pending(): void
    {
        $rider = $this->rider();
        $this->apply($rider)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.reviewed_by');
        $this->apply($rider)->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->assertFalse($rider->hasVerification(VerificationKind::EventOrganizer), 'pending grants nothing');
    }

    public function test_application_input_is_validated(): void
    {
        $rider = $this->rider();
        $this->actingAs($rider)->postJson('/api/v1/users/me/verifications', ['kind' => 'identity', 'statement' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['kind', 'statement']);
        $this->apply($rider, 'expert_reviewer')->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->apply($rider, 'trip_contributor', 'medical')->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->apply($rider, 'expert_reviewer', 'mechanical')->assertCreated();
        $this->apply($rider, 'expert_reviewer', 'medical')->assertCreated();
    }

    public function test_unverified_or_suspended_riders_cannot_apply(): void
    {
        $this->apply($this->rider(['email_verified_at' => null]))->assertStatus(403);
        $this->apply($this->rider(['suspended_until' => now()->addDay()]))->assertStatus(403);
    }

    public function test_riders_cannot_reach_review_endpoints_or_others_claims(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $claim = $this->apply($other)->json('data.id');
        $this->actingAs($rider)->getJson('/api/v1/admin/verifications')->assertForbidden();
        $this->actingAs($rider)->postJson("/api/v1/admin/verifications/{$claim}/decision", ['status' => 'approved'])->assertForbidden();
        $this->actingAs($rider)->getJson('/api/v1/users/me/verifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_moderators_decide_trip_contributor_claims_but_not_organizer_or_expert_claims(): void
    {
        $mod = $this->staff('moderator');
        $rider = $this->rider();
        $org = $this->apply($rider)->json('data.id');
        $trip = $this->apply($rider, 'trip_contributor')->json('data.id');
        $this->actingAs($mod)->postJson("/api/v1/admin/verifications/{$org}/decision", ['status' => 'approved'])->assertForbidden();
        $this->actingAs($mod)->getJson("/api/v1/admin/verifications/{$org}")->assertOk()->assertJsonPath('data.can_decide', false);
        $this->actingAs($mod)->postJson("/api/v1/admin/verifications/{$trip}/decision", ['status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($rider->hasVerification(VerificationKind::TripContributor));
        $this->assertFalse($rider->hasVerification(VerificationKind::EventOrganizer), 'one approval never implies another');
    }

    public function test_admin_decisions_follow_the_state_machine_and_need_new_reasons(): void
    {
        $admin = $this->staff('admin');
        $rider = $this->rider();
        $id = $this->apply($rider)->json('data.id');
        $decide = fn (array $body) => $this->actingAs($admin)->postJson("/api/v1/admin/verifications/{$id}/decision", $body);

        $decide(['status' => 'revoked', 'review_note' => 'Jumping straight to revoked.'])->assertStatus(422)->assertJsonValidationErrors('status');
        $decide(['status' => 'rejected', 'review_note' => 'too short'])->assertStatus(422)->assertJsonValidationErrors('review_note');
        $decide(['status' => 'approved', 'review_note' => 'Checked ride history.'])->assertOk();
        $this->assertTrue($rider->hasVerification(VerificationKind::EventOrganizer));
        $decide(['status' => 'suspended', 'review_note' => 'Checked ride history.'])->assertStatus(422)->assertJsonValidationErrors('review_note');
        $decide(['status' => 'suspended', 'review_note' => 'Report of unsafe group riding; reviewing.'])->assertOk();
        $this->assertFalse($rider->hasVerification(VerificationKind::EventOrganizer), 'suspension removes rights immediately');
        $decide(['status' => 'approved'])->assertOk(); // reinstate

        $claim = Verification::find($id);
        $this->assertSame($admin->id, $claim->reviewed_by);
        $this->assertSame(4, AuditLog::where('subject_type', 'user_verifications')->where('subject_id', $id)->count(), 'applied + 3 decisions');
        // The rider sees the decision and the reason, never the reviewer.
        $this->actingAs($rider)->getJson('/api/v1/users/me/verifications')->assertOk()
            ->assertJsonPath('data.0.review_note', 'Report of unsafe group riding; reviewing.')->assertJsonMissingPath('data.0.reviewed_by');
        $this->actingAs($rider)->getJson('/api/v1/users/me/notifications')->assertOk()->assertJsonPath('unread', 3);
    }

    public function test_nobody_reviews_their_own_claim(): void
    {
        $admin = $this->staff('admin');
        $id = $this->apply($admin, 'expert_reviewer', 'mechanical')->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/admin/verifications/{$id}/decision", ['status' => 'approved'])->assertForbidden();
    }

    public function test_reviewers_see_history_and_the_queue(): void
    {
        $mod = $this->staff('moderator');
        $id = $this->apply($this->rider())->json('data.id');
        $this->actingAs($mod)->getJson('/api/v1/admin/verifications')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($mod)->getJson("/api/v1/admin/verifications/{$id}")->assertOk()->assertJsonPath('history.0.action', 'verification.applied');
    }
}

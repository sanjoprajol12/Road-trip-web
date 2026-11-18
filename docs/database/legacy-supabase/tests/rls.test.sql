-- Authorization tests. Every block either asserts a visible result or expects a refusal.
-- The whole file is ONE transaction that ends in ROLLBACK, so it is safe to run against the
-- local Supabase database (pnpm test:db:local) as well as a throwaway container (pnpm test:db).
-- Scenarios are savepoints: "rollback to" undoes one, "release" keeps its changes for later ones.
-- Any failure stops the script (ON_ERROR_STOP).

\o /dev/null
begin;
create schema tests;
grant usage on schema tests to anon, authenticated;
create function tests.uid(who text) returns uuid language sql immutable as $$
  select (case who when 'rider' then '11111111-1111-4111-8111-111111111111'
                   when 'rider2' then '22222222-2222-4222-8222-222222222222'
                   when 'moderator' then '33333333-3333-4333-8333-333333333333'
                   when 'admin' then '44444444-4444-4444-8444-444444444444' end)::uuid
$$;
create function tests.login(who text) returns void language sql as $$
  select set_config('role', case when who = 'anon' then 'anon' else 'authenticated' end, true),
         set_config('request.jwt.claims', case when who = 'anon' then '' else json_build_object('sub', tests.uid(who))::text end, true)
$$;
grant execute on all functions in schema tests to anon, authenticated;

insert into auth.users (id, email, raw_user_meta_data) values
  (tests.uid('rider'), 'rider@example.test', '{"display_name":"Test Rider"}'),
  (tests.uid('rider2'), 'rider2@example.test', '{}'),
  (tests.uid('moderator'), 'mod@example.test', '{}'),
  (tests.uid('admin'), 'admin@example.test', '{}');
insert into public.user_roles (user_id, role) values
  (tests.uid('moderator'), 'moderator'), (tests.uid('admin'), 'admin');
insert into public.destinations (slug, name, status) values ('hidden-draft', 'Hidden Draft', 'draft');

-- What each audience should see, computed up front so existing dev data cannot skew the counts.
create table tests.expected as select
  (select count(*) from public.destinations where status = 'published' and deleted_at is null) as published,
  (select count(*) from public.destinations) as all_destinations,
  (select count(*) from public.profiles where deleted_at is null) as profiles,
  (select count(*) from public.user_verifications) as verifications;
grant select on tests.expected to anon, authenticated;

-- ---------------------------------------------------------------- signup trigger
do $$ begin
  assert (select display_name from public.profiles where id = tests.uid('rider')) = 'Test Rider', 'profile takes display_name from metadata';
  assert (select display_name from public.profiles where id = tests.uid('rider2')) = 'rider2', 'profile falls back to email name';
end $$;

-- ---------------------------------------------------------------- guests
savepoint s;
select tests.login('anon');
do $$ begin
  assert (select count(*) from public.destinations) = (select published from tests.expected), 'guest sees published destinations only, not the draft';
  assert (select count(*) from public.profiles) = (select profiles from tests.expected), 'guest can read public profiles';
end $$;
do $$ begin
  perform 1 from public.user_roles;
  raise exception 'FAIL: guest read staff roles';
exception when insufficient_privilege then null; end $$;
do $$ begin
  insert into public.destinations (slug, name) values ('x', 'Guest Insert');
  raise exception 'FAIL: guest inserted a destination';
exception when insufficient_privilege then null; end $$;
do $$ begin
  perform 1 from public.audit_logs;
  raise exception 'FAIL: guest read the audit log';
exception when insufficient_privilege then null; end $$;
rollback to savepoint s;

-- ---------------------------------------------------------------- riders
savepoint s;
select tests.login('rider');
do $$
declare n int;
begin
  update public.profiles set display_name = 'Renamed' where id = tests.uid('rider2');
  get diagnostics n = row_count;
  assert n = 0, 'rider cannot edit another profile';
  update public.profiles set display_name = 'Own Name', home_city = 'Pokhara' where id = tests.uid('rider');
  get diagnostics n = row_count;
  assert n = 1, 'rider can edit own profile';
end $$;
do $$ begin
  update public.profiles set deleted_at = now() where id = tests.uid('rider');
  raise exception 'FAIL: rider wrote a protected profile column';
exception when insufficient_privilege then null; end $$;
do $$ begin
  insert into public.user_roles (user_id, role) values (tests.uid('rider'), 'admin');
  raise exception 'FAIL: rider granted themselves admin';
exception when insufficient_privilege then null; end $$;
do $$ begin
  insert into public.user_verifications (user_id, kind, statement)
  values (tests.uid('rider'), 'event_organizer', 'I have organised weekend rides around Kathmandu for three years.');
end $$;
do $$ begin
  insert into public.user_verifications (user_id, kind, statement)
  values (tests.uid('rider2'), 'event_organizer', 'Applying on behalf of somebody else entirely here.');
  raise exception 'FAIL: rider applied for another user';
exception when insufficient_privilege then null; end $$;
do $$ begin
  insert into public.user_verifications (user_id, kind, statement)
  values (tests.uid('rider'), 'event_organizer', 'A second open application for the same claim.');
  raise exception 'FAIL: two open applications for one claim';
exception when unique_violation then null; end $$;
do $$ begin
  insert into public.user_verifications (user_id, kind, statement, status)
  values (tests.uid('rider'), 'identity', 'Trying to skip the review step by approving myself.', 'approved');
  raise exception 'FAIL: rider self-approved';
exception when insufficient_privilege then null; end $$;
do $$
declare n int;
begin
  update public.user_verifications set status = 'approved' where user_id = tests.uid('rider');
  get diagnostics n = row_count;
  assert n = 0, 'rider cannot approve own application';
  assert not public.has_verification('event_organizer'), 'pending application grants nothing';
  assert not public.is_staff(), 'rider is not staff';
  assert (select count(*) from public.destinations) = (select published from tests.expected), 'rider does not see drafts';
end $$;
release savepoint s;

savepoint s;
select tests.login('rider2');
do $$ begin
  assert (select count(*) from public.user_verifications) = 0, 'rider2 cannot see rider''s application';
end $$;
rollback to savepoint s;

-- ---------------------------------------------------------------- moderators
savepoint s;
select tests.login('moderator');
do $$
declare n int;
begin
  assert public.is_staff() and not public.is_admin(), 'moderator is staff, not admin';
  assert (select count(*) from public.user_verifications) = (select verifications from tests.expected) + 1, 'moderator sees the whole queue';
  assert (select count(*) from public.destinations) = (select all_destinations from tests.expected), 'moderator sees drafts';
  update public.user_verifications set status = 'approved' where kind = 'event_organizer' and user_id = tests.uid('rider');
  get diagnostics n = row_count;
  assert n = 0, 'moderator cannot decide organizer applications';
end $$;
do $$ begin
  perform 1 from public.audit_logs where table_name <> 'user_verifications' limit 1;
  assert not found, 'moderator sees no audit rows outside verification history';
end $$;
rollback to savepoint s;

-- ---------------------------------------------------------------- admins
savepoint s;
select tests.login('admin');
do $$ begin
  update public.user_verifications set status = 'revoked' where kind = 'event_organizer' and user_id = tests.uid('rider');
  raise exception 'FAIL: pending jumped straight to revoked';
exception when check_violation then null; end $$;
do $$
declare n int;
begin
  update public.user_verifications set status = 'approved', review_note = 'Checked ride history.' where kind = 'event_organizer' and user_id = tests.uid('rider');
  get diagnostics n = row_count;
  assert n = 1, 'admin approves organizer';
  assert (select (new_data ->> 'reviewed_by')::uuid from public.audit_logs
          where table_name = 'user_verifications' order by id desc limit 1) = tests.uid('admin'), 'reviewer recorded in the audit trail';
  assert (select count(*) from public.audit_logs where table_name = 'user_verifications' and record_id = (select id::text from public.user_verifications where user_id = tests.uid('rider'))) = 2, 'insert and approval audited';
end $$;
release savepoint s;

savepoint s;
select tests.login('rider');
do $$ begin
  assert public.has_verification('event_organizer'), 'approved organizer is recognised';
end $$;
rollback to savepoint s;

savepoint s;
select tests.login('admin');
do $$ begin
  update public.user_verifications set status = 'suspended' where kind = 'event_organizer' and user_id = tests.uid('rider');
  raise exception 'FAIL: suspended without a reason';
exception when check_violation then null; end $$;
update public.user_verifications set status = 'suspended', review_note = 'Reported for unsafe group riding; under review.'
  where kind = 'event_organizer' and user_id = tests.uid('rider');
release savepoint s;

savepoint s;
select tests.login('rider');
do $$ begin
  assert not public.has_verification('event_organizer'), 'suspension removes organizer rights immediately';
end $$;
rollback to savepoint s;

savepoint s;
select tests.login('admin');
do $$ begin
  insert into public.user_verifications (user_id, kind, scope, statement)
  values (tests.uid('admin'), 'expert_reviewer', 'mechanical', 'Qualified motorcycle mechanic, workshop owner since 2015.');
  update public.user_verifications set status = 'approved' where user_id = tests.uid('admin');
  raise exception 'FAIL: admin approved own claim';
exception when insufficient_privilege then null; end $$;
rollback to savepoint s;

-- ---------------------------------------------------------------- milestone 2: review workflow
savepoint s;
select tests.login('rider');
do $$ begin
  perform reviewed_by from public.user_verifications;
  raise exception 'FAIL: rider can see who reviewed them';
exception when insufficient_privilege then null; end $$;
do $$ begin
  assert (select review_note from public.user_verifications where user_id = tests.uid('rider') and kind = 'event_organizer')
    = 'Reported for unsafe group riding; under review.', 'rider sees the reason for a decision';
end $$;
do $$ begin
  perform public.admin_find_users('');
  raise exception 'FAIL: rider searched users';
exception when insufficient_privilege then null; end $$;
release savepoint s;

savepoint s;
select tests.login('rider2');
insert into public.user_verifications (user_id, kind, statement)
values (tests.uid('rider2'), 'trip_contributor', 'Rode Kathmandu to Jomsom twice; happy to share segment reports.');
release savepoint s;

savepoint s;
select tests.login('moderator');
do $$ begin
  update public.user_verifications set status = 'rejected', review_note = 'too short'
    where user_id = tests.uid('rider2');
  raise exception 'FAIL: rejected with a 9-character reason';
exception when check_violation then null; end $$;
do $$
declare n int;
begin
  update public.user_verifications set status = 'approved' where user_id = tests.uid('rider2') and kind = 'trip_contributor';
  get diagnostics n = row_count;
  assert n = 1, 'moderator approves a trip contributor claim';
  assert (select count(*) from public.audit_logs where table_name = 'user_verifications') > 0, 'moderator reads verification history';
  assert (select count(*) from public.audit_logs where table_name <> 'user_verifications') = 0, 'moderator cannot read other audit history';
end $$;
do $$ begin
  perform public.admin_find_users('');
  raise exception 'FAIL: moderator searched users';
exception when insufficient_privilege then null; end $$;
release savepoint s;

savepoint s;
select tests.login('rider2');
do $$ begin
  assert public.has_verification('trip_contributor'), 'approved trip contributor is recognised';
  assert not public.has_verification('event_organizer'), 'one approval never implies another';
end $$;
rollback to savepoint s;

savepoint s;
select tests.login('admin');
do $$ begin
  assert (select email from public.admin_find_users('rider2@example')) = 'rider2@example.test', 'admin finds users by email';
  assert (select roles from public.admin_find_users('mod@example')) = '{moderator}', 'search shows roles';
  assert (select count(*) from public.admin_find_users('%')) = 0, 'wildcards in the query are literal';
end $$;
reset role;
-- Make the test admin the only admin (rolled back below), then try to remove that role.
delete from public.user_roles where role = 'admin' and user_id <> tests.uid('admin');
select tests.login('admin');
do $$ begin
  delete from public.user_roles where user_id = tests.uid('admin') and role = 'admin';
  raise exception 'FAIL: removed the last administrator';
exception when check_violation then null; end $$;
do $$
declare n int;
begin
  delete from public.user_roles where user_id = tests.uid('moderator');
  get diagnostics n = row_count;
  assert n = 1, 'admin removes a moderator role';
end $$;
rollback to savepoint s;

rollback;
\o
\echo 'RLS tests: all passed'

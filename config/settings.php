<?php

/*
| Site settings that staff change in the staff app (roadmap stage 3). Each key: group, type, default,
| validation rules, and flags:
|   public  - included in GET /api/v1/site (never set this on a secret)
|   secret  - stored encrypted (APP_KEY), never returned by any endpoint, masked in the audit log
| Keys not listed here are refused. `.env` stays the source for infrastructure (database, APP_KEY).
| Groups map to permissions: settings.<group>.manage.
*/

return [
    'registry' => [
        // General
        'general.site_name' => ['type' => 'string', 'default' => 'My Ride Nepal', 'rules' => ['required', 'string', 'min:2', 'max:60'], 'public' => true],
        'general.tagline' => ['type' => 'string', 'default' => 'Plan better. Ride prepared. Learn from real journeys.', 'rules' => ['nullable', 'string', 'max:120'], 'public' => true],
        'general.description' => ['type' => 'string', 'default' => 'Motorcycle travel in Nepal: destinations, routes and dated road reports from riders who were there.', 'rules' => ['nullable', 'string', 'max:300'], 'public' => true],
        'general.contact_email' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'email:rfc', 'max:255'], 'public' => true],
        'general.contact_phone' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'regex:/^[0-9+()\-\s]{5,40}$/'], 'public' => true],
        'general.address' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:300'], 'public' => true],
        // Only English content exists; a second language needs an i18n decision (D5).
        'general.default_language' => ['type' => 'string', 'default' => 'en', 'rules' => ['required', 'in:en'], 'public' => true],
        'general.timezone' => ['type' => 'string', 'default' => 'Asia/Kathmandu', 'rules' => ['required', 'timezone:all'], 'public' => true],
        'general.date_format' => ['type' => 'string', 'default' => 'day_month_year', 'rules' => ['required', 'in:day_month_year,month_day_year,iso'], 'public' => true],

        // Maintenance: the public website shows the message; staff and sign-in keep working.
        'maintenance.enabled' => ['type' => 'bool', 'default' => false, 'rules' => ['required', 'boolean'], 'public' => true],
        'maintenance.message' => ['type' => 'string', 'default' => 'My Ride Nepal is being updated. Please check back soon.', 'rules' => ['required', 'string', 'min:10', 'max:300'], 'public' => true],

        // Registration
        'registration.open' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean'], 'public' => true],
        'registration.closed_message' => ['type' => 'string', 'default' => 'New accounts are paused for now.', 'rules' => ['required', 'string', 'min:10', 'max:300'], 'public' => true],

        // Storage. Only the local disk exists until a provider is chosen (decision D1); upload limits never
        // exceed the server cap in config/mrn.php.
        // Only "local" until a durable provider is chosen (decision D1); adding it means adding its disk here.
        // App\Support\UploadPolicy refuses uploads until the chosen disk is configured and, in production, durable.
        'storage.driver' => ['type' => 'string', 'default' => 'local', 'rules' => ['required', 'in:local']],
        'storage.uploads_enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean']],
        'storage.uploads_paused_message' => ['type' => 'string', 'default' => 'Photo uploads are paused for now. Everything else works, and you can send your report without photos.', 'rules' => ['required', 'string', 'min:10', 'max:200']],
        'storage.max_upload_kb' => ['type' => 'int', 'default' => 8192, 'rules' => ['required', 'integer', 'between:256,8192']],
        'storage.image_types' => ['type' => 'list', 'default' => ['image/jpeg', 'image/png', 'image/webp'], 'rules' => ['required', 'array', 'min:1'], 'item_rules' => ['string', 'in:image/jpeg,image/png,image/webp']],

        // Branding: the image link previews use (a public media-library image).
        'branding.share_image_media_id' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'uuid', 'exists:media_assets,id'], 'public' => true],
        'branding.share_image_alt' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:200'], 'public' => true],

        // Social links (https only). Empty ones are not shown.
        'social.facebook' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],
        'social.instagram' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],
        'social.youtube' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],
        'social.tiktok' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],
        'social.x' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],

        // SEO defaults (crawler visibility of a client-rendered site is decision D4).
        'seo.default_title' => ['type' => 'string', 'default' => 'My Ride Nepal: motorcycle travel in Nepal', 'rules' => ['required', 'string', 'min:5', 'max:60'], 'public' => true],
        'seo.default_description' => ['type' => 'string', 'default' => 'Motorcycle travel in Nepal: destinations, routes and dated road reports from riders who were there.', 'rules' => ['required', 'string', 'min:20', 'max:160'], 'public' => true],

        // Footer (the current copy is the default).
        'footer.tagline' => ['type' => 'string', 'default' => 'Plan better. Ride prepared. Learn from real journeys.', 'rules' => ['nullable', 'string', 'max:160'], 'public' => true],
        'footer.disclaimer' => ['type' => 'string', 'default' => 'Not an emergency service. My Ride Nepal does not dispatch help or track riders. In an emergency, contact local emergency services directly.', 'rules' => ['required', 'string', 'min:20', 'max:400'], 'public' => true],
        'footer.note' => ['type' => 'string', 'default' => 'Rider reports describe the day they were made. Check current conditions locally before you ride.', 'rules' => ['nullable', 'string', 'max:300'], 'public' => true],
        'footer.credits' => ['type' => 'string', 'default' => 'Photos: Igor Ovsyannykov, Maesi64, Sagar Dani, scvishnu7 and phuchche, all CC0 via Wikimedia Commons.', 'rules' => ['nullable', 'string', 'max:300'], 'public' => true],

        // Mail (SMTP). Off: Laravel uses MAIL_* from .env. On: these values replace them at runtime (queue workers
        // restart on save so they pick the change up). The password is encrypted and never returned.
        'mail.override' => ['type' => 'bool', 'default' => false, 'rules' => ['required', 'boolean']],
        'mail.host' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'required_if:override,true', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i']],
        'mail.port' => ['type' => 'int', 'default' => 587, 'rules' => ['required', 'integer', 'between:1,65535']],
        'mail.encryption' => ['type' => 'string', 'default' => 'tls', 'rules' => ['required', 'in:tls,ssl,none']],
        'mail.username' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:255']],
        'mail.password' => ['type' => 'string', 'default' => null, 'secret' => true, 'rules' => []],
        'mail.from_address' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'required_if:override,true', 'email:rfc', 'max:255']],
        'mail.from_name' => ['type' => 'string', 'default' => 'My Ride Nepal', 'rules' => ['required', 'string', 'max:100']],

        // Maps. Tiles are loaded by the browser (public); geocoding and routing are called only by Laravel, and only
        // once a provider is chosen (owner decision D2). "Configured" never means "connected": use the test.
        'maps.tile_url' => ['type' => 'string', 'default' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'rules' => ['required', 'string', 'max:300', 'regex:#^https://[a-z0-9.-]+(:[0-9]+)?/\S*\{z\}\S*\{x\}\S*\{y\}\S*$#i'], 'public' => true],
        'maps.attribution' => ['type' => 'string', 'default' => '© OpenStreetMap contributors', 'rules' => ['required', 'string', 'min:3', 'max:200'], 'public' => true],
        'maps.attribution_url' => ['type' => 'string', 'default' => 'https://www.openstreetmap.org/copyright', 'rules' => ['nullable', 'url:https', 'max:300'], 'public' => true],
        'maps.default_lat' => ['type' => 'float', 'default' => 28.2, 'rules' => ['required', 'numeric', 'between:26,31'], 'public' => true],
        'maps.default_lng' => ['type' => 'float', 'default' => 84.1, 'rules' => ['required', 'numeric', 'between:80,89'], 'public' => true],
        'maps.default_zoom' => ['type' => 'int', 'default' => 7, 'rules' => ['required', 'integer', 'between:5,14'], 'public' => true],
        'maps.geocoding_provider' => ['type' => 'string', 'default' => 'none', 'rules' => ['required', 'in:none,nominatim']],
        'maps.geocoding_url' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'required_unless:geocoding_provider,none', 'url:https', 'max:300']],
        'maps.geocoding_key' => ['type' => 'string', 'default' => null, 'secret' => true, 'rules' => []],
        'maps.routing_provider' => ['type' => 'string', 'default' => 'none', 'rules' => ['required', 'in:none,osrm,openrouteservice']],
        'maps.routing_url' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'required_unless:routing_provider,none', 'url:https', 'max:300']],
        'maps.routing_key' => ['type' => 'string', 'default' => null, 'secret' => true, 'rules' => []],

        // Security: staff session limits and sign-in attempts (riders and staff alike, per address and email).
        'security.staff_idle_minutes' => ['type' => 'int', 'default' => (int) env('STAFF_SESSION_IDLE_MINUTES', 60), 'rules' => ['required', 'integer', 'between:15,240']],
        'security.staff_max_hours' => ['type' => 'int', 'default' => intdiv((int) env('STAFF_SESSION_MAX_MINUTES', 720), 60), 'rules' => ['required', 'integer', 'between:1,24']],
        'security.login_attempts_per_minute' => ['type' => 'int', 'default' => 5, 'rules' => ['required', 'integer', 'between:3,20']],

        // Trust: community corroboration. A published report becomes "corroborated by other riders" when this many
        // distinct eligible riders (confirmed email, not suspended, not the author) said "still accurate" within the
        // window. Corroboration is never official verification.
        'trust.corroboration_threshold' => ['type' => 'int', 'default' => 3, 'rules' => ['required', 'integer', 'between:2,20']],
        'trust.corroboration_window_days' => ['type' => 'int', 'default' => 90, 'rules' => ['required', 'integer', 'between:7,365']],
    ],

    'groups' => ['general', 'maintenance', 'registration', 'storage', 'branding', 'social', 'seo', 'footer', 'mail', 'maps', 'security', 'trust'],
];

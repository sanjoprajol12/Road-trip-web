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
        'storage.driver' => ['type' => 'string', 'default' => 'local', 'rules' => ['required', 'in:local']],
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
    ],

    'groups' => ['general', 'maintenance', 'registration', 'storage', 'branding', 'social', 'seo', 'footer'],
];

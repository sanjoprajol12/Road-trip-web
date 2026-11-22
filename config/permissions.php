<?php

/*
| Staff permissions, defined in code (the registry). `php artisan mrn:permissions:sync` writes them to the
| `permissions` table; roles are built from them in the staff app. A permission only means something if code
| checks it, so new keys are added here together with the route or policy that uses them.
|
| Key format: <module>.<action>. The `admin` system role always holds every key; `moderator` is the default
| set listed under 'defaults'.
*/

return [
    'registry' => [
        'overview.view' => 'See the staff overview counts',

        'verification.view' => 'See verification claims and their history',
        'verification.decide' => 'Decide identity and trip contributor claims',
        'verification.decide_sensitive' => 'Decide event organizer and expert reviewer claims',

        'contributions.moderate' => 'Review, publish, reject and flag rider contributions',

        'events.view' => 'See all events, including private and draft ones',
        'events.cancel' => 'Cancel any event with a reason',

        'content.destinations.manage' => 'Create, edit and publish destinations',
        'content.routes.manage' => 'Create, edit and publish routes and their segments',
        'content.services.manage' => 'Create, edit and publish service points',

        'guides.manage' => 'Write and edit preparation and safety guides',
        'guides.publish' => 'Publish guides (safety guides still need a matching expert review)',

        'users.view' => 'Find riders and staff by name or username',
        'users.emails.view' => 'See and search email addresses',
        'users.suspend' => 'Suspend and reinstate rider accounts',
        'users.manage_staff' => 'Suspend, deactivate, reset or sign out other staff accounts',
        'users.activate' => 'Deactivate and reactivate accounts',
        'users.password_reset' => 'Send a password reset link to an account',
        'users.sessions.revoke' => 'Sign an account out everywhere',
        'users.roles.manage' => 'Give and remove staff roles',
        'users.invite' => 'Invite new staff by email',

        'roles.manage' => 'Create, edit and delete roles and their permissions',

        'audit.view' => 'Read the audit log',

        'settings.general.manage' => 'Change site name, contact details, language, timezone and date format',
        'settings.maintenance.manage' => 'Switch maintenance mode on or off',
        'settings.registration.manage' => 'Open or close public sign-up',
        'settings.storage.manage' => 'Change storage and upload settings, test storage',
        'system.cache.manage' => 'Clear the settings cache',

        'media.manage' => 'Upload, describe and delete images in the media library',

        'cms.home.edit' => 'Edit homepage sections (drafts), their order and visibility',
        'cms.home.publish' => 'Publish the homepage drafts to the public website',
        'cms.menus.manage' => 'Edit the header and footer menus',
        'cms.notices.manage' => 'Create, schedule and remove site-wide notices',
        'places.manage' => 'Create and edit places of interest (drafts), their images and sources',
        'places.publish' => 'Publish, unpublish and archive places, edit published places, and decide any place including rider suggestions',
        'places.review' => 'Review rider-suggested places (approve, request changes, reject with a reason) and rider corrections; no creating, editing, unpublishing or archiving',
        'cms.pages.manage' => 'Create and edit custom pages',
        'cms.faqs.manage' => 'Create and edit frequently asked questions',
        'cms.blog.manage' => 'Create and edit blog posts, categories, tags and authors',
        'cms.team.manage' => 'Create and edit team members',
        'cms.promotions.manage' => 'Create and edit popups and promotional banners',
        'cms.content.publish' => 'Publish, schedule and archive CMS content (pages, FAQs, blog, team, popups, banners)',
        'contact.view' => 'Read contact messages, including the sender email address',
        'contact.manage' => 'Change the status of contact messages and delete them',
        'settings.branding.manage' => 'Change the default social-sharing image and brand text',
        'settings.social.manage' => 'Change the social media links',
        'settings.seo.manage' => 'Change the default page title and description',
        'settings.footer.manage' => 'Change the footer text',
        'settings.mail.manage' => 'Change outgoing mail (SMTP) settings, test the connection and send a test email',
        'settings.maps.manage' => 'Change map tiles, default view and geocoding/routing provider settings, test providers',
        'settings.security.manage' => 'Change staff session limits and sign-in attempt limits',
        'settings.trust.manage' => 'Change the community corroboration threshold and window',
    ],

    // Permissions of the built-in moderator role (what moderators could do before roles became configurable).
    'defaults' => [
        'moderator' => [
            'overview.view', 'verification.view', 'verification.decide', 'contributions.moderate', 'events.view', 'events.cancel',
            'content.destinations.manage', 'content.routes.manage', 'content.services.manage', 'guides.manage', 'guides.publish',
            'users.view', 'users.suspend', 'media.manage', 'places.review',
        ],
    ],
];

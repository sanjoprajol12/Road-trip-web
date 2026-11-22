<?php

/*
| Homepage section types (migration stage A8). Code owns layout, styling and behaviour; staff edit only these fields.
| Every field is validated here (Laravel rules, dot paths for nested data); unknown fields are dropped. No HTML:
| text is stored and shown as plain text. Links ("to") are internal paths or https URLs. Images come from the media
| library (public assets only) or from the photos bundled with the website (allow-list below, each credited in
| frontend/public/images/photos/LICENSES.md).
*/

$link = ['nullable', 'array:label,to'];
$linkLabel = ['nullable', 'string', 'max:30'];
$linkTo = ['nullable', 'string', 'max:300', 'regex:#^(/[a-z0-9\-/]*|https://\S+)$#'];

return [
    // Photos that ship with the public website, usable as backgrounds without uploading them again.
    'bundled_photos' => [
        '/images/photos/kathmandu-motorcycle' => ['widths' => [640, 1280, 1920, 2560], 'credit' => 'Kathmandu. Photo: Igor Ovsyannykov, CC0'],
    ],

    'types' => [
        'hero' => [
            'label' => 'Hero',
            'rules' => [
                'eyebrow' => ['nullable', 'string', 'max:60'],
                'lines' => ['required', 'array', 'min:1', 'max:3'],
                'lines.*' => ['required', 'string', 'max:40'],
                'lead' => ['nullable', 'string', 'max:320'],
                'primary_cta' => $link, 'primary_cta.label' => $linkLabel, 'primary_cta.to' => $linkTo,
                'secondary_cta' => $link, 'secondary_cta.label' => $linkLabel, 'secondary_cta.to' => $linkTo,
                'background' => ['required', 'array:kind,ref'],
                'background.kind' => ['required', 'in:bundled,media'],
                'background.ref' => ['required', 'string', 'max:120'],
                'photo_credit' => ['nullable', 'string', 'max:120'],
            ],
        ],
        'slides' => [
            'label' => 'Slider',
            'rules' => [
                'heading' => ['nullable', 'string', 'max:80'],
                'items' => ['required', 'array', 'min:1', 'max:5'],
                'items.*' => ['array:heading,text,media_id,cta'],
                'items.*.heading' => ['required', 'string', 'max:80'],
                'items.*.text' => ['nullable', 'string', 'max:240'],
                'items.*.media_id' => ['required', 'uuid'],
                'items.*.cta' => $link, 'items.*.cta.label' => ['nullable', 'string', 'max:30'], 'items.*.cta.to' => $linkTo,
            ],
        ],
        'layers' => [
            'label' => 'How it works',
            'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'intro' => ['nullable', 'string', 'max:400'],
                'items' => ['required', 'array', 'min:1', 'max:6'],
                'items.*' => ['array:name,text'],
                'items.*.name' => ['required', 'string', 'max:40'],
                'items.*.text' => ['required', 'string', 'max:240'],
            ],
        ],
        // Session 21: the working route planner on the homepage, previewing one real published route.
        'planner' => [
            'label' => 'Route planner',
            'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'intro' => ['nullable', 'string', 'max:400'],
                // Empty: the newest published route that has stretches.
                'ids' => ['present', 'array', 'max:1'], 'ids.*' => ['uuid'],
            ],
        ],
        'tools' => [
            'label' => 'What riders can do',
            'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'intro' => ['nullable', 'string', 'max:400'],
                'items' => ['required', 'array', 'min:1', 'max:6'],
                'items.*' => ['array:name,text,cta'],
                'items.*.name' => ['required', 'string', 'max:40'],
                'items.*.text' => ['required', 'string', 'max:240'],
                'items.*.cta' => $link, 'items.*.cta.label' => ['nullable', 'string', 'max:30'], 'items.*.cta.to' => $linkTo,
            ],
        ],
        'featured_destinations' => [
            'label' => 'Featured destinations',
            'rules' => [
                'heading' => ['required', 'string', 'max:80'],
                'link_label' => ['nullable', 'string', 'max:40'],
                // Empty: the newest published destinations.
                'ids' => ['present', 'array', 'max:7'], 'ids.*' => ['uuid', 'distinct'],
            ],
        ],
        'featured_routes' => [
            'label' => 'Featured routes',
            'rules' => [
                'heading' => ['required', 'string', 'max:80'],
                'link_label' => ['nullable', 'string', 'max:40'],
                'ids' => ['present', 'array', 'max:6'], 'ids.*' => ['uuid', 'distinct'],
            ],
        ],
        'featured_experiences' => [
            'label' => 'Featured rider experiences',
            'rules' => [
                'heading' => ['required', 'string', 'max:80'],
                'link_label' => ['nullable', 'string', 'max:40'],
                'ids' => ['present', 'array', 'max:6'], 'ids.*' => ['uuid', 'distinct'],
            ],
        ],
        'freshness' => [
            'label' => 'Freshness explainer',
            'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'text' => ['nullable', 'string', 'max:400'],
                'legend' => ['present', 'array', 'max:6'],
                'legend.*' => ['array:term,detail'],
                'legend.*.term' => ['required', 'string', 'max:30'],
                'legend.*.detail' => ['required', 'string', 'max:160'],
                'caption' => ['nullable', 'string', 'max:240'],
            ],
        ],
        'banner' => [
            'label' => 'Promotional banner',
            'rules' => [
                // Site content only (decision D6 open for third-party promotion).
                'heading' => ['required', 'string', 'max:80'],
                'text' => ['nullable', 'string', 'max:240'],
                'media_id' => ['nullable', 'uuid'],
                'cta' => $link, 'cta.label' => ['nullable', 'string', 'max:30'], 'cta.to' => $linkTo,
            ],
        ],
        'text' => [
            'label' => 'Text',
            'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:2000'],
            ],
        ],
    ],

    // Defaults for a new section of each type (staff then edit them).
    'defaults' => [
        'hero' => ['eyebrow' => null, 'lines' => ['New heading'], 'lead' => null, 'primary_cta' => null, 'secondary_cta' => null, 'background' => ['kind' => 'bundled', 'ref' => '/images/photos/kathmandu-motorcycle'], 'photo_credit' => 'Kathmandu. Photo: Igor Ovsyannykov, CC0'],
        'slides' => ['heading' => null, 'items' => []],
        'layers' => ['heading' => 'New section', 'intro' => null, 'items' => [['name' => 'Item', 'text' => 'Describe it.']]],
        'planner' => ['heading' => 'New section', 'intro' => null, 'ids' => []],
        'tools' => ['heading' => 'New section', 'intro' => null, 'items' => [['name' => 'Item', 'text' => 'Describe it.', 'cta' => null]]],
        'featured_destinations' => ['heading' => 'Destinations', 'link_label' => 'All destinations', 'ids' => []],
        'featured_routes' => ['heading' => 'Routes', 'link_label' => 'All routes', 'ids' => []],
        'featured_experiences' => ['heading' => 'Rider experiences', 'link_label' => 'All experiences', 'ids' => []],
        'freshness' => ['heading' => 'New section', 'text' => null, 'legend' => [], 'caption' => null],
        'banner' => ['heading' => 'New banner', 'text' => null, 'media_id' => null, 'cta' => null],
        'text' => ['heading' => 'New section', 'body' => 'Write the text.'],
    ],

    'menu_locations' => ['header', 'footer'],
];

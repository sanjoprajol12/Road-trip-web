<?php

/*
| CMS content types (Stage 8). One `cms_entries` row per item; this file says, per type, which permission manages it,
| which core columns it uses and which extra fields live in `data`. The staff portal builds its forms from
| GET /admin/cms/types (this file), and App\Support\CmsContent builds validation rules from the same descriptors,
| so a field is declared once. Text is plain text (no HTML). Links are internal paths or https URLs.
|
| Field kinds: text, textarea, select (options), link (label + to), ref (one entry of type `ref`), refs (several),
| blocks (ordered heading / text / callout blocks), number.
*/

return [
    'types' => [
        'page' => [
            'label' => 'Pages', 'singular' => 'Page', 'permission' => 'cms.pages.manage', 'public' => 'detail',
            'core' => ['slug', 'summary', 'body', 'media', 'seo'],
            'fields' => [
                ['key' => 'blocks', 'label' => 'Content blocks', 'kind' => 'blocks', 'max' => 30],
            ],
        ],
        'faq' => [
            'label' => 'FAQs', 'singular' => 'Question', 'permission' => 'cms.faqs.manage', 'public' => 'list',
            'title_label' => 'Question', 'body_label' => 'Answer', 'body_required' => true,
            'core' => ['body'],
            'fields' => [
                ['key' => 'category', 'label' => 'Group (for example Accounts, Trips)', 'kind' => 'text', 'max' => 60],
            ],
        ],
        'post' => [
            'label' => 'Blog posts', 'singular' => 'Post', 'permission' => 'cms.blog.manage', 'public' => 'detail',
            'body_required' => true,
            'core' => ['slug', 'summary', 'body', 'media', 'seo'],
            'fields' => [
                ['key' => 'category_id', 'label' => 'Category', 'kind' => 'ref', 'ref' => 'blog_category'],
                ['key' => 'tag_ids', 'label' => 'Tags', 'kind' => 'refs', 'ref' => 'blog_tag', 'max' => 10],
                ['key' => 'author_id', 'label' => 'Author', 'kind' => 'ref', 'ref' => 'author'],
            ],
        ],
        'blog_category' => [
            'label' => 'Blog categories', 'singular' => 'Category', 'permission' => 'cms.blog.manage', 'public' => 'list',
            'core' => ['slug', 'summary'], 'fields' => [],
        ],
        'blog_tag' => [
            'label' => 'Blog tags', 'singular' => 'Tag', 'permission' => 'cms.blog.manage', 'public' => 'list',
            'core' => ['slug'], 'fields' => [],
        ],
        'author' => [
            'label' => 'Authors', 'singular' => 'Author', 'permission' => 'cms.blog.manage', 'public' => 'list',
            'title_label' => 'Name', 'summary_label' => 'Short biography',
            'core' => ['slug', 'summary', 'media'], 'fields' => [],
        ],
        'team_member' => [
            'label' => 'Team', 'singular' => 'Team member', 'permission' => 'cms.team.manage', 'public' => 'list',
            'title_label' => 'Name', 'summary_label' => 'Short biography',
            'core' => ['summary', 'media'],
            'fields' => [
                ['key' => 'role', 'label' => 'Role', 'kind' => 'text', 'max' => 80, 'required' => true],
            ],
        ],
        'popup' => [
            'label' => 'Popups', 'singular' => 'Popup', 'permission' => 'cms.promotions.manage', 'public' => 'active',
            'core' => ['body'], 'body_required' => true,
            'fields' => [
                ['key' => 'cta', 'label' => 'Button', 'kind' => 'link'],
                ['key' => 'show_on', 'label' => 'Show on', 'kind' => 'select', 'options' => ['all' => 'Every page', 'home' => 'Homepage only'], 'required' => true],
                ['key' => 'frequency', 'label' => 'Show again', 'kind' => 'select', 'options' => ['once' => 'Once per visitor', 'session' => 'Once per visit'], 'required' => true],
            ],
        ],
        'banner' => [
            'label' => 'Banners', 'singular' => 'Banner', 'permission' => 'cms.promotions.manage', 'public' => 'active',
            'core' => ['summary', 'media'],
            'fields' => [
                ['key' => 'cta', 'label' => 'Button', 'kind' => 'link'],
                ['key' => 'placement', 'label' => 'Placement', 'kind' => 'select', 'options' => ['home' => 'Homepage', 'blog' => 'Blog pages'], 'required' => true],
            ],
        ],
    ],
];

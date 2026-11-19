<?php

// Backend only (architecture Option B): this app serves the REST API under /api/v1 and nothing else.
// The public website and the staff app are separate static builds (Road-trip-admin repo) whose hosts proxy
// /api and /sanctum here. Any other path answers Laravel's normal 404.

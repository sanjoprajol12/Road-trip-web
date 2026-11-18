# Introduction

REST API of My Ride Nepal (/api/v1). The web SPA and the admin SPA authenticate with Laravel Sanctum cookie sessions: GET /sanctum/csrf-cookie, then send the X-XSRF-TOKEN header on every state-changing request. Errors are JSON: {message, errors?}. Validation errors use HTTP 422; unauthenticated 401; forbidden 403; password confirmation required 423; rate limited 429.

<aside>
    <strong>Base URL</strong>: <code>http://127.0.0.1:5180</code>
</aside>

    This documentation aims to provide all the information you need to work with our API.

    <aside>As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
    You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).</aside>


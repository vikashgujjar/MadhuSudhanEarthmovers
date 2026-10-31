<?php

return [
    // Rows per page for list and report screens.
    'page_size' => (int) env('ERP_PAGE_SIZE', 25),

    // Login lockout: failed attempts for one username within the window.
    'lockout_attempts' => 5,
    'lockout_minutes' => 15,

    // Secret typed into the one-time web installer (/install). Empty = installer disabled.
    'install_key' => env('ERP_INSTALL_KEY', ''),
];

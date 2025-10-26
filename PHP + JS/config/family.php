<?php

return [
    'session_secret' => env('FP_SESSION_SECRET', 'dev-secret'),
    'uploads_dir' => env('FP_UPLOADS_DIR', storage_path('app/uploads')),
    'thumbs_dir' => env('FP_THUMBS_DIR', storage_path('app/uploads/thumbs')),
    'max_upload_mb' => (int) env('FP_MAX_UPLOAD_MB', 6),
];

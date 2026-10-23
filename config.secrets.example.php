<?php
// ============================================================
// AUCTION HUB GROUP — config.secrets.example.php
// Copy to config.secrets.php and fill in the real values.
// config.secrets.php is git-ignored; this template is not.
// ============================================================
return [
    'local' => [
        'DB_NAME' => 'parkvillageauctions_db',
        'DB_USER' => 'root',
        'DB_PASS' => '',
    ],
    'production' => [
        'DB_NAME' => 'auctionhubgroupc_db',
        'DB_USER' => 'auctionhubgroupc_db',
        'DB_PASS' => 'change-me',
    ],
    'MAIL_PASSWORD'         => 'change-me',
    'CAREER_MAIL_PASS'      => 'change-me',
    'JWT_SECRET'            => 'php -r "echo bin2hex(random_bytes(32));"',
    'CLOUDINARY_API_KEY'    => '',
    'CLOUDINARY_API_SECRET' => '',
    'GROQ_API_KEY'          => '',
    'PEXELS_API_KEY'        => '',
    'AHG_CRON_KEY'          => 'change-me',
];

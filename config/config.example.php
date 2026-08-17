<?php
declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://salvest.germanmallo.com',
        'timezone' => 'Europe/Madrid',
        'session_name' => 'salvest_session',
        'secret_key' => 'GENERA_UNA_CADENA_ALEATORIA_DE_64_CARACTERES',
        'encryption_key' => 'GENERA_32_BYTES_EN_BASE64',
        'cron_token' => 'GENERA_OTRA_CADENA_ALEATORIA',
        'cookie_secure' => true,
    ],
    'database' => [
        'host' => 'MYSQL_HOST', 'port' => 3306, 'name' => 'MYSQL_DATABASE',
        'user' => 'MYSQL_USER', 'password' => 'MYSQL_PASSWORD', 'charset' => 'utf8mb4',
    ],
    'openai' => ['api_key' => 'OPENAI_API_KEY', 'model' => 'gpt-5.6-luna', 'timeout_seconds' => 120],
    'imap' => [
        'default_host' => 'imap.ionos.es', 'default_port' => 993,
        'timeout_seconds' => 30, 'max_messages_per_mailbox' => 25,
    ],
    'processing' => [
        'classification_threshold' => 92.0, 'max_attachment_bytes' => 26214400,
        'storage_root' => dirname(__DIR__) . '/storage/invoices',
        'incoming_root' => dirname(__DIR__) . '/storage/incoming',
    ],
    'google_drive' => [
        'enabled' => false,
        'root_folder_id' => 'ID_DE_LA_CARPETA_COMUNIDADES',
        'oauth_client_file' => dirname(__DIR__) . '/config/google_oauth_client.json',
        'oauth_token_file' => dirname(__DIR__) . '/config/google_oauth_token.json',
    ],
];

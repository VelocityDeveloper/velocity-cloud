<?php

/*
| Konfigurasi Velocity Cloud.
| pods: spesifikasi container per app (kunci = slug di apps.json landing). Image null = belum bisa dipesan.
| Variabel {sandi} dan {sandi_db} diganti sandi acak per layanan saat provisioning (ditampilkan ke pelanggan);
| {rahasia} juga acak tapi tidak ditampilkan; {email} = email pelanggan. 'pengguna' (opsional) = username login app, ditampilkan.
*/

// Limit CPU/RAM pod dibaca dari apps.json landing (field pod) agar spek di situs = spek provisioning.
$katalogDir = env('CLOUD_KATALOG_DIR', '/home/velocitydeveloper-ai-id/src/data');
$apps = array_column(json_decode(file_get_contents("$katalogDir/apps.json"), true, flags: JSON_THROW_ON_ERROR), 'pod', 'slug');
$limit = fn (string $slug) => ['cpu' => $apps[$slug]['cpu'], 'memori' => $apps[$slug]['ram_mb'].'m'];

return [
    'katalog_dir' => $katalogDir,
    // App pelanggan = <acak>.velocitydeveloper.ai.id (DNS wildcard → tunnel → router nginx :8188 → pod). Nama yang sudah dipakai situs dicadangkan.
    'domain_app' => env('CLOUD_DOMAIN_APP', 'velocitydeveloper.ai.id'),
    'subdomain_cadangan' => ['www', 'app', 'ai', 'api', 'admin', 'mail', 'dash', 'panel', 'blog', 'status', 'docs', 'cdn', 'static', 'assets', 'mx', 'smtp', 'imap', 'ns1', 'ns2', 'ftp', 'cpanel', 'webmail'],
    'whatsapp' => env('CLOUD_WHATSAPP', '6285600566674'),

    // Analitik landing: log beacon nginx (lihat /etc/nginx/conf.d/velocitydeveloper-ai-id.conf) diimpor tiap menit.
    'analitik' => [
        'log' => env('CLOUD_ANALITIK_LOG', '/var/log/nginx/velocitydeveloper-analitik.log'),
        'ip_internal' => array_filter(explode(',', env('CLOUD_ANALITIK_IP_INTERNAL', ''))), // IP kantor
        'simpan_hari' => 400,
    ],

    // Google Search Console (Admin › SEO): service account gscvd-agent (izin Terbatas), kunci dipakai bersama velocity-agent.
    // Token bersama dengan server bot (/etc/velocity/secrets/agen-diskusi.token) untuk POST /internal/agen/diskusi.
    'agen_diskusi_token' => env('AGEN_DISKUSI_TOKEN', ''),

    'gsc' => [
        'kredensial' => env('GSC_KREDENSIAL', '/etc/velocity/secrets/gsc-velocitydeveloper-co.json'),
        'situs' => env('GSC_SITUS', 'sc-domain:velocitydeveloper.ai.id'),
    ],

    // Token AI prabayar (13 Nov). Paket dibuat katalog:sync bila belum ada; harga/aktif sesudahnya diatur di Admin › Paket.
    // Model upstream & kunci 9router ada di proxy: /opt/velocity-ai-proxy + /etc/velocity/secrets/ai-token-upstream.env.
    'token_ai' => [
        'endpoint' => env('TOKEN_AI_ENDPOINT', 'https://ai.velocitydeveloper.ai.id/v1'),
        'beli_maks_juta' => 1000, // sekali beli/top-up
        'paket' => [
            ['slug' => 'ai-deepseek-v4-1-flash', 'nama' => 'DeepSeek V4.1 Flash', 'model' => 'deepseek-v4.1-flash', 'harga' => 5000],
        ],
    ],

    // VPS otomatis (13 Nov): VM KVM di node Incus (PC bekas kantor), panduan deploy/node-vps/PANDUAN.md.
    // Lalu lintas VM keluar lewat relay WireGuard ber-IP publik (bukan IP kantor); port relay = DNAT statis per nomor VM.
    'vps' => [
        'relay_host' => env('VPS_RELAY_HOST'), // kosong = VPS tetap disiapkan manual
        'subnet' => '10.77.0', // IP VM = subnet.N, N 2..254
        'port_ssh' => 22000, // SSH = relay:(port_ssh + N)
        'port_ekstra' => 40000, // port bebas = relay:(port_ekstra + N*10) s/d +9 → VM port yang sama
        'project' => 'velocity',
        'network' => 'incusbr0',
        'pool' => 'default',
        'client_cert' => env('VPS_INCUS_CERT', '/etc/velocity/secrets/incus-client.crt'),
        'client_key' => env('VPS_INCUS_KEY', '/etc/velocity/secrets/incus-client.key'),
        'paket' => ['vps-cloud-1', 'vps-cloud-2', 'vps-cloud-3'], // dibuat otomatis; paket lain tetap manual (pemasok)
        'image_server' => 'https://images.linuxcontainers.org',
        'os' => [
            'ubuntu-24.04' => ['nama' => 'Ubuntu 24.04 LTS', 'alias' => 'ubuntu/24.04/cloud'],
            'debian-12' => ['nama' => 'Debian 12', 'alias' => 'debian/12/cloud'],
        ],
    ],

    // Chat AI kelola layanan (app/Services/Chat/AlatLayanan.php): kunci Pexels dipakai bersama installer, cek-blok = validator markup blok installer.
    'chat_ai' => [
        'pexels_kunci' => env('CHAT_AI_PEXELS_KUNCI', '/home/pexel/api-key.txt'),
        'cek_blok' => env('CHAT_AI_CEK_BLOK', '/opt/velocity-wp-install-automation/scripts/cek-blok'),
        // server memanggil proxy token AI langsung (tanpa tunnel); koneksi queue job JalankanChat (tes: sync)
        'endpoint' => env('CHAT_AI_ENDPOINT', env('TOKEN_AI_ENDPOINT', 'https://ai.velocitydeveloper.ai.id/v1')),
        'antrean' => env('CHAT_AI_QUEUE_CONNECTION', 'chat'),
    ],

    'tagihan' => [
        'terbit_hari_sebelum' => 7,  // tagihan perpanjangan terbit H-7 jatuh tempo
        'tangguhkan_hari_sesudah' => 3, // layanan ditangguhkan H+3 jika belum lunas
        'jatuh_tempo_hari' => 3,     // batas bayar tagihan pesanan baru
    ],

    'pods' => [
        'n8n' => [
            'port' => 5678,
            // Owner dibuat saat provisioning (12 Nov): tanpa ini wizard owner terbuka untuk siapa pun yang membuka URL duluan.
            'pengguna' => '{email}',
            'siapkan' => [
                'tunggu' => '/healthz/readiness', // 200 setelah DB n8n siap
                'langkah' => [['path' => '/rest/owner/setup', 'json' => ['email' => '{email}', 'firstName' => 'Admin', 'lastName' => 'Velocity', 'password' => '{sandi}']]],
            ],
            'containers' => [
                ['name' => 'n8n', 'image' => 'docker.io/n8nio/n8n:latest', 'env' => ['N8N_HOST' => '{host}', 'N8N_PROTOCOL' => 'https', 'WEBHOOK_URL' => 'https://{host}/'], 'volume' => '/home/node/.n8n'],
            ],
            'limit' => $limit('n8n'),
        ],
        'wordpress' => [
            'port' => 80,
            // Instalasi WordPress dijalankan saat provisioning (12 Nov) agar wizard install.php tidak bisa diklaim orang lain.
            'pengguna' => '{email}',
            'siapkan' => [
                'tunggu' => '/wp-admin/install.php',
                'langkah' => [['path' => '/wp-admin/install.php?step=2', 'form' => [
                    'weblog_title' => 'Situs WordPress', 'user_name' => '{email}', 'admin_email' => '{email}',
                    'admin_password' => '{sandi}', 'admin_password2' => '{sandi}', 'blog_public' => '1', 'language' => 'id_ID',
                ], 'cocok' => 'wp-login.php']],
            ],
            'containers' => [
                ['name' => 'db', 'image' => 'docker.io/library/mariadb:11', 'env' => ['MARIADB_DATABASE' => 'wordpress', 'MARIADB_USER' => 'wordpress', 'MARIADB_PASSWORD' => '{sandi_db}', 'MARIADB_RANDOM_ROOT_PASSWORD' => '1'], 'volume' => '/var/lib/mysql'],
                ['name' => 'web', 'image' => 'docker.io/library/wordpress:6-apache', 'env' => ['WORDPRESS_DB_HOST' => '127.0.0.1', 'WORDPRESS_DB_NAME' => 'wordpress', 'WORDPRESS_DB_USER' => 'wordpress', 'WORDPRESS_DB_PASSWORD' => '{sandi_db}'], 'volume' => '/var/www/html'],
            ],
            'limit' => $limit('wordpress'),
        ],
        'waha' => [
            'port' => 3000,
            'containers' => [
                ['name' => 'waha', 'image' => 'docker.io/devlikeapro/waha:latest', 'env' => ['WAHA_API_KEY' => '{sandi}', 'WAHA_DASHBOARD_PASSWORD' => '{sandi}'], 'volume' => '/app/.sessions'],
            ],
            'limit' => $limit('waha'),
        ],
        'evolution-api' => [
            'port' => 8080,
            'containers' => [
                ['name' => 'db', 'image' => 'docker.io/library/postgres:17', 'env' => ['POSTGRES_DB' => 'evolution', 'POSTGRES_USER' => 'evolution', 'POSTGRES_PASSWORD' => '{sandi_db}'], 'volume' => '/var/lib/postgresql/data'],
                ['name' => 'api', 'image' => 'docker.io/evoapicloud/evolution-api:latest', 'env' => ['AUTHENTICATION_API_KEY' => '{sandi}', 'DATABASE_PROVIDER' => 'postgresql', 'DATABASE_CONNECTION_URI' => 'postgresql://evolution:{sandi_db}@127.0.0.1:5432/evolution', 'SERVER_URL' => 'https://{host}'], 'volume' => '/evolution/instances'],
            ],
            'limit' => $limit('evolution-api'),
        ],
        'postgresql' => [
            'port' => 5432, 'tcp' => true,
            'containers' => [
                ['name' => 'postgres', 'image' => 'docker.io/library/postgres:17', 'env' => ['POSTGRES_USER' => 'app', 'POSTGRES_PASSWORD' => '{sandi}', 'POSTGRES_DB' => 'app'], 'volume' => '/var/lib/postgresql/data'],
            ],
            'limit' => $limit('postgresql'),
        ],
        'mysql' => [
            'port' => 3306, 'tcp' => true,
            'containers' => [
                ['name' => 'mariadb', 'image' => 'docker.io/library/mariadb:11', 'env' => ['MARIADB_USER' => 'app', 'MARIADB_PASSWORD' => '{sandi}', 'MARIADB_DATABASE' => 'app', 'MARIADB_RANDOM_ROOT_PASSWORD' => '1'], 'volume' => '/var/lib/mysql'],
            ],
            'limit' => $limit('mysql'),
        ],
        'redis' => [
            'port' => 6379, 'tcp' => true,
            'containers' => [
                ['name' => 'redis', 'image' => 'docker.io/library/redis:7', 'env' => [], 'args' => ['redis-server', '--appendonly', 'yes', '--requirepass', '{sandi}'], 'volume' => '/data'],
            ],
            'limit' => $limit('redis'),
        ],
        'uptime-kuma' => [
            'port' => 3001,
            'containers' => [
                ['name' => 'kuma', 'image' => 'docker.io/louislam/uptime-kuma:1', 'env' => [], 'volume' => '/app/data'],
            ],
            'limit' => $limit('uptime-kuma'),
        ],
        'metabase' => [
            'port' => 3000,
            'containers' => [
                ['name' => 'metabase', 'image' => 'docker.io/metabase/metabase:latest', 'env' => ['MB_DB_FILE' => '/metabase-data/metabase.db'], 'volume' => '/metabase-data'],
            ],
            'limit' => $limit('metabase'),
        ],
        'minio' => [
            'port' => 9001,
            'containers' => [
                ['name' => 'minio', 'image' => 'docker.io/minio/minio:latest', 'env' => ['MINIO_ROOT_USER' => 'admin', 'MINIO_ROOT_PASSWORD' => '{sandi}'], 'args' => ['server', '/data', '--console-address', ':9001'], 'volume' => '/data'],
            ],
            'limit' => $limit('minio'),
        ],
        'hermes-agent' => [
            // Pelanggan mengatur API key model, model, dan Telegram sendiri lewat dashboard Hermes (keputusan user 11 Nov).
            // Dashboard di bind non-loopback wajib provider auth (Hermes fail-closed); basic auth = pengguna 'admin' + {sandi}.
            // Docs Hermes: basic auth "tidak cocok untuk internet publik langsung" → diterima user (Cloudflare + HTTPS + sandi 24 karakter).
            'port' => 9119,
            'pengguna' => 'admin',
            'containers' => [
                ['name' => 'hermes', 'image' => 'docker.io/nousresearch/hermes-agent:v0.23.1', 'env' => [
                    'HERMES_DASHBOARD' => '1',
                    'HERMES_DASHBOARD_BASIC_AUTH_USERNAME' => 'admin',
                    'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => '{sandi}',
                    'HERMES_DASHBOARD_BASIC_AUTH_SECRET' => '{rahasia}', // sesi login tetap sah setelah restart
                ], 'args' => ['gateway', 'run'], 'volume' => '/opt/data'],
            ],
            'limit' => $limit('hermes-agent'),
        ],
        'nakama' => [
            // Image resmi (docs/website/content/docs/docker.mdx + deployment-hardening.mdx). Berjalan UID 1000, data di /nakama/data.
            // Admin di-seed saat boot pertama (tanpa wizard, agar tidak bisa diklaim orang lain): email pelanggan + {sandi}.
            // NAKAMA_SECRETS_KEY mengenkripsi secret di Settings; hilang = secret tak terbaca (tetap di env pod).
            'port' => 4310,
            'pengguna' => '{email}',
            'containers' => [
                ['name' => 'nakama', 'image' => 'ghcr.io/ahmadrosid/nakama:0.4.72', 'env' => [
                    'NAKAMA_CONFIG_DIR' => '/nakama/data',
                    'NAKAMA_SECRETS_KEY' => '{rahasia}',
                    'NAKAMA_WEB_PUBLIC_URL' => 'https://{host}',
                    'NAKAMA_SEED_ADMIN_EMAIL' => '{email}',
                    'NAKAMA_SEED_ADMIN_NAME' => 'Admin',
                    'NAKAMA_SEED_ADMIN_PASSWORD' => '{sandi}',
                ], 'volume' => '/nakama/data'],
            ],
            'limit' => $limit('nakama'),
        ],
        'openclaw' => [
            // Image resmi (docs/install/docker.md, repo openclaw/openclaw). Gateway + Control UI di port 18789, user node, state di ~/.openclaw.
            // Auth token = {sandi} (ditampilkan sebagai "Sandi / API key", ditempel di Control UI). Browser baru wajib pairing:
            // pelanggan menyetujuinya di panel layanan (agent: podman exec `openclaw devices approve`), opsi bypass sudah dihapus OpenClaw.
            // Tanpa publicOrigin origin publik ditolak; tanpa trustedProxies gateway membalas 403 proxy_attribution_required.
            'port' => 18789,
            'pairing' => true,
            'containers' => [
                ['name' => 'openclaw', 'image' => 'ghcr.io/openclaw/openclaw:2026.11.3', 'env' => [
                    'OPENCLAW_GATEWAY_TOKEN' => '{sandi}',
                    'VC_HOST' => '{host}',
                    'TZ' => 'Asia/Jakarta',
                ], 'args' => ['sh', '-c', implode(' ', [
                    // Tiap start: mode local, publicOrigin, trustedProxies = IPv4 container (pasta menampilkan router nginx sebagai IP ini; IP LAN host bisa berubah).
                    'node openclaw.mjs config set --batch-json "$(node -e \'const ips=Object.values(require("os").networkInterfaces()).flat().filter(i=>!i.internal&&i.family==="IPv4").map(i=>i.address);',
                    'process.stdout.write(JSON.stringify([{path:"gateway.mode",value:"local"},{path:"gateway.publicOrigin",value:"https://"+process.env.VC_HOST},{path:"gateway.trustedProxies",value:[...ips,"127.0.0.1","::1"]}]))\')"',
                    '&& exec node /app/docker-entrypoint.mjs node openclaw.mjs gateway --bind lan --port 18789',
                ])],
                    'volume' => '/home/node/.openclaw'],
            ],
            'limit' => $limit('openclaw'),
        ],
    ],
];

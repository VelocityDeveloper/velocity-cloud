<?php

/*
| Konfigurasi Velocity Data Center.
| pods: spesifikasi container per app (kunci = slug di apps.json landing). Image null = belum bisa dipesan.
| Variabel {sandi} dan {sandi_db} diganti sandi acak per layanan saat provisioning (ditampilkan ke pelanggan);
| {rahasia} juga acak tapi tidak ditampilkan; {email} = email pelanggan. 'pengguna' (opsional) = username login app, ditampilkan.
*/

// Limit CPU/RAM pod dibaca dari apps.json landing (field pod) agar spek di situs = spek provisioning.
$katalogDir = env('CLOUD_KATALOG_DIR', '/home/velocitydeveloper-ai-id/src/data');
$dataApps = json_decode(file_get_contents("$katalogDir/apps.json"), true, flags: JSON_THROW_ON_ERROR);
$apps = array_column($dataApps, 'pod', 'slug');
$limit = fn (string $slug) => ['cpu' => $apps[$slug]['cpu'], 'memori' => $apps[$slug]['ram_mb'].'m'];

// Varian paket app (field varian di apps.json, #16): kunci = slug paket tanpa "app-" (wordpress, wordpress-plus, ...).
// Paket dasar memakai field pod; varian memakai pod app induk dengan limit CPU/RAM sendiri.
$varian = [];
foreach ($dataApps as $a) {
    if (empty($a['varian'])) {
        continue;
    }
    $varian[$a['slug']] = ['app' => $a['slug'], 'nama' => $a['varian_dasar'] ?? 'Starter', 'cpu' => $a['pod']['cpu'], 'ram_mb' => $a['pod']['ram_mb']];
    foreach ($a['varian'] as $v) {
        $varian[$a['slug'].'-'.Illuminate\Support\Str::slug($v['nama'])] = ['app' => $a['slug'], 'nama' => $v['nama'], 'cpu' => $v['cpu'], 'ram_mb' => $v['ram_mb']];
    }
}

return [
    'katalog_dir' => $katalogDir,
    'varian' => $varian,
    // App pelanggan = <acak>.velocitydeveloper.ai.id (DNS wildcard → tunnel → router nginx :8188 → pod). Nama yang sudah dipakai situs dicadangkan.
    'domain_app' => env('CLOUD_DOMAIN_APP', 'velocitydeveloper.ai.id'),
    'subdomain_cadangan' => ['www', 'app', 'ai', 'api', 'admin', 'mail', 'dash', 'panel', 'blog', 'status', 'docs', 'cdn', 'static', 'assets', 'mx', 'smtp', 'imap', 'ns1', 'ns2', 'ftp', 'cpanel', 'webmail'],
    // Custom domain app: relay HTTPS di VPS Sumopod (nginx + certbot) → WireGuard → router nginx PC :8188.
    // Pelanggan mengarahkan A record ke relay_ip; relay menarik daftar domain aktif dari /relay/domain memakai relay_token.
    'relay_ip' => env('CLOUD_RELAY_IP', '43.159.35.76'),
    'relay_token' => env('CLOUD_RELAY_TOKEN'),
    'whatsapp' => env('CLOUD_WHATSAPP', '6282131939422'),

    // Analitik landing: log beacon nginx (lihat /etc/nginx/conf.d/velocitydeveloper-ai-id.conf) diimpor tiap menit.
    'analitik' => [
        'log' => env('CLOUD_ANALITIK_LOG', '/var/log/nginx/velocitydeveloper-analitik.log'),
        'ip_internal' => array_filter(explode(',', env('CLOUD_ANALITIK_IP_INTERNAL', ''))), // IP/CIDR kantor
        'ddns_internal' => env('CLOUD_ANALITIK_DDNS_INTERNAL', 'ip-kantor.velocity.ai.id'), // DDNS IP dinamis kantor (Indibiz)
        'simpan_hari' => 400,
    ],

    // Google Search Console (Admin › SEO): service account gscvd-agent (izin Terbatas), kunci dipakai bersama velocity-agent.
    // Token bersama dengan server bot (/etc/velocity/secrets/agen-diskusi.token) untuk POST /internal/agen/diskusi.
    'agen_diskusi_token' => env('AGEN_DISKUSI_TOKEN', ''),

    'gsc' => [
        'kredensial' => env('GSC_KREDENSIAL', '/etc/velocity/secrets/gsc-velocitydeveloper-co.json'),
        'situs' => env('GSC_SITUS', 'sc-domain:velocitydatacenter.com'),
        // backlog riset kata kunci bot Kang SEO (tabel markdown) → Admin › SEO › Keyword Mapping
        'riset_kata_kunci' => env('GSC_RISET_KATA_KUNCI', '/srv/velocity-bot/4b25d5fb8f/riset-kata-kunci.md'),
        // kata kunci negatif (satu per baris), diatur dari Keyword Mapping, dibaca juga oleh Kang SEO saat riset
        'kata_negatif' => env('GSC_KATA_NEGATIF', '/srv/velocity-bot/4b25d5fb8f/kata-negatif.txt'),
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
        'network' => 'vpsbr0', // bridge khusus VM pelanggan (incusbr0 dipakai VM lain di node)
        'pool' => 'default',
        'client_cert' => env('VPS_INCUS_CERT', '/etc/velocity/secrets/incus-client.crt'),
        'client_key' => env('VPS_INCUS_KEY', '/etc/velocity/secrets/incus-client.key'),
        'paket' => ['vps-cloud-1', 'vps-cloud-2', 'vps-cloud-3', 'vps-cloud-4-80-gb'], // dibuat otomatis; paket lain tetap manual (pemasok)
        'image_server' => 'https://images.linuxcontainers.org',
        // Urutan & penamaan mengikuti Sumopod (25 Nov). OpenCloudOS, CentOS 7, Debian 10/11 tidak ada image VM di images.linuxcontainers.org.
        'os' => [
            'ubuntu-24.04' => ['nama' => 'Ubuntu Server 24.04 LTS 64bit', 'alias' => 'ubuntu/24.04/cloud'],
            'ubuntu-22.04' => ['nama' => 'Ubuntu Server 22.04 LTS 64bit', 'alias' => 'ubuntu/22.04/cloud'],
            'centos-stream-9' => ['nama' => 'CentOS Stream 9 64bit', 'alias' => 'centos/9-Stream/cloud'],
            'debian-12' => ['nama' => 'Debian 12 64bit', 'alias' => 'debian/12/cloud'],
            'rocky-9' => ['nama' => 'Rocky Linux 9 64bit', 'alias' => 'rockylinux/9/cloud'],
            'ubuntu-26.04' => ['nama' => 'Ubuntu Server 26.04 LTS 64bit', 'alias' => 'ubuntu/26.04/cloud'],
            'debian-13' => ['nama' => 'Debian 13 64bit', 'alias' => 'debian/13/cloud'],
            // aaPanel dipasang cloud-init (±5-10 mnt sesudah aktif; uji matsuba 2 mnt) di port tambahan pertama VM; login velocity + sandi root (aaPanel menolak admin/root).
            'aapanel-debian-12' => ['nama' => 'aaPanel (Debian 12)', 'alias' => 'debian/12/cloud', 'aapanel' => true],
        ],
    ],

    // Email masuk support@ → tiket (tiket:ambil-email tiap menit, IMAP lewat scripts/ambil-email.py). Kosong/tak terbaca = mati.
    'tiket_email' => [
        'kredensial' => env('TIKET_EMAIL_KREDENSIAL', '/etc/velocity/secrets/smtp_velocitydatacenter_support'),
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

    // Jualan domain: harga & pembelian lewat API publik CRM new-vdnet (satu PC, nginx :8174). Perpanjangan ditagih CRM.
    'crm' => [
        'url' => env('CRM_API_URL', 'http://127.0.0.1:8174/api/api/public'),
        'key' => env('CRM_API_KEY'),
        'sumber' => 'app.velocitydatacenter.com', // dicatat di order CRM bersama nomor tagihan (kunci idempoten)
        // kelola domain milik customer (nameserver dsb.): API panel customer-vdnet CRM, header vdnet-key = CUSTOMER_VDNET_KEY CRM
        'customer_url' => env('CRM_CUSTOMER_URL', 'http://127.0.0.1:8174/api/api/customer-vdnet'),
        'customer_key' => env('CRM_CUSTOMER_KEY'),
        // arah sebaliknya: CRM membaca layanan per email lewat /api/crm/layanan (Bearer)
        'masuk_key' => env('CRM_MASUK_KEY'),
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
        '9router' => [
            // Image resmi decolua/9router (Next.js, port 20128, data SQLite di /app/data). Login dashboard = sandi saja (INITIAL_PASSWORD;
            // tanpa env ini sandi bawaan 123456). REQUIRE_API_KEY: /v1 tanpa API key = 401, karena endpoint terbuka ke internet.
            // API_KEY_SECRET = HMAC API key buatan pelanggan (bawaan publik di repo); env pod tetap sama saat restart, jadi key tetap sah.
            'port' => 20128,
            'containers' => [
                ['name' => '9router', 'image' => 'docker.io/decolua/9router:0.5.123', 'env' => [
                    'INITIAL_PASSWORD' => '{sandi}',
                    'API_KEY_SECRET' => '{rahasia}',
                    'REQUIRE_API_KEY' => 'true',
                    'AUTH_COOKIE_SECURE' => 'true',
                    'NEXT_PUBLIC_BASE_URL' => 'https://{host}',
                    'TZ' => 'Asia/Jakarta',
                ], 'volume' => '/app/data'],
            ],
            'limit' => $limit('9router'),
        ],
    ],
];

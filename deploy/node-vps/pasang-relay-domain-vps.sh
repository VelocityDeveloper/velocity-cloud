#!/usr/bin/env bash
# Custom domain VPS (25 Nov 2026). Jalankan sebagai root di relay Sumopod (mesin yang sama dengan vc-relay-sync & relay VPS wg1).
#   bash pasang-relay-domain-vps.sh <TOKEN> [URL_APP] [HOST_NODE]
#   TOKEN = CLOUD_RELAY_TOKEN di .env dashboard; URL_APP bawaan https://app.velocitydatacenter.com
#   HOST_NODE = vps_host node ber-IP publik (dipakai vc-edge di node itu, mis. matsuba.velocity.ai.id); kosong = relay Sumopod
# www.<domain> ikut dilayani (satu sertifikat) bila A record-nya sama dengan domain.
# Tiap menit menarik GET /relay/domain-vps ([{domain, ip}]) lalu menulis /etc/nginx/conf.d/vc-vps-<domain>.conf:
# SSL Let's Encrypt diterbitkan di relay, lalu lintas diteruskan HTTP ke VM (10.77.0.N) port 80 lewat wg1.
# Terpisah dari vc-relay-sync (domain app, berkas vc-relay-*.conf) agar tidak saling menghapus.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }
TOKEN=${1:?TOKEN wajib (CLOUD_RELAY_TOKEN)}
URL=${2:-https://app.velocitydatacenter.com}
HOST=${3:-}
command -v nginx >/dev/null && command -v certbot >/dev/null && command -v python3 >/dev/null \
  || { echo "nginx, certbot, python3 wajib ada (seharusnya sudah dari vc-relay-sync)."; exit 1; }

(umask 077; printf 'URL=%s\nTOKEN=%s\nHOST=%s\n' "$URL" "$TOKEN" "$HOST" > /etc/vc-relay-vps.env)
install -d -m 755 /var/www/vc-vps-acme /var/lib/vc-relay-vps

cat > /usr/local/bin/vc-relay-vps-sync <<'PY'
#!/usr/bin/env python3
"""Sinkron domain VPS dari dashboard ke nginx relay. Gagal menarik daftar = tidak mengubah apa pun."""
import glob, ipaddress, json, os, re, socket, subprocess, sys, time, urllib.parse, urllib.request

CONF = '/etc/nginx/conf.d/vc-vps-{}.conf'
LIVE = '/etc/letsencrypt/live/vc-vps-{}/fullchain.pem'
GAGAL = '/var/lib/vc-relay-vps/gagal-{}'
NAMA = '/var/lib/vc-relay-vps/nama-{}'  # nama yang ada di sertifikat (domain [+ www])
ACME = '/var/www/vc-vps-acme'
JEDA_GAGAL = 1800  # batas gagal Let's Encrypt 5/jam per domain: coba lagi tiap 30 menit
POLA = re.compile(r'^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$')
SUBNET = ipaddress.ip_network('10.77.0.0/24')


def log(pesan):
    print(time.strftime('%F %T'), pesan, flush=True)


def daftar():
    env = dict(l.strip().split('=', 1) for l in open('/etc/vc-relay-vps.env') if '=' in l)
    q = '?host=' + urllib.parse.quote(env['HOST']) if env.get('HOST') else ''
    req = urllib.request.Request(env['URL'].rstrip('/') + '/relay/domain-vps' + q, headers={
        'Authorization': 'Bearer ' + env['TOKEN'], 'Accept': 'application/json', 'User-Agent': 'vc-relay-vps-sync'})
    hasil = {}
    for d in json.load(urllib.request.urlopen(req, timeout=20)):
        dom, ip = str(d.get('domain', '')).lower(), str(d.get('ip', ''))
        try:
            if POLA.match(dom) and ipaddress.ip_address(ip) in SUBNET:
                hasil[dom] = ip
        except ValueError:
            pass
    return hasil


def alamat(nama):
    try:
        return {a[4][0] for a in socket.getaddrinfo(nama, 80, socket.AF_INET)}
    except OSError:
        return set()


def nama_ingin(dom):
    """domain + www.domain bila www menunjuk ke alamat yang sama (A record / proxy Cloudflare yang sama)."""
    a = alamat(dom)
    return [dom, 'www.' + dom] if a and not dom.startswith('www.') and alamat('www.' + dom) == a else [dom]


def nama_sert(dom):
    try:
        return open(NAMA.format(dom)).read().split()
    except OSError:
        return [dom]  # sertifikat lama (sebelum ada berkas nama) hanya domain


def conf(dom, ip):
    proxy = f"""
        proxy_pass http://{ip}:80;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $http_connection;
        proxy_read_timeout 300s;
        proxy_connect_timeout 10s;"""
    ssl = os.path.exists(LIVE.format(dom))
    nama = ' '.join(nama_sert(dom) if ssl else nama_ingin(dom))
    teks = f"""# dikelola vc-relay-vps-sync, jangan diedit
server {{
    listen 80;
    server_name {nama};
    client_max_body_size 256m;
    location /.well-known/acme-challenge/ {{ root {ACME}; }}
    location / {{ {'return 301 https://$host$request_uri;' if ssl else proxy} }}
}}
"""
    if ssl:
        teks += f"""server {{
    listen 443 ssl;
    server_name {nama};
    client_max_body_size 256m;
    ssl_certificate /etc/letsencrypt/live/vc-vps-{dom}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/vc-vps-{dom}/privkey.pem;
    # perpanjangan lewat Cloudflare "Always Use HTTPS" dialihkan ke 443: tantangan ACME tetap dijawab di sini
    location /.well-known/acme-challenge/ {{ root {ACME}; }}
    location / {{ {proxy} }}
}}
"""
    return teks


def terapkan(ingin):
    """Tulis/hapus berkas conf; bila nginx -t gagal semua dikembalikan. Kembalikan True bila ada yang berubah."""
    lama = {f: open(f).read() for f in glob.glob(CONF.format('*'))}
    baru = {CONF.format(d): conf(d, ip) for d, ip in ingin.items()}
    if lama == baru:
        return False
    for f in lama.keys() - baru.keys():
        os.remove(f)
    for f, isi in baru.items():
        if lama.get(f) != isi:
            open(f, 'w').write(isi)
    if subprocess.run(['nginx', '-t'], capture_output=True).returncode != 0:
        for f in baru.keys() - lama.keys():
            os.remove(f)
        for f, isi in lama.items():
            open(f, 'w').write(isi)
        log('nginx -t gagal, perubahan dibatalkan')
        return False
    subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
    log('nginx dimuat ulang: ' + ', '.join(sorted(ingin)) if ingin else 'nginx dimuat ulang: tanpa domain VPS')
    return True


def main():
    try:
        ingin = daftar()
    except Exception as e:  # dashboard tak terjangkau: biarkan konfigurasi apa adanya
        log(f'gagal menarik daftar: {e}')
        return 1
    terapkan(ingin)
    terbit = False
    for dom in ingin:
        nama = nama_ingin(dom)
        if os.path.exists(LIVE.format(dom)) and set(nama) <= set(nama_sert(dom)):
            continue
        tanda = GAGAL.format(dom)
        if os.path.exists(tanda) and time.time() - os.path.getmtime(tanda) < JEDA_GAGAL:
            continue
        r = subprocess.run(['certbot', 'certonly', '--webroot', '-w', ACME, *sum((['-d', n] for n in nama), []), '--cert-name', 'vc-vps-' + dom,
                            '--expand', '--deploy-hook', 'systemctl reload nginx',
                            '--non-interactive', '--agree-tos', '--register-unsafely-without-email'], capture_output=True, text=True)
        if r.returncode == 0:
            terbit = True
            open(NAMA.format(dom), 'w').write(' '.join(nama))
            log(f'SSL terbit: {" ".join(nama)}')
            if os.path.exists(tanda):
                os.remove(tanda)
        else:
            open(tanda, 'w').write(r.stderr[-2000:])
            log(f'SSL gagal: {dom} (coba lagi 30 menit)')
    if terbit:
        terapkan(ingin)
    return 0


sys.exit(main())
PY
chmod 755 /usr/local/bin/vc-relay-vps-sync

echo 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' > /etc/cron.d/vc-relay-vps  # cron tanpa /usr/sbin: nginx -t gagal FileNotFoundError (25 Nov, cfbeauty)
echo '* * * * * root flock -n /run/vc-relay-vps.lock /usr/local/bin/vc-relay-vps-sync >> /var/log/vc-relay-vps.log 2>&1' >> /etc/cron.d/vc-relay-vps
cat > /etc/logrotate.d/vc-relay-vps <<'EOF'
/var/log/vc-relay-vps.log { weekly rotate 4 compress missingok notifempty }
EOF

/usr/local/bin/vc-relay-vps-sync && echo "=== OK: vc-relay-vps-sync terpasang (cron tiap menit, log /var/log/vc-relay-vps.log) ==="

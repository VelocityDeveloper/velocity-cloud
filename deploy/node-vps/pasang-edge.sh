#!/usr/bin/env bash
# vc-edge (25 Nov 2026): custom domain VPS di node ber-IP publik (matsuba) TANPA web server di host node.
# Container unprivileged kecil di project velocity, IP tetap 10.77.0.254 (VM memakai .2–.253), tidak diisolasi
# agar bisa menjangkau VM (VM pelanggan memakai security.port_isolation). Host hanya DNAT node:80/443 → edge
# (tabel velocity_vps, pasang-node-publik.sh). Di dalam edge: nginx + certbot + vc-relay-vps-sync
# (pasang-relay-domain-vps.sh) yang menarik /relay/domain-vps?host=<HOST_NODE>, SSL Let's Encrypt di edge → VM port 80.
# Jalankan sebagai root di node, berkas pasang-relay-domain-vps.sh di folder yang sama:
#   bash pasang-edge.sh <berkas berisi CLOUD_RELAY_TOKEN> <HOST_NODE> [URL_APP]
# Aman diulang. Batal: incus delete --project velocity --force vc-edge (DNAT 80/443 lalu tak berujung).
set -euo pipefail
TOKEN_FILE=${1:?berkas berisi CLOUD_RELAY_TOKEN}
HOST_NODE=${2:?vps_host node, mis. matsuba.velocity.ai.id}
URL=${3:-https://app.velocitydatacenter.com}
P="--project velocity"
NAMA=vc-edge
EDGE_IP=10.77.0.254
DIR=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }
[ -s "$TOKEN_FILE" ] && [ -f "$DIR/pasang-relay-domain-vps.sh" ] || { echo "Token / pasang-relay-domain-vps.sh tidak ada."; exit 1; }

if ! incus info $P "$NAMA" >/dev/null 2>&1; then
  incus init $P images:debian/12 "$NAMA" -c limits.cpu=1 -c limits.memory=512MiB -c boot.autostart=true \
    -d root,size=5GiB
  incus config device override $P "$NAMA" eth0 ipv4.address=$EDGE_IP
  incus start $P "$NAMA"
fi
x() { incus exec $P "$NAMA" -- "$@"; }
for i in $(seq 30); do x sh -c 'getent hosts deb.debian.org >/dev/null' && break; sleep 2; done

x env DEBIAN_FRONTEND=noninteractive apt-get update -q
x env DEBIAN_FRONTEND=noninteractive apt-get install -y -q nginx certbot python3 nftables cron logrotate

# Firewall edge: masuk hanya 80/443; ke jaringan privat hanya VM port 80 + DNS/DHCP node (edge dibobol ≠ akses VM lain).
x sh -c 'cat > /etc/nftables.conf' <<'EOF'
#!/usr/sbin/nft -f
flush ruleset
table inet edge {
  chain input {
    type filter hook input priority filter; policy drop;
    iif lo accept
    ct state established,related accept
    tcp dport { 80, 443 } accept
    udp sport 67 udp dport 68 accept
    icmp type echo-request accept
  }
  chain output {
    type filter hook output priority filter; policy accept;
    oif lo accept
    ct state established,related accept comment "balasan, termasuk hairpin (sumber 10.77.0.1)"
    ip daddr 10.77.0.1 udp dport { 53, 67 } accept
    ip daddr 10.77.0.1 tcp dport 53 accept
    ip daddr 10.77.0.0/24 tcp dport 80 accept
    ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, 169.254.0.0/16 } reject comment "edge hanya ke VM port 80"
  }
}
EOF
x nft -c -f /etc/nftables.conf
x systemctl enable --now nftables
x systemctl restart nftables

# Domain tak dikenal: 80 ditutup, 443 tolak jabat tangan (tidak membocorkan sertifikat domain lain).
x sh -c 'rm -f /etc/nginx/sites-enabled/default; cat > /etc/nginx/conf.d/00-vc-default.conf' <<'EOF'
server {
    listen 80 default_server;
    server_name _;
    location /.well-known/acme-challenge/ { root /var/www/vc-vps-acme; }
    location / { return 444; }
}
server {
    listen 443 ssl default_server;
    server_name _;
    ssl_reject_handshake on;
}
EOF
x install -d -m 755 /var/www/vc-vps-acme
x nginx -t
x systemctl enable --now nginx cron
x systemctl reload nginx

incus file push $P "$DIR/pasang-relay-domain-vps.sh" "$NAMA/root/pasang-relay-domain-vps.sh"
x bash /root/pasang-relay-domain-vps.sh "$(cat "$TOKEN_FILE")" "$URL" "$HOST_NODE"
echo "=== OK: $NAMA $EDGE_IP siap. Uji: curl -sI http://<IP node>/ (444 = edge menjawab) ==="

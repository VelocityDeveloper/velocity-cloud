#!/usr/bin/env bash
# Node VPS ber-IP publik di DC (25 Nov 2026, matsuba): TANPA relay Sumopod.
# VM keluar lewat NAT IP publik node; SSH/port tambahan pelanggan = DNAT di node sendiri (skema port sama dgn relay).
# API Incus hanya lewat WireGuard wg-app dari PC app (tidak terbuka ke internet).
# Prasyarat: Incus sudah terpasang + storage pool "default" (lihat /root/pasca-instal.sh di node).
#   bash pasang-node-publik.sh <IP_PUBLIK_NODE> <KUNCI_PUBLIK_WG_PC_APP> <berkas incus-client.crt>
# Aman diulang. Node ini kemudian didaftarkan di app dengan URL https://10.79.0.1:8443 dan Host akses = hostname node.
set -euo pipefail
PUB_IP=${1:?IP publik node, mis. 202.73.26.2}
APP_PUB=${2:?kunci publik WireGuard PC app}
CLIENT_CRT=${3:?berkas incus-client.crt dari PC app}
SUBNET=10.77.0          # sama dengan config cloud.vps.subnet
PORT_SSH=22000          # node:(22000+N) → VM N port 22
PORT_EKSTRA=40000       # node:(40000+N*10 … +9) → VM N port yang sama
WG_PORT=51830
NODE_WG=10.79.0.1
APP_WG=10.79.0.2
PROJECT=velocity
export PATH=/usr/sbin:/sbin:$PATH
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }
[ -e /dev/kvm ] || { echo "/dev/kvm tidak ada."; exit 1; }
[ -f "$CLIENT_CRT" ] || { echo "Berkas $CLIENT_CRT tidak ada."; exit 1; }
IFACE=$(ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="dev") print $(i+1)}')
ip -4 addr show "$IFACE" | grep -q " $PUB_IP/" || { echo "$PUB_IP tidak ada di $IFACE."; exit 1; }

DEBIAN_FRONTEND=noninteractive apt-get install -y -q wireguard-tools nftables openssl

# 1. WireGuard wg-app: jalur API Incus dari PC app (PC di balik NAT kantor yang memulai koneksi).
install -d -m 700 /etc/wireguard
[ -f /etc/wireguard/app.key ] || (umask 077; wg genkey > /etc/wireguard/app.key)
wg pubkey < /etc/wireguard/app.key > /etc/wireguard/app.pub
(umask 077; cat > /etc/wireguard/wg-app.conf <<EOF
[Interface]
Address = $NODE_WG/30
ListenPort = $WG_PORT
PrivateKey = $(cat /etc/wireguard/app.key)

[Peer]
# PC app velocity-cloud
PublicKey = $APP_PUB
AllowedIPs = $APP_WG/32
EOF
)

# 2. Sertifikat server Incus dengan SAN IP WireGuard (app memverifikasi node dengan PEM ini).
if ! openssl x509 -in /var/lib/incus/server.crt -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:$NODE_WG"; then
  systemctl stop incus.service incus.socket || true
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -days 3650 -nodes \
    -keyout /var/lib/incus/server.key -out /var/lib/incus/server.crt -subj "/CN=$(hostname -f)" \
    -addext "subjectAltName=IP:$NODE_WG,DNS:$(hostname -f)" -addext "basicConstraints=critical,CA:TRUE" 2>/dev/null
  chmod 600 /var/lib/incus/server.key
  systemctl start incus.socket incus.service
fi
incus admin waitready --timeout=60
# Dengar di semua alamat agar tidak gagal saat boot sebelum wg-app naik; firewall hanya mengizinkan lewat wg-app.
incus config set core.https_address ":8443"

# 3. Bridge VM pelanggan: NAT keluar ke IP publik node.
incus network show vpsbr0 >/dev/null 2>&1 || incus network create vpsbr0 \
  ipv4.address=$SUBNET.1/24 ipv4.nat=true ipv4.nat.address=$PUB_IP ipv4.dhcp.ranges=$SUBNET.2-$SUBNET.254 ipv6.address=none

# 4. Project terbatas untuk app + sertifikat app (restricted ke project itu saja).
if ! incus project show "$PROJECT" >/dev/null 2>&1; then
  incus project create "$PROJECT" -c features.images=false
  incus profile device add default root disk path=/ pool=default --project "$PROJECT"
  incus profile device add default eth0 nic network=vpsbr0 name=eth0 --project "$PROJECT"
fi
incus project set "$PROJECT" restricted=true restricted.devices.nic=managed restricted.devices.disk=managed \
  restricted.virtual-machines.lowlevel=block restricted.containers.privilege=unprivileged restricted.networks.access=vpsbr0
FP=$(openssl x509 -in "$CLIENT_CRT" -noout -fingerprint -sha256 | cut -d= -f2 | tr -d : | tr A-F a-f)
incus config trust list --format csv | grep -qi "${FP:0:12}" || \
  incus config trust add-certificate "$CLIENT_CRT" --name velocity-cloud --restricted --projects "$PROJECT"

# 5. Firewall: tabel sendiri (DNAT port pelanggan + isolasi VM). Tabel inet filter host tetap policy drop.
{
  echo "table inet velocity_vps"
  echo "delete table inet velocity_vps"
  echo "table inet velocity_vps {"
  echo "  map ssh { type inet_service : ipv4_addr . inet_service; elements = {"
  for n in $(seq 2 254); do echo "    $((PORT_SSH + n)) : $SUBNET.$n . 22,"; done
  echo "  } }"
  echo "  map ekstra { type inet_service : ipv4_addr; flags interval; elements = {"
  for n in $(seq 2 254); do echo "    $((PORT_EKSTRA + n * 10))-$((PORT_EKSTRA + n * 10 + 9)) : $SUBNET.$n,"; done
  echo "  } }"
  cat <<EOF
  chain prerouting {
    type nat hook prerouting priority dstnat; policy accept;
    iifname "$IFACE" ip daddr $PUB_IP dnat ip addr . port to tcp dport map @ssh
    iifname "$IFACE" ip daddr $PUB_IP meta l4proto { tcp, udp } dnat ip to th dport map @ekstra
  }
  chain forward {
    type filter hook forward priority filter - 1; policy accept;
    ct state established,related accept
    iifname "vpsbr0" tcp dport 25 reject with tcp reset comment "anti-spam: SMTP keluar diblok"
    iifname "vpsbr0" ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } drop comment "VM tidak boleh ke jaringan privat"
    iifname "vpsbr0" oifname "$IFACE" accept
    iifname "$IFACE" oifname "vpsbr0" ct status dnat accept
    iifname "vpsbr0" drop
    oifname "vpsbr0" drop
  }
  chain input {
    type filter hook input priority filter - 1; policy accept;
    iifname "vpsbr0" udp dport { 53, 67 } accept
    iifname "vpsbr0" tcp dport 53 accept
    iifname "vpsbr0" ct state established,related accept
    iifname "vpsbr0" drop comment "VM tidak boleh mengakses node"
    tcp dport 8443 iifname != "wg-app" drop comment "API Incus hanya lewat wg-app"
  }
}
EOF
} > /etc/velocity-vps.nft
nft -c -f /etc/velocity-vps.nft

# Tabel host (/etc/nftables.conf) harus mengizinkan WireGuard + API di wg-app. Ganti 'flush ruleset' (menghapus tabel
# Incus & velocity_vps saat nftables dimuat ulang) dengan hapus-tabel-sendiri.
if grep -q '^flush ruleset' /etc/nftables.conf; then
  cp -a /etc/nftables.conf /etc/nftables.conf.bak-$(date +%Y%m%d%H%M)
  sed -i 's/^flush ruleset$/table inet filter\ndelete table inet filter/' /etc/nftables.conf
fi
grep -q "udp dport $WG_PORT accept" /etc/nftables.conf || \
  sed -i "s/^\(\s*\)ip protocol icmp accept$/&\n\1udp dport $WG_PORT accept\n\1iifname \"wg-app\" tcp dport 8443 accept\n\1iifname \"vpsbr0\" udp dport { 53, 67 } accept\n\1iifname \"vpsbr0\" tcp dport 53 accept/" /etc/nftables.conf
nft -c -f /etc/nftables.conf
nft -f /etc/nftables.conf

cat > /etc/systemd/system/velocity-vps-jaringan.service <<EOF
[Unit]
Description=Velocity Cloud node VPS publik: DNAT port pelanggan + isolasi VM
After=network-online.target nftables.service
Before=incus.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/velocity-vps.nft
ExecStop=/usr/sbin/nft delete table inet velocity_vps

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable velocity-vps-jaringan wg-quick@wg-app
systemctl restart velocity-vps-jaringan
systemctl restart wg-quick@wg-app

cat <<EOF

=== Node publik siap ===
Kunci publik WireGuard node : $(cat /etc/wireguard/app.pub)
Endpoint WireGuard node     : $PUB_IP:$WG_PORT
Di app (Admin > Node > Tambah): jenis "VPS (Incus KVM)", URL https://$NODE_WG:8443, Host akses $(hostname -f),
tempel sertifikat server berikut:

$(cat /var/lib/incus/server.crt)
EOF

#!/usr/bin/env bash
# Node VPS Velocity Cloud (13 Nov 2026): Incus (VM KVM) di PC kantor, VM keluar internet HANYA lewat relay WireGuard.
# Jalankan sebagai root di Debian 13 (minimal + SSH) sesudah pasang-relay.sh:
#   bash pasang-node.sh <ENDPOINT_RELAY ip:51820> <KUNCI_PUBLIK_RELAY> <IP_LAN_APP> <berkas incus-client.crt>
# Aman diulang. Yang dijamin:
#   - VM tidak bisa menjangkau LAN kantor / internet kantor (IP kantor di-whitelist server hosting);
#     WireGuard putus = VM tanpa internet (blackhole), bukan bocor lewat kantor.
#   - API Incus :8443 hanya untuk IP app, sertifikat app dibatasi ke project "velocity" (restricted).
set -euo pipefail
RELAY=${1:?endpoint relay, mis. 203.0.113.10:51820}
RELAY_PUB=${2:?kunci publik relay}
APP_IP=${3:?IP LAN PC app, mis. 192.168.72.177}
CLIENT_CRT=${4:?berkas incus-client.crt dari PC app}
SUBNET=10.77.0
NODE_WG=10.78.0.2
PROJECT=velocity
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }
grep -Eq 'vmx|svm' /proc/cpuinfo || { echo "CPU tanpa VT-x/AMD-V: aktifkan Virtualization di BIOS."; exit 1; }
[ -f "$CLIENT_CRT" ] || { echo "Berkas $CLIENT_CRT tidak ada."; exit 1; }
LAN_IP=$(ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')
echo "IP LAN node: $LAN_IP"

# 1. Paket: Incus dari repo resmi Zabbly (sudah termasuk QEMU/OVMF untuk VM).
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q curl gpg wireguard-tools nftables unattended-upgrades
install -d -m 755 /etc/apt/keyrings
curl -fsSL https://pkgs.zabbly.com/key.asc -o /etc/apt/keyrings/zabbly.asc
cat > /etc/apt/sources.list.d/zabbly-incus-stable.sources <<EOF
Enabled: yes
Types: deb
URIs: https://pkgs.zabbly.com/incus/stable
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: main
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/zabbly.asc
EOF
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q incus
[ -e /dev/kvm ] || { echo "/dev/kvm tidak ada: cek BIOS (VT-x/SVM)."; exit 1; }

# 2. Sertifikat server Incus dengan SAN = IP LAN, agar app bisa memverifikasi node (dicetak di akhir).
if ! openssl x509 -in /var/lib/incus/server.crt -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:$LAN_IP"; then
  systemctl stop incus.service incus.socket || true
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -days 3650 -nodes \
    -keyout /var/lib/incus/server.key -out /var/lib/incus/server.crt -subj "/CN=velocity-node-vps" \
    -addext "subjectAltName=IP:$LAN_IP" -addext "basicConstraints=critical,CA:TRUE" 2>/dev/null
  chmod 600 /var/lib/incus/server.key
  systemctl start incus.socket incus.service
fi
incus admin waitready --timeout=60

# 3. Inisialisasi: storage dir, bridge tanpa NAT (keluar lewat WireGuard), API di IP LAN.
incus storage show default >/dev/null 2>&1 || incus storage create default dir
incus network show incusbr0 >/dev/null 2>&1 || incus network create incusbr0 \
  ipv4.address=$SUBNET.1/24 ipv4.nat=false ipv4.dhcp.ranges=$SUBNET.2-$SUBNET.254 ipv6.address=none
incus profile device show default | grep -q '^root:' || incus profile device add default root disk path=/ pool=default
incus config set core.https_address "$LAN_IP:8443"

# 4. Project terbatas untuk app + sertifikat app (restricted ke project itu saja).
if ! incus project show "$PROJECT" >/dev/null 2>&1; then
  incus project create "$PROJECT" -c features.images=false -c features.profiles=false
fi
incus project set "$PROJECT" restricted=true restricted.devices.nic=managed restricted.devices.disk=managed \
  restricted.virtual-machines.lowlevel=block restricted.containers.privilege=unprivileged
FP=$(openssl x509 -in "$CLIENT_CRT" -noout -fingerprint -sha256 | cut -d= -f2 | tr -d : | tr A-F a-f)
incus config trust list --format csv | grep -qi "${FP:0:12}" || \
  incus config trust add-certificate "$CLIENT_CRT" --name velocity-cloud --restricted --projects "$PROJECT"

# 5. WireGuard ke relay (Table=off: hanya lalu lintas VM yang dirutekan ke sini).
install -d -m 700 /etc/wireguard
[ -f /etc/wireguard/node.key ] || (umask 077; wg genkey > /etc/wireguard/node.key)
wg pubkey < /etc/wireguard/node.key > /etc/wireguard/node.pub
(umask 077; cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = $NODE_WG/30
PrivateKey = $(cat /etc/wireguard/node.key)
Table = off
PostUp = ip route replace default dev wg0 metric 100 table 77
PostDown = ip route del default dev wg0 table 77 || true

[Peer]
PublicKey = $RELAY_PUB
Endpoint = $RELAY
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
)

# 6. Rute kebijakan: semua dari VM → tabel 77 (wg0); tanpa wg0 tersisa blackhole (gagal tertutup).
cat > /usr/local/sbin/velocity-vps-rute <<EOF
#!/bin/sh
while ip rule del priority 999 2>/dev/null; do :; done
while ip rule del priority 1000 2>/dev/null; do :; done
ip rule add from $SUBNET.0/24 to $SUBNET.0/24 lookup main priority 999
ip rule add from $SUBNET.0/24 lookup 77 priority 1000
ip route replace blackhole default metric 4000 table 77
EOF
chmod 755 /usr/local/sbin/velocity-vps-rute

# 7. Firewall node.
cat > /etc/velocity-vps.nft <<EOF
table inet velocity_vps
delete table inet velocity_vps
table inet velocity_vps {
  chain forward {
    type filter hook forward priority filter - 1; policy accept;
    iifname "incusbr0" oifname != "wg0" drop comment "VM hanya boleh keluar lewat relay"
    iifname "wg0" oifname != "incusbr0" drop comment "relay hanya boleh ke VM"
  }
  chain input {
    type filter hook input priority filter - 1; policy accept;
    iifname "incusbr0" udp dport { 53, 67 } accept
    iifname "incusbr0" tcp dport 53 accept
    iifname "incusbr0" ct state established,related accept
    iifname "incusbr0" drop comment "VM tidak boleh mengakses node"
    iifname "wg0" ct state established,related accept
    iifname "wg0" drop
    tcp dport 8443 ip saddr != $APP_IP drop comment "API Incus hanya untuk PC app"
  }
}
EOF
nft -c -f /etc/velocity-vps.nft

cat > /etc/systemd/system/velocity-vps-jaringan.service <<EOF
[Unit]
Description=Velocity Cloud node VPS: rute kebijakan + firewall VM (sebelum Incus & WireGuard)
After=network-online.target
Before=incus.service wg-quick@wg0.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/velocity-vps-rute
ExecStart=/usr/sbin/nft -f /etc/velocity-vps.nft

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/sysctl.d/90-velocity-vps.conf <<EOF
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 2
net.ipv4.conf.default.rp_filter = 2
EOF
sysctl -q --system
systemctl daemon-reload
systemctl enable velocity-vps-jaringan wg-quick@wg0
systemctl restart velocity-vps-jaringan
systemctl restart wg-quick@wg0

cat <<EOF

=== Node siap ===
1) Di relay jalankan:  bash pasang-relay.sh peer $(cat /etc/wireguard/node.pub)
2) Di app (Admin > Node > Tambah): jenis "VPS (Incus KVM)", URL https://$LAN_IP:8443,
   kapasitas = jumlah VPS maksimal (16 GB RAM: 4-5), tempel sertifikat server berikut:

$(cat /var/lib/incus/server.crt)
EOF

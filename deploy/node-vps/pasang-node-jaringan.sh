#!/usr/bin/env bash
set -euo pipefail
SUBNET=10.77.0
NODE_WG=10.78.0.2
LAN_IP=$(ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')
export PATH=/usr/sbin:/sbin:$PATH
[ "$(id -u)" = 0 ]
# VARIAN (14 Nov): hanya jaringan node (WireGuard + rute + nft), tanpa pasang Incus (sudah ada dari Debian) dan memakai bridge vpsbr0.
RELAY_PUB=${RELAY_PUB:?}; RELAY=${RELAY:?}; APP_IP=${APP_IP:?}
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
    iifname "vpsbr0" oifname != "wg0" drop comment "VM hanya boleh keluar lewat relay"
    iifname "wg0" oifname != "vpsbr0" drop comment "relay hanya boleh ke VM"
  }
  chain input {
    type filter hook input priority filter - 1; policy accept;
    iifname "vpsbr0" udp dport { 53, 67 } accept
    iifname "vpsbr0" tcp dport 53 accept
    iifname "vpsbr0" ct state established,related accept
    iifname "vpsbr0" drop comment "VM tidak boleh mengakses node"
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

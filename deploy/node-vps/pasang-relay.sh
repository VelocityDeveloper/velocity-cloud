#!/usr/bin/env bash
# Relay publik VPS Velocity Cloud (13 Nov 2026). Jalankan sebagai root di VPS ber-IP publik (Debian 12/13, Ubuntu 24.04).
#   bash pasang-relay.sh                  → pasang WireGuard + firewall, cetak kunci publik relay
#   bash pasang-relay.sh peer <PUBKEY>    → daftarkan node (kunci publik dari pasang-node.sh)
# Semua lalu lintas VM pelanggan keluar lewat IP relay ini; port SSH/tambahan diteruskan statis ke VM.
set -euo pipefail
SUBNET=10.77.0          # IP VM = SUBNET.N (N 2..254), sama dengan config cloud.vps.subnet
RELAY_WG=10.78.0.1
NODE_WG=10.78.0.2
WG_PORT=51820
PORT_SSH=22000          # relay:(22000+N) → VM N port 22
PORT_EKSTRA=40000       # relay:(40000+N*10 … +9) → VM N port yang sama
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }
IFACE=$(ip route show default | awk '{print $5; exit}')

if [ "${1:-}" = peer ]; then
  PUB=${2:?kunci publik node wajib}
  grep -q "$PUB" /etc/wireguard/wg0.conf || cat >> /etc/wireguard/wg0.conf <<EOF

[Peer]
# node VPS (PC kantor)
PublicKey = $PUB
AllowedIPs = $NODE_WG/32, $SUBNET.0/24
EOF
  systemctl restart wg-quick@wg0
  wg show wg0
  exit 0
fi

apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q wireguard-tools nftables unattended-upgrades
install -d -m 700 /etc/wireguard
[ -f /etc/wireguard/relay.key ] || (umask 077; wg genkey > /etc/wireguard/relay.key)
wg pubkey < /etc/wireguard/relay.key > /etc/wireguard/relay.pub
[ -f /etc/wireguard/wg0.conf ] || (umask 077; cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = $RELAY_WG/30
ListenPort = $WG_PORT
PrivateKey = $(cat /etc/wireguard/relay.key)
EOF
)

cat > /etc/sysctl.d/90-velocity-relay.conf <<EOF
net.ipv4.ip_forward = 1
EOF
sysctl -q --system

# Tabel sendiri (tidak menyentuh /etc/nftables.conf milik penyedia).
{
  echo "table ip velocity_relay"
  echo "delete table ip velocity_relay"
  echo "table ip velocity_relay {"
  echo "  map ssh { type inet_service : ipv4_addr . inet_service; elements = {"
  for n in $(seq 2 254); do echo "    $((PORT_SSH + n)) : $SUBNET.$n . 22,"; done
  echo "  } }"
  echo "  map ekstra { type inet_service : ipv4_addr; flags interval; elements = {"
  for n in $(seq 2 254); do echo "    $((PORT_EKSTRA + n * 10))-$((PORT_EKSTRA + n * 10 + 9)) : $SUBNET.$n,"; done
  echo "  } }"
  cat <<EOF
  chain prerouting {
    type nat hook prerouting priority dstnat; policy accept;
    iifname "$IFACE" dnat ip addr . port to tcp dport map @ssh
    iifname "$IFACE" meta l4proto { tcp, udp } dnat ip to th dport map @ekstra
  }
  chain postrouting {
    type nat hook postrouting priority srcnat; policy accept;
    oifname "$IFACE" ip saddr $SUBNET.0/24 masquerade
  }
  chain forward {
    type filter hook forward priority filter; policy accept;
    ct state established,related accept
    iifname "wg0" ip saddr $SUBNET.0/24 tcp dport 25 reject with tcp reset comment "anti-spam: SMTP keluar diblok"
    iifname "wg0" oifname "$IFACE" ip saddr $SUBNET.0/24 accept
    iifname "$IFACE" oifname "wg0" ct status dnat accept
    iifname "wg0" drop
    oifname "wg0" drop
  }
  chain input {
    type filter hook input priority filter; policy accept;
    iifname "wg0" ct state established,related accept
    iifname "wg0" drop comment "node/VM tidak boleh mengakses relay"
  }
}
EOF
} > /etc/velocity-relay.nft
nft -c -f /etc/velocity-relay.nft

cat > /etc/systemd/system/velocity-relay-firewall.service <<EOF
[Unit]
Description=Velocity Cloud relay VPS: DNAT port + NAT keluar VM
After=network-online.target
Before=wg-quick@wg0.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/velocity-relay.nft
ExecStop=/usr/sbin/nft delete table ip velocity_relay

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now velocity-relay-firewall wg-quick@wg0
systemctl restart velocity-relay-firewall

IP=$(curl -4 -s --max-time 10 https://ifconfig.me || echo "<IP-PUBLIK-RELAY>")
cat <<EOF

=== Relay siap ===
Endpoint relay     : $IP:$WG_PORT   (buka UDP $WG_PORT di firewall panel penyedia)
Kunci publik relay : $(cat /etc/wireguard/relay.pub)
Port pelanggan     : TCP $((PORT_SSH + 2))-$((PORT_SSH + 254)) (SSH), TCP/UDP $((PORT_EKSTRA + 20))-$((PORT_EKSTRA + 2549)) (tambahan)
Langkah berikut    : jalankan pasang-node.sh di PC node dengan endpoint & kunci di atas,
                     lalu di sini: bash pasang-relay.sh peer <KUNCI-PUBLIK-NODE>
EOF

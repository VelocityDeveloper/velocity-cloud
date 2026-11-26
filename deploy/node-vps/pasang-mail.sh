#!/usr/bin/env bash
# vc-mail (26 Nov 2026): relay SMTP keluar untuk semua VM di node ber-IP publik (matsuba).
# VM pelanggan diblok port 25 keluar (forward velocity_vps, pasang-node-publik.sh); satu-satunya jalur kirim email:
#   VM → vc-mail:587 (STARTTLS wajib + SASL per akun) → Postfix + OpenDKIM → internet:25 lewat IP node (NAT incus).
# Container unprivileged di project velocity, IP tetap 10.77.0.253, tidak diisolasi (VM port_isolation tetap bisa menjangkau).
# shortcut: .253 juga nomor VM terakhir di ProvisionVps::nomorBebas (2..253, kapasitas node 20 → tak tercapai);
#   bila kapasitas node > 250, ubah range jadi 2..252. Peta DNAT ssh/ekstra .253 ikut mengarah ke sini → ditolak firewall container.
# Akun/domain dikelola di dalam container: vc-mail-akun tambah <akun> <domain> | daftar | hapus <akun>.
# Jalankan sebagai root di node: bash pasang-mail.sh <HOST_NODE>   (aman diulang)
# Batal: incus delete --project velocity --force vc-mail + hapus rule "vc-mail" di /etc/velocity-vps.nft.
set -euo pipefail
HOST_NODE=${1:?vps_host node, mis. matsuba.velocity.ai.id (harus = PTR IP node)}
P="--project velocity"
NAMA=vc-mail
MAIL_IP=10.77.0.253
SUBNET=10.77.0
[ "$(id -u)" = 0 ] || { echo "Jalankan sebagai root."; exit 1; }

if ! incus info $P "$NAMA" >/dev/null 2>&1; then
  incus init $P images:debian/12 "$NAMA" -c limits.cpu=1 -c limits.memory=512MiB -c boot.autostart=true \
    -d root,size=5GiB
  incus config device override $P "$NAMA" eth0 ipv4.address=$MAIL_IP
  incus start $P "$NAMA"
fi
x() { incus exec $P "$NAMA" -- "$@"; }
for i in $(seq 30); do x sh -c 'getent hosts deb.debian.org >/dev/null' && break; sleep 2; done

x sh -c 'echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections'
x env DEBIAN_FRONTEND=noninteractive apt-get update -q
x env DEBIAN_FRONTEND=noninteractive apt-get install -y -q postfix dovecot-core opendkim opendkim-tools ssl-cert \
  nftables rsyslog logrotate

# Firewall: masuk hanya 587 dari subnet VM; keluar ke jaringan privat hanya DNS/DHCP node.
x sh -c 'cat > /etc/nftables.conf' <<EOF
#!/usr/sbin/nft -f
flush ruleset
table inet mail {
  chain input {
    type filter hook input priority filter; policy drop;
    iif lo accept
    ct state established,related accept
    ip saddr $SUBNET.0/24 tcp dport 587 accept
    udp sport 67 udp dport 68 accept
    icmp type echo-request accept
  }
  chain output {
    type filter hook output priority filter; policy accept;
    oif lo accept
    ct state established,related accept
    ip daddr $SUBNET.1 udp dport { 53, 67 } accept
    ip daddr $SUBNET.1 tcp dport 53 accept
    ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, 169.254.0.0/16 } reject comment "vc-mail tidak membuka koneksi ke VM/privat"
  }
}
EOF
x nft -c -f /etc/nftables.conf
x systemctl enable --now nftables
x systemctl restart nftables

x install -d -m 751 -o root -g dovecot /etc/vc-mail
x install -d -m 750 -o opendkim -g opendkim /etc/vc-mail/dkim
x sh -c 'touch /etc/vc-mail/akun /etc/vc-mail/pengirim /etc/vc-mail/dkim/keytable /etc/vc-mail/dkim/signingtable
  chown root:dovecot /etc/vc-mail/akun; chmod 640 /etc/vc-mail/akun; chown opendkim:opendkim /etc/vc-mail/dkim/*'

# Postfix: tanpa penerimaan port 25 & tanpa pengiriman lokal; 587 = STARTTLS wajib + SASL, pengirim harus domain milik akun.
x sh -c 'cat > /etc/postfix/main.cf' <<EOF
compatibility_level = 3.6
myhostname = $HOST_NODE
myorigin = \$myhostname
mydestination =
local_recipient_maps =
local_transport = error:vc-mail tidak menerima email lokal
relay_domains =
mynetworks = 127.0.0.0/8
inet_interfaces = all
inet_protocols = ipv4
smtpd_banner = \$myhostname ESMTP
biff = no
append_dot_mydomain = no
alias_maps =
alias_database =
message_size_limit = 26214400
maximal_queue_lifetime = 2d
bounce_queue_lifetime = 2d
smtp_tls_security_level = may
smtp_tls_loglevel = 1
smtpd_tls_cert_file = /etc/ssl/certs/ssl-cert-snakeoil.pem
smtpd_tls_key_file = /etc/ssl/private/ssl-cert-snakeoil.key
smtpd_tls_security_level = may
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sender_login_maps = hash:/etc/vc-mail/pengirim
smtpd_relay_restrictions = permit_sasl_authenticated, reject
smtpd_recipient_restrictions = permit_sasl_authenticated, reject
# batas per IP VM per jam (war tiket ±250 peserta x beberapa email); lewat batas = 4xx, VM mengantre ulang
anvil_rate_time_unit = 3600s
smtpd_client_message_rate_limit = 1000
smtpd_client_recipient_rate_limit = 3000
smtpd_client_connection_rate_limit = 2000
milter_default_action = accept
smtpd_milters = inet:127.0.0.1:8891
non_smtpd_milters = \$smtpd_milters
EOF
x sh -c "sed -i 's/^smtp\s\+inet\s.*smtpd\$/#& # vc-mail: port 25 masuk ditutup/' /etc/postfix/master.cf
  grep -q '^submission inet' /etc/postfix/master.cf || cat >> /etc/postfix/master.cf <<'EOF'
submission inet n       -       y       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_sender_restrictions=reject_sender_login_mismatch,permit_sasl_authenticated,reject
EOF"
x postmap /etc/vc-mail/pengirim

# Dovecot hanya untuk SASL (tanpa IMAP/POP), akun dari passwd-file, tanpa akun sistem (PAM).
x sed -i 's/^!include auth-system.conf.ext/#&/' /etc/dovecot/conf.d/10-auth.conf
x sh -c 'cat > /etc/dovecot/local.conf' <<'EOF'
protocols =
auth_mechanisms = plain login
disable_plaintext_auth = no
passdb {
  driver = passwd-file
  args = scheme=SHA512-CRYPT username_format=%u /etc/vc-mail/akun
}
userdb {
  driver = static
  args = uid=nobody gid=nogroup home=/nonexistent
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
EOF

# OpenDKIM: tanda tangan per domain (selector vcmail) untuk email dari subnet VM.
x sh -c 'cat > /etc/opendkim.conf' <<EOF
Syslog yes
UMask 007
Mode s
Canonicalization relaxed/simple
OversignHeaders From
KeyTable file:/etc/vc-mail/dkim/keytable
SigningTable refile:/etc/vc-mail/dkim/signingtable
InternalHosts 127.0.0.1, $SUBNET.0/24
Socket inet:8891@127.0.0.1
PidFile /run/opendkim/opendkim.pid
UserID opendkim
EOF
x sh -c 'sed -i "s|^SOCKET=.*|SOCKET=inet:8891@127.0.0.1|" /etc/default/opendkim 2>/dev/null || true
  [ -x /lib/opendkim/opendkim.service.generate ] && /lib/opendkim/opendkim.service.generate; systemctl daemon-reload'

x sh -c 'cat > /usr/local/sbin/vc-mail-akun; chmod 700 /usr/local/sbin/vc-mail-akun' <<'EOS'
#!/usr/bin/env bash
# vc-mail-akun tambah <akun> <domain>  : buat akun SASL (sandi baru bila belum ada) + izinkan domain + kunci DKIM
# vc-mail-akun daftar | hapus <akun>
set -euo pipefail
D=/etc/vc-mail; K=$D/dkim; SEL=vcmail
IP_NODE=$(curl -s4 -m 10 https://api.ipify.org || echo '<IP node>')
muat() { postmap $D/pengirim; systemctl reload postfix; systemctl restart opendkim; doveadm auth cache flush 2>/dev/null || true; }
case "${1:-}" in
tambah)
  a=${2:?akun}; d=${3:?domain}; [[ $a =~ ^[a-z0-9-]+$ && $d =~ ^[a-z0-9.-]+\.[a-z]+$ ]] || { echo "akun/domain tidak sah"; exit 1; }
  if ! grep -q "^$a:" $D/akun; then
    s=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)
    echo "$a:$(doveadm pw -s SHA512-CRYPT -p "$s")::::::" >> $D/akun
    echo "SASL baru  : $a / $s   (simpan sekarang, tidak bisa ditampilkan ulang)"
  fi
  grep -q "^@$d " $D/pengirim && sed -i "s/^@$d .*/@$d $a/" $D/pengirim || echo "@$d $a" >> $D/pengirim
  if [ ! -f $K/$d.$SEL.private ]; then
    opendkim-genkey -b 2048 -d "$d" -s $SEL -D $K && mv $K/$SEL.private $K/$d.$SEL.private && mv $K/$SEL.txt $K/$d.$SEL.txt
    echo "$SEL._domainkey.$d $d:$SEL:$K/$d.$SEL.private" >> $K/keytable
    echo "*@$d $SEL._domainkey.$d" >> $K/signingtable
    chown opendkim:opendkim $K/*; chmod 600 $K/*.private
  fi
  muat
  echo "Relay      : $(hostname -I | awk '{print $1}'):587 STARTTLS, akun $a, pengirim *@$d"
  echo "DNS $d:"
  echo "  TXT @                  : SPF tambahkan ip4:$IP_NODE"
  echo "  TXT $SEL._domainkey : $(tr -d '\n\t" ' < $K/$d.$SEL.txt | sed 's/.*(//; s/).*//; s/;/; /g')"
  ;;
daftar) cut -d: -f1 $D/akun | while read -r a; do echo "$a: $(awk -v a="$a" '$2==a{printf "%s ", $1}' $D/pengirim)"; done ;;
hapus)
  a=${2:?akun}; sed -i "/^$a:/d" $D/akun; awk -v a="$a" '$2!=a' $D/pengirim > $D/pengirim.n && mv $D/pengirim.n $D/pengirim
  muat; echo "akun $a dihapus (kunci DKIM dibiarkan)" ;;
*) echo "pakai: $0 tambah <akun> <domain> | daftar | hapus <akun>"; exit 1 ;;
esac
EOS
x apt-get install -y -q curl >/dev/null
x systemctl enable --now opendkim dovecot postfix rsyslog
x systemctl restart opendkim dovecot postfix
x postfix check
x sh -c 'ss -ltnp | grep -E ":587|:8891"; ls -l /var/spool/postfix/private/auth'

# Node: buka port 25 keluar HANYA untuk vc-mail (sebelum rule anti-spam). Rule yang sama ada di pasang-node-publik.sh.
F=/etc/velocity-vps.nft
if ! grep -q 'vc-mail' $F; then
  cp -p $F $F.bak-$(date +%Y%m%d%H%M)-sebelum-mail
  sed -i "s|^\(\s*\)iifname \"vpsbr0\" tcp dport 25 reject|\1iifname \"vpsbr0\" ip saddr $MAIL_IP tcp dport 25 accept comment \"vc-mail: satu-satunya jalur SMTP keluar\"\n&|" $F
  nft -c -f $F && nft -f $F
fi
x sh -c 'g=$(getent ahostsv4 gmail-smtp-in.l.google.com | awk "NR==1{print \$1}"); timeout 10 bash -c "</dev/tcp/$g/25"' && echo "vc-mail → gmail:25 OK"
echo "=== OK: $NAMA $MAIL_IP siap. Tambah akun: incus exec $P $NAMA -- vc-mail-akun tambah <akun> <domain> ==="

# Panduan Node VPS: PC bekas → VPS otomatis di app.velocitydeveloper.ai.id

Hasil akhir: pelanggan memesan Cloud 1/2/3, dan setelah tagihan lunas VM KVM dibuat otomatis di PC bekas.
Pelanggan login SSH `root@<relay> -p 22xxx` dan bisa menyalakan, mematikan, atau me-restart VM dari dashboard.

```
Pelanggan ──internet──► RELAY (VPS ber-IP publik) ══WireGuard══► PC BEKAS (Incus, VM 10.77.0.N)
                                 ▲                                   ▲
              IP keluar VM = IP relay                  API Incus :8443 (LAN, hanya dari PC app)
                                                                     │
                                                    PC APP 192.168.72.177 (velocity-cloud)
```

Aturan keamanan yang dijamin skrip:
- VM tidak bisa menjangkau LAN kantor atau internet kantor. IP publik kantor di-whitelist di CSF semua server hosting, jadi lalu lintas VM pelanggan tidak boleh keluar lewat IP itu.
- Kalau WireGuard putus, VM kehilangan internet (blackhole). VM tidak beralih ke jalur kantor.
- Sertifikat app hanya boleh mengelola project `velocity` yang dibatasi (restricted): tidak bisa membuat container privileged atau me-mount disk host.
- SMTP keluar (port 25) dari VM diblok di relay, supaya IP relay tidak masuk daftar hitam spam.

---

## 0. Persiapan

**PC bekas**
- CPU mendukung Intel VT-x atau AMD-V (hampir semua CPU sejak 2010).
- RAM 16 GB. Host memakai ±2 GB, jadi sisa ±12 GB cukup untuk 4–5 VPS kecil.
- SSD minimal 256 GB (Cloud 1 = 15 GB, Cloud 2 = 20 GB, Cloud 3 = 25 GB per VPS). Jangan memakai HDD.
- Kabel LAN ke switch kantor dan listrik dari jalur cadangan (baterai/PLTS).
- Flashdisk ≥ 4 GB untuk installer.

**VPS relay** (sewa terpisah, misalnya penyedia lokal)
- 1 vCPU, 1 GB RAM, IPv4 publik, Debian 12/13 atau Ubuntu 24.04.
- Kuota bandwidth relay ≥ total bandwidth paket yang dijual, karena semua lalu lintas VPS pelanggan lewat relay.
- Firewall panel penyedia dibuka untuk:
  - UDP 51820 (WireGuard)
  - TCP 22002–22254 (SSH pelanggan)
  - TCP/UDP 40020–42549 (port tambahan pelanggan)

## 1. BIOS PC bekas

1. Nyalakan PC dan masuk BIOS (tekan Del, F2, atau F10).
2. Aktifkan **Intel Virtualization Technology (VT-x)** atau **SVM Mode** (AMD). VT-d/IOMMU boleh ikut diaktifkan.
3. Set **Restore on AC Power Loss = Power On**, supaya PC menyala sendiri setelah listrik pulih.
4. Matikan fitur sleep/ErP bila ada. Simpan, lalu reboot.

## 2. Instal Debian 13

1. Unduh `debian-13.x.x-amd64-netinst.iso` dari https://www.debian.org/download.
2. Tulis ke flashdisk dengan Rufus atau balenaEtcher, lalu boot PC dari flashdisk.
3. Pilih **Install** (mode teks):
   - Bahasa bebas, zona waktu Asia/Jakarta.
   - Hostname: `node-vps-1`. Domain: kosongkan.
   - Kata sandi root yang kuat. Catat di tempat aman, karena hanya dipakai di layar PC.
   - Buat satu user biasa (misalnya `velocity`).
   - Partisi: **Guided – use entire disk** → **All files in one partition**.
   - Software selection: **hapus centang** desktop (GNOME dan lainnya); **centang** `SSH server` dan `standard system utilities`.
4. Selesai instal, cabut flashdisk lalu reboot.

## 3. IP tetap & akses SSH dari PC app

1. Login di layar PC sebagai root, lalu lihat IP-nya: `ip -4 addr` (misalnya `192.168.72.50`).
2. Kunci IP itu di MikroTik (IP → DHCP Server → Leases → Make Static), supaya IP node tidak berubah. URL node di app memakai IP ini.
3. Masih di layar PC, tambahkan kunci SSH PC app:
   ```
   mkdir -p /root/.ssh && chmod 700 /root/.ssh
   echo 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMVYIWU0beFlT9sH68myIJWgJpuPQ4ouj3SGFlUTsnqV pc-lokal->node-vps' >> /root/.ssh/authorized_keys
   chmod 600 /root/.ssh/authorized_keys
   ```
4. Dari PC app, cek koneksinya: `ssh -i /root/.ssh/id_ed25519_nodevps root@192.168.72.50 hostname`.

> Setelah langkah 3, langkah 4–7 bisa dikerjakan Claude dari PC app. Cukup kirim IP node, IP relay, dan akses root relay.

## 4. Pasang relay

Dari PC app:
```
scp /home/velocity-cloud/deploy/node-vps/pasang-relay.sh root@<IP-RELAY>:/root/
ssh root@<IP-RELAY> bash /root/pasang-relay.sh
```
Catat **Endpoint relay** dan **Kunci publik relay** yang tercetak di akhir.

## 5. Pasang node

Dari PC app:
```
N=192.168.72.50
scp -i /root/.ssh/id_ed25519_nodevps /home/velocity-cloud/deploy/node-vps/pasang-node.sh /etc/velocity/secrets/incus-client.crt root@$N:/root/
ssh -i /root/.ssh/id_ed25519_nodevps root@$N bash /root/pasang-node.sh <IP-RELAY>:51820 <KUNCI-PUBLIK-RELAY> 192.168.72.177 /root/incus-client.crt
```
`incus-client.crt` hanya sertifikat publik; kunci privatnya tetap di PC app. Di akhir, skrip mencetak **kunci publik node** dan **sertifikat server**.

## 6. Hubungkan relay ↔ node

```
ssh root@<IP-RELAY> bash /root/pasang-relay.sh peer <KUNCI-PUBLIK-NODE>
```
Cek dari node: `wg show` harus menampilkan `latest handshake`.

## 7. Daftarkan node di app

1. Buka **Admin › Node › Tambah**:
   - Jenis: **VPS (Incus KVM)**.
   - URL: `https://192.168.72.50:8443`.
   - Sertifikat server: tempel dari langkah 5.
   - Kapasitas VPS: 4 (untuk RAM 16 GB).
   - Aktif.
2. Klik ikon **Uji**. Hasilnya harus "Terhubung ke Incus …".
3. Isi alamat relay di `.env` velocity-cloud, lalu muat ulang:
   ```
   cd /home/velocity-cloud
   echo 'VPS_RELAY_HOST=<IP-ATAU-HOSTNAME-RELAY>' >> .env
   php84 artisan config:cache && systemctl restart velocity-cloud velocity-cloud-queue
   ```
   Selama `VPS_RELAY_HOST` kosong, pesanan VPS tetap disiapkan manual seperti sekarang.

## 8. Uji sebelum dijual

1. Pesan **Cloud 1** dengan akun uji, lalu tandai lunas di **Admin › Tagihan**.
2. Dalam ±2–5 menit, status layanan berubah menjadi **Aktif**. Pesanan pertama lebih lama karena image OS diunduh dulu.
3. Login SSH dengan perintah yang tampil di halaman layanan: `ssh root@<relay> -p 22002`, sandi dari halaman layanan.
4. Di dalam VM, jalankan pemeriksaan berikut:
   - `curl -4 ifconfig.me` harus menampilkan **IP relay**, bukan IP publik kantor.
   - `ping -c2 192.168.72.1` harus **gagal** (LAN kantor tertutup).
   - `ping -c2 1.1.1.1` harus **berhasil**.
5. Uji gagal-tertutup: di node jalankan `systemctl stop wg-quick@wg0`. `ping 1.1.1.1` dari VM harus gagal. Nyalakan lagi dengan `systemctl start wg-quick@wg0`.
6. Coba tombol Off/On/Restart di halaman layanan.
7. Batalkan layanan uji, lalu hapus VM-nya di node: `incus delete --project velocity --force svc-<id>`.

---

## Yang perlu diketahui

- **Paket otomatis:** `vps-cloud-1..3` (config `cloud.vps.paket`). Paket lain, atau saat node penuh, tetap manual: status "disiapkan" dan muncul di Dashboard Admin › Layanan perlu tindakan.
- **Akses pelanggan:** SSH `relay:22000+N` dan 10 port bebas `relay:40000+N*10…+9`, diteruskan ke port yang sama di VM. Tidak ada IP publik sendiri, dan port 80/443 tidak tersedia. Website di VPS harus memakai port tambahan atau menunggu fitur domain/HTTP.
- **Klaim di landing:** "1 Gbps" dan kuota bandwidth berlaku untuk server pemasok. VPS dari PC kantor dibatasi upload ISP kantor dan kuota relay. Sesuaikan teks paket Cloud 1–3 sebelum dijual.
- **Belum ada:** hapus VM otomatis saat layanan dibatalkan (masih manual, lihat langkah 8.7), reinstall OS dari dashboard, backup/snapshot, dan web console.
- **Satu PC = satu titik gagal.** Kalau PC mati, semua VPS di dalamnya ikut mati. VM menyala lagi otomatis saat PC hidup (`boot.autostart`).
- **Pembaruan:** unattended-upgrades terpasang di node dan relay. Incus diperbarui lewat `apt upgrade`; VM tetap berjalan, kecuali saat QEMU diperbarui.

# Pesan WhatsApp Ledger

Format asli untuk WhatsApp: satu bintang untuk teks tebal, garis bawah untuk catatan. Font ditentukan aplikasi WhatsApp; keterbacaan diatur melalui hierarki teks dan jarak antarbagian.

## Konfirmasi

```text
*Ledger · Konfirmasi transaksi*

Periksa detail berikut sebelum disimpan.

Pengeluaran · *Rp35.000*
Dompet: GoPay
Kategori: Makan & minum
Tanggal: 10 Okt 2026
Catatan: Makan siang

*1* — Simpan transaksi
*3* — Batalkan
_Berlaku 30 menit sejak ringkasan dibuat._
```

## Bantuan

```text
*Ledger · Panduan WhatsApp*

Catat transaksi, cek saldo, dan lihat ringkasan keuangan lewat chat.

*Catat transaksi*
• Pengeluaran: makan 35rb gopay
• Pemasukan: gaji 5jt masuk bca
• Transfer: transfer 100rb bca ke gopay

*Pantau keuangan*
• dompet
• saldo bca
• ringkasan hari ini
• ringkasan bulan ini
• 5 transaksi terakhir

*Periksa sebelum menyimpan*
Setiap transaksi ditampilkan sebagai draf. Balas *1* untuk menyimpan atau *3* untuk membatalkan. Draf berlaku 30 menit.

Gunakan nama dompet Anda di Ledger. Nominal dapat ditulis *50rb*, *50k*, *50000*, atau *1,5jt*.
```

## Setelah verifikasi

```text
*Ledger · WhatsApp terhubung*

Nomor Anda sudah terverifikasi. Mulai catat transaksi dengan pesan singkat.

*Coba kirim*
• makan 35rb gopay
• gaji 5jt masuk bca
• transfer 100rb bca ke gopay

Gunakan nama dompet yang terdaftar di Ledger. Detail transaksi akan ditampilkan untuk Anda konfirmasi sebelum disimpan.

Ketik *bantuan* untuk panduan atau *dompet* untuk melihat akun Anda.
```

Tanggal transaksi dan ringkasan mengikuti `WHATSAPP_TIMEZONE` (default `Asia/Jakarta`). Waktu kedaluwarsa tetap memakai timestamp aplikasi. Nominal desimal ditampilkan tanpa menghilangkan pecahan. Nama dompet dan catatan dinormalisasi agar tidak membuat bagian atau format pesan tambahan.

## Penanganan input dan verifikasi

- Pesan pemasukan/pengeluaran yang menyebut beberapa dompet meminta pengguna memilih satu dompet.
- Nominal negatif atau beberapa nominal yang tidak dapat dipastikan tidak diteruskan ke AI untuk ditebak. Transaksi harus dikirim ulang dan dikonfirmasi.
- OTP hanya diterima sebelum waktu kedaluwarsa, maksimal lima percobaan per kode, dan hanya untuk pemilik permintaan.
- Kegagalan pengiriman mempertahankan kode sebelumnya; pengiriman lama yang selesai terlambat tidak membatalkan kode dengan ID lebih baru.
- Konflik kepemilikan nomor saat verifikasi menghasilkan pesan validasi, bukan error server.

Pengujian provider menggunakan mock; tidak ada pesan uji yang dikirim ke WhatsApp sungguhan.

# Perbaikan audit 9 Oktober 2026

## Perubahan

- Semua jalur status tagihan menggunakan BillService. Penanda recurrence_generated_at mencegah jadwal berikutnya dibuat kembali ketika tagihan diaktifkan dan dilunasi ulang.
- GET /api/bills memakai pagination 20 item. summary selalu mencakup semua tagihan pengguna, bukan hanya halaman aktif. Parameter today mengikuti tanggal lokal browser.
- Daftar/detail goal tidak lagi menyertakan seluruh kontribusi. Riwayat tersedia melalui GET /api/goals/{id}/contributions?page=1 dengan otorisasi pemilik dan pagination.
- Arus kas memakai satu query agregasi dan tetap mengisi bulan tanpa aktivitas dengan nol.
- Pengiriman balasan WhatsApp dilakukan setelah transaksi finansial selesai, dengan cache lock per pesan selama 30 detik. Provider memiliki timeout 10 detik. Permintaan bersamaan mendapat 502 agar provider dapat mencoba ulang.

## Deployment

Jalankan `php artisan migrate --force` sebelum melayani kode API baru. Deploy frontend dan backend bersama karena format daftar tagihan berubah.

Pada banyak server, gunakan cache database atau Redis yang sama untuk seluruh instance; cache array/file lokal tidak menyediakan koordinasi lintas server. Pengiriman tetap sinkron, tetapi tidak menahan row lock database. Jaminan tepat satu balasan pada kegagalan proses setelah provider menerima pesan membutuhkan idempotency dari provider; transaksi finansial tetap dilindungi pencatatan processed_at.

Migrasi menandai tagihan berulang lama yang sudah lunas sebagai telah diproses untuk menghindari penggandaan. Jadwal lama yang hilang/duplikat akibat bug sebelumnya tidak diperbaiki otomatis karena tidak ada relasi historis yang cukup untuk menentukannya dengan aman.

## Validasi

AuditRegressionTest mencakup semua jalur pelunasan, pelunasan ulang, ringkasan lintas halaman, pemisahan pengguna, riwayat goal, dan jumlah query arus kas. WhatsAppWorkflowTest mencakup pengiriman tanpa transaksi database tambahan, penguncian balasan bersamaan, dan retry tanpa menggandakan transaksi.

Pengujian menggunakan database terpisah. Belum ada uji beban produksi atau verifikasi provider WhatsApp secara langsung dalam perubahan ini.

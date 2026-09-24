# OCR invoice / bukti transaksi

Endpoint `POST /api/imports/scan` menerima `document` berupa JPG, PNG, WEBP hingga 10 MB dan 20 megapiksel. PDF belum didukung. Gambar diperkecil bila sisi terpanjang melewati 2000 piksel, diratakan di latar putih, lalu dibaca satu kali oleh Tesseract. Hasil selalu berupa draft; pengguna tetap perlu memeriksa nominal dan tanggal.

## Deploy

- Deploy backend dan build frontend (`npm run build`) termasuk service worker baru secara bersamaan.
- Pastikan PHP GD, Tesseract, dan language data `eng` tersedia untuk user PHP-FPM. Set `TESSERACT_BINARY=/usr/bin/tesseract` bila sesuai dengan server.
- `OCR_TIMEOUT=15` membatasi proses Tesseract (dibatasi kode maksimal 20 detik). Frontend menunggu hingga 45 detik termasuk upload. Batas PHP-FPM/reverse proxy juga harus cukup untuk upload, preprocessing, dan OCR.
- Pastikan user PHP-FPM dapat menulis `storage` (termasuk `storage/app/private` dan `storage/logs`) serta `bootstrap/cache`. Sesuaikan owner/group dengan deployment server; jangan menggunakan permission 777.
- Jalankan `php artisan config:cache` setelah mengubah environment/config. Jalankan migrasi sesuai prosedur deployment jika tabel `transaction_imports` belum tersedia.
- Tutup dan buka kembali PWA agar service worker baru aktif. Coba upload galeri, kamera, dan Share dari m-banking; coba juga Share saat belum login.

## Diagnosis

Cari event `Ledger OCR received`, `Ledger OCR completed`, `Ledger OCR stage failed`, dan `Ledger OCR timeout` di channel log Laravel aktif. Untuk channel file:

```sh
rg 'Ledger OCR' storage/logs/laravel*.log | tail -n 50
```

Log memuat `ocr_request_id`, tahap gagal (`preprocess`, `tesseract`, `parse`), serta durasi dalam milidetik. Respons gagal OCR memuat `request_id` untuk korelasi. Log tahap tidak merekam isi invoice. Timeout Tesseract menghasilkan HTTP 422 dengan `code: OCR_TIMEOUT`; timeout browser 45 detik tidak membuktikan Tesseract yang lambat karena termasuk upload dan waktu menunggu server.

## Verifikasi lokal

```sh
php artisan test --filter=OcrImportTest
```

Tes memakai proses pengganti Tesseract untuk menguji timeout nyata, satu kali eksekusi, batas dimensi, cleanup, validasi file, dan penyimpanan draft. Akurasi OCR perlu diuji terpisah dengan gambar invoice representatif dan Tesseract server.

Frontend: `node --test tests/share-target.test.js` menguji isolasi dua Share bersamaan dan Share tanpa gambar agar tidak mengambil invoice lama. `npm run build` memverifikasi bundle aplikasi dan service worker.

Draf untuk semakan klinik. Bukan dokumen rasmi.

# Penyelia CA

Penyelia CA juga menggunakan langkah dalam [panduan Pembantu Klinik](./ca.md). Bahagian di bawah hanya untuk tugas tambahan penyelia.

## Rekod dan sahkan stok pembukaan

1. Pada halaman “Inventory”, buka bahagian “Stock Setup” dan pilih tab “Opening Balance”.
2. Pilih “Branch”, “Location”, “SKU” dan “Batch”. Gunakan “Search batches” jika batch belum kelihatan.
3. Masukkan “Quantity” dalam unit stok SKU yang dipaparkan, kemudian pilih “Record Opening Balance”.
4. Buka “Pilot readiness”. Sahkan base unit setiap SKU dengan “Verify base unit:” dan unit yang dipaparkan. Jika “Stock unit” dan “Dispensing unit” berbeza, dispensing ditolak; semak rujukan unit sebelum membenarkan dispensing.
5. Dalam “Pilot readiness”, semak SKU, lokasi, nombor batch, tarikh luput dan kuantiti dengan stok fizikal.
6. Minta penyemak berkuasa yang berbeza daripada pencatat untuk membuka “Pilot readiness” pada akaun sendiri dan memilih “Sign off opening”. Pencatat tidak boleh menandatangani stok pembukaan sendiri.

## Terima penghantaran

1. Pada “Inventory”, buka pesanan yang sedia diterima di bahagian “Purchase Orders & Receiving”. Jika pesanan yang betul tiada atau statusnya tidak membenarkan penerimaan, berhenti dan rujuk pengendali klinik.
2. Buka “Split lots and delivery exceptions”. Masukkan “Delivery note or supplier invoice reference” yang sebenar.
3. Bagi setiap baris pesanan, tandakan “Include this order line”, masukkan “Physical quantity counted”, dan rekod setiap “Batch number”, “Real expiry date”, “Lot quantity” serta “Outcome”. Pilih “Add lot (maximum 20)” jika satu item mempunyai beberapa lot.
4. Jumlah lot mesti sepadan dengan kuantiti fizikal. Tarikh luput sebenar diperlukan; jangan gunakan tarikh pengganti. Bagi penghantaran pendek, tandakan “Declared short delivery (requires approval)”.
5. Untuk lot diterima sepenuhnya tanpa pengecualian, pilih “accepted” dan “Post receipt”.
6. Untuk lot “quarantined”, “rejected”, atau kekurangan/pelanggaran yang memerlukan pengecualian, pilih sebab terkawal dan “Request exception approval”. Jangan pilih “Post receipt” untuk permintaan pengecualian.
7. Pada “Inventory”, penyemak berkuasa yang bukan penerima membuka “Receiving reviews” dan memilih “Load receiving reviews (25 per page)”. Selepas ulasan dimuatkan, pilih “Approve delivery exception”. Selepas diluluskan, penerima asal kembali dan memilih “Post approved delivery”.
8. “Quarantined” meletakkan tahanan pada seluruh batch di cawangan itu, termasuk baki terdahulu. Penerimaan tidak melepaskan tahanan.

## Buat kiraan, kiraan semula dan hapus kira

1. Dari “Inventory”, buka “Stock count (governed)”. Cari SKU, batch dan lokasi yang betul; pilih “Take snapshot”.
2. Pada kes itu, pilih “Enter count”, masukkan kuantiti fizikal dan pilih “Save evidence”. Kiraan sifar beza ditutup tanpa pergerakan stok.
3. Jika ada beza, orang lain mesti memilih “Independent recount” dan merekod kiraan semula. Perbezaan memerlukan tiga orang berbeza: pengira pertama, pengira semula dan pelulus yang memilih “Approve and post”. Pelulus tidak boleh menjadi pencadang, pengira atau pengira semula.
4. Untuk stok yang perlu dihapus kira, buka “Write-off (governed)”, pilih item dan “Propose write-off”. Masukkan kuantiti, “Controlled reason” dan “Disposal document or log reference”, kemudian “Save evidence”.
5. Untuk write-off, pencadang dan pelulus yang memilih “Approve and post” mesti orang berbeza. Rujukan pelupusan direkodkan sahaja; KPOne tidak mengesahkan pelupusan fizikal.

## Letak tahanan batch

1. Pada “Inventory”, buka “Batch safety holds” dan cari batch pada cawangan yang betul.
2. Pilih “Place safety hold”, pilih “Controlled reason”, kemudian “Place hold”.
3. Tahanan menghalang batch daripada digunakan di cawangan itu tetapi tidak mengubah kuantiti stok. CA Supervisor boleh meletakkan tahanan tetapi tidak boleh melepaskannya. Minta Finance Officer atau Director yang berbeza daripada orang yang meletakkan tahanan memilih “Release hold” dengan sebab terkawal.

## Jangan gunakan aliran lama

Aliran lama “Stocktake” dan “Manual Adjustment” telah ditutup. Gunakan “Stock count (governed)” dan “Write-off (governed)” untuk perubahan yang dibenarkan. Jangan cuba membuat kiraan atau pelarasan melalui aliran lama.

## Imbas kod bar

1. Pada “Treatment room usage”, “Stock count (governed)”, “Write-off (governed)”, “Supplier returns” dan penerimaan penghantaran, klik kotak “Scan or type barcode” dahulu, kemudian imbas atau taip kod. “Use camera” menggunakan kamera peranti.
2. Imbasan hanya mencari SKU. Ia tidak menanda kotak, mengisi kuantiti atau menyimpan apa-apa. Batch masih perlu dipilih sendiri.
3. Jika mesej menyatakan “This code is on more than one SKU”, buka “Pilot readiness”. Pada bahagian “Barcodes shared by more than one SKU”, betulkan “Barcode for …” dan pilih “Save”, atau pilih “Clear” pada SKU yang salah.

## Tutup tunai harian: lulus atau pulangkan kiraan

1. Buka “Clinic management” > “Patients” > “Daily cash closing”. Pilih “Business date”, kemudian “Load day”.
2. Dalam “Counts for this day”, cari kiraan orang lain (“Counted by …”).
3. Jika kiraan perlu dibuat semula, tulis sebab dalam “Reason to return (only for Return)” dan pilih “Return for recount”.
4. Jika kiraan betul, pilih “Approve closing”. Butang ini hanya boleh digunakan selepas hari itu tamat (selepas tengah malam waktu cawangan).
5. Anda tidak boleh meluluskan kiraan sendiri. Hari itu juga tidak boleh diluluskan selagi ada bayaran balik tunai untuk hari itu yang belum diluluskan.

## Rekod bayaran balik (refund)

1. Resit mesti diterbalikkan dahulu oleh Finance Officer (“Reverse recording error”). Penyelia CA tidak boleh menterbalikkan resit.
2. Pada halaman “Patient Billing” lawatan itu, buka bahagian “Refunds”. Pilih “Given back by” (cara wang dipulangkan) dan “Reason” (“Overpaid”, “Service not given”, “Invoice corrected” atau “Other”).
3. Pilih “Record refund of RM …”. Amaun ialah amaun penuh resit yang diterbalikkan; ia tidak ditaip.
4. Finance Officer atau Director yang bukan perekod memilih “Approve refund” atau “Reject”.
5. Bayaran balik tidak tersedia selepas “Complete Visitation”. Hanya resit yang telah diterbalikkan boleh dibayar balik.

## Pemindahan stok: terima dan selesaikan perbezaan

1. Pada “Inventory”, buka “Stock Requests & Transfers”. Cawangan penghantar memilih “Dispatch batch” dan “Dispatch stock”.
2. Cawangan penerima mengira barang yang tiba. Bagi setiap baris, masukkan “Accepted into stock”, “Short”, “Damaged” dan “Extra”. Jumlah accepted + short + damaged mesti sama dengan kuantiti yang dihantar.
3. Pilih “Confirm receipt”, kemudian sahkan dalam dialog. Hanya kuantiti accepted masuk ke stok cawangan.
4. Setiap perbezaan kekal terbuka di bawah “Transfer differences”. Penyelia CA, Finance Officer atau Director yang bukan penerima memilih “Outcome”, memasukkan rujukan dokumen (wajib) dan memilih “Resolve difference”.
5. Penerima tidak boleh menyelesaikan perbezaan pemindahan yang diterimanya sendiri.

## Pulangan kepada pembekal

1. Pada “Inventory”, buka “Supplier returns”. Dalam “Received stock you can return”, pilih “Request return” pada lot yang betul.
2. Masukkan “Quantity”, pilih “Reason”, tambah “Supplier return authorisation (optional)” jika ada, dan pilih “Send for approval”.
3. Finance Officer atau Director yang bukan peminta memilih “Approve” atau “Reject”.
4. Selepas diluluskan, pilih “Dispatch to supplier” dan masukkan “Return delivery note or courier reference”. Stok keluar dari cawangan pada langkah ini.
5. Finance Officer atau Director merekod “Record credit note”. Ini rujukan sahaja; KPOne tidak merekod bayaran.
6. Peminta boleh memilih “Cancel” sebelum barang dihantar. Selepas dihantar, pulangan tidak boleh dibatalkan.

## Penggunaan bilik rawatan: terbalikkan entri orang lain

1. Merekod penggunaan atau pembaziran stok diterangkan dalam panduan Pembantu Klinik.
2. Jika entri orang lain salah, buka “Treatment room usage” dan cari entri itu dalam “Entries”. Pilih “Reverse”, pilih sebab dan pilih “Reverse and put stock back”.
3. Anda tidak boleh menterbalikkan entri sendiri. Entri yang batchnya sudah dikira atau dihapus kira selepas itu tidak boleh diterbalikkan; gunakan “Stock count (governed)”.

## Kumpulan ubat dan ubat terkawal

1. Buka “Clinic management” > “Inventory & services” > “Medicine Catalogue”.
2. Pada baris ubat, buka menu “…” dan pilih “Drug groups”. Masukkan satu kumpulan ubat setiap baris (maksimum 10, contohnya Penicillins atau NSAIDs) dan pilih “Save”. Amaran alahan di farmasi turut menyemak kumpulan ini.
3. Kumpulan ubat perlu disemak dengan rujukan yang boleh dipercayai; KPOne tidak menentukannya. Jangan keliru dengan medan “Group” dalam dialog “Edit medicine”.
4. Untuk ubat terkawal, buka menu “…”, pilih “Mark controlled”, kemudian “Mark”. Label ubat baharu akan mencetak “Ubat Terkawal / Controlled Medicine”. Untuk membuang tanda, pilih “Unmark controlled” dan “Remove mark”.

Senarai kumpulan ubat dan ubat terkawal perlu disemak oleh ahli farmasi sebelum digunakan: [PERLU DISAHKAN].

## Harga

Pada “Pricing”, isi “Price book *” dan “Amount (RM) *”, kemudian pilih “Publish price”. Amaun kosong ditolak. Taip 0.00 jika perkhidmatan itu percuma.

## Gabungkan rekod pesakit yang berganda

1. Cadangan gabungan dibuat pada halaman pesakit (lihat panduan Pembantu Klinik). Pada senarai pesakit, pilih “Merge requests” untuk melihat permintaan yang menunggu.
2. Semak permintaan orang lain, kemudian pilih “Approve” atau “Reject”. Anda tidak boleh meluluskan permintaan anda sendiri.
3. Rekod yang digabungkan disembunyikan, tidak dipadam. Untuk membatalkan gabungan, pada halaman pesakit di bawah “Merged records”, pilih “Unmerge”, pilih sebab dan “Send for approval”. Orang lain mesti meluluskannya.
4. Gabungan ditolak jika ada lawatan yang belum selesai, rekod sudah digabungkan, atau rekod pendua mempunyai alahan aktif yang tiada pada rekod yang disimpan.

## Sejarah perubahan pesakit

Pada halaman pesakit, bahagian “Change history” menunjukkan siapa menukar bahagian rekod dan bila. Nilai lama dan baharu tidak dipaparkan. Pilih “Show older changes” untuk melihat perubahan lebih awal.


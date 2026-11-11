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
7. Pada “Inventory”, penyemak berkuasa yang bukan penerima membuka bahagian “Delivery exceptions and corrections” dan memilih “Load receiving reviews (25 per page)”. Selepas ulasan dimuatkan, pilih “Approve delivery exception”. Selepas diluluskan, penerima asal kembali dan memilih “Post approved delivery”.
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
6. Pilih “Month view” untuk halaman “Monthly cash closing”: setiap hari dalam bulan itu dengan status (“Approved”, “Waiting for approval”, “Returned”, “Not counted”, “Open”, “Not in use yet”), kiraan, jangkaan dan beza. Hari “Not counted” dikira bermula dari tarikh mula tutup tunai cawangan (“Cash closing start date”). Tarikh ini ditetapkan atau ditukar oleh Finance Officer atau Director (kad itu menyatakan “A Director or Finance Officer can change this date.”); jika belum ditetapkan, kiraan bermula dari kiraan pertama cawangan.

## Rekod bayaran balik (refund)

Bahagian “Refunds” berada pada halaman “Patient Billing” lawatan itu. Langkahnya bergantung pada sama ada lawatan sudah selesai.

**Sebelum “Complete Visitation”:**

1. Resit mesti diterbalikkan dahulu oleh Finance Officer (“Reverse recording error”). Penyelia CA tidak boleh menterbalikkan resit.
2. Dalam “Refunds”, pilih “Given back by” (cara wang dipulangkan) dan “Reason” (“Overpaid”, “Service not given”, “Invoice corrected” atau “Other”).
3. Pilih “Record refund of RM …”. Amaun ialah amaun penuh resit yang diterbalikkan; ia tidak ditaip.

**Selepas “Complete Visitation”:**

1. Resit tidak perlu diterbalikkan. Dalam “Refunds”, setiap resit menunjukkan baki yang boleh dipulangkan (“… left to refund”).
2. Masukkan “Amount given back (RM)” (sebahagian atau semua, tidak melebihi baki), pilih “Given back by” dan “Reason” (“Medicine returned”, “Service not given”, “Invoice corrected”, “Goodwill” atau “Other”).
3. Pilih “Record refund”. Selepas diluluskan, invois dikurangkan dengan amaun yang sama dan dicetak sebagai “REVISED”, jadi tiada amaun baharu perlu dibayar. Resit asal tidak berubah.

**Dalam kedua-dua keadaan:** Finance Officer atau Director yang bukan perekod memilih “Approve refund” atau “Reject” (dengan sebab). Jika anda merekod bayaran balik itu, skrin menyatakan “You recorded this refund. …”; anda tidak boleh meluluskannya sendiri.

Selepas “Complete Visitation”, jika resit tersalah rekod (contohnya amaun atau cara bayaran salah), gunakan “Correct a receipt”, bukan bayaran balik. Sebelum lawatan selesai, Finance Officer menterbalikkan resit (“Reverse recording error”). Resit yang sudah ada bayaran balik tidak boleh dibetulkan. Pembetulan resit dibuat oleh Finance Officer yang bukan perekod resit itu: resit yang salah diterbalikkan dan amaun yang benar-benar diterima direkod semula. Hanya wang yang tidak diterima menjadi amaun perlu dibayar.

## Semak pulangan ubat

1. Buka “Clinic management” > “Inventory & services” > “Returns to check”. Senarai menunjukkan pulangan ubat cawangan ini yang menunggu semakan, yang paling lama dahulu. Pilih “Open case” untuk pergi ke bahagian “Medicine returns” kes itu.
2. Pada panel “Check this return” bagi pulangan yang menunggu, pilih “Outcome”:
    - “Dispose”: ubat dilupuskan; tiada stok dikembalikan.
    - “Quarantine”: ubat dipindahkan ke lokasi pulangan (“Move to returns location”) dan ditahan; ia tidak boleh didispens dari situ. Jika baris ubat dibekalkan daripada satu batch sahaja, batch itu digunakan.
        - Jika baris ubat dibekalkan daripada dua batch atau lebih, bahagian “Batches the units came from” muncul: “Enter how many went back to each batch. Leave 0 for a batch you did not use.” Isi kuantiti bagi setiap batch berdasarkan kotak yang dipulangkan. Setiap batch menunjukkan “Available: …”, iaitu jumlah maksimum yang boleh dipulangkan ke batch itu.
        - Baris “Placed … of …” menunjukkan jumlah yang telah diisi, dengan “All placed”, “… left” atau “… too many”. Jumlah mesti sama dengan kuantiti pulangan sebelum anda boleh meneruskan.
        - “Fill from earliest expiry” mengisi kotak bermula dari batch yang paling awal luput. Gunakannya hanya jika kotak yang dipulangkan jelas sepadan, dan semak semula selepas itu (“Filled from earliest expiry. Check the boxes match.”).
        - Dialog pengesahan menyenaraikan batch dan kuantiti bagi setiap batch sebelum anda mengesahkan.
    - “Refuse”: pulangan direkod secara salah. Pilih sebab (contohnya “Wrong case or patient” atau “Recorded twice”), kemudian “Refuse return”. Tiada perubahan stok, dan kuantiti itu boleh direkod semula.
3. Setiap pilihan meminta pengesahan dalam dialog (contohnya “Move to returns location” atau “Refuse return”). “Dispose” dan “Quarantine” tidak boleh dibatalkan. Bagi ubat terkawal, skrin menetapkan “Dispose” (“Controlled medicines are always disposed.”); “Refuse” masih dibenarkan jika pulangan direkod secara salah.
4. Anda tidak boleh menyemak pulangan yang anda rekod sendiri: senarai menyatakan “You recorded this. Another CA Supervisor must check it.” Jika cawangan belum ada lokasi pulangan, skrin menyatakan “This branch has no returns location yet. Ask the Inventory team to set one up.”

Semakan pulangan ubat ialah semakan klinikal, jadi ia kekal dengan staf CA sahaja (keputusan pemilik, 10 November 2026). Finance Officer dan Director tidak menyemak pulangan ubat.

## Panggil pesakit seterusnya untuk doktor (“Call next”)

1. Pada papan “Consultation”, pilih doktor dalam penapis doktor dan pilih “Apply”. Sebelum itu, butang “Call next” dilumpuhkan dengan sebab “Choose a doctor and press Apply to call their next patient.”; selepas “Apply”, butang menjadi “Call next for …”.
2. Pilih butang itu. Dialog menamakan pesakit, contohnya “Call A-012 Ahmad Ismail?”. Semak nama dan pilih “Call A-012”.
3. Peraturan sama seperti doktor: pesakit hari ini dahulu, pesakit “Not ready to call” dilangkau, dan tiada panggilan tanpa pengesahan.

## Ringkasan harian AI

Buka “Clinic management” > “AI agents” > “AI daily summary” untuk membaca laporan pukul 07:00 dan kemas kini pukul 14:00 daripada ejen AI. Halaman ini untuk bacaan sahaja; cadangan ejen tidak mengubah apa-apa sehingga orang yang berkuasa meluluskannya, dan kelulusan itu hanya merekod keputusan.

Cadangan ejen (contohnya tambah stok atau amaran luput) diputuskan di “Clinic management” > “AI agents” > “Approval inbox”. Pilih “Approve” atau “Reject” (penolakan memerlukan sebab). Kelulusan hanya merekod keputusan dan tidak mengubah stok. Anda tidak boleh memutuskan cadangan daripada larian yang anda mulakan sendiri.

## Pemindahan stok: terima dan selesaikan perbezaan

1. Pada “Inventory”, buka “Stock Requests & Transfers”. Cawangan penghantar memilih “Dispatch batch” dan “Dispatch stock”.
2. Cawangan penerima mengira barang yang tiba. Bagi setiap baris, masukkan “Accepted into stock”, “Short”, “Damaged” dan “Extra”. Jumlah accepted + short + damaged mesti sama dengan kuantiti yang dihantar.
3. Pilih “Confirm receipt”, kemudian sahkan dalam dialog. Hanya kuantiti accepted masuk ke stok cawangan.
4. Setiap perbezaan kekal terbuka di bawah “Transfer differences”. Penyelia CA, Finance Officer atau Director yang bukan penerima memilih “Outcome”, memasukkan rujukan dokumen (wajib) dan memilih “Resolve difference”.
5. Penerima tidak boleh menyelesaikan perbezaan pemindahan yang diterimanya sendiri.

## Pulangan kepada pembekal

1. Pada “Inventory”, buka “Supplier returns”. Dalam “Received stock you can return”, pilih “Request return” pada lot yang betul.
2. Masukkan “Quantity”, pilih “Reason”, tambah “Supplier return authorisation (optional)” jika ada, dan pilih “Send for approval”.
3. Finance Officer atau Director yang bukan peminta memilih “Approve” atau “Reject” (dengan sebab).
4. Selepas diluluskan, pilih “Dispatch to supplier” dan masukkan “Return delivery note or courier reference”. Stok keluar dari cawangan pada langkah ini.
5. Finance Officer atau Director memilih “Record credit note” dan memasukkan rujukan nota kredit (amaun RM pilihan). Ini rujukan sahaja; KPOne tidak merekod bayaran.
6. Peminta boleh memilih “Cancel” sebelum barang dihantar. Selepas dihantar, pulangan tidak boleh dibatalkan.

## Penggunaan bilik rawatan: terbalikkan entri orang lain

1. Merekod penggunaan atau pembaziran stok diterangkan dalam panduan Pembantu Klinik.
2. Jika entri orang lain salah, buka “Treatment room usage” dan cari entri itu dalam “Entries”. Pilih “Reverse”, pilih sebab dan pilih “Reverse and put stock back”.
3. Anda tidak boleh menterbalikkan entri sendiri. Entri yang batchnya sudah dikira atau dihapus kira selepas itu tidak boleh diterbalikkan; gunakan “Stock count (governed)”.

## Kumpulan ubat dan ubat terkawal

1. Buka “Clinic management” > “Inventory & services” > “Medicine Catalogue”.
2. Pada baris ubat, buka menu “…” dan pilih “Drug groups”. Masukkan satu kumpulan ubat setiap baris (maksimum 10, contohnya Penicillins atau NSAIDs) dan pilih “Save”. Amaran alahan di farmasi turut menyemak kumpulan ini.
3. Kumpulan ubat perlu disemak dengan rujukan yang boleh dipercayai; KPOne tidak menentukannya. Jangan keliru dengan medan “Group” dalam dialog “Edit medicine”.
4. Untuk ubat terkawal, buka menu “…”, pilih “Mark controlled”, kemudian “Mark”. Label (termasuk cetakan semula) akan mencetak “Ubat Terkawal/Controlled Medicine” di bawah sempadan label. Untuk membuang tanda, pilih “Unmark controlled” dan “Remove mark”.

5. Untuk nama jenama atau ejaan lain yang biasa, buka menu “…” dan pilih “Other names”. Masukkan satu nama setiap baris (maksimum 10). Amaran alahan di farmasi turut menyemak nama ini. Nama lain tidak dicetak pada label dan tidak mengubah nama ubat.

Senarai kumpulan ubat dan ubat terkawal perlu disemak oleh ahli farmasi sebelum digunakan: [PERLU DISAHKAN].

## Harga

Pada “Pricing”, isi “Price book *” dan “Amount (RM) *”, kemudian pilih “Publish price”. Amaun kosong ditolak. Taip 0.00 jika perkhidmatan itu percuma.

Lajur “Current price” menunjukkan harga yang berkuat kuasa sekarang; harga baharu yang dijadualkan dipaparkan sebagai baris kedua (“From …”). Untuk menyemak semua ubat dan perkhidmatan sekali gus, buka “Check price list” (dari halaman “Pricing” atau “Clinic management”). Pilih “Price history” pada baris harga untuk melihat semua versi yang pernah diterbitkan.

## Gabungkan rekod pesakit yang berganda

1. Cadangan gabungan dibuat pada halaman pesakit (lihat panduan Pembantu Klinik). Pada senarai pesakit, pilih “Merge requests” untuk melihat permintaan yang menunggu.
2. Semak permintaan orang lain, kemudian pilih “Approve” atau “Reject”. Anda tidak boleh meluluskan permintaan anda sendiri.
3. Rekod yang digabungkan disembunyikan, tidak dipadam. Untuk membatalkan gabungan, pada halaman pesakit di bawah “Merged records”, pilih “Unmerge”, pilih sebab dan “Send for approval”. Orang lain mesti meluluskannya.
4. Gabungan ditolak jika ada lawatan yang belum selesai, rekod sudah digabungkan, atau rekod pendua mempunyai alahan aktif yang tiada pada rekod yang disimpan.

## Sejarah perubahan pesakit

Pada halaman pesakit, bahagian “Change history” menunjukkan siapa menukar bahagian rekod dan bila. Nilai lama dan baharu tidak dipaparkan. Pilih “Show older changes” untuk melihat perubahan lebih awal.

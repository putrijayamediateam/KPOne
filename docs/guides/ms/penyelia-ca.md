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
    - “Quarantine”: ubat dipindahkan ke lokasi pulangan (“Move to returns location”) dan ditahan; ia tidak boleh didispens dari situ. Jika baris ubat dibekalkan daripada beberapa batch, pilih batch asal.
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

## Permintaan pembelian (“Purchase requests”)

Gunakan halaman ini untuk meminta stok; orang lain menukarnya kepada draf pesanan pembelian.

1. Pada “Inventory” > “Orders and suppliers”, buka “Purchase requests”. Tab menunjukkan “Waiting for review”, “Converted”, “Rejected” dan “Withdrawn”.
2. Pilih “New request”. Dalam “New purchase request”, pilih “Deliver to” (lokasi), dan jika perlu “Preferred supplier (optional)”. Untuk setiap baris, taip nama atau kod dalam “Search SKU for line 1”, pilih “Search”, buka senarai “Select” dan pilih SKU, kemudian isi “Quantity” dan “Note (optional)”. Pilih “Add a line” untuk baris lain.
3. Pilih “Send for review”. Permintaan muncul di “Waiting for review” dengan nombor seperti PR-…. Anda boleh memilih “Withdraw” sebelum ia disemak. Skrin menyatakan: “You sent this request, so another person must review it.”
4. Orang lain (CA Supervisor lain atau Finance Officer; hanya orang yang membuat permintaan tidak dibenarkan) memilih “Convert to draft order” atau “Reject”. Dalam “Convert … to a draft order”, pilih “Supplier”, ubah kuantiti “Order” jika perlu (masukkan 0 untuk membiarkan satu baris), kemudian pilih “Create draft order”. Kuantiti yang diminta kekal pada permintaan. Permintaan bertukar kepada “Converted” dengan nombor “Draft order” (PO-…).

## Pesanan pembelian (“Purchase Orders & Receiving”)

1. Pada “Inventory”, bahagian “Purchase Orders & Receiving”, draf PO daripada permintaan muncul dengan status “Draft”. Untuk PO baharu tanpa permintaan, pilih pembekal, destinasi dan baris SKU (kuantiti dan “Estimated cost per stock unit (RM)”; ini kos untuk satu unit stok, contohnya satu biji tablet, bukan satu kotak), kemudian “Create PO draft”.
2. Pada draf, semak pembekal, lokasi dan kuantiti, pilih “Update draft” jika ada perubahan, kemudian “Submit”. Skrin menyatakan “Purchase Order submitted.” Status menjadi “Submitted”. “Cancel” membatalkan draf.
3. Finance Officer atau Director memilih “Approve”, kemudian “Approve Purchase Order” untuk mengesahkan. Kelulusan membenarkan penerimaan terhadap PO itu; CA Supervisor tidak meluluskan PO. Finance Officer atau Director yang menyediakan atau menghantar PO itu tidak boleh meluluskannya; orang lain mesti meluluskannya.
4. Pilih “Print PO” untuk mencetak PO.
5. Selepas diluluskan, status menjadi “Approved” dan baris menunjukkan “Received 0 tablet · Outstanding 150 tablet”. Terima penghantaran mengikut bahagian “Terima penghantaran” di atas. Untuk penerimaan penuh tanpa pengecualian, isi “Delivery note or supplier invoice reference (required)”, “Batch number (required)”, “Expiry date (required)” (dd/mm/yyyy), “Quantity this receipt” dan, jika ada, “Total paid for this batch (RM, optional)”, kemudian “Post receipt”. Dialog bertanya “Post receipt for PO-…?” dan menyatakan ia menambah stok; pilih “Post receipt” untuk mengesahkan. Skrin menyatakan “Goods Receipt posted.”

## Invois pembekal (“Supplier invoices”)

1. Pada “Inventory” > “Orders and suppliers”, buka “Supplier invoices”. Halaman ini membandingkan setiap baris invois dengan apa yang diterima dan kosnya. KPOne tidak membuat sebarang pembayaran: invois yang “matched” atau “accepted” sedia dibayar di luar KPOne.
2. Pada “Record an invoice”, pilih “Purchase order (required)” (hanya pesanan yang telah menerima stok dan menunggu invois), isi “Invoice number (required)”, “Invoice date (required)” dan “Invoice total, RM (optional)”. Untuk setiap baris, isi “Invoiced quantity” dan “Line total, RM” seperti pada invois; masukkan 0 untuk baris yang tiada pada invois. Pilih “Record invoice”.
3. Jika semua sepadan, invois muncul di “Matched”. Jika ada beza, ia menunggu di “Waiting for approval” untuk Finance Officer atau Director yang bukan orang yang merekodnya. Tab lain: “Accepted” dan “Rejected”.
4. Amaun hanya dipaparkan kepada Director atau Finance Officer.

## Semak daftar ubat terkawal (“Controlled register”)

1. Pada “Inventory” > “Count and adjust”, buka “Controlled register”. Halaman ini ialah draf: skrin menyatakan “Draft register — pending pharmacist review. Not a statutory register until a pharmacist approves its format.”
2. Ubat muncul hanya jika ditanda terkawal dalam Medicine Catalogue dan dipautkan kepada SKU. Pilih “Open register” pada ubat itu.
3. Daftar menyenaraikan setiap pergerakan masuk dan keluar. Kuantiti disembunyikan sehingga anda merekod kiraan sendiri supaya semakan tidak bergantung pada daftar.
4. Kira ubat yang ada di cawangan, isi “Quantity counted”, kemudian pilih “Record check”. Kiraan sentiasa disimpan dan tidak mengubah stok.
5. Jika kiraan berbeza daripada daftar, skrin menyatakan “Your count differs from the register”. Orang yang mengira menambah sebab di bawah “Earlier checks”: “A governed stock count will follow”, “Stock is held somewhere else”, “Difference to investigate” atau “Other (add a note)”, kemudian “Add reason”. Jangan masukkan butiran pesakit atau preskriber. Sebab hanya ditambah sekali dan tidak boleh diubah. Betulkan stok melalui “Stock count (governed)”. Dalam tampilan baharu, butang “Add the reason” pada panel amaran membuka “Add the reason for the difference”, dan “Add reason” mengesahkannya.
6. Penyemak mesti orang kedua: bukan sesiapa yang merekod pergerakan sejak semakan terakhir, dan bukan orang yang membuat semakan terakhir. Skrin menyatakan “You did the previous check, so another person must check this register.” Penyemak kemudian mungkin melihat “Check N differs from the register and still waits for its reason from the person who recorded it. You can still record your check.”; anda masih boleh merekod semakan anda.

## Cadangan pesanan, stok cawangan lain dan kesihatan stok

Halaman ini untuk dibaca; tiada apa yang dipesan atau dihantar daripadanya.

- “Reorder suggestions”: menyenaraikan stok pada atau di bawah paras pesanan semula beserta cadangan kuantiti. Gunakan “Show” untuk memilih “Only rows that need ordering” atau “All low-stock rows”. Jika tiada, skrin menyatakan “Nothing needs ordering at this branch.” Untuk memesan, guna “Purchase requests”.
- “Stock other branches need”: cawangan lain yang berada pada atau di bawah paras pesanan semula, dan stok yang cawangan ini boleh beri. Hantar stok melalui “Stock Requests & Transfers” pada “Inventory”.
- “Stock health”: berapa lama stok bertahan, kekerapan ia habis digunakan dan kerugian kerana tamat tempoh. Pilih “Period” (“Last 30 days”, “Last 90 days” atau “Last 365 days”). Halaman ini hanya menunjukkan kuantiti, tiada nilai wang.
- “Stock review”: tiga senarai: “Low stock”, “Expiring soon” (dalam 90 hari) dan “Expired stock still on hand”. Mengakui (acknowledge) sesuatu tidak mengubah stok. Rekod pelupusan melalui “Write-off (governed)”.

## Penerimaan dengan pengecualian (contoh yang diikuti pada skrin)

Bahagian penerimaan, pulangan kepada pembekal dan recall di sini mengikut tampilan Classic. Tampilan baharu menggunakan lembaran terima sendiri; nama butang di sana mungkin berbeza: [PERLU DISAHKAN].

1. Pada “Split lots and delivery exceptions”, isi “Delivery note or supplier invoice reference”, tandakan “Include this order line” hanya untuk baris yang tiba, dan isi “Physical quantity counted”. Nyahtanda baris yang tidak tiba; baris yang ditanda mesti mempunyai kuantiti.
2. Isi setiap lot: “Batch number”, “Real expiry date”, “Lot quantity” dan “Outcome” (“accepted”, “quarantined” atau “rejected”). Pilih “Add lot” untuk lot lain (contohnya 480 diterima dan 20 rosak).
3. Untuk lot “quarantined” atau “rejected”, pilih sebab terkawal: “Damaged”, “Wrong item”, “Short expiry”, “Missing documentation” atau “Other approved reason”. Jika sebab tiada, skrin menyatakan “Accepted lots have no exception reason. Quarantined and rejected lots need a controlled reason.”
4. Pilih “Request exception approval”. Pada “Delivery exceptions and corrections”, orang lain (CA Supervisor lain, Finance Officer atau Director) memilih “Load receiving reviews”, menyemak resit yang “Awaiting independent approval” dan memilih “Approve delivery exception”. Skrin menyatakan “Delivery exception approved. The original receiver may post the unchanged delivery.”
5. Penerima asal memilih “Load receiving reviews”, kemudian “Post approved delivery”. Skrin menyatakan “Goods Receipt posted.”
6. Lot yang “quarantined” diletakkan tahanan secara automatik pada “Batch safety holds” (contohnya “Held: Damaged”; untuk sebab lain, tahanan itu menjadi “Held: Other safety concern”). Pelepasan tahanan memerlukan Finance Officer atau Director.

## Pulangan beberapa lot sekali gus

Pada “Supplier returns”, bahagian “Return several lots from one delivery” membolehkan anda memilih satu penghantaran, menanda lot dan menetapkan kuantiti (sehingga 20 lot). Lot itu diluluskan, dihantar dan ditutup bersama dengan satu nota kredit. Lot yang telah “quarantined” juga boleh dipulangkan; skrin menunjukkan “(quarantined)” pada lot itu.

## Recall

1. Buka “Inventory” > “Count and adjust” > “Recalls”. Skrin menyatakan recall menyekat batch yang dinamakan di setiap cawangan, dan tidak menggerakkan stok. Tab menunjukkan “Active” dan “Lifted”.
2. CA Supervisor hanya melihat senarai. Director memilih “Start a recall”, mencari SKU dan memilih batch, memilih “Reason” (“Supplier recall”, “Regulator notice”, “Quality issue” atau “Other”), boleh mengisi “Notice reference (optional)” dan “Note (optional)” (tanpa nama pesakit atau nombor IC), kemudian “Start recall”. Hanya Director boleh mengangkat recall, dan bukan Director yang memulakannya, jadi mengangkat recall memerlukan Director kedua. Jika hanya ada seorang Director, recall tidak dapat diangkat.
3. Batch yang di bawah recall tidak boleh digunakan sehingga recall diangkat.

## Kiraan yang patut dibuat (“Counts due”)

Pada “Stock count (governed)”, bahagian “Counts due” menyenaraikan SKU dan lokasi yang patut dikira. Kelas A dikira setiap 30 hari, B setiap 60 dan C setiap 90; kelas bergantung pada berapa kerap item dikeluarkan di cawangan ini dalam 90 hari lepas, dan ubat terkawal sentiasa kelas A. Warna kuning bermaksud sudah patut, lewat sehingga 29 hari, atau belum pernah dikira (“Never counted”; baris ini dipaparkan dahulu); merah bermaksud 30 hari atau lebih. Langkah mengira dan mengira semula ada dalam bahagian “Buat kiraan, kiraan semula dan hapus kira” di atas.

## Halaman inventori lain

“Supplier price list” (harga hanya untuk Director atau Finance Officer), “Stock value and cost” (Director sahaja; CA Supervisor mendapat 403) dan “Import opening stock” (CA Supervisor memuat naik fail CSV; skrin menyatakan setiap baris direkod atau tiada langsung, dan setiap stok pembukaan masih perlu disahkan oleh orang lain pada “Pilot readiness”) belum diikuti langkah demi langkah dalam panduan ini: [PERLU DISAHKAN]. Pemindahan antara lokasi (“Stock Requests & Transfers”) dan kiraan semula juga belum diikuti pada skrin untuk panduan ini: [PERLU DISAHKAN]. Jika halaman tiada pada akaun anda, ia tidak dibenarkan untuk anda.

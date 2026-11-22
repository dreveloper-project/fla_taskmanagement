<?php

return [
    'timezone' => 'Asia/Makassar',
    'document_max_kb' => 2048,
    'certificate_requires_expiry' => true,
    'required_service_documents' => ['application_letter', 'technical_attachment'],
    'vessel_documents' => [
        ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Liferaft / ILR — Sertifikat Service', 'types' => ['liferaft'], 'required' => true, 'must_be_valid' => true, 'equipment_ready' => true],
        ['label' => 'Sertifikat PMK / Pemeriksaan Pemadam', 'types' => ['fire_fighting'], 'required' => true, 'must_be_valid' => true, 'equipment_ready' => true],
        ['label' => 'Life Jacket — Record Kondisi & Jumlah', 'types' => ['life_jacket'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
        ['label' => 'Life Buoy — Record Kondisi & Jumlah', 'types' => ['life_buoy'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
        ['label' => 'Emergency Equipment — Record Pemeriksaan', 'types' => ['emergency_equipment'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
        ['label' => 'Grosse Akta', 'types' => ['grosse_akta'], 'required' => false, 'must_be_valid' => false],
        ['label' => 'Surat Ukur', 'types' => ['surat_ukur'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Surat Laut / Pas Besar / Pas Kecil', 'types' => ['surat_laut', 'pas_besar', 'pas_kecil'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Ship Particulars', 'types' => ['ship_particulars'], 'required' => false, 'must_be_valid' => false],
        ['label' => 'General Arrangement', 'types' => ['general_arrangement'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Safe Manning', 'types' => ['safe_manning'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Load Line Certificate', 'types' => ['load_line'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Radio Certificate', 'types' => ['radio_certificate'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Classification Certificate', 'types' => ['classification_certificate'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'SMC', 'types' => ['smc'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Asuransi Kapal', 'types' => ['insurance'], 'required' => false, 'must_be_valid' => true],
    ],
];

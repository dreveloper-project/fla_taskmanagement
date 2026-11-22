<?php

return [
    'timezone' => 'Asia/Makassar',
    'document_max_kb' => 2048,
    'required_spb_documents' => [],
    'safety_equipment_documents' => [
        ['label' => 'Liferaft / ILR — Sertifikat & Kesiapan', 'types' => ['liferaft'], 'required' => true, 'must_be_valid' => true, 'equipment_ready' => true],
        ['label' => 'PMK / APAR — Pemeriksaan Kesiapan', 'types' => ['fire_fighting'], 'required' => true, 'must_be_valid' => true, 'equipment_ready' => true],
        ['label' => 'Life Jacket — Jumlah & Kesiapan', 'types' => ['life_jacket'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
        ['label' => 'Life Buoy — Kondisi & Jumlah', 'types' => ['life_buoy'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true, 'cargo_only' => true],
        ['label' => 'Emergency Equipment / Signals — Checklist', 'types' => ['emergency_equipment'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
    ],
    'required_vessel_documents' => [
        ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'requires_expiry' => false],
        ['label' => 'Surat Ukur', 'types' => ['surat_ukur'], 'requires_expiry' => false],
        ['label' => 'Surat Laut / Pas Besar / Pas Kecil', 'types' => ['surat_laut', 'pas_besar', 'pas_kecil'], 'requires_expiry' => false],
        ['label' => 'Sertifikat Keselamatan Kapal Penumpang', 'types' => ['safety_certificate'], 'requires_expiry' => true],
        ['label' => 'Safe Manning', 'types' => ['safe_manning'], 'requires_expiry' => true],
        ['label' => 'Classification Certificate', 'types' => ['classification_certificate'], 'requires_expiry' => true, 'required' => false],
    ],
];

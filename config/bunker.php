<?php

return [
    'timezone' => 'Asia/Makassar',
    'document_max_kb' => 2048,
    'required_service_documents' => ['application_letter', 'bunker_request'],
    'vessel_documents' => [
        ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Sertifikat PMK / Kesiapan APAR', 'types' => ['fire_fighting'], 'required' => true, 'must_be_valid' => true, 'equipment_ready' => true],
        ['label' => 'SOPEP Equipment — Checklist Kesiapan', 'types' => ['sopep_equipment'], 'required' => true, 'must_be_valid' => false, 'equipment_ready' => true],
        ['label' => 'Surat Ukur', 'types' => ['surat_ukur'], 'required' => false, 'must_be_valid' => false],
        ['label' => 'Surat Laut / Pas Besar / Pas Kecil', 'types' => ['surat_laut', 'pas_besar', 'pas_kecil'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Sertifikat Keselamatan', 'types' => ['safety_certificate'], 'required' => false, 'must_be_valid' => true],
        ['label' => 'Ship Particulars', 'types' => ['ship_particulars'], 'required' => false, 'must_be_valid' => false],
    ],
];

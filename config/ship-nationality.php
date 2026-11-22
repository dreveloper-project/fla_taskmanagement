<?php

return [
    'timezone' => 'Asia/Makassar',
    'document_max_kb' => 2048,
    'required_service_documents' => ['application_letter'],
    'ownership_vessel_document_types' => ['grosse_akta'],
    'ownership_service_document_type' => 'ownership_evidence',
    'eligibility' => [
        'surat_laut' => ['allowed_vessel_types' => []],
        'pas_besar' => ['allowed_vessel_types' => []],
        'pas_kecil' => ['allowed_vessel_types' => []],
    ],
    'vessel_documents' => [
        ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Surat Ukur', 'types' => ['surat_ukur'], 'required' => true, 'must_be_valid' => false],
    ],
];

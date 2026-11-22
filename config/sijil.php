<?php

return [
    'timezone' => 'Asia/Makassar',
    'document_max_kb' => 2048,
    'official_result_required' => true,
    'crew_positions' => [
        'Nakhoda' => 'Dek',
        'Mualim' => 'Dek',
        'KKM' => 'Mesin',
        'Masinis' => 'Mesin',
        'Juru Mudi' => 'Dek',
        'Kelasi' => 'Dek',
        'Juru Minyak' => 'Mesin',
        'Koki' => 'Pelayanan',
        'Pelayan' => 'Pelayanan',
    ],
    'required_crew_documents' => [
        'sign_on' => ['seafarer_book', 'competency_certificate', 'pkl'],
        'sign_off' => ['seafarer_book'],
        'update' => ['seafarer_book', 'competency_certificate', 'pkl'],
    ],
    'vessel_documents' => [
        ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'required' => true, 'must_be_valid' => false],
        ['label' => 'Sijil Awak Kapal existing', 'types' => ['sijil_awak_kapal'], 'required' => false, 'must_be_valid' => false],
        ['label' => 'Safe Manning', 'types' => ['safe_manning'], 'required' => false, 'must_be_valid' => true],
    ],
];

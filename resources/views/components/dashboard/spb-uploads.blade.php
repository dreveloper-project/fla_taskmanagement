@props(['types', 'existing' => null, 'selected' => [], 'dropzone' => true, 'disabledTypes' => []])
<x-dashboard.service-uploads :max-kb="config('spb.document_max_kb')" :types="$types" :existing="$existing" :selected="$selected" :dropzone="$dropzone" :disabled-types="$disabledTypes" prefix="spb" />

@props(['checks', 'vessel', 'serviceId' => null, 'requestType' => 'new_issue'])
<x-dashboard.service-vessel-check :checks="$checks" :vessel="$vessel" service-type="safety-certificates" :service-id="$serviceId" :request-type="$requestType" />

@props(['status'])
<span @class(['inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-semibold',
    'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' => in_array($status, ['draft', 'cancelled']),
    'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300' => in_array($status, ['ready_to_apply', 'under_review']),
    'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300' => in_array($status, ['revision_required', 'ready_to_update_crew']),
    'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300' => $status === 'rejected',
    'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300' => in_array($status, ['completed']),
])>{{ \App\Models\SijilService::STATUSES[$status] }}</span>

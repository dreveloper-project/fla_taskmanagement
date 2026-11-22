<?php
use App\Models\User;
use App\Services\StaffManagement;
use App\Services\TaskServiceRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithPagination;

    public string $search = '';

    public string $status = '';

    public string $role = '';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $workloadId = null;

    public bool $editing = false;

    public array $form = [];

    public $picture = null;

    #[Locked]
    public ?string $errorForm = null;

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'role');
        $this->resetPage();
    }

    #[Computed]
    public function staff(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = User::query()->whereIn('role', ['superadmin', 'admin', 'staff', 'finance'])->select('users.*')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->role !== '', fn ($q) => $q->where('role', $this->role))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('username', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')));
        foreach (array_values(TaskServiceRegistry::TYPES) as $index => $class) {
            $query->selectSub($class::query()->whereIn('status', $class::ACTIVE)->whereColumn('assigned_staff_id', 'users.id')->selectRaw('COUNT(*)'), 'services_'.$index);
        }

        return $query->orderBy('name')->paginate(10);
    }

    #[Computed]
    public function summary(): array
    {
        return ['Total staf' => User::where('role', 'staff')->count(), 'Staf aktif' => User::query()->assignable()->count(),
            'Staf nonaktif' => User::where('role', 'staff')->where('status', 'inactive')->count()];
    }

    public function create(): void
    {
        Gate::authorize('access-admin');
        $this->editingId = null;
        $this->form = ['fullname' => '', 'username' => '', 'email' => '', 'phone' => '', 'password' => '', 'password_confirmation' => '', 'status' => 'active', 'role' => 'staff'];
        $this->picture = null;
        $this->editing = true;
        $this->resetValidation();
        $this->dispatch('staff-form-opened');
    }

    public function edit(int $id): void
    {
        Gate::authorize('access-admin');
        $staff = User::whereIn('role', ['admin', 'staff', 'finance'])->findOrFail($id);
        $this->editingId = $staff->id;
        $this->form = $staff->only(['username', 'email', 'phone', 'status', 'role']) + ['fullname' => $staff->name];
        $this->picture = null;
        $this->editing = true;
        $this->resetValidation();
        $this->dispatch('staff-form-opened');
    }

    public function save(): void
    {
        $this->errorForm = 'save';
        $this->resetValidation();
        $this->editing = true;
        Gate::authorize('access-admin');
        app(StaffManagement::class)->save($this->editingId ? User::findOrFail($this->editingId) : null, $this->form, $this->picture);
        $this->editing = false;
        $this->picture = null;
        $this->form = [];
        unset($this->staff, $this->summary);
        session()->flash('staff-success', 'Akun berhasil disimpan.');
    }

    public function changeStatus(int $id, string $status): void
    {
        $this->errorForm = 'status-'.$id;
        $this->resetValidation();
        Gate::authorize('access-admin');
        $staff = User::findOrFail($id);
        app(StaffManagement::class)->setStatus($staff, $status);
        $work = app(StaffManagement::class)->workload($staff);
        session()->flash('staff-success', 'Status akun diperbarui. Pengguna masih memiliki '.$work['services'].' layanan aktif.');
        unset($this->staff, $this->summary);
    }

    public function viewWorkload(int $id): void
    {
        Gate::authorize('access-admin');
        if ($id !== 0) {
            User::where('role', 'staff')->findOrFail($id);
        }
        $this->workloadId = $id;
        unset($this->workload);
        $this->dispatch('staff-workload-opened');
    }

    public function closeWorkload(): void
    {
        $this->workloadId = null;
        unset($this->workload);
    }

    #[Computed]
    public function workload(): array
    {
        Gate::authorize('access-admin');
        if ($this->workloadId === null) {
            return ['services' => []];
        }
        $services = [];
        foreach (TaskServiceRegistry::TYPES as $type => $class) {
            $query = $class::query()->whereIn('status', $class::ACTIVE);
            $this->workloadId === 0 ? $query->whereNull('assigned_staff_id') : $query->where('assigned_staff_id', $this->workloadId);
            foreach ($query->get(['id', 'service_number', 'status']) as $service) {
                $services[] = ['type' => $type, 'id' => $service->id, 'number' => $service->service_number, 'status' => $service->status];
            }
        }
        return ['services' => $services, 'name' => $this->workloadId === 0 ? null : User::find($this->workloadId)?->name];
    }

};
?>
<x-dashboard.shell page="Staff Management" :demo="false">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">Management / Akun</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Staff Management</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Kelola akun dan lihat layanan aktif yang ditangani setiap staf.</p>
        </div>
        <x-dashboard.add-button wire:click="create">Tambah Akun</x-dashboard.add-button>
    </div>

    @if (session('staff-success'))
        <p data-form-success role="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">{{ session('staff-success') }}</p>
    @endif

    <section aria-label="Ringkasan staf" class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($this->summary as $label => $value)
            <div wire:key="summary-{{ $loop->index }}" class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <span @class(['flex size-11 shrink-0 items-center justify-center rounded-xl', 'bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400' => $loop->first, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400' => $loop->iteration === 2, 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => $loop->last])><x-dashboard.icon :name="$loop->iteration === 2 ? 'check' : 'clients'" class="size-5" /></span>
                <div><p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p></div>
            </div>
        @endforeach
    </section>

    @if ($editing)
        <form novalidate wire:submit="save" class="mt-6 overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-xs dark:border-blue-900 dark:bg-slate-900" x-data="{ uploading: false }" x-init="$nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))" x-on:staff-form-opened.window="$nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false">
            <x-form-errors id="staff-save-errors" :fields="$errorForm === 'save' ? [] : ['picture']" />
            <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
                <h2 class="text-base font-semibold">{{ $editingId ? 'Edit akun' : 'Tambah akun' }}</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $editingId ? 'Perbarui informasi dan akses akun.' : 'Isi informasi berikut untuk membuat akun baru.' }} Tanda * wajib diisi.</p>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                @foreach (['fullname' => 'Nama lengkap', 'username' => 'Username', 'email' => 'Email', 'phone' => 'Nomor telepon (opsional)'] as $key => $label)
                    <div wire:key="field-{{ $key }}">
                        <label for="staff-{{ $key }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}{{ $key !== 'phone' ? ' *' : '' }}</label>
                        <input id="staff-{{ $key }}" wire:model="form.{{ $key }}" type="{{ $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') }}" @required($key !== 'phone') autocomplete="{{ match ($key) { 'fullname' => 'name', 'username' => 'username', 'email' => 'email', default => 'tel' } }}" class="block h-11 w-full min-w-0 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                    </div>
                @endforeach
                <div>
                    <label for="staff-role" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Tipe akun *</label>
                    <select id="staff-role" wire:model="form.role" class="block h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="staff">Staff</option><option value="admin">Admin</option><option value="finance">Finance</option></select>
                </div>
                @if ($editingId)
                    <div><label for="staff-status" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Status akun *</label><select id="staff-status" wire:model="form.status" class="block h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
                @else
                    <div class="hidden sm:block"></div>
                    <div><label for="staff-password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Password *</label><x-password-field input-id="staff-password"><input id="staff-password" type="password" x-bind:type="passwordVisible ? 'text' : 'password'" autocomplete="new-password" wire:model="form.password" required class="block h-11 w-full rounded-lg border border-slate-200 bg-white px-3 pr-12 text-sm dark:border-slate-700 dark:bg-slate-950" /></x-password-field></div>
                    <div><label for="staff-password-confirmation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Konfirmasi password *</label><x-password-field input-id="staff-password-confirmation"><input id="staff-password-confirmation" type="password" x-bind:type="passwordVisible ? 'text' : 'password'" autocomplete="new-password" wire:model="form.password_confirmation" required class="block h-11 w-full rounded-lg border border-slate-200 bg-white px-3 pr-12 text-sm dark:border-slate-700 dark:bg-slate-950" /></x-password-field></div>
                @endif
                <div class="sm:col-span-2"><label for="staff-picture" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Foto profil (opsional)</label><input id="staff-picture" type="file" data-max-file-kb="4096" data-max-image-dimension="4096" data-compress-photo wire:model="picture" accept="image/jpeg,image/png,image/webp" class="block w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-blue-100 file:px-3 file:py-2 file:font-medium file:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:file:bg-blue-950 dark:file:text-blue-300" /><p class="mt-2 text-xs text-slate-500 dark:text-slate-400">JPG, PNG, atau WebP; maksimal 4 MB.</p><p x-cloak x-show="uploading" class="mt-2 text-xs text-blue-600 dark:text-blue-400">Mengunggah foto...</p></div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40 sm:px-6">
                <button type="button" wire:click="$set('editing', false)" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">Batal</button>
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading" class="rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-50"><span wire:loading.remove wire:target="save">Simpan akun</span><span wire:loading wire:target="save">Menyimpan...</span></button>
            </div>
        </form>
    @endif

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900 dark:shadow-none" aria-labelledby="account-list-heading">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <div><h2 id="account-list-heading" class="text-base font-semibold">Daftar akun</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->staff->total() }} akun sesuai filter · termasuk admin dan finance</p></div>
            <button type="button" wire:click="viewWorkload(0)" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"><x-dashboard.icon name="document" class="size-4" /> Layanan tanpa PIC</button>
        </div>
        <div class="grid gap-3 border-b border-slate-100 bg-slate-50/60 p-5 sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-800 dark:bg-slate-950/30">
            <x-dashboard.filter-field name="staff-search" label="Cari akun" type="search" wide wire:model.live.debounce.300ms="search" maxlength="255" placeholder="Nama, username, atau email" />
            <x-dashboard.filter-field name="staff-role-filter" label="Tipe akun" type="select" wire:model.live="role"><option value="">Semua tipe</option><option value="superadmin">Superadmin</option><option value="admin">Admin</option><option value="staff">Staff</option><option value="finance">Finance</option></x-dashboard.filter-field>
            <x-dashboard.filter-field name="staff-status-filter" label="Status" type="select" wire:model.live="status"><option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></x-dashboard.filter-field>
            @if ($search !== '' || $role !== '' || $status !== '')<div class="sm:col-span-2 lg:col-span-4"><button type="button" wire:click="resetFilters" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Reset filter</button></div>@endif
        </div>
        <div class="grid gap-3 p-4 lg:grid-cols-2 sm:p-5" aria-live="polite">
            @forelse ($this->staff as $staff)
                @php($services = collect(array_keys(array_values(\App\Services\TaskServiceRegistry::TYPES)))->sum(fn ($index) => $staff->getAttribute('services_'.$index)))
                <article wire:key="staff-{{ $staff->id }}" class="min-w-0 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-start gap-3">
                        <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">{{ $staff->initials() }}</span>
                        <div class="min-w-0 flex-1"><h3 class="break-words text-sm font-semibold text-slate-900 dark:text-white">{{ $staff->name }}</h3><p class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400">{{ $staff->username ? '@'.$staff->username : 'Username belum tersedia' }} · {{ $staff->roleLabel() }}</p></div>
                        <span @class(['shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $staff->isActive(), 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' => ! $staff->isActive()])>{{ $staff->isActive() ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2"><div class="min-w-0"><dt class="text-slate-400">Email</dt><dd class="mt-1 break-all text-slate-700 dark:text-slate-300">{{ $staff->email }}</dd></div><div><dt class="text-slate-400">Telepon</dt><dd class="mt-1 break-all text-slate-700 dark:text-slate-300">{{ $staff->phone ?: 'Belum diisi' }}</dd></div></dl>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400"><span class="font-semibold text-slate-700 dark:text-slate-200">{{ $services }}</span> layanan aktif</p>@if (! $staff->isActive() && $services)<p class="text-xs text-amber-700 dark:text-amber-300">Akun nonaktif masih menangani layanan.</p>@endif</div>
                    <x-form-errors id="staff-status-{{ $staff->id }}-errors" :show="$errorForm === 'status-'.$staff->id" class="mt-3" />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @unless ($staff->isSuperadmin())
                            <button type="button" wire:click="edit({{ $staff->id }})" class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300"><x-dashboard.icon name="edit" class="size-3.5" /> Edit</button>
                            <button type="button" wire:click="changeStatus({{ $staff->id }}, '{{ $staff->isActive() ? 'inactive' : 'active' }}')" wire:confirm="{{ $staff->isActive() ? 'Nonaktifkan' : 'Aktifkan' }} akun {{ $staff->name }}?" @class(['inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-medium', 'border-rose-200 text-rose-700 hover:bg-rose-50 dark:border-rose-900 dark:text-rose-300 dark:hover:bg-rose-950/40' => $staff->isActive(), 'border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-300 dark:hover:bg-emerald-950/40' => ! $staff->isActive()])><x-dashboard.icon name="power" class="size-3.5" /> {{ $staff->isActive() ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        @endunless
                        @if ($staff->isStaff())<button type="button" wire:click="viewWorkload({{ $staff->id }})" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"><x-dashboard.icon name="document" class="size-3.5" /> Lihat layanan</button>@endif
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center dark:border-slate-700 lg:col-span-2"><p class="text-sm font-medium">Tidak ada akun yang cocok.</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Coba ubah pencarian atau filter yang dipilih.</p></div>
            @endforelse
        </div>
        <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $this->staff->links() }}</div>
    </section>

    @if ($workloadId !== null)
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-700 dark:bg-slate-900 dark:shadow-none" aria-labelledby="staff-workload-heading" x-data x-init="$nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))" x-on:staff-workload-opened.window="$nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="staff-workload-heading" class="font-semibold">{{ $workloadId === 0 ? 'Layanan tanpa PIC' : 'Layanan aktif · '.$this->workload['name'] }}</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ count($this->workload['services']) }} layanan aktif</p></div><button type="button" wire:click="closeWorkload" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">Tutup</button></div>
            <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($this->workload['services'] as $service)
                    <div wire:key="service-{{ $service['type'] }}-{{ $service['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"><a href="{{ route($service['type'].'.show', $service['id']) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ $service['number'] }}</a><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ str_replace('_', ' ', ucfirst($service['status'])) }}</span></div>
                @empty<p class="py-4 text-sm text-slate-500 dark:text-slate-400">Tidak ada layanan aktif.</p>@endforelse
            </div>
        </section>
    @endif
</x-dashboard.shell>

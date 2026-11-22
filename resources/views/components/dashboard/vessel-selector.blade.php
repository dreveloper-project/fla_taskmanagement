@props(['vessels', 'selectedVessel' => null, 'disabled' => false, 'passengerOnly' => false])

<div
    x-data="{
        vessels: @js($vessels->map(fn ($vessel) => ['id' => (string) $vessel->id, 'name' => $vessel->vessel_name])->values()->all()),
        query: @js($selectedVessel?->vessel_name ?? ''),
        open: false,
        activeIndex: -1,
        get matches() {
            const term = this.query.trim().toLocaleLowerCase();
            return this.vessels.filter(vessel => vessel.name.toLocaleLowerCase().includes(term));
        },
        choose(vessel) {
            if (!vessel) return;
            this.query = vessel.name;
            this.open = false;
            this.activeIndex = -1;
            $wire.$set('form.vessel_id', vessel.id);
        },
        search() {
            this.open = true;
            this.activeIndex = -1;
            $wire.$set('form.vessel_id', '', false);
        },
        close() {
            this.open = false;
            this.activeIndex = -1;
            const selected = this.vessels.find(vessel => vessel.id === String($wire.form.vessel_id));
            this.query = selected ? selected.name : '';
        }
    }"
    @click.outside="close()"
    @focusout="if (!$el.contains($event.relatedTarget)) close()"
    class="relative"
>
    <label for="vessel_id" class="mb-2 block text-sm font-medium">Pilih Vessel atau Kapal{{ $passengerOnly ? ' Penumpang' : '' }} *</label>
    <input
        id="vessel_id"
        type="text"
        x-model="query"
        @input="search()"
        @focus="open = true"
        @keydown.escape.prevent="close()"
        @keydown.arrow-down.prevent="open = true; activeIndex = Math.min(activeIndex + 1, matches.length - 1)"
        @keydown.arrow-up.prevent="open = true; activeIndex = Math.max(activeIndex - 1, 0)"
        @keydown.enter="if (open) { $event.preventDefault(); choose(matches[activeIndex < 0 ? 0 : activeIndex]); }"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="vessel-options"
        :aria-expanded="open.toString()"
        :aria-activedescendant="open && activeIndex >= 0 && matches[activeIndex] ? 'vessel-option-' + matches[activeIndex].id : null"
        placeholder="Cari dan pilih vessel atau kapal"
        autocomplete="off"
        required
        @disabled($disabled)
        class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm disabled:bg-slate-50 dark:border-slate-700 dark:disabled:bg-slate-800"
    >
    <div x-cloak x-show="open" id="vessel-options" role="listbox" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
        <template x-for="(vessel, index) in matches" :key="vessel.id">
            <button type="button" :id="'vessel-option-' + vessel.id" role="option" :aria-selected="String($wire.form.vessel_id) === vessel.id" @mousedown.prevent @click="choose(vessel)" @mousemove="activeIndex = index" :class="activeIndex === index ? 'bg-blue-50 dark:bg-slate-800' : ''" class="block w-full px-3 py-2 text-left text-sm hover:bg-blue-50 dark:hover:bg-slate-800" x-text="vessel.name"></button>
        </template>
        <p x-show="matches.length === 0" class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400">Kapal tidak ditemukan.</p>
    </div>
</div>

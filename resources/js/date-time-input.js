const pad = (value) => String(value).padStart(2, '0');

export function parseDate(value) {
    const text = String(value || '').trim();
    let match = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        const local = text.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$/)
            || text.match(/^(\d{2})(\d{2})(\d{4})$/);
        if (!local) return '';
        match = [local[0], local[3], pad(local[2]), pad(local[1])];
    }
    const iso = `${match[1]}-${pad(match[2])}-${pad(match[3])}`;
    const date = new Date(`${iso}T12:00:00Z`);
    return Number(match[1]) > 0 && !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === iso ? iso : '';
}

export function displayDate(value) {
    return parseDate(value) ? value.split('-').reverse().join('/') : '';
}

export function parseTime(value) {
    const text = String(value || '').trim();
    const match = text.match(/^(\d{1,2})[:.](\d{2})$/) || text.match(/^(\d{1,2})(\d{2})$/);
    return match && Number(match[1]) < 24 && Number(match[2]) < 60 ? `${pad(match[1])}:${match[2]}` : '';
}

export function witaNow(now = new Date()) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(now).map(({ type, value }) => [type, value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

export function addDays(iso, count) {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + count);
    return date.toISOString().slice(0, 10);
}

export function dateTimeInput({ type = 'date', value = '' } = {}) {
    return {
        value: value || '', lastValue: '', dateText: '', timeText: '', error: '', open: false, root: null,
        month: 0, year: 2000, focusedDate: '',
        months: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        weekdays: ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
        get hasTime() { return type === 'datetime-local'; },
        get today() { return witaNow().date; },
        get minimum() { return this.root?.dataset.min || ''; },
        get maximum() {
            if (this.root?.dataset.max !== 'now') return this.root?.dataset.max || '';
            const current = witaNow();
            return current.date + (this.hasTime ? `T${current.time}` : '');
        },
        get selectedDate() { return parseDate(this.dateText); },
        get nowAllowed() {
            const current = witaNow();
            const candidate = current.date + (this.hasTime ? `T${current.time}` : '');
            return (!this.minimum || candidate >= this.minimum) && (!this.maximum || candidate <= this.maximum);
        },
        get tomorrowMorningAllowed() {
            const candidate = `${addDays(witaNow().date, 1)}T08:00`;
            return (!this.minimum || candidate >= this.minimum) && (!this.maximum || candidate <= this.maximum);
        },
        get monthLabel() { return `${this.months[this.month]} ${this.year}`; },
        get weeks() {
            const first = `${String(this.year).padStart(4, '0')}-${pad(this.month + 1)}-01`;
            const start = addDays(first, -((new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7));
            return Array.from({ length: 6 }, (_, week) => Array.from({ length: 7 }, (_, day) => {
                const iso = addDays(start, week * 7 + day);
                return { iso, day: Number(iso.slice(8)), currentMonth: Number(iso.slice(5, 7)) === this.month + 1 };
            }));
        },
        init() {
            this.root = this.$el;
            this.value = this.root.dataset.initialValue ?? this.value;
            this.receiveValue(this.value);
            this.$watch('value', (value) => {
                if ((value || '') !== this.lastValue) this.receiveValue(value);
            });
            this.reposition = () => { if (this.open) this.positionCalendar(); };
            window.addEventListener('resize', this.reposition);
            window.addEventListener('scroll', this.reposition, true);
        },
        destroy() {
            window.removeEventListener('resize', this.reposition);
            window.removeEventListener('scroll', this.reposition, true);
            if (this.open) this.$refs.calendar.hidePopover();
        },
        receiveValue(value) {
            this.lastValue = value || '';
            const [date = '', time = ''] = this.lastValue.split('T');
            this.dateText = displayDate(date);
            this.timeText = this.hasTime ? time.slice(0, 5) : '';
            this.error = '';
            this.viewDate(parseDate(date) || witaNow().date);
        },
        viewDate(iso) {
            this.year = Number(iso.slice(0, 4));
            this.month = Number(iso.slice(5, 7)) - 1;
            this.focusedDate = iso;
        },
        dateAllowed(iso) {
            return (!this.minimum || iso >= this.minimum.slice(0, 10)) && (!this.maximum || iso <= this.maximum.slice(0, 10));
        },
        valueError() {
            if (!this.dateText.trim() && !this.timeText.trim()) {
                return this.root.dataset.required === 'true' ? 'Tanggal wajib diisi.' : '';
            }
            const date = this.selectedDate;
            if (!date) return 'Masukkan tanggal yang valid, misalnya 27/10/2026.';
            const time = parseTime(this.timeText);
            if (this.hasTime && !time) return 'Masukkan jam 00:00–23:59, misalnya 14:30.';
            const candidate = date + (this.hasTime ? `T${time}` : '');
            if (this.minimum && candidate < this.minimum) {
                return `Paling awal ${this.boundaryLabel(this.minimum)}.`;
            }
            if (this.maximum && candidate > this.maximum) {
                return `Paling akhir ${this.boundaryLabel(this.maximum)}.`;
            }
            return '';
        },
        boundaryLabel(value) {
            return displayDate(value.slice(0, 10)) + (this.hasTime && value.includes('T') ? ` pukul ${value.slice(11, 16)} WITA` : '');
        },
        syncValue(showError = false) {
            const error = this.valueError();
            const date = this.selectedDate;
            const candidate = !error && date ? date + (this.hasTime ? `T${parseTime(this.timeText)}` : '') : '';
            this.lastValue = candidate;
            this.value = candidate;
            this.error = showError ? error : (error ? this.error : '');
            return !error;
        },
        commitDate() {
            const iso = this.selectedDate;
            if (iso) this.dateText = displayDate(iso);
            this.syncValue(!this.hasTime || Boolean(this.timeText) || !iso);
        },
        commitTime() {
            const time = parseTime(this.timeText);
            if (time) this.timeText = time;
            this.syncValue(true);
        },
        clear() {
            this.dateText = '';
            this.timeText = '';
            this.error = '';
            this.lastValue = '';
            this.value = '';
            this.closeCalendar();
        },
        useNow(now = new Date()) {
            const current = witaNow(now);
            this.dateText = displayDate(current.date);
            if (this.hasTime) this.timeText = current.time;
            this.syncValue(true);
        },
        useTomorrowMorning(now = new Date()) {
            this.dateText = displayDate(addDays(witaNow(now).date, 1));
            this.timeText = '08:00';
            this.syncValue(true);
        },
        chooseDate(iso) {
            if (!this.dateAllowed(iso)) return;
            this.dateText = displayDate(iso);
            this.syncValue(false);
            this.closeCalendar();
            if (this.hasTime && !parseTime(this.timeText)) this.$refs.time.focus();
        },
        fullDateLabel(iso) {
            return new Intl.DateTimeFormat('id-ID', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(`${iso}T12:00:00Z`));
        },
        openCalendar() {
            if (this.open) { this.closeCalendar(); return; }
            let initial = this.selectedDate || witaNow().date;
            if (this.minimum && initial < this.minimum.slice(0, 10)) initial = this.minimum.slice(0, 10);
            if (this.maximum && initial > this.maximum.slice(0, 10)) initial = this.maximum.slice(0, 10);
            this.viewDate(initial);
            this.$refs.calendar.showPopover();
            this.open = true;
            this.$nextTick(() => { this.positionCalendar(); this.focusDay(); });
        },
        closeCalendar() {
            if (!this.open) return;
            this.$refs.calendar.hidePopover();
            this.open = false;
            this.$refs.trigger.focus();
        },
        positionCalendar() {
            const anchor = this.$refs.date.getBoundingClientRect();
            const panel = this.$refs.calendar;
            const width = Math.min(336, window.innerWidth - 24);
            panel.style.width = `${width}px`;
            const height = panel.getBoundingClientRect().height;
            panel.style.left = `${Math.max(12, Math.min(anchor.left, window.innerWidth - width - 12))}px`;
            panel.style.top = `${Math.max(12, anchor.bottom + height + 8 < window.innerHeight - 12 ? anchor.bottom + 8 : anchor.top - height - 8)}px`;
        },
        changeMonth(offset) {
            const date = new Date(Date.UTC(this.year, this.month + offset, 1, 12));
            if (date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999) return;
            this.year = date.getUTCFullYear();
            this.month = date.getUTCMonth();
            this.focusedDate = `${String(this.year).padStart(4, '0')}-${pad(this.month + 1)}-01`;
            if (this.minimum && this.focusedDate < this.minimum.slice(0, 10)) this.focusedDate = this.minimum.slice(0, 10);
        },
        changeYear(value) {
            if (/^\d{1,4}$/.test(value) && Number(value) >= 1) {
                this.year = Number(value);
                this.focusedDate = `${String(this.year).padStart(4, '0')}-${pad(this.month + 1)}-01`;
                if (this.minimum && this.focusedDate < this.minimum.slice(0, 10)) this.focusedDate = this.minimum.slice(0, 10);
            }
        },
        focusDay() {
            const button = this.$refs.calendar.querySelector(`[data-date="${this.focusedDate}"]:not(:disabled)`)
                || this.$refs.calendar.querySelector('[data-date]:not(:disabled)');
            if (button) { this.focusedDate = button.dataset.date; button.focus(); }
        },
        navigateDay(event, iso) {
            const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
            let target;
            if (event.key in offsets) target = addDays(iso, offsets[event.key]);
            else if (event.key === 'Home' || event.key === 'End') {
                const weekday = (new Date(`${iso}T12:00:00Z`).getUTCDay() + 6) % 7;
                target = addDays(iso, event.key === 'Home' ? -weekday : 6 - weekday);
            } else if (event.key === 'PageUp' || event.key === 'PageDown') {
                const delta = (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1);
                const date = new Date(`${iso}T12:00:00Z`);
                const day = date.getUTCDate();
                date.setUTCDate(1);
                date.setUTCMonth(date.getUTCMonth() + delta);
                const last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
                date.setUTCDate(Math.min(day, last));
                target = date.toISOString().slice(0, 10);
            } else return;
            event.preventDefault();
            if (this.minimum && target < this.minimum.slice(0, 10)) target = this.minimum.slice(0, 10);
            if (this.maximum && target > this.maximum.slice(0, 10)) target = this.maximum.slice(0, 10);
            this.viewDate(target);
            this.$nextTick(() => this.focusDay());
        },
    };
}

export function installDateTimeInputs(document, browser = window) {
    const register = () => browser.Alpine.data('dateTimeInput', dateTimeInput);
    if (browser.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });

    document.addEventListener('submit', (event) => {
        let firstInvalid;
        for (const element of event.target.querySelectorAll('[data-date-time-input]')) {
            const control = element.querySelector('[data-date-text]');
            if (control.disabled || control.readOnly || control.closest('fieldset[disabled]')) continue;
            const picker = browser.Alpine.$data(element);
            if (!picker.syncValue(true)) firstInvalid ||= control;
        }
        if (firstInvalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            firstInvalid.focus();
        }
    }, true);
}

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addDays, dateTimeInput, displayDate, installDateTimeInputs, parseDate, parseTime, witaNow } from '../../resources/js/date-time-input.js';

test('Indonesian dates accept typing and pasting without swapping day and month', () => {
    for (const [input, expected] of [
        ['27/10/2026', '2026-10-27'], ['5/2/2026', '2026-02-05'],
        ['27-10-2026', '2026-10-27'], ['27.10.2026', '2026-10-27'],
        ['27102026', '2026-10-27'], ['2026-10-27', '2026-10-27'],
        ['29/02/2024', '2024-02-29'], ['29/02/2026', ''], ['31/04/2026', ''],
        ['13/27/2026', ''], ['00/01/2026', ''], ['27/10/26', ''], ['', ''],
    ]) assert.equal(parseDate(input), expected, input);
    assert.equal(displayDate('2026-02-05'), '05/02/2026');
});

test('24 hour time accepts shorthand and rejects impossible times', () => {
    for (const [input, expected] of [
        ['9:30', '09:30'], ['09.30', '09:30'], ['930', '09:30'], ['1430', '14:30'],
        ['00:00', '00:00'], ['23:59', '23:59'], ['24:00', ''], ['12:60', ''],
        ['2:5', ''], ['-1:30', ''], ['', ''],
    ]) assert.equal(parseTime(input), expected, input);
});

test('now uses WITA even when the device is in another timezone and the date rolls over', () => {
    assert.deepEqual(witaNow(new Date('2026-10-27T18:07:00Z')), { date: '2026-10-28', time: '02:07' });
    assert.deepEqual(witaNow(new Date('2026-10-27T16:00:00Z')), { date: '2026-10-28', time: '00:00' });
});

function picker(options = {}) {
    const state = dateTimeInput(options);
    state.$el = { dataset: { min: options.min || '', max: options.max || '', required: options.required ? 'true' : 'false' } };
    state.root = state.$el;
    state.$refs = { trigger: { focus() {} }, time: { focus() {} }, calendar: { hidePopover() {} } };
    state.receiveValue(options.value || '');
    return state;
}

test('date fields load initial DOM values without putting changing form data in their initializer', (context) => {
    const originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
    Object.defineProperty(globalThis, 'window', { configurable: true, value: new EventTarget() });
    context.after(() => {
        if (originalWindow) Object.defineProperty(globalThis, 'window', originalWindow);
        else delete globalThis.window;
    });
    const state = dateTimeInput({ type: 'datetime-local' });
    state.$el = { dataset: { initialValue: '2026-10-27T14:30' } };
    let receiveServerValue;
    state.$watch = (name, callback) => { receiveServerValue = callback; };

    state.init();

    assert.equal(state.value, '2026-10-27T14:30');
    assert.equal(state.dateText, '27/10/2026');
    assert.equal(state.timeText, '14:30');
    state.dateText = '28/10/2026';
    state.syncValue();
    state.root.dataset.initialValue = '2026-10-27T14:30';
    receiveServerValue(state.value);
    assert.equal(state.dateText, '28/10/2026');
    receiveServerValue('');
    assert.equal(state.dateText, '');
    assert.equal(state.timeText, '');
    state.destroy();
});

test('date bounds remain safe while Alpine initializes or reconciles the component', () => {
    const state = dateTimeInput();

    assert.equal(state.minimum, '');
    assert.equal(state.maximum, '');
    assert.equal(state.nowAllowed, true);
});

test('date and time are combined in the exact local format expected by existing workflows', () => {
    const state = picker({ type: 'datetime-local' });
    state.dateText = '5/2/2026';
    state.timeText = '930';
    state.commitDate();
    state.commitTime();
    assert.equal(state.dateText, '05/02/2026');
    assert.equal(state.timeText, '09:30');
    assert.equal(state.value, '2026-02-05T09:30');
    assert.equal(state.error, '');
});

test('incomplete or invalid edits cannot submit the previously selected value', () => {
    const state = picker({ type: 'datetime-local', value: '2026-10-27T14:30' });
    state.dateText = '31/02/2026';
    assert.equal(state.syncValue(true), false);
    assert.equal(state.value, '');
    assert.match(state.error, /tanggal yang valid/);
    state.dateText = '27/10/2026';
    state.timeText = '24:00';
    assert.equal(state.syncValue(true), false);
    assert.equal(state.value, '');
    assert.match(state.error, /00:00–23:59/);
});

test('date selection preserves an existing time and does not invent a time for a new event', () => {
    const state = picker({ type: 'datetime-local', value: '2026-10-27T14:30' });
    state.chooseDate('2026-10-28');
    assert.equal(state.value, '2026-10-28T14:30');
    state.clear();
    state.chooseDate('2026-10-29');
    assert.equal(state.dateText, '29/10/2026');
    assert.equal(state.timeText, '');
    assert.equal(state.value, '');
    assert.equal(state.error, '');
});

test('datetime boundaries apply to the full time while the calendar allows boundary dates', () => {
    const state = picker({ type: 'datetime-local', min: '2026-10-27T10:00', max: '2026-10-28T15:00' });
    assert.equal(state.dateAllowed('2026-10-26'), false);
    assert.equal(state.dateAllowed('2026-10-27'), true);
    assert.equal(state.dateAllowed('2026-10-29'), false);
    state.dateText = '27/10/2026';
    state.timeText = '09:59';
    assert.equal(state.syncValue(true), false);
    assert.match(state.error, /Paling awal 27\/10\/2026 pukul 10:00 WITA/);
    state.timeText = '10:00';
    assert.equal(state.syncValue(true), true);
    state.dateText = '28/10/2026';
    state.timeText = '15:01';
    assert.equal(state.syncValue(true), false);
    assert.match(state.error, /Paling akhir/);
    state.timeText = '15:00';
    assert.equal(state.syncValue(true), true);
});

test('blank optional fields stay blank and required fields show a useful error', () => {
    const optional = picker();
    assert.equal(optional.syncValue(true), true);
    assert.equal(optional.value, '');
    const required = picker({ required: true });
    assert.equal(required.syncValue(true), false);
    assert.equal(required.error, 'Tanggal wajib diisi.');
});

test('a now boundary advances while the form stays open', (context) => {
    context.mock.timers.enable({ apis: ['Date'], now: new Date('2026-10-27T06:30:00Z').getTime() });
    const state = picker({ type: 'datetime-local', max: 'now' });
    assert.equal(state.maximum, '2026-10-27T14:30');
    context.mock.timers.tick(120000);
    state.useNow();
    assert.equal(state.maximum, '2026-10-27T14:32');
    assert.equal(state.value, '2026-10-27T14:32');
    state.timeText = '14:33';
    assert.equal(state.syncValue(true), false);
});

test('today shortcuts are unavailable when outside the permitted range', (context) => {
    context.mock.timers.enable({ apis: ['Date'], now: new Date('2026-10-27T06:30:00Z').getTime() });
    assert.equal(picker({ min: '2026-10-28' }).nowAllowed, false);
    assert.equal(picker({ max: '2026-10-26' }).nowAllowed, false);
    assert.equal(picker({ type: 'datetime-local', min: '2026-10-27T14:31' }).nowAllowed, false);
    assert.equal(picker({ type: 'datetime-local', max: 'now' }).nowAllowed, true);
});

test('now and clear update both parts and server resets replace the displayed values', () => {
    const state = picker({ type: 'datetime-local' });
    state.useNow(new Date('2026-10-27T18:07:00Z'));
    assert.equal(state.value, '2026-10-28T02:07');
    state.clear();
    assert.equal(state.value, '');
    assert.equal(state.dateText, '');
    assert.equal(state.timeText, '');
    state.receiveValue('2027-01-02T23:45');
    assert.equal(state.dateText, '02/01/2027');
    assert.equal(state.timeText, '23:45');
    state.receiveValue(null);
    assert.equal(state.dateText, '');
    assert.equal(state.timeText, '');
});

test('tomorrow morning shortcut uses the next WITA date at 08:00', () => {
    const state = picker({ type: 'datetime-local' });
    state.useTomorrowMorning(new Date('2026-12-31T18:07:00Z'));
    assert.equal(state.value, '2027-01-02T08:00');
    assert.equal(state.dateText, '02/01/2027');
    assert.equal(state.timeText, '08:00');
});

test('calendar weeks start on Monday and preserve leap days and year transitions', () => {
    const state = picker({ value: '2024-02-29' });
    assert.equal(state.weeks[0][0].iso, '2024-01-29');
    assert.equal(state.weeks.flat().find((day) => day.iso === '2024-02-29').day, 29);
    assert.equal(addDays('2026-12-31', 1), '2027-01-01');
    assert.equal(addDays('2024-03-01', -1), '2024-02-29');
    state.changeMonth(-2);
    assert.equal(state.year, 2023);
    assert.equal(state.month, 11);
    state.changeYear('2035');
    assert.equal(state.year, 2035);
    state.changeYear('');
    assert.equal(state.year, 2035);
});

test('keyboard month navigation clamps the day and respects allowed dates', () => {
    const state = picker({ value: '2026-01-31', max: '2026-03-01' });
    state.$nextTick = (callback) => callback();
    state.focusDay = () => {};
    let prevented = false;
    state.navigateDay({ key: 'PageDown', preventDefault() { prevented = true; } }, '2026-01-31');
    assert.equal(prevented, true);
    assert.equal(state.focusedDate, '2026-02-28');
    state.navigateDay({ key: 'ArrowRight', preventDefault() {} }, '2026-03-01');
    assert.equal(state.focusedDate, '2026-03-01');
});

test('invalid manual dates stop submission even in novalidate Livewire forms', () => {
    const listeners = new Map();
    const state = picker({ required: true });
    let focused = false;
    const control = { disabled: false, readOnly: false, closest: () => null, focus() { focused = true; } };
    const element = { querySelector: () => control };
    const document = { addEventListener: (name, handler) => listeners.set(name, handler) };
    const browser = { Alpine: { data() {}, $data: () => state } };
    installDateTimeInputs(document, browser);
    const event = { target: { querySelectorAll: () => [element] }, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
    listeners.get('submit')(event);
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, true);
    assert.equal(focused, true);
    state.dateText = '27/10/2026';
    const valid = { ...event, prevented: false, stopped: false };
    listeners.get('submit')(valid);
    assert.equal(valid.prevented, false);
    assert.equal(state.value, '2026-10-27');
});

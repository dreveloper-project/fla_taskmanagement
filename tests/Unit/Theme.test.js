import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { installTheme } from '../../resources/js/theme.js';

test('initial page defaults to light and restores explicit choices before the app loads', () => {
    const layout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    const script = layout.match(/<script>([\s\S]*?)<\/script>/)[1];

    for (const [saved, systemDark, unavailable, expected, forcedTheme = null] of [
        ['dark', false, false, true],
        ['light', true, false, false],
        ['matcha', true, false, false],
        [null, true, false, false],
        [null, false, false, false],
        [null, true, true, false],
        ['unknown', true, false, false],
        ['dark', true, false, false, 'light'],
    ]) {
        let dark;
        const attributes = new Map();
        const document = { documentElement: {
            getAttribute: () => forcedTheme,
            setAttribute: (name, value) => attributes.set(name, value),
            classList: { toggle: (_, value) => { dark = value; } }, style: {},
        } };
        runInNewContext(script, {
            document,
            window: { matchMedia: () => ({ matches: systemDark }) },
            localStorage: { getItem: () => { if (unavailable) throw new Error('Blocked'); return saved; } },
        });
        assert.equal(dark, expected);
        assert.equal(attributes.get('data-theme'), forcedTheme || (!unavailable && ['light', 'dark'].includes(saved) ? saved : 'light'));
        assert.equal(document.documentElement.style.colorScheme, expected ? 'dark' : 'light');
    }
});

function themeHarness({ saved = null, systemDark = false, storageUnavailable = false, forcedTheme = null } = {}) {
    const events = new Map();
    const browserEvents = new Map();
    const classes = new Set();
    let button = new Map();
    const attributes = new Map();
    const label = { textContent: '' };
    const storage = new Map(saved ? [['fla-theme', saved]] : []);
    const media = { matches: systemDark, addEventListener: (_, callback) => { media.change = callback; } };
    const document = {
        body: { getAttribute: () => forcedTheme },
        documentElement: {
            setAttribute: (name, value) => attributes.set(name, value),
            classList: {
                toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
                contains: (name) => classes.has(name),
            },
            style: {},
        },
        querySelectorAll: (selector) => selector === '[data-theme-label]' ? [label] : [{ setAttribute: (name, value) => button.set(name, value) }],
        addEventListener: (name, callback) => events.set(name, callback),
    };
    const browser = {
        matchMedia: () => media,
        localStorage: {
            getItem: (key) => { if (storageUnavailable) throw new Error('Blocked'); return storage.get(key) ?? null; },
            setItem: (key, value) => { if (storageUnavailable) throw new Error('Blocked'); storage.set(key, value); },
        },
        addEventListener: (name, callback) => browserEvents.set(name, callback),
    };
    installTheme(document, browser);
    return {
        document, media, storage, events, browserEvents, label,
        get theme() { return attributes.get('data-theme'); },
        get dark() { return classes.has('dark'); },
        get button() { return button; },
        setForcedTheme(theme) { forcedTheme = theme; },
        click(target = '[data-theme-toggle]') { events.get('click')({ target: { closest: (selector) => selector === target ? {} : null } }); },
        navigate() { classes.clear(); button = new Map(); events.get('livewire:navigated')(); },
    };
}

test('login stays light across navigation and storage changes while preserving the dashboard preference', () => {
    const harness = themeHarness({ saved: 'dark', forcedTheme: 'light' });
    assert.equal(harness.theme, 'light');
    assert.equal(harness.dark, false);
    assert.equal(harness.document.documentElement.style.colorScheme, 'light');
    harness.click();
    assert.equal(harness.storage.get('fla-theme'), 'dark');
    harness.navigate();
    harness.browserEvents.get('storage')({ key: 'fla-theme', newValue: 'dark' });
    assert.equal(harness.theme, 'light');
    harness.setForcedTheme(null);
    harness.navigate();
    assert.equal(harness.theme, 'dark');
    assert.equal(harness.dark, true);
    harness.setForcedTheme('light');
    harness.navigate();
    assert.equal(harness.theme, 'light');
});

test('theme defaults to light even when the system uses dark mode', () => {
    const harness = themeHarness({ systemDark: true });
    assert.equal(harness.dark, false);
    assert.equal(harness.document.documentElement.style.colorScheme, 'light');
    harness.click();
    assert.equal(harness.dark, true);
    assert.equal(harness.storage.get('fla-theme'), 'dark');
});

test('saved light and dark choices override the system on reload', () => {
    assert.equal(themeHarness({ saved: 'light', systemDark: true }).dark, false);
    assert.equal(themeHarness({ saved: 'dark', systemDark: false }).dark, true);
    assert.equal(themeHarness({ saved: 'unknown', systemDark: true }).dark, false);
});

test('one navbar toggle cycles between light and dark and restores Indonesian hints after navigation', () => {
    const harness = themeHarness();
    harness.click();
    assert.equal(harness.dark, true);
    assert.equal(harness.button.get('aria-label'), 'Tema gelap. Ganti ke Tema terang.');
    assert.equal(harness.button.get('title'), 'Ganti ke Tema terang');
    harness.navigate();
    assert.equal(harness.dark, true);
    assert.equal(harness.button.get('aria-label'), 'Tema gelap. Ganti ke Tema terang.');
    harness.click();
    assert.equal(harness.dark, false);
    assert.equal(harness.theme, 'light');
    assert.equal(harness.document.documentElement.style.colorScheme, 'light');
    assert.equal(harness.storage.get('fla-theme'), 'light');
    assert.equal(harness.label.textContent, 'Tema terang');
    assert.equal(harness.button.get('title'), 'Ganti ke Tema gelap');
});

test('unrelated clicks do not change the theme', () => {
    const harness = themeHarness();
    harness.events.get('click')({ target: { closest: () => null } });
    assert.equal(harness.dark, false);
    assert.equal(harness.storage.size, 0);
});

test('theme can still be toggled and retained across navigation when storage is blocked', () => {
    const harness = themeHarness({ storageUnavailable: true });
    harness.click();
    harness.navigate();
    assert.equal(harness.dark, true);
    harness.click();
    assert.equal(harness.dark, false);
    assert.equal(harness.theme, 'light');
    harness.navigate();
    assert.equal(harness.theme, 'light');
    harness.click();
    assert.equal(harness.theme, 'dark');
});

test('theme changes and clearing preferences in another tab update the current page', () => {
    const harness = themeHarness({ systemDark: true, saved: 'light' });
    harness.browserEvents.get('storage')({ key: 'fla-theme', newValue: 'dark' });
    assert.equal(harness.dark, true);
    harness.browserEvents.get('storage')({ key: 'unrelated', newValue: 'light' });
    assert.equal(harness.dark, true);
    harness.browserEvents.get('storage')({ key: 'fla-theme', newValue: 'light' });
    assert.equal(harness.dark, false);
    harness.browserEvents.get('storage')({ key: null, newValue: null });
    assert.equal(harness.dark, false);
});

test('removed Bubble Gum preferences fall back to light on reload and navigation', () => {
    const harness = themeHarness({ saved: 'matcha', systemDark: true });
    assert.equal(harness.theme, 'light');
    assert.equal(harness.dark, false);
    assert.equal(harness.document.documentElement.style.colorScheme, 'light');
    assert.equal(harness.button.get('aria-label'), 'Tema terang. Ganti ke Tema gelap.');
    assert.equal(harness.label.textContent, 'Tema terang');
    harness.navigate();
    assert.equal(harness.theme, 'light');
    assert.equal(harness.button.get('title'), 'Ganti ke Tema gelap');
});

test('the single toggle selects and persists light from dark then returns to dark', () => {
    const harness = themeHarness({ saved: 'dark' });
    harness.click();
    assert.equal(harness.theme, 'light');
    assert.equal(harness.dark, false);
    assert.equal(harness.storage.get('fla-theme'), 'light');
    assert.equal(harness.button.get('aria-label'), 'Tema terang. Ganti ke Tema gelap.');
    harness.click();
    assert.equal(harness.theme, 'dark');
    assert.equal(harness.storage.get('fla-theme'), 'dark');
    assert.equal(harness.button.get('title'), 'Ganti ke Tema terang');
});

test('the single toggle replaces a removed theme preference with a valid choice', () => {
    const harness = themeHarness({ saved: 'matcha' });
    harness.click();
    assert.equal(harness.theme, 'dark');
    assert.equal(harness.storage.get('fla-theme'), 'dark');
    assert.equal(harness.dark, true);
    assert.equal(harness.button.get('title'), 'Ganti ke Tema terang');
    assert.equal(harness.label.textContent, 'Tema gelap');
});

test('removed themes from another tab fall back to light even when storage is blocked', () => {
    const harness = themeHarness({ storageUnavailable: true });
    harness.click();
    harness.click();
    harness.navigate();
    assert.equal(harness.theme, 'light');
    harness.browserEvents.get('storage')({ key: 'fla-theme', newValue: 'dark' });
    assert.equal(harness.theme, 'dark');
    harness.browserEvents.get('storage')({ key: 'fla-theme', newValue: 'matcha' });
    assert.equal(harness.theme, 'light');
    harness.browserEvents.get('storage')({ key: null, newValue: null });
    assert.equal(harness.theme, 'light');
});

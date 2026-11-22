export function installTheme(document, window) {
    const themes = ['light', 'dark'];
    const labels = { light: 'Tema terang', dark: 'Tema gelap' };
    let preference;

    try {
        preference = window.localStorage.getItem('fla-theme');
    } catch {
        preference = null;
    }

    function applyTheme() {
        if (!themes.includes(preference)) preference = 'light';
        const forcedTheme = document.body.getAttribute('data-forced-theme');
        const theme = themes.includes(forcedTheme) ? forcedTheme : preference;
        const dark = theme === 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';

        for (const button of document.querySelectorAll('[data-theme-toggle]')) {
            const nextTheme = themes[(themes.indexOf(theme) + 1) % themes.length];
            button.setAttribute('aria-label', `${labels[theme]}. Ganti ke ${labels[nextTheme]}.`);
            button.setAttribute('title', `Ganti ke ${labels[nextTheme]}`);
        }
        for (const label of document.querySelectorAll('[data-theme-label]')) {
            label.textContent = labels[theme];
        }
    }

    document.addEventListener('click', (event) => {
        if (themes.includes(document.body.getAttribute('data-forced-theme')) || !event.target.closest('[data-theme-toggle]')) {
            return;
        }

        preference = themes[(themes.indexOf(preference) + 1) % themes.length];
        try {
            window.localStorage.setItem('fla-theme', preference);
        } catch {
            // Tema tetap dapat diganti saat penyimpanan browser tidak tersedia.
        }
        applyTheme();
    });

    document.addEventListener('livewire:navigated', applyTheme);
    window.addEventListener('storage', (event) => {
        if (event.key === 'fla-theme' || event.key === null) {
            preference = event.newValue;
            applyTheme();
        }
    });

    applyTheme();
}

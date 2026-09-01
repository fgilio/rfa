(function (root, factory) {
    const api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaAppearanceStore = api;
        api.restoreSelectedAppearance(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const APPEARANCE_COOKIE = 'rfa_appearance';
    const LEGACY_THEME_COOKIE = 'rfa_theme';
    const FLUX_STORAGE_KEY = 'flux.appearance';
    const SELECTED_APPEARANCES = ['light', 'dark', 'system'];

    function readCookie(root, name) {
        const prefix = `${name}=`;
        const cookie = root.document.cookie
            .split(';')
            .map((value) => value.trim())
            .find((value) => value.startsWith(prefix));

        return cookie ? cookie.slice(prefix.length) : null;
    }

    function restoreSelectedAppearance(root) {
        if (root.localStorage.getItem(FLUX_STORAGE_KEY) !== null) return false;

        const appearance = readCookie(root, APPEARANCE_COOKIE);

        if (!['light', 'dark'].includes(appearance)) return false;

        root.localStorage.setItem(FLUX_STORAGE_KEY, appearance);

        return true;
    }

    function persistSelectedAppearance(root, appearance) {
        if (!SELECTED_APPEARANCES.includes(appearance)) return false;

        root.document.cookie = `${APPEARANCE_COOKIE}=${appearance};path=/;max-age=31536000;SameSite=Lax`;
        root.document.cookie = `${LEGACY_THEME_COOKIE}=;path=/;max-age=0;SameSite=Lax`;

        return true;
    }

    return {
        persistSelectedAppearance,
        readCookie,
        restoreSelectedAppearance,
    };
});

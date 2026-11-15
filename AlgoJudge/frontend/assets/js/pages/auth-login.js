// Shared auth-page banner: finish every animation, rest 1.5s, then replay.
(() => {
    'use strict';
    const banner = document.querySelector('.auth-page--animated .auth-banner');
    if (!banner || typeof banner.getAnimations !== 'function') return;

    let timer = null;
    let generation = 0;

    async function repeat(restart = false) {
        const current = ++generation;
        const animations = banner.getAnimations({ subtree: true });
        if (!animations.length) return;
        if (restart) {
            for (const animation of animations) {
                animation.cancel();
                animation.play();
            }
        }
        try {
            await Promise.all(animations.map(animation => animation.finished));
        } catch (_) {
            // A removed element/cancelled animation should not start a timer chain.
            return;
        }
        if (current !== generation) return;
        timer = window.setTimeout(() => {
            timer = null;
            repeat(true);
        }, 1500);
    }

    window.addEventListener('pagehide', () => {
        generation++;
        window.clearTimeout(timer);
        timer = null;
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) repeat(true);
    });
    repeat();
})();

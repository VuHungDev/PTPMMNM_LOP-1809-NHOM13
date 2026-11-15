// Shared decorative SVGs and delegated, viewport-safe action tooltips (also for generated rows).
(function (global) {
    'use strict';
    const api = global.AlgoJudge = global.AlgoJudge || {};
    const allowedIcons = new Set(['view', 'edit', 'delete', 'lock', 'unlock', 'hide', 'show']);
    const svgNS = 'http://www.w3.org/2000/svg';

    api.decorateActionButton = function (button, icon, label) {
        if (!button || !allowedIcons.has(icon) || typeof label !== 'string') return button;
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('class', 'aj-action-icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '20');
        svg.setAttribute('height', '20');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const use = document.createElementNS(svgNS, 'use');
        use.setAttribute('href', '#aj-icon-' + icon);
        svg.appendChild(use);
        button.textContent = '';
        button.appendChild(svg);
        button.classList.remove('chatbot-button');
        button.classList.add('aj-icon-action', 'aj-icon-action--' + icon);
        button.setAttribute('aria-label', label);
        button.setAttribute('data-action-label', label);
        button.setAttribute('title', label);
        return button;
    };

    function startTooltips() {
        if (!document.body || document.getElementById('aj-action-tooltip')) return;
        const tooltip = document.createElement('div');
        tooltip.id = 'aj-action-tooltip';
        tooltip.className = 'aj-action-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.appendChild(tooltip);
        let active = null;
        let hovered = null;
        let focused = null;
        let dismissed = null;
        let hideTimer = null;
        let savedTitle = null;
        let dismissedTitle = null;

        function restoreDismissedTitle() {
            if (dismissed && dismissedTitle !== null && !dismissed.hasAttribute('title')) {
                dismissed.setAttribute('title', dismissedTitle);
            }
            dismissed = null;
            dismissedTitle = null;
        }

        function control(target) {
            return target && typeof target.closest === 'function'
                ? target.closest('[data-action-label]') : null;
        }
        function clearHide() {
            global.clearTimeout(hideTimer);
            hideTimer = null;
        }
        function hide() {
            clearHide();
            if (active) {
                const ids = (active.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== tooltip.id);
                if (ids.length) active.setAttribute('aria-describedby', ids.join(' '));
                else active.removeAttribute('aria-describedby');
                if (active === dismissed) dismissedTitle = savedTitle;
                else if (savedTitle !== null && !active.hasAttribute('title')) active.setAttribute('title', savedTitle);
            }
            active = null;
            savedTitle = null;
            tooltip.hidden = true;
        }
        function place() {
            if (!active || !active.isConnected || active.disabled || active.closest('[hidden]')) {
                hide();
                return;
            }
            const box = active.getBoundingClientRect();
            if (!box.width || !box.height || box.bottom < 0 || box.top > global.innerHeight || box.right < 0 || box.left > global.innerWidth) {
                hide();
                return;
            }
            const size = tooltip.getBoundingClientRect();
            const gutter = 8;
            const gap = 8;
            const left = Math.max(gutter, Math.min(box.left + (box.width - size.width) / 2, global.innerWidth - size.width - gutter));
            const above = box.top - size.height - gap;
            const below = box.bottom + gap;
            const top = above >= gutter ? above : Math.min(below, global.innerHeight - size.height - gutter);
            tooltip.style.left = left + 'px';
            tooltip.style.top = Math.max(gutter, top) + 'px';
        }
        function show(target) {
            clearHide();
            if (!target || target === dismissed || target.disabled || target.closest('[hidden]')) return;
            const label = target.getAttribute('data-action-label');
            if (!label) return;
            if (target !== active) {
                hide();
                active = target;
                savedTitle = target.getAttribute('title');
                target.removeAttribute('title'); // Avoid a second native tooltip while the custom one is visible.
                const ids = (target.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                if (!ids.includes(tooltip.id)) ids.push(tooltip.id);
                target.setAttribute('aria-describedby', ids.join(' '));
            }
            tooltip.textContent = label;
            tooltip.hidden = false;
            place();
        }
        function scheduleHide() {
            clearHide();
            hideTimer = global.setTimeout(() => {
                if (focused && focused.isConnected && focused !== dismissed) show(focused);
                else hide();
            }, 120);
        }
        document.addEventListener('pointerover', event => {
            const target = control(event.target);
            if (!target || target.contains(event.relatedTarget)) return;
            hovered = target;
            if (dismissed && dismissed !== target) restoreDismissedTitle();
            show(target);
        });
        document.addEventListener('pointerout', event => {
            const target = control(event.target);
            if (!target || target.contains(event.relatedTarget)) return;
            if (hovered === target) hovered = null;
            if (dismissed === target && focused !== target) restoreDismissedTitle();
            if (tooltip.contains(event.relatedTarget)) clearHide();
            else scheduleHide();
        });
        document.addEventListener('focusin', event => {
            const target = control(event.target);
            if (!target) return;
            focused = target;
            if (dismissed && dismissed !== target) restoreDismissedTitle();
            show(target);
        });
        document.addEventListener('focusout', event => {
            const target = control(event.target);
            if (target && focused === target) focused = null;
            if (dismissed === target && hovered !== target) restoreDismissedTitle();
            if (hovered) show(hovered);
            else scheduleHide();
        });
        tooltip.addEventListener('pointerenter', clearHide);
        tooltip.addEventListener('pointerleave', scheduleHide);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && active) {
                dismissed = active;
                hide();
            }
        });
        document.addEventListener('click', event => {
            if (active && (control(event.target) || !tooltip.contains(event.target))) {
                dismissed = active;
                hide();
            }
        });
        // Capturing scroll handles nested tables, modal bodies and the floating chatbot.
        document.addEventListener('scroll', () => { if (active) place(); }, true);
        global.addEventListener('resize', () => { if (active) place(); });
        function leavePage() {
            hide();
            restoreDismissedTitle();
            hovered = null;
            focused = null;
        }
        global.addEventListener('blur', leavePage);
        global.addEventListener('pagehide', leavePage);
        if (typeof global.MutationObserver === 'function') {
            new global.MutationObserver(() => {
                if (active && (!active.isConnected || active.disabled || active.closest('[hidden]'))) hide();
            }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden'] });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startTooltips, { once: true });
    else startTooltips();
})(window);

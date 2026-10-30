/**
 * Heading outline in an entry's sidebar (src/templates/_outline/panel.twig): loads its list after the page, collapses and
 * remembers that in the browser (every panel on the screen follows), and reloads its list after the editor saves a draft (an
 * autosave) or a column is saved in a slideout. Reloads wait until the editor pauses (10 seconds; a minute while the panel
 * is collapsed), a newer reload cancels one that's still running, and expanding a panel with changes reloads it at once.
 * Every panel is set up on its own, also one in a slideout next to the page's.
 */
(function($) {
    const KEY = 'headingsChecker.outline.collapsed';
    const DELAY = 10000;
    const DELAY_COLLAPSED = 60000;

    const setCollapsed = (panel, value) => {
        panel.classList.toggle('is-collapsed', value);
        panel.querySelector('.hc-outline__toggle')?.setAttribute('aria-expanded', value ? 'false' : 'true');
    };

    const init = (id) => {
        const panel = document.querySelector(`[data-hc-panel="${id}"]`);
        if (!panel || panel.dataset.hcReady) {
            return;
        }
        panel.dataset.hcReady = '1';
        const toggle = panel.querySelector('.hc-outline__toggle');
        const body = panel.querySelector('.hc-outline__body');
        const summary = panel.querySelector('[data-outline-summary]');
        let elementId = panel.dataset.elementId;
        let timer = null;
        let controller = null;
        let stale = false;

        const collapsed = () => panel.classList.contains('is-collapsed');

        // The element editor is created after this script runs: listen on the class, for the editor of this panel's form
        const editorOf = () => $(panel).closest('form').data('elementEditor');

        const load = () => {
            clearTimeout(timer);
            timer = null;
            if (!document.body.contains(panel)) {
                return;
            }
            // A newer list is asked for: the one still loading is outdated
            controller?.abort();
            const request = controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            stale = false;
            Craft.sendActionRequest('POST', 'headings-checker/outline/list', {
                data: {elementId: elementId ?? editorOf()?.settings.elementId, siteId: panel.dataset.siteId},
                signal: request?.signal,
            }).then(({data}) => {
                body.innerHTML = data.html;
                const issues = parseInt(body.querySelector('[data-outline-issues]')?.dataset.outlineIssues ?? '0', 10);
                summary.textContent = issues ? Craft.t('headings-checker', '{count, plural, =1{# issue} other{# issues}}', {count: issues}) : '';
                summary.classList.toggle('hc-outline__summary--issues', issues > 0);
            }).catch(() => {}).finally(() => {
                if (controller === request) {
                    controller = null;
                }
            });
        };

        // After a change: once the editor pauses
        const schedule = () => {
            stale = true;
            clearTimeout(timer);
            timer = setTimeout(load, collapsed() ? DELAY_COLLAPSED : DELAY);
        };
        panel.hcSchedule = schedule;
        panel.hcExpanded = () => {
            if (stale) {
                load();
            }
        };

        toggle.addEventListener('click', () => {
            const value = !collapsed();
            // Every panel on the screen follows, as the remembered state is the same for all
            document.querySelectorAll('.hc-outline').forEach((other) => {
                setCollapsed(other, value);
                if (!value) {
                    other.hcExpanded?.();
                }
            });
            try {
                localStorage.setItem(KEY, value ? '1' : '0');
            } catch (e) {}
        });

        Garnish.on(Craft.ElementEditor, 'afterSaveDraft', (ev) => {
            if (ev.target === editorOf()) {
                elementId = ev.response?.data?.elementId ?? ev.response?.elementId ?? ev.target.settings?.elementId ?? elementId;
                schedule();
            }
        });
        // A column saved in a slideout changes the page behind it
        if (Craft.CpScreenSlideout) {
            Garnish.on(Craft.CpScreenSlideout, 'submit', () => schedule());
        }

        // The first list, once the page has loaded
        if (document.readyState === 'complete') {
            setTimeout(load, 0);
        } else {
            window.addEventListener('load', () => load(), {once: true});
        }
    };

    window.HeadingsCheckerOutline = {init};
    $(() => {
        (window.HeadingsCheckerOutlines || []).forEach(init);
        window.HeadingsCheckerOutlines = [];
    });
})(jQuery);

/**
 * Heading outline in an entry's sidebar (src/templates/_outline/panel.twig): collapses and remembers that in the browser, for
 * every entry, and reloads its list after the editor saves a draft (an autosave), or after a column is saved in a slideout.
 * Also for the panel of an entry opened in a slideout, next to the one of the page behind it.
 */
(function($) {
    const KEY = 'headingsChecker.outline.collapsed';

    const collapsed = () => {
        try {
            return localStorage.getItem(KEY) === '1';
        } catch (e) {
            return false;
        }
    };

    const setCollapsed = (panel, value) => {
        panel.classList.toggle('is-collapsed', value);
        panel.querySelector('.hc-outline__toggle')?.setAttribute('aria-expanded', value ? 'false' : 'true');
    };

    // A panel added later (a slideout) gets the remembered state
    const init = (root) => {
        const panels = [...(root.querySelectorAll?.('.hc-outline:not([data-hc-init])') ?? [])];
        if (root.matches?.('.hc-outline:not([data-hc-init])')) {
            panels.push(root);
        }
        panels.forEach((panel) => {
            panel.dataset.hcInit = '1';
            setCollapsed(panel, collapsed());
        });
    };

    const refresh = (panel, elementId) => {
        const body = panel.querySelector('.hc-outline__body');
        const summary = panel.querySelector('[data-outline-summary]');
        Craft.sendActionRequest('POST', 'headings-checker/outline/list', {
            data: {elementId: elementId ?? panel.dataset.elementId, siteId: panel.dataset.siteId},
        }).then(({data}) => {
            body.innerHTML = data.html;
            const issues = parseInt(body.querySelector('[data-outline-issues]')?.dataset.outlineIssues ?? '0', 10);
            summary.textContent = issues ? Craft.t('headings-checker', '{count, plural, =1{# issue} other{# issues}}', {count: issues}) : '';
            summary.classList.toggle('hc-outline__summary--issues', issues > 0);
        }).catch(() => {});
    };

    $(() => {
        init(document);
        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => node.nodeType === 1 && init(node));
            }
        }).observe(document.body, {childList: true, subtree: true});

        document.addEventListener('click', (ev) => {
            const toggle = ev.target.closest('.hc-outline__toggle');
            if (!toggle) {
                return;
            }
            const value = !toggle.closest('.hc-outline').classList.contains('is-collapsed');
            // Every panel on the screen follows, as the remembered state is the same for all
            document.querySelectorAll('.hc-outline').forEach((panel) => setCollapsed(panel, value));
            try {
                localStorage.setItem(KEY, value ? '1' : '0');
            } catch (e) {}
        });

        // The panels in the form of the editor that saved a draft; the element id is the draft's
        Garnish.on(Craft.ElementEditor, 'afterSaveDraft', (ev) => {
            const form = ev.target.$container?.[0];
            form?.querySelectorAll('.hc-outline').forEach((panel) => {
                refresh(panel, ev.response?.data?.elementId ?? ev.response?.elementId ?? ev.target.settings?.elementId);
            });
        });
        // A column saved in a slideout changes the page behind it
        if (Craft.CpScreenSlideout) {
            Garnish.on(Craft.CpScreenSlideout, 'submit', () => setTimeout(() => {
                document.querySelectorAll('.hc-outline').forEach((panel) => {
                    const editor = $(panel).closest('form').data('elementEditor');
                    refresh(panel, editor?.settings.elementId);
                });
            }, 500));
        }
    });
})(jQuery);

/**
 * Heading outline in an entry's sidebar (src/templates/_outline/panel.twig): collapses and remembers
 * that in the browser, for every entry, and reloads its list after the editor saves a draft (an autosave), or after a column
 * is saved in a slideout.
 */
(function($) {
    const KEY = 'headingsChecker.outline.collapsed';

    $(() => {
        const panel = document.getElementById('hc-outline');
        if (!panel) {
            return;
        }
        const toggle = panel.querySelector('.hc-outline__toggle');
        const body = panel.querySelector('.hc-outline__body');
        const summary = panel.querySelector('[data-outline-summary]');

        toggle.addEventListener('click', () => {
            const collapsed = panel.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            try {
                localStorage.setItem(KEY, collapsed ? '1' : '0');
            } catch (e) {}
        });

        // The element editor is created after this script runs: listen on the class, for the editor of this panel's form
        const editorOf = () => $(panel).closest('form').data('elementEditor');

        const refresh = (elementId) => {
            const editor = editorOf();
            Craft.sendActionRequest('POST', 'headings-checker/outline/list', {
                data: {elementId: elementId ?? editor?.settings.elementId, siteId: panel.dataset.siteId},
            }).then(({data}) => {
                body.innerHTML = data.html;
                const issues = parseInt(body.querySelector('[data-outline-issues]')?.dataset.outlineIssues ?? '0', 10);
                summary.textContent = issues ? Craft.t('headings-checker', '{count, plural, =1{# issue} other{# issues}}', {count: issues}) : '';
                summary.classList.toggle('hc-outline__summary--issues', issues > 0);
            }).catch(() => {});
        };

        Garnish.on(Craft.ElementEditor, 'afterSaveDraft', (ev) => {
            if (ev.target === editorOf()) {
                refresh(ev.response?.data?.elementId ?? ev.response?.elementId);
            }
        });
        if (Craft.CpScreenSlideout) {
            Garnish.on(Craft.CpScreenSlideout, 'submit', () => setTimeout(() => refresh(), 500));
        }
    });
})(jQuery);

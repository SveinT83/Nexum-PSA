document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-email-template-form]');

    if (! form) {
        return;
    }

    const modeInput = form.querySelector('[data-layout-mode]');
    const layoutInput = form.querySelector('[data-layout-html]');
    const brandingLayout = form.querySelector('[data-branding-layout-source]');
    const customPanel = form.querySelector('[data-custom-layout-panel]');
    const modeBadge = form.querySelector('[data-layout-mode-badge]');
    const customizeButton = form.querySelector('[data-customize-layout]');
    const resetButton = form.querySelector('[data-reset-layout]');
    const previewFrame = form.querySelector('[data-template-preview]');
    const previewSubject = form.querySelector('[data-template-preview-subject]');
    const previewText = form.querySelector('[data-template-preview-text]');
    const previewStatus = form.querySelector('[data-template-preview-status]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let previewTimer = null;
    let previewController = null;

    function syncLayoutUi() {
        const custom = modeInput?.value === 'custom';

        customPanel?.classList.toggle('d-none', ! custom);
        customizeButton?.classList.toggle('d-none', custom);
        resetButton?.classList.toggle('d-none', ! custom);

        if (modeBadge) {
            modeBadge.className = custom ? 'badge text-bg-warning' : 'badge text-bg-success';
            modeBadge.textContent = custom ? 'Custom layout' : 'Branding managed';
        }
    }

    async function refreshPreview() {
        if (! form.dataset.previewUrl) {
            return;
        }

        previewController?.abort();
        previewController = new AbortController();

        if (previewStatus) {
            previewStatus.className = 'small text-muted';
            previewStatus.textContent = 'Updating preview…';
        }

        const payload = {
            subject: form.querySelector('[name="subject"]')?.value || '',
            body_html: form.querySelector('[name="body_html"]')?.value || '',
            body_text: form.querySelector('[name="body_text"]')?.value || '',
            layout_mode: modeInput?.value || 'branding',
            layout_html: layoutInput?.value || '',
            variables: form.querySelector('[name="variables"]')?.value || '',
        };

        try {
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
                signal: previewController.signal,
            });
            const result = await response.json().catch(() => ({}));

            if (! response.ok) {
                const message = Object.values(result.errors || {}).flat()[0]
                    || result.message
                    || 'Preview could not be rendered.';

                throw new Error(message);
            }

            if (previewFrame) {
                previewFrame.srcdoc = result.html || '';
            }

            if (previewSubject) {
                previewSubject.textContent = result.subject || '';
            }

            if (previewText) {
                previewText.textContent = result.text || '';
            }

            if (previewStatus) {
                previewStatus.className = 'small text-success';
                previewStatus.textContent = 'Preview matches the current unsaved fields.';
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            if (previewStatus) {
                previewStatus.className = 'small text-danger';
                previewStatus.textContent = error.message || 'Preview could not be rendered.';
            }
        }
    }

    function schedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 350);
    }

    customizeButton?.addEventListener('click', () => {
        if (layoutInput && brandingLayout) {
            layoutInput.value = brandingLayout.value;
        }

        if (modeInput) {
            modeInput.value = 'custom';
        }

        syncLayoutUi();
        schedulePreview();
        layoutInput?.focus();
    });

    resetButton?.addEventListener('click', () => {
        const hasCustomHtml = Boolean(layoutInput?.value.trim());

        if (hasCustomHtml && ! window.confirm('Discard this custom layout and resume current company branding?')) {
            return;
        }

        if (modeInput) {
            modeInput.value = 'branding';
        }

        if (layoutInput) {
            layoutInput.value = '';
        }

        syncLayoutUi();
        schedulePreview();
    });

    form.querySelectorAll('[name="subject"], [name="body_html"], [name="body_text"], [name="layout_html"], [name="variables"]')
        .forEach((input) => {
            input.addEventListener('input', schedulePreview);
            input.addEventListener('change', schedulePreview);
        });

    syncLayoutUi();
    schedulePreview();
});

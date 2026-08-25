/*
 * Shared outbound HTML editor adapter.
 *
 * The textarea remains the canonical value so ordinary form submission,
 * Marketing preview code, AI draft insertion, and future editor replacements
 * all use the same small contract.
 */
document.addEventListener('DOMContentLoaded', async () => {
    const textareas = Array.from(document.querySelectorAll('textarea[data-html-editor]'));

    if (textareas.length === 0) {
        return;
    }

    const [{ Jodit }] = await Promise.all([
        import('jodit'),
        import('jodit/es2021/jodit.min.css'),
        import('../css/email-html-editor.css'),
    ]);

    textareas.forEach((textarea) => {
        if (textarea.dataset.htmlEditorReady === 'true') {
            return;
        }

        const height = Number.parseInt(textarea.dataset.htmlEditorHeight || '340', 10);
        const editor = Jodit.make(textarea, {
            buttons: [
                'source', '|',
                'undo', 'redo', '|',
                'paragraph', 'bold', 'italic', 'underline', 'strikethrough', '|',
                'ul', 'ol', 'outdent', 'indent', '|',
                'link', 'table', 'hr', '|',
                'left', 'center', 'right', '|',
                'eraser',
            ],
            buttonsMD: [
                'source', '|',
                'undo', 'redo', '|',
                'paragraph', 'bold', 'italic', 'underline', '|',
                'ul', 'ol', '|',
                'link', 'table', 'hr',
            ],
            buttonsSM: [
                'source', '|',
                'undo', 'redo', '|',
                'bold', 'italic', 'underline', '|',
                'ul', 'ol', '|',
                'link', 'table',
            ],
            buttonsXS: ['source', '|', 'bold', 'italic', '|', 'ul', 'ol', '|', 'link'],
            defaultMode: Jodit.MODE_WYSIWYG,
            height: Number.isNaN(height) ? 340 : height,
            minHeight: 240,
            toolbarAdaptive: true,
            toolbarSticky: false,
            statusbar: true,
            showCharsCounter: true,
            showWordsCounter: true,
            showXPathInStatusbar: false,
            uploader: {
                insertImageAsBase64URI: false,
            },
        });
        let syncing = false;

        const syncToTextarea = () => {
            if (syncing) {
                return;
            }

            syncing = true;
            textarea.value = editor.value;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            syncing = false;
        };
        const syncFromTextarea = () => {
            if (! syncing && editor.value !== textarea.value) {
                editor.value = textarea.value;
            }
        };

        editor.events.on('change', syncToTextarea);
        textarea.addEventListener('input', syncFromTextarea);
        textarea.addEventListener('change', syncFromTextarea);
        textarea.form?.addEventListener('submit', () => {
            textarea.value = editor.value;
        });
        textarea.dataset.htmlEditorReady = 'true';
        textarea.htmlEditor = editor;
    });
});

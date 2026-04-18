<?php
/**
 * TinyMCE Editor Helper
 * Include this where a rich text editor is needed.
 * 
 * Usage in <head>:
 *   <?php include '../includes/tinymce_head.php'; ?>
 * 
 * Usage in JS:
 *   initTinyMCE('#my-textarea', { height: 300 });   // full toolbar
 *   initTinyMCE('#my-textarea', { mode: 'compact' }); // compact for short answers
 *   initTinyMCE('#my-textarea', { mode: 'exam_answer' }); // for student exam answers
 */
?>
<!-- TinyMCE CDN (free, no API key needed for self-hosted/basic) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<style>
    .tox-notifications-container { display: none !important; }
    .tox-statusbar__branding { display: none !important; }
    .tox .tox-promotion { display: none !important; }
    .tinymce-wrapper { border: 1px solid #dee2e6; border-radius: 0.375rem; overflow: hidden; }
    .tinymce-wrapper .tox-tinymce { border: none !important; }
    /* Match form-control sizing */
    .tox .tox-edit-area__iframe { background: #fff !important; }
</style>
<script>
/**
 * Initialize TinyMCE on a selector.
 * @param {string} selector - CSS selector for the textarea
 * @param {object} opts - { height, mode, onChange, readonly }
 *   mode: 'full' (default), 'compact', 'exam_answer', 'exam_question'
 */
function initTinyMCE(selector, opts = {}) {
    const mode = opts.mode || 'full';
    const height = opts.height || (mode === 'compact' ? 200 : (mode === 'exam_answer' ? 350 : 400));
    const readonly = opts.readonly || false;
    
    let toolbar, plugins, menubar;
    
    switch (mode) {
        case 'compact':
            // For short answers, minimal formatting
            plugins = 'autolink lists link charmap';
            toolbar = 'bold italic underline | subscript superscript | bullist numlist | link charmap | removeformat';
            menubar = false;
            break;
        case 'exam_answer':
            // For student answers - formatting, tables, equations
            plugins = 'autolink lists link image table charmap wordcount fullscreen';
            toolbar = 'undo redo | bold italic underline strikethrough | ' +
                      'subscript superscript | bullist numlist outdent indent | ' +
                      'table link image charmap | align | fullscreen | removeformat';
            menubar = false;
            break;
        case 'exam_question':
            // For exam officer typing questions - full
            plugins = 'autolink lists link image table charmap wordcount code fullscreen media';
            toolbar = 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | ' +
                      'forecolor backcolor | subscript superscript | ' +
                      'align | bullist numlist outdent indent | ' +
                      'table link image media charmap | fullscreen code | removeformat';
            menubar = 'edit view insert format tools table help';
            break;
        case 'dissertation':
            // For dissertation concept notes and chapter writing
            plugins = 'autolink lists link image table charmap wordcount code fullscreen media pagebreak';
            toolbar = 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | ' +
                      'forecolor backcolor | subscript superscript | ' +
                      'align lineheight | bullist numlist outdent indent | ' +
                      'table link image media charmap pagebreak | fullscreen code | removeformat';
            menubar = 'edit view insert format tools table help';
            break;
        default: // 'full'
            plugins = 'autolink lists link image table charmap wordcount code fullscreen media';
            toolbar = 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | ' +
                      'forecolor backcolor | subscript superscript | ' +
                      'align | bullist numlist outdent indent | ' +
                      'table link image media charmap | fullscreen code | removeformat';
            menubar = 'edit view insert format tools table help';
            break;
    }
    
    return tinymce.init({
        selector: selector,
        height: height,
        plugins: plugins,
        toolbar: toolbar,
        menubar: menubar,
        readonly: readonly,
        branding: false,
        promotion: false,
        statusbar: mode !== 'compact',
        content_style: `
            body { 
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                font-size: 14px; 
                line-height: 1.6; 
                color: #1e293b; 
                padding: 10px 15px;
            }
            p { margin: 0 0 0.5em 0; }
            table { border-collapse: collapse; width: 100%; }
            table td, table th { border: 1px solid #ccc; padding: 6px 10px; }
            img { max-width: 100%; height: auto; }
        `,
        setup: function(editor) {
            if (opts.onChange) {
                editor.on('change keyup', function() {
                    opts.onChange(editor.getContent());
                });
            }
            if (opts.onInit) {
                editor.on('init', function() {
                    opts.onInit(editor);
                });
            }
        },
        // Auto-resize
        autoresize_min_height: mode === 'compact' ? 120 : 200,
        autoresize_max_height: 800,
        // Image upload handling (base64 for now)
        automatic_uploads: false,
        images_upload_handler: function (blobInfo, progress) {
            return new Promise(function(resolve) {
                resolve('data:' + blobInfo.blob().type + ';base64,' + blobInfo.base64());
            });
        },
        // Table defaults
        table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
        table_default_attributes: { border: '1' },
        // Allow all content
        valid_elements: '*[*]',
        extended_valid_elements: 'span[*],div[*],img[*],table[*],tr[*],td[*],th[*]',
    });
}

/**
 * Get content from a TinyMCE instance by selector
 */
function getTinyContent(selector) {
    const editor = tinymce.get(document.querySelector(selector)?.id);
    return editor ? editor.getContent() : (document.querySelector(selector)?.value || '');
}

/**
 * Set content in a TinyMCE instance by selector  
 */
function setTinyContent(selector, html) {
    const el = document.querySelector(selector);
    if (!el) return;
    const editor = tinymce.get(el.id);
    if (editor) {
        editor.setContent(html || '');
    } else {
        el.value = html || '';
    }
}
</script>

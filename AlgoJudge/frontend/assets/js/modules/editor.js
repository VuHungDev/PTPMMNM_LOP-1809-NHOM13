// frontend/assets/js/modules/editor.js — ES module: khởi tạo Monaco dùng chung (workspace + sân chơi).
const CDN_VS = 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs';

function loadMonaco() {
    return new Promise((resolve, reject) => {
        if (window.monaco && window.monaco.editor) {
            resolve(window.monaco);
            return;
        }
        const boot = () => {
            try {
                window.require.config({ paths: { vs: CDN_VS } });
                window.require(['vs/editor/editor.main'],
                    () => resolve(window.monaco),
                    (err) => reject(new Error('monaco-require-fail: ' + (err && err.message ? err.message : 'unknown'))));
            } catch (e) {
                reject(e);
            }
        };
        const loader = document.getElementById('monaco-loader');
        // Thẻ loader có defer nên có thể đã tải xong trước khi module chạy —
        // trường hợp đó window.require đã sẵn sàng, boot ngay thay vì chờ load.
        if (window.require && (!loader || loader.readyState === 'complete' || loader.dataset.booted)) {
            boot();
            return;
        }
        if (loader) {
            loader.addEventListener('load', boot, { once: true });
            loader.addEventListener('error', () => reject(new Error('monaco-load-failed')), { once: true });
            // Dự phòng: sự kiện load đã bắn trước khi gắn listener.
            setTimeout(() => {
                if ((!window.monaco || !window.monaco.editor) && window.require) boot();
            }, 1500);
            setTimeout(() => {
                if (!window.monaco || !window.monaco.editor) reject(new Error('monaco-timeout'));
            }, 15000);
        } else if (window.require) {
            boot();
        } else {
            reject(new Error('monaco-loader-missing'));
        }
    });
}

export async function createCodeEditor(containerId, { value = '', language = 'python', theme = 'vs-dark' } = {}) {
    await loadMonaco();
    const editor = window.monaco.editor.create(document.getElementById(containerId), {
        value,
        language,
        theme,
        fontSize: 14,
        minimap: { enabled: false },
        automaticLayout: true,
    });
    return editor;
}

export function setEditorLanguage(editor, monacoLang) {
    if (!editor || !window.monaco) return;
    window.monaco.editor.setModelLanguage(editor.getModel(), monacoLang || 'python');
}

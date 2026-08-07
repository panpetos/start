/**
 * post-format.js — простое форматирование текста постов канала (жирный/курсив/списки),
 * без хранения HTML (только markdown-лайт разметка в тексте, безопасный рендер через esc()).
 * Используется в composer'е поста (psychologist-dashboard.html) и во всех местах, где пост
 * показывается: feed.html, psychologist-profile.html, chat.html (доработка по фидбэку админа
 * — "нет возможности редактировать текст выделять украшать").
 */
(function (global) {
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    /** Поддержка: **жирный**, *курсив* или _курсив_, строки "- " -> список, переносы строк -> <br>. Вход всегда экранируется. */
    function formatPostText(raw) {
        if (!raw) return '';
        const lines = String(raw).split('\n');
        let html = '';
        let inList = false;
        const inline = (s) => escapeHtml(s)
            .replace(/\*\*([^\n*]+)\*\*/g, '<b>$1</b>')
            .replace(/(^|[^*])\*([^\n*]+)\*(?!\*)/g, '$1<i>$2</i>')
            .replace(/_([^\n_]+)_/g, '<i>$1</i>');
        lines.forEach((line, i) => {
            const m = /^\s*[-•]\s+(.+)$/.exec(line);
            if (m) {
                if (!inList) { html += '<ul style="margin:0.3rem 0 0.3rem 1.1rem;padding:0;">'; inList = true; }
                html += '<li>' + inline(m[1]) + '</li>';
            } else {
                if (inList) { html += '</ul>'; inList = false; }
                html += (i > 0 ? '<br>' : '') + inline(line);
            }
        });
        if (inList) html += '</ul>';
        return html;
    }

    /** Обернуть выделенный в textarea текст маркерами (для кнопок Ж/К тулбара). */
    function wrapSelection(textarea, before, after) {
        if (!textarea) return;
        after = after == null ? before : after;
        const start = textarea.selectionStart, end = textarea.selectionEnd;
        const val = textarea.value;
        const selected = val.slice(start, end) || 'текст';
        textarea.value = val.slice(0, start) + before + selected + after + val.slice(end);
        textarea.focus();
        textarea.setSelectionRange(start + before.length, start + before.length + selected.length);
    }

    /** Добавить "- " в начало текущей строки (кнопка списка). */
    function prefixLine(textarea, prefix) {
        if (!textarea) return;
        const start = textarea.selectionStart;
        const val = textarea.value;
        const lineStart = val.lastIndexOf('\n', start - 1) + 1;
        textarea.value = val.slice(0, lineStart) + prefix + val.slice(lineStart);
        textarea.focus();
        textarea.setSelectionRange(start + prefix.length, start + prefix.length);
    }

    global.formatPostText = formatPostText;
    global.wrapPostSelection = wrapSelection;
    global.prefixPostLine = prefixLine;
})(window);

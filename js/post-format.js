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

    /**
     * Разметка: **жирный**, *курсив* / _курсив_, __подчёркнутый__, ~~зачёркнутый~~,
     * `код`, `## заголовок`, `> цитата`, "- " и "1. " списки, [текст](ссылка),
     * переносы строк -> <br>.
     * Вход ВСЕГДА экранируется до подстановки тегов — HTML автора не сохраняется и не исполняется.
     *
     * Содержимое маркеров обязано начинаться и заканчиваться непробельным символом: иначе
     * обычный текст вроде «5 * 3 * 2» превращался в курсив. Обходимся без lookbehind —
     * он до сих пор не поддерживается в старых Safari, а синтаксическая ошибка убила бы весь файл.
     */
    const NOSP = '([^\\s*_~`][^\\n]*?[^\\s*_~`]|[^\\s*_~`])';
    const rx = (open, close) => new RegExp(open.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + NOSP +
                                           close.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');

    function inlineFmt(s) {
        return escapeHtml(s)
            .replace(rx('**', '**'), '<b>$1</b>')
            .replace(rx('~~', '~~'), '<s>$1</s>')
            .replace(rx('`', '`'), '<code style="background:rgba(127,127,127,0.18);padding:0.05em 0.3em;border-radius:0.25em;font-size:0.92em;">$1</code>')
            // двойное подчёркивание разбираем ДО одинарного, иначе от него остались бы «хвосты»
            .replace(rx('__', '__'), '<u>$1</u>')
            .replace(rx('*', '*'), '<i>$1</i>')
            .replace(rx('_', '_'), '<i>$1</i>')
            // ссылка [текст](url) — пускаем только http(s) и внутренние /пути, иначе оставляем текстом
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g,
                '<a href="$2" target="_blank" rel="noopener nofollow">$1</a>');
    }

    function formatPostText(raw) {
        if (!raw) return '';
        const lines = String(raw).split('\n');
        let html = '';
        let list = null; // 'ul' | 'ol' | null
        const closeList = () => { if (list) { html += '</' + list + '>'; list = null; } };
        const openList = (kind) => {
            if (list !== kind) { closeList(); html += '<' + kind + ' style="margin:0.4rem 0 0.4rem 1.2rem;padding:0;">'; list = kind; }
        };
        let prevWasBlock = true;
        lines.forEach(line => {
            const h = /^\s*(#{2,3})\s+(.+)$/.exec(line);
            const q = /^\s*>\s?(.*)$/.exec(line);
            const ul = /^\s*[-•]\s+(.+)$/.exec(line);
            const ol = /^\s*\d+[.)]\s+(.+)$/.exec(line);
            if (h) {
                closeList();
                const tag = h[1].length === 2 ? 'h2' : 'h3';
                html += '<' + tag + '>' + inlineFmt(h[2]) + '</' + tag + '>';
                prevWasBlock = true;
            } else if (q) {
                closeList();
                html += '<blockquote style="margin:0.5rem 0;padding:0.4rem 0.9rem;border-left:3px solid #7C3AED;opacity:0.9;">' + inlineFmt(q[1]) + '</blockquote>';
                prevWasBlock = true;
            } else if (ul) {
                openList('ul');
                html += '<li>' + inlineFmt(ul[1]) + '</li>';
                prevWasBlock = true;
            } else if (ol) {
                openList('ol');
                html += '<li>' + inlineFmt(ol[1]) + '</li>';
                prevWasBlock = true;
            } else {
                closeList();
                if (line.trim() === '') { html += '<br>'; prevWasBlock = true; return; }
                html += (prevWasBlock ? '' : '<br>') + inlineFmt(line);
                prevWasBlock = false;
            }
        });
        closeList();
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

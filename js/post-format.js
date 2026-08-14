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
        const out = escapeHtml(s)
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
        return autoLink(out);
    }

    /**
     * Голые ссылки и домены делаем кликабельными: люди редко пишут разметкой,
     * а адрес в переписке должен открываться нажатием.
     *
     * Работает по УЖЕ собранному html, поэтому пропускаем то, что внутри тегов
     * (<a href="…">) — иначе адрес внутри атрибута разорвало бы вложенной ссылкой.
     */
    function autoLink(html) {
        if (!html || !/[.:@]/.test(html)) return html;
        const parts = html.split(/(<[^>]+>)/);      // нечётные элементы — сами теги
        let insideLink = 0;
        for (let i = 0; i < parts.length; i++) {
            const p = parts[i];
            if (i % 2 === 1) {                       // это тег
                if (/^<a\b/i.test(p)) insideLink++;
                else if (/^<\/a>/i.test(p)) insideLink = Math.max(0, insideLink - 1);
                continue;
            }
            if (insideLink) continue;                // внутри ссылки второй раз не оборачиваем
            parts[i] = p
                // полный адрес со схемой
                .replace(/(^|[\s(])((?:https?:\/\/)[^\s<>()]+[^\s<>().,;:!?])/g,
                    '$1<a href="$2" target="_blank" rel="noopener nofollow">$2</a>')
                // домен без схемы: site.ru, www.site.com/path
                .replace(/(^|[\s(])((?:www\.)?[a-zA-Zа-яА-Я0-9][-a-zA-Zа-яА-Я0-9]*(?:\.[-a-zA-Zа-яА-Я0-9]+)*\.(?:ru|рф|com|net|org|io|dev|me|pro|su|info|biz|online|site|store|app|ai)(?:\/[^\s<>()]*)?)(?=$|[\s).,;:!?])/g,
                    function (m, pre, dom) {
                        if (/@/.test(dom)) return m;             // это часть почты — не трогаем
                        return pre + '<a href="https://' + dom + '" target="_blank" rel="noopener nofollow">' + dom + '</a>';
                    })
                // почта
                .replace(/(^|[\s(])([^\s<>()@]+@[^\s<>()@]+\.[a-zA-Zа-яА-Я]{2,})(?=$|[\s).,;:!?])/g,
                    '$1<a href="mailto:$2">$2</a>');
        }
        return parts.join('');
    }

    // ── Блок-схемы ───────────────────────────────────────────────────────────────
    // Пишутся простым текстом в блоке ```схема … ``` :
    //     Заявка -> Проверка
    //     Проверка -> Оплата
    //     Проверка -> Отказ
    // Рисуем сами: сторонние библиотеки на страницы не подключить (чужие скрипты
    // и картинки с других доменов не загружаются), а схема в переписке нужна.

    const DIA_RE = /^\s*```\s*(?:схема|блок-схема|diagram|flow)\s*$/i;

    /** Разобрать строки в узлы и связи. Стрелка: -> или → ; подпись связи: -> |текст| */
    function parseDiagram(src) {
        const nodes = [];              // сохраняем порядок появления
        const index = new Map();
        const edges = [];
        const add = (name) => {
            name = name.trim();
            if (!name) return -1;
            if (!index.has(name)) { index.set(name, nodes.length); nodes.push(name); }
            return index.get(name);
        };
        String(src || '').split('\n').forEach(line => {
            const t = line.trim();
            if (!t) return;
            const parts = t.split(/\s*(?:->|→|-->)\s*/);
            if (parts.length === 1) { add(t.replace(/^[\[(]|[\])]$/g, '')); return; }
            for (let i = 0; i < parts.length - 1; i++) {
                let from = parts[i], to = parts[i + 1];
                // подпись связи в вертикальных чёрточках: Проверка -> |нет| Отказ
                let label = '';
                const lm = /^\|([^|]*)\|\s*(.*)$/.exec(to);
                if (lm) { label = lm[1].trim(); to = lm[2]; }
                const a = add(from.replace(/^[\[(]|[\])]$/g, ''));
                const b = add(to.replace(/^[\[(]|[\])]$/g, ''));
                if (a >= 0 && b >= 0 && a !== b) edges.push({ from: a, to: b, label });
            }
        });
        return { nodes, edges };
    }

    /** Разложить узлы по ярусам: у кого нет входящих — первый ярус, дальше вглубь. */
    function diagramLevels(nodes, edges) {
        const level = nodes.map(() => 0);
        const incoming = nodes.map(() => 0);
        edges.forEach(e => { incoming[e.to]++; });
        // несколько проходов: длиннее цепочки, чем узлов, не бывает
        // Проходов не больше числа узлов: при циклической схеме («A → B → C → A»)
        // уровни росли бы бесконечно, а так просто останавливаемся.
        const maxLevel = Math.max(0, nodes.length - 1);
        for (let pass = 0; pass < nodes.length; pass++) {
            let moved = false;
            edges.forEach(e => {
                if (level[e.to] < level[e.from] + 1 && level[e.from] + 1 <= maxLevel) {
                    level[e.to] = level[e.from] + 1;
                    moved = true;
                }
            });
            if (!moved) break;
        }
        return level;
    }

    /** Схема в SVG. Возвращает '' если рисовать нечего. */
    function diagramSvg(src) {
        const { nodes, edges } = parseDiagram(src);
        if (!nodes.length) return '';
        const level = diagramLevels(nodes, edges);
        // Ярусы могут идти с пропусками (а при циклической схеме — разъехаться далеко),
        // поэтому сжимаем их в подряд идущие номера: иначе в раскладке появлялись
        // пустые строки, ширина считалась по несуществующему ярусу и схема схлопывалась.
        const used = [...new Set(level)].sort((a, b) => a - b);
        const compact = new Map(used.map((lv, i) => [lv, i]));
        const rows = used.map(() => []);
        level.forEach((lv, i) => { rows[compact.get(lv)].push(i); });

        const CH = 7.2, PADX = 14, BH = 40, GAPX = 26, GAPY = 62, M = 12;
        const w = nodes.map(n => Math.max(70, Math.round(n.length * CH) + PADX * 2));
        const rowW = rows.map(r => r.reduce((s, i) => s + w[i], 0) + GAPX * (r.length - 1));
        const width = Math.max(...rowW) + M * 2;
        const height = rows.length * BH + (rows.length - 1) * (GAPY - BH) + M * 2;

        const box = [];                 // координаты каждого узла
        rows.forEach((r, lv) => {
            let x = M + (width - M * 2 - rowW[lv]) / 2;
            const y = M + lv * GAPY;
            r.forEach(i => { box[i] = { x, y, w: w[i], h: BH }; x += w[i] + GAPX; });
        });

        const esc = (t) => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        let out = '';
        edges.forEach(e => {
            const a = box[e.from], b = box[e.to];
            if (!a || !b) return;
            const x1 = a.x + a.w / 2, y1 = a.y + a.h, x2 = b.x + b.w / 2, y2 = b.y;
            // связь вверх или вбок рисуем той же кривой — стрелка всё равно показывает направление
            const my = (y1 + y2) / 2;
            const d = `M ${x1} ${y1} C ${x1} ${my}, ${x2} ${my}, ${x2} ${y2}`;
            out += `<path d="${d}" fill="none" stroke="currentColor" stroke-width="1.6" opacity="0.55" marker-end="url(#psyArrow)"/>`;
            if (e.label) {
                out += `<text x="${(x1 + x2) / 2}" y="${my - 3}" text-anchor="middle" font-size="11" fill="currentColor" opacity="0.75">${esc(e.label)}</text>`;
            }
        });
        nodes.forEach((n, i) => {
            const b = box[i];
            // Цвета берём от текста пузыря (currentColor): фиолетовая рамка терялась
            // на фиолетовом фоне своих сообщений, а тут схема читается везде.
            out += `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="9" ry="9"
                     fill="currentColor" fill-opacity="0.10" stroke="currentColor" stroke-opacity="0.55" stroke-width="1.5"/>`
                 + `<text x="${b.x + b.w / 2}" y="${b.y + b.h / 2 + 4}" text-anchor="middle"
                     font-size="13" font-family="inherit" fill="currentColor">${esc(n)}</text>`;
        });
        return `<div class="psy-diagram" style="overflow-x:auto;margin:0.5rem 0;">
                  <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" style="max-width:100%;height:auto;display:block;">
                    <defs><marker id="psyArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                      <path d="M 0 0 L 10 5 L 0 10 z" fill="currentColor" opacity="0.55"/></marker></defs>
                    ${out}
                  </svg>
                </div>`;
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
        let diaBuf = null;           // строки схемы, пока не встретим закрывающие ```
        lines.forEach(line => {
            if (diaBuf !== null) {
                if (/^\s*```\s*$/.test(line)) {
                    closeList();
                    html += diagramSvg(diaBuf.join('\n'));
                    diaBuf = null;
                    prevWasBlock = true;
                } else { diaBuf.push(line); }
                return;
            }
            if (DIA_RE.test(line)) { diaBuf = []; return; }
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
        // Блок не закрыли — всё равно покажем схему, а не проглотим хвост сообщения.
        if (diaBuf !== null && diaBuf.length) html += diagramSvg(diaBuf.join('\n'));
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
    global.psyDiagramSvg = diagramSvg;
    global.wrapPostSelection = wrapSelection;
    global.prefixPostLine = prefixLine;
})(window);

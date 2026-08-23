/**
 * lightbox.js — просмотр картинок во весь экран вместо перехода на прямую ссылку
 * (фидбэк админа: «фотки везде на сайте чтоб открывали и в чатах превью, а не на ссылку прямую»).
 *
 * Работает без разметки на страницах: перехватывает клики по ссылкам, ведущим на картинку,
 * и по достаточно крупным <img>. Мелкие аватарки и иконки не трогаем — иначе открывался бы
 * лайтбокс на каждый чих.
 *
 * Явное управление: атрибут data-zoom у <img> заставит открывать его всегда,
 * data-nozoom — никогда. Программно: psyLightbox(src, alt, gallery, index).
 *
 * Группы (задача #47): элементы с одинаковым data-group образуют альбом — открыв любой
 * из них, можно пролистать остальные стрелками/клавишами/свайпом, а не только посмотреть
 * один снимок. Список элементов группы собирается прямо из DOM в момент открытия.
 *
 * ВАЖНО: reg.ru отдаёт этот файл с cache-control: max-age=3888000 (45 дней), а подключается
 * он без версии в URL — при следующей правке этого файла обязательно поднимите ?v=N во всех
 * местах, где он подключается (grep 'lightbox.js' по репозиторию), иначе браузеры и кэш
 * хостинга будут месяцами отдавать старую версию, и правка не дойдёт до пользователей.
 */
(function (global) {
    // Подключиться дважды — обычное дело: страница ставит тег сама, а layout.js
    // добавляет свой, если своего не нашёл. Раньше это давало ДВА независимых
    // перехватчика кликов и два окна просмотра поверх друг друга: по фото в ленте
    // открывалось сразу два, и закрывать приходилось дважды. Второй запуск теперь
    // просто выходит — кто успел первым, тот и работает.
    if (global.psyLightbox) return;

    const IMG_RE = /\.(jpe?g|png|gif|webp|avif|bmp|svg)(\?|#|$)/i;
    const MIN_SIDE = 90;   // меньше — это иконка или аватарка, не фото

    function isImageUrl(u) { return IMG_RE.test(String(u || '')); }

    let box = null;
    let gallery = [];      // [{src, alt}] — текущая группа, если открыли с листанием
    let galleryIdx = 0;

    function build() {
        if (box) return box;
        box = document.createElement('div');
        box.id = 'psyLightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', 'Просмотр изображения');
        box.innerHTML =
            '<button class="plb-close" type="button" aria-label="Закрыть">✕</button>' +
            '<button class="plb-more" type="button" aria-label="Действия с фото" aria-haspopup="true">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">' +
            '<circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></button>' +
            '<div class="plb-menu" role="menu"></div>' +
            '<button class="plb-nav plb-prev" type="button" aria-label="Предыдущее изображение">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg></button>' +
            '<button class="plb-nav plb-next" type="button" aria-label="Следующее изображение">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></button>' +
            '<div class="plb-count"></div>' +
            '<div class="plb-stage"><img class="plb-img" alt=""></div>' +
            '<div class="plb-hint">Двойное касание или щипок — увеличить</div>';
        const css = document.createElement('style');
        css.textContent =
            '#psyLightbox{position:fixed;inset:0;z-index:5000;display:none;align-items:center;' +
            'justify-content:center;background:rgba(12,12,18,0.92);padding:2.5rem 1rem 3.5rem;}' +
            '#psyLightbox.open{display:flex;}' +
            '#psyLightbox .plb-img{max-width:100%;max-height:100%;object-fit:contain;border-radius:0.5rem;' +
            'box-shadow:0 12px 50px rgba(0,0,0,0.5);user-select:none;}' +
            '#psyLightbox .plb-close{position:absolute;top:0.75rem;right:0.9rem;width:40px;height:40px;' +
            'border:none;border-radius:50%;background:rgba(255,255,255,0.14);color:#fff;font-size:1.1rem;' +
            'cursor:pointer;line-height:1;z-index:1;}' +
            '#psyLightbox .plb-close:hover{background:rgba(255,255,255,0.26);}' +
            '#psyLightbox .plb-nav{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;' +
            'border:none;border-radius:50%;background:rgba(255,255,255,0.14);color:#fff;' +
            'cursor:pointer;display:none;align-items:center;justify-content:center;z-index:1;}' +
            '#psyLightbox .plb-nav:hover{background:rgba(255,255,255,0.26);}' +
            '#psyLightbox.has-gallery .plb-nav{display:flex;}' +
            '#psyLightbox .plb-prev{left:0.6rem;}' +
            '#psyLightbox .plb-next{right:0.6rem;}' +
            '#psyLightbox .plb-count{position:absolute;top:0.9rem;left:50%;transform:translateX(-50%);' +
            'color:#fff;font-size:0.8rem;font-weight:600;background:rgba(255,255,255,0.14);' +
            'padding:0.25rem 0.7rem;border-radius:1rem;display:none;}' +
            '#psyLightbox.has-gallery .plb-count{display:block;}' +
            // Сцена, внутри которой картинку двигают и увеличивают
            '#psyLightbox .plb-stage{position:absolute;inset:0;display:flex;align-items:center;' +
            'justify-content:center;overflow:hidden;touch-action:none;}' +
            // touch-action у КАРТИНКИ, а не только у сцены: свойство не наследуется, а
            // палец попадает именно в картинку. Без этого браузер считал движение
            // прокруткой страницы, отменял жест (pointercancel) — и ни увеличение
            // щипком, ни листание свайпом не срабатывали.
            '#psyLightbox .plb-img{will-change:transform;transition:transform 0.18s ease;touch-action:none;}' +
            '#psyLightbox.zooming .plb-img{transition:none;}' +
            // Меню действий — как в галерее телефона
            '#psyLightbox .plb-more{position:absolute;top:0.75rem;right:3.6rem;width:40px;height:40px;' +
            'border:none;border-radius:50%;background:rgba(255,255,255,0.14);color:#fff;' +
            'cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;}' +
            '#psyLightbox .plb-more:hover{background:rgba(255,255,255,0.26);}' +
            '#psyLightbox .plb-menu{position:absolute;top:3.6rem;right:0.9rem;z-index:3;display:none;' +
            'min-width:230px;max-width:calc(100vw - 1.8rem);background:#2B2F36;border-radius:0.9rem;' +
            'box-shadow:0 16px 44px rgba(0,0,0,0.55);overflow:hidden;padding:0.3rem;}' +
            '#psyLightbox .plb-menu.open{display:block;}' +
            '#psyLightbox .plb-menu button{display:flex;align-items:center;gap:0.75rem;width:100%;' +
            'padding:0.7rem 0.8rem;border:none;background:none;color:#fff;cursor:pointer;' +
            'font-family:inherit;font-size:0.95rem;text-align:left;border-radius:0.6rem;}' +
            '#psyLightbox .plb-menu button:hover{background:rgba(255,255,255,0.10);}' +
            '#psyLightbox .plb-menu svg{flex-shrink:0;opacity:0.85;}' +
            // Подсказка про увеличение — только пока не увеличивали
            '#psyLightbox .plb-hint{position:absolute;bottom:1.1rem;left:50%;transform:translateX(-50%);' +
            'color:rgba(255,255,255,0.6);font-size:0.78rem;background:rgba(0,0,0,0.35);' +
            'padding:0.35rem 0.8rem;border-radius:2rem;pointer-events:none;transition:opacity 0.25s;}' +
            '#psyLightbox.zoomed .plb-hint,#psyLightbox.zoomed .plb-nav{opacity:0;pointer-events:none;}' +
            '@media (max-width:640px){#psyLightbox .plb-nav{width:38px;height:38px;}' +
            '#psyLightbox .plb-count{top:0.9rem;}}' +
            '@media (hover:none){#psyLightbox .plb-hint{display:block;}}';
        document.head.appendChild(css);
        document.body.appendChild(box);
        box.addEventListener('click', function (e) {
            // Клик мимо всего закрывает просмотр; по кнопкам и меню — нет.
            // Само фото обрабатывается отдельно (см. bindZoom): там ещё зум и перетаскивание.
            if (e.target.closest('.plb-stage, .plb-menu, .plb-more, .plb-nav, .plb-close')) return;
            close();
        });
        box.querySelector('.plb-close').addEventListener('click', close);
        box.querySelector('.plb-prev').addEventListener('click', function () { step(-1); });
        box.querySelector('.plb-next').addEventListener('click', function () { step(1); });
        box.querySelector('.plb-more').addEventListener('click', function (e) {
            e.stopPropagation();
            box.querySelector('.plb-menu').classList.toggle('open');
        });

        bindZoom(box);
        return box;
    }

    // ── Увеличение и перетаскивание ──────────────────────────────────────────
    //
    //  На телефоне фото открывалось «как есть» и рассмотреть детали было нельзя:
    //  щипок страница не пропускала, а браузерное масштабирование в приложении
    //  отключено (в chat.html стоит maximum-scale=1). Поэтому масштаб делаем сами:
    //  щипок двумя пальцами, двойное касание и колесо мыши. Увеличенное фото можно
    //  таскать пальцем; листание соседних снимков в этот момент выключается, иначе
    //  каждое движение перескакивало бы на другую картинку.
    let zoom = 1, panX = 0, panY = 0;

    function applyZoom() {
        const img = box && box.querySelector('.plb-img');
        if (!img) return;
        img.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
        box.classList.toggle('zoomed', zoom > 1.02);
    }

    function resetZoom() { zoom = 1; panX = 0; panY = 0; applyZoom(); }

    /** Не даём утащить картинку за пределы экрана. */
    function clampPan() {
        const img = box.querySelector('.plb-img');
        if (!img) return;
        const r = img.getBoundingClientRect();
        const maxX = Math.max(0, (r.width - window.innerWidth) / 2 + 20);
        const maxY = Math.max(0, (r.height - window.innerHeight) / 2 + 20);
        panX = Math.max(-maxX, Math.min(maxX, panX));
        panY = Math.max(-maxY, Math.min(maxY, panY));
    }

    function setZoom(next, cx, cy) {
        const prev = zoom;
        zoom = Math.max(1, Math.min(5, next));
        if (zoom === 1) { panX = 0; panY = 0; }
        else if (cx != null) {
            // Увеличиваем «от пальца»: точка под пальцем остаётся на месте
            const k = zoom / prev - 1;
            panX -= (cx - window.innerWidth / 2 - panX) * k;
            panY -= (cy - window.innerHeight / 2 - panY) * k;
            clampPan();
        }
        applyZoom();
    }

    function bindZoom(el) {
        const stage = el.querySelector('.plb-stage');
        let pts = new Map();          // активные касания
        let startDist = 0, startZoom = 1, startPan = null, startMid = null;
        let downAt = 0, downX = 0, downY = 0, lastTap = 0, moved = false;
        let downOnImg = false, tapTimer = null;

        const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
        const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });

        stage.addEventListener('pointerdown', function (e) {
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            // Захватываем указатель ТОЛЬКО когда он и правда нужен — при щипке и при
            // перетаскивании увеличенного фото. От захвата на обычном касании браузер
            // переадресует последующий click на сцену, и одиночное нажатие по фото
            // выглядело как нажатие мимо — просмотр закрывался, не дав увеличить.
            if (pts.size === 2 || zoom > 1.02) { try { stage.setPointerCapture(e.pointerId); } catch (err) {} }
            if (pts.size === 1) {
                downAt = Date.now(); downX = e.clientX; downY = e.clientY; moved = false;
                downOnImg = !!(e.target && e.target.closest && e.target.closest('.plb-img'));
            }
            if (pts.size === 2) {
                const [a, b] = [...pts.values()];
                startDist = dist(a, b); startZoom = zoom;
                startPan = { x: panX, y: panY }; startMid = mid(a, b);
                el.classList.add('zooming');
            }
        });

        const onMove = function (e) {
            if (!pts.has(e.pointerId)) return;
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (Math.abs(e.clientX - downX) > 8 || Math.abs(e.clientY - downY) > 8) moved = true;

            if (pts.size === 2 && startDist) {          // щипок
                const [a, b] = [...pts.values()];
                const m = mid(a, b);
                zoom = Math.max(1, Math.min(5, startZoom * (dist(a, b) / startDist)));
                panX = startPan.x + (m.x - startMid.x);
                panY = startPan.y + (m.y - startMid.y);
                clampPan(); applyZoom();
                e.preventDefault();
                return;
            }
            if (pts.size === 1 && zoom > 1.02) {        // таскаем увеличенное
                panX += e.movementX; panY += e.movementY;
                clampPan(); applyZoom();
                e.preventDefault();
            }
        };

        const up = function (e) {
            const wasPinch = pts.size === 2;
            pts.delete(e.pointerId);
            try { stage.releasePointerCapture(e.pointerId); } catch (err) {}
            if (pts.size < 2) { startDist = 0; el.classList.remove('zooming'); }
            if (wasPinch || pts.size) return;

            const dx = e.clientX - downX, dy = e.clientY - downY;
            const quick = Date.now() - downAt < 350;

            // Двойное касание — увеличить/вернуть
            if (quick && !moved) {
                const now = Date.now();
                if (tapTimer) { clearTimeout(tapTimer); tapTimer = null; }
                if (now - lastTap < 320) {
                    lastTap = 0;
                    setZoom(zoom > 1.02 ? 1 : 2.5, e.clientX, e.clientY);
                    return;
                }
                lastTap = now;
                const onImg = downOnImg, x = e.clientX, y = e.clientY;
                // Ждём, не будет ли второго касания. Не дождались — обычное нажатие:
                // мимо фото закрывает просмотр, по фото ничего не делает.
                tapTimer = setTimeout(function () {
                    tapTimer = null;
                    if (!onImg && zoom <= 1.02) close();
                }, 330);
                return;
            }
            // Свайп в сторону — соседнее фото (только когда не увеличено)
            if (zoom <= 1.02 && Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) step(dx < 0 ? 1 : -1);
        };
        // Слушаем на окне, а не на сцене: палец во время жеста легко уходит за край
        // картинки, и «отпускание» до сцены уже не доходило — увеличение и листание
        // просто не срабатывали.
        window.addEventListener('pointermove', onMove, { passive: false });
        window.addEventListener('pointerup', up);
        // Браузер всё же отменил жест (так бывает при системных свайпах от края) —
        // доводим его до конца сами, чтобы листание не пропадало.
        window.addEventListener('pointercancel', up);

        // Запасной путь для листания — на «пальцевых» событиях. Указатели браузер
        // при движении иногда отменяет (принимает жест за прокрутку), и свайп терялся;
        // здесь же событие приходит всегда. Работает, только пока фото не увеличено —
        // увеличенное таскают, а не листают.
        let tX = null, tY = null;
        stage.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) { tX = null; return; }
            tX = e.touches[0].clientX; tY = e.touches[0].clientY;
        }, { passive: true });
        stage.addEventListener('touchend', function (e) {
            if (tX == null || zoom > 1.02) { tX = null; return; }
            const t = e.changedTouches[0];
            const dx = t.clientX - tX, dy = t.clientY - tY;
            tX = null;
            if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy)) return;
            step(dx < 0 ? 1 : -1);
        }, { passive: true });

        // Колесо мыши на компьютере
        stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            setZoom(zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX, e.clientY);
        }, { passive: false });

    }

    /** Имя файла для сохранения: из адреса, а если там мусор — из подписи. */
    function fileNameFor(src, alt) {
        let name = '';
        try { name = decodeURIComponent(String(src).split(/[?#]/)[0].split('/').pop() || ''); } catch (e) {}
        if (!IMG_RE.test(name)) name = (alt && IMG_RE.test(alt)) ? alt : (name || 'image.jpg');
        return name;
    }

    const ICONS = {
        share: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>',
        save:  '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/></svg>',
        goto:  '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12H8"/><path d="M14 6l7 6-7 6"/><path d="M3 4v16"/></svg>',
        fwd:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="14" height="12" rx="2"/><path d="M8 20h9a4 4 0 004-4v-3"/><path d="M18 10l3 3-3 3"/></svg>',
        copy:  '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>',
    };

    /** Скачать файл, а не открыть его в соседней вкладке (важно для чужих доменов). */
    async function downloadItem(item) {
        const name = fileNameFor(item.src, item.alt);
        try {
            const r = await fetch(item.src, { credentials: 'include' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const url = URL.createObjectURL(await r.blob());
            const a = document.createElement('a');
            a.href = url; a.download = name;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        } catch (e) {
            // Не получилось (чужой домен без разрешения) — открываем прямой ссылкой
            const a = document.createElement('a');
            a.href = item.src; a.download = name; a.target = '_blank'; a.rel = 'noopener';
            document.body.appendChild(a); a.click(); a.remove();
        }
    }

    /** «Поделиться» системным окном телефона; где его нет — копируем ссылку. */
    async function shareItem(item) {
        const url = new URL(item.src, location.href).href;
        try {
            if (navigator.canShare && navigator.share) {
                // Пробуем поделиться самим файлом — так фото уходит в мессенджер картинкой
                try {
                    const r = await fetch(item.src, { credentials: 'include' });
                    const blob = await r.blob();
                    const file = new File([blob], fileNameFor(item.src, item.alt), { type: blob.type || 'image/jpeg' });
                    if (navigator.canShare({ files: [file] })) { await navigator.share({ files: [file] }); return; }
                } catch (e) {}
            }
            if (navigator.share) { await navigator.share({ url: url }); return; }
            await navigator.clipboard.writeText(url);
            toastLB('Ссылка на фото скопирована');
        } catch (e) { /* человек закрыл окно «поделиться» — это не ошибка */ }
    }

    function toastLB(text) {
        if (typeof window.toast === 'function') { window.toast(text); return; }
        alert(text);
    }

    /**
     * Пункты меню. Базовые есть везде; чат добавляет свои («перейти к сообщению»,
     * «переслать») через psyLightboxActions — так lightbox.js остаётся общим для
     * всего сайта и ничего не знает про переписку.
     */
    function menuItems(item) {
        const list = [
            { icon: ICONS.share, label: 'Поделиться', run: function () { shareItem(item); } },
            { icon: ICONS.save, label: 'Скачать', run: function () { downloadItem(item); } },
        ];
        try {
            if (typeof global.psyLightboxActions === 'function') {
                (global.psyLightboxActions(item) || []).forEach(function (x) {
                    list.push({ icon: ICONS[x.icon] || ICONS.goto, label: x.label, run: x.run });
                });
            }
        } catch (e) {}
        list.push({ icon: ICONS.copy, label: 'Копировать ссылку', run: function () {
            try { navigator.clipboard.writeText(new URL(item.src, location.href).href); toastLB('Ссылка скопирована'); }
            catch (e) {}
        } });
        return list;
    }

    function paintMenu(item) {
        const menu = box.querySelector('.plb-menu');
        menu.innerHTML = '';
        menuItems(item).forEach(function (it) {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.innerHTML = it.icon + '<span>' + it.label + '</span>';
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                menu.classList.remove('open');
                try { it.run(); } catch (err) {}
            });
            menu.appendChild(b);
        });
    }

    function paint() {
        const item = gallery[galleryIdx];
        if (!item) return;
        const el = build();
        el.querySelector('.plb-img').src = item.src;
        el.querySelector('.plb-img').alt = item.alt || '';
        resetZoom();                       // новое фото открываем в обычном масштабе
        el.querySelector('.plb-menu').classList.remove('open');
        paintMenu(item);
        el.classList.toggle('has-gallery', gallery.length > 1);
        el.querySelector('.plb-count').textContent = gallery.length > 1
            ? (galleryIdx + 1) + ' из ' + gallery.length : '';
    }

    function step(dir) {
        if (gallery.length < 2) return;
        galleryIdx = (galleryIdx + dir + gallery.length) % gallery.length;
        paint();
    }

    /**
     * src/alt — то, что показать сразу. gal (необязательно) — вся группа [{src, alt}],
     * idx — позиция src внутри неё. Без gal группа состоит из одного этого изображения.
     */
    function open(src, alt, gal, idx, node) {
        if (!src) return;
        gallery = (gal && gal.length) ? gal : [{ src: src, alt: alt, node: node || null }];
        galleryIdx = Math.max(0, Math.min(gallery.length - 1, idx || 0));
        build();
        paint();
        box.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!box) return;
        box.classList.remove('open', 'zoomed');
        box.querySelector('.plb-menu').classList.remove('open');
        box.querySelector('.plb-img').src = '';
        resetZoom();
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (!box || !box.classList.contains('open')) return;
        if (e.key === 'Escape') {
            if (box.querySelector('.plb-menu').classList.contains('open')) { box.querySelector('.plb-menu').classList.remove('open'); return; }
            close();
        }
        else if (e.key === 'ArrowLeft') step(-1);
        else if (e.key === 'ArrowRight') step(1);
        else if (e.key === '+' || e.key === '=') setZoom(zoom * 1.3);
        else if (e.key === '-') setZoom(zoom / 1.3);
        else if (e.key === '0') resetZoom();
    });

    /** Собрать группу из всех элементов страницы с тем же data-group, в порядке DOM. */
    function collectGroup(groupId, clickedHref) {
        const nodes = document.querySelectorAll('[data-group="' + CSS.escape(groupId) + '"]');
        const items = [];
        let idx = 0;
        nodes.forEach(function (node) {
            const href = node.tagName === 'A' ? node.getAttribute('href') : (node.currentSrc || node.src);
            if (!href || !isImageUrl(href)) return;
            if (href === clickedHref) idx = items.length;
            const img = node.tagName === 'IMG' ? node : node.querySelector('img');
            // node нужен пунктам меню из чата: по нему находят сообщение, из которого фото
            items.push({ src: href, alt: img ? img.alt : '', node: node });
        });
        return { items, idx };
    }

    // Перехват на этапе всплытия: если на самой картинке есть свой обработчик
    // (например, открыть карточку пользователя), он успеет отработать первым.
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        if (e.target.closest('#psyLightbox')) return;

        const img = e.target.closest('img');
        const link = e.target.closest('a[href]');
        const groupEl = e.target.closest('[data-group]');
        const groupId = groupEl ? groupEl.getAttribute('data-group') : '';

        // ссылка на картинку (в чатах вложение обёрнуто именно так) — открываем просмотр
        if (link && isImageUrl(link.getAttribute('href'))) {
            if (link.hasAttribute('download') || link.hasAttribute('data-nozoom')) return;
            e.preventDefault();
            if (groupId) {
                const g = collectGroup(groupId, link.href);
                if (g.items.length > 1) { open(link.href, img ? img.alt : '', g.items, g.idx); return; }
            }
            open(link.href, img ? img.alt : '', null, 0, link);
            return;
        }
        if (!img || link) return;
        if (img.hasAttribute('data-nozoom')) return;
        const forced = img.hasAttribute('data-zoom');
        if (!forced) {
            if (!isImageUrl(img.currentSrc || img.src)) return;
            const r = img.getBoundingClientRect();
            if (r.width < MIN_SIDE || r.height < MIN_SIDE) return;   // аватарка/иконка
        }
        e.preventDefault();
        if (groupId) {
            const g = collectGroup(groupId, img.currentSrc || img.src);
            if (g.items.length > 1) { open(img.currentSrc || img.src, img.alt, g.items, g.idx); return; }
        }
        open(img.currentSrc || img.src, img.alt, null, 0, img);
    });

    global.psyLightbox = open;
    global.psyLightboxClose = close;
    global.psyIsImageUrl = isImageUrl;
})(window);

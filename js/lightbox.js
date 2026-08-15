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
            '<button class="plb-nav plb-prev" type="button" aria-label="Предыдущее изображение">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg></button>' +
            '<button class="plb-nav plb-next" type="button" aria-label="Следующее изображение">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></button>' +
            '<div class="plb-count"></div>' +
            '<a class="plb-save" download aria-label="Скачать">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/></svg>Скачать</a>' +
            '<img class="plb-img" alt="">';
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
            '#psyLightbox .plb-save{position:absolute;bottom:1rem;left:50%;transform:translateX(-50%);' +
            'display:inline-flex;align-items:center;gap:0.4rem;' +
            'color:#fff;font-size:0.85rem;text-decoration:none;background:rgba(255,255,255,0.14);' +
            'padding:0.45rem 0.9rem;border-radius:2rem;font-family:inherit;}' +
            '#psyLightbox .plb-save:hover{background:rgba(255,255,255,0.26);}' +
            '@media (max-width:640px){#psyLightbox .plb-nav{width:38px;height:38px;}}';
        document.head.appendChild(css);
        document.body.appendChild(box);
        box.addEventListener('click', function (e) {
            // клик по самой картинке не закрывает, по фону и крестику — закрывает
            if (e.target.closest('.plb-img, .plb-save, .plb-nav')) return;
            close();
        });
        box.querySelector('.plb-close').addEventListener('click', close);
        box.querySelector('.plb-prev').addEventListener('click', function () { step(-1); });
        box.querySelector('.plb-next').addEventListener('click', function () { step(1); });

        // Свайп влево/вправо — как перелистывание фото в мессенджерах
        let touchX = null;
        box.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) touchX = e.touches[0].clientX;
        }, { passive: true });
        box.addEventListener('touchend', function (e) {
            if (touchX == null) return;
            const dx = (e.changedTouches[0].clientX - touchX);
            touchX = null;
            if (Math.abs(dx) < 40) return;
            step(dx < 0 ? 1 : -1);
        }, { passive: true });
        return box;
    }

    /** Имя файла для сохранения: из адреса, а если там мусор — из подписи. */
    function fileNameFor(src, alt) {
        let name = '';
        try { name = decodeURIComponent(String(src).split(/[?#]/)[0].split('/').pop() || ''); } catch (e) {}
        if (!IMG_RE.test(name)) name = (alt && IMG_RE.test(alt)) ? alt : (name || 'image.jpg');
        return name;
    }

    function paint() {
        const item = gallery[galleryIdx];
        if (!item) return;
        const el = build();
        el.querySelector('.plb-img').src = item.src;
        el.querySelector('.plb-img').alt = item.alt || '';
        const save = el.querySelector('.plb-save');
        save.href = item.src;
        // download работает для своего домена; для чужого браузер просто откроет файл
        save.setAttribute('download', fileNameFor(item.src, item.alt));
        el.classList.toggle('has-gallery', gallery.length > 1);
        el.querySelector('.plb-count').textContent = gallery.length > 1
            ? (galleryIdx + 1) + ' / ' + gallery.length : '';
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
    function open(src, alt, gal, idx) {
        if (!src) return;
        gallery = (gal && gal.length) ? gal : [{ src, alt }];
        galleryIdx = Math.max(0, Math.min(gallery.length - 1, idx || 0));
        build();
        paint();
        box.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!box) return;
        box.classList.remove('open');
        box.querySelector('.plb-img').src = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (!box || !box.classList.contains('open')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') step(-1);
        else if (e.key === 'ArrowRight') step(1);
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
            items.push({ src: href, alt: img ? img.alt : '' });
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
            open(link.href, img ? img.alt : '');
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
        open(img.currentSrc || img.src, img.alt);
    });

    global.psyLightbox = open;
    global.psyLightboxClose = close;
    global.psyIsImageUrl = isImageUrl;
})(window);

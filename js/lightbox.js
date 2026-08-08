/**
 * lightbox.js — просмотр картинок во весь экран вместо перехода на прямую ссылку
 * (фидбэк админа: «фотки везде на сайте чтоб открывали и в чатах превью, а не на ссылку прямую»).
 *
 * Работает без разметки на страницах: перехватывает клики по ссылкам, ведущим на картинку,
 * и по достаточно крупным <img>. Мелкие аватарки и иконки не трогаем — иначе открывался бы
 * лайтбокс на каждый чих.
 *
 * Явное управление: атрибут data-zoom у <img> заставит открывать его всегда,
 * data-nozoom — никогда. Программно: psyLightbox(src, alt).
 */
(function (global) {
    const IMG_RE = /\.(jpe?g|png|gif|webp|avif|bmp|svg)(\?|#|$)/i;
    const MIN_SIDE = 90;   // меньше — это иконка или аватарка, не фото

    function isImageUrl(u) { return IMG_RE.test(String(u || '')); }

    let box = null;

    function build() {
        if (box) return box;
        box = document.createElement('div');
        box.id = 'psyLightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', 'Просмотр изображения');
        box.innerHTML =
            '<button class="plb-close" type="button" aria-label="Закрыть">✕</button>' +
            '<a class="plb-open" target="_blank" rel="noopener">Открыть оригинал</a>' +
            '<img class="plb-img" alt="">';
        const css = document.createElement('style');
        css.textContent =
            '#psyLightbox{position:fixed;inset:0;z-index:5000;display:none;align-items:center;' +
            'justify-content:center;background:rgba(12,12,18,0.92);padding:2.5rem 1rem 3.5rem;}' +
            '#psyLightbox.open{display:flex;}' +
            '#psyLightbox .plb-img{max-width:100%;max-height:100%;object-fit:contain;border-radius:0.5rem;' +
            'box-shadow:0 12px 50px rgba(0,0,0,0.5);}' +
            '#psyLightbox .plb-close{position:absolute;top:0.75rem;right:0.9rem;width:40px;height:40px;' +
            'border:none;border-radius:50%;background:rgba(255,255,255,0.14);color:#fff;font-size:1.1rem;' +
            'cursor:pointer;line-height:1;}' +
            '#psyLightbox .plb-close:hover{background:rgba(255,255,255,0.26);}' +
            '#psyLightbox .plb-open{position:absolute;bottom:1rem;left:50%;transform:translateX(-50%);' +
            'color:#fff;font-size:0.85rem;text-decoration:none;background:rgba(255,255,255,0.14);' +
            'padding:0.45rem 0.9rem;border-radius:2rem;font-family:inherit;}' +
            '#psyLightbox .plb-open:hover{background:rgba(255,255,255,0.26);}';
        document.head.appendChild(css);
        document.body.appendChild(box);
        box.addEventListener('click', function (e) {
            // клик по самой картинке не закрывает, по фону и крестику — закрывает
            if (e.target.classList.contains('plb-img') || e.target.classList.contains('plb-open')) return;
            close();
        });
        return box;
    }

    function open(src, alt) {
        if (!src) return;
        const el = build();
        el.querySelector('.plb-img').src = src;
        el.querySelector('.plb-img').alt = alt || '';
        el.querySelector('.plb-open').href = src;
        el.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!box) return;
        box.classList.remove('open');
        box.querySelector('.plb-img').src = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && box && box.classList.contains('open')) close();
    });

    // Перехват на этапе всплытия: если на самой картинке есть свой обработчик
    // (например, открыть карточку пользователя), он успеет отработать первым.
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        if (e.target.closest('#psyLightbox')) return;

        const img = e.target.closest('img');
        const link = e.target.closest('a[href]');

        // ссылка на картинку (в чатах вложение обёрнуто именно так) — открываем просмотр
        if (link && isImageUrl(link.getAttribute('href'))) {
            if (link.hasAttribute('download') || link.hasAttribute('data-nozoom')) return;
            e.preventDefault();
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
        open(img.currentSrc || img.src, img.alt);
    });

    global.psyLightbox = open;
    global.psyLightboxClose = close;
    global.psyIsImageUrl = isImageUrl;
})(window);

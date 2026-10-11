/**
 * qr.js — генератор QR-кода без внешних библиотек.
 *
 * Зачем свой: страницы отдаются со строгой политикой, сторонние скрипты и картинки
 * с чужих доменов не грузятся. Нужен ровно один режим — байтовый, уровень коррекции M,
 * версии 1–10: этого хватает на ссылку вида https://psytalk.pro/qr.html?code=<32 hex>.
 *
 * Используется на странице входа: компьютер показывает код, телефон его сканирует.
 */
(function (global) {
    'use strict';

    // ── Арифметика Галуа GF(256), нужна для кодов Рида — Соломона ──
    var EXP = new Uint8Array(512), LOG = new Uint8Array(256);
    (function () {
        var x = 1;
        for (var i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11d;      // порождающий многочлен QR
        }
        for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
    })();
    function gmul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }

    /** Многочлен-генератор для n проверочных байт. */
    function rsPoly(n) {
        var poly = [1];
        for (var i = 0; i < n; i++) {
            var next = new Array(poly.length + 1).fill(0);
            for (var j = 0; j < poly.length; j++) {
                next[j] ^= poly[j];
                next[j + 1] ^= gmul(poly[j], EXP[i]);
            }
            poly = next;
        }
        return poly;
    }

    function rsEncode(data, ecLen) {
        var gen = rsPoly(ecLen);
        var res = new Array(ecLen).fill(0);
        for (var i = 0; i < data.length; i++) {
            var factor = data[i] ^ res[0];
            res.shift();
            res.push(0);
            for (var j = 0; j < ecLen; j++) res[j] ^= gmul(gen[j + 1], factor);
        }
        return res;
    }

    // Таблицы для уровня коррекции M, версии 1–10:
    // [всего байт данных, число блоков группы 1, байт данных в блоке, блоков группы 2, байт в блоке]
    var VER = {
        1:  { ec: 10, groups: [[1, 16]] },
        2:  { ec: 16, groups: [[1, 28]] },
        3:  { ec: 26, groups: [[1, 44]] },
        4:  { ec: 18, groups: [[2, 32]] },
        5:  { ec: 24, groups: [[2, 43]] },
        6:  { ec: 16, groups: [[4, 27]] },
        7:  { ec: 18, groups: [[4, 31]] },
        8:  { ec: 22, groups: [[2, 38], [2, 39]] },
        9:  { ec: 22, groups: [[3, 36], [2, 37]] },
        10: { ec: 26, groups: [[4, 43], [1, 44]] }
    };
    var ALIGN = {
        1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30],
        6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
    };

    function capacity(v) {
        var g = VER[v].groups, total = 0;
        g.forEach(function (x) { total += x[0] * x[1]; });
        return total;
    }

    function pickVersion(byteLen) {
        for (var v = 1; v <= 10; v++) {
            var cciBits = v < 10 ? 8 : 16;                 // длина поля счётчика в байтовом режиме
            var need = 4 + cciBits + byteLen * 8;
            if (need <= capacity(v) * 8) return v;
        }
        return null;
    }

    /** Текст → массив кодовых слов с проверочными байтами, разложенный чересстрочно. */
    function makeCodewords(text, version) {
        var utf8 = unescape(encodeURIComponent(text));
        var bits = [];
        function push(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); }
        push(4, 4);                                        // байтовый режим
        push(utf8.length, version < 10 ? 8 : 16);
        for (var i = 0; i < utf8.length; i++) push(utf8.charCodeAt(i) & 0xff, 8);
        var cap = capacity(version) * 8;
        push(0, Math.min(4, cap - bits.length));           // терминатор
        while (bits.length % 8) bits.push(0);
        var data = [];
        for (var b = 0; b < bits.length; b += 8) {
            var v = 0;
            for (var k = 0; k < 8; k++) v = (v << 1) | bits[b + k];
            data.push(v);
        }
        var pad = [0xec, 0x11], p = 0;
        while (data.length < capacity(version)) data.push(pad[p++ % 2]);

        // Раскладываем по блокам и считаем коррекцию для каждого
        var blocks = [], ecs = [], pos = 0;
        VER[version].groups.forEach(function (g) {
            for (var n = 0; n < g[0]; n++) {
                var blk = data.slice(pos, pos + g[1]);
                pos += g[1];
                blocks.push(blk);
                ecs.push(rsEncode(blk, VER[version].ec));
            }
        });
        var out = [], maxLen = Math.max.apply(null, blocks.map(function (b) { return b.length; }));
        for (var c = 0; c < maxLen; c++)
            for (var bi = 0; bi < blocks.length; bi++)
                if (c < blocks[bi].length) out.push(blocks[bi][c]);
        for (var e = 0; e < VER[version].ec; e++)
            for (var bj = 0; bj < ecs.length; bj++) out.push(ecs[bj][e]);
        return out;
    }

    function buildMatrix(version, codewords, mask) {
        var size = version * 4 + 17;
        var m = [], reserved = [];
        for (var i = 0; i < size; i++) {
            m.push(new Array(size).fill(0));
            reserved.push(new Array(size).fill(false));
        }
        function setFinder(r, c) {
            for (var dr = -1; dr <= 7; dr++) for (var dc = -1; dc <= 7; dc++) {
                var rr = r + dr, cc = c + dc;
                if (rr < 0 || cc < 0 || rr >= size || cc >= size) continue;
                var on = (dr >= 0 && dr <= 6 && (dc === 0 || dc === 6)) ||
                         (dc >= 0 && dc <= 6 && (dr === 0 || dr === 6)) ||
                         (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
                m[rr][cc] = on ? 1 : 0;
                reserved[rr][cc] = true;
            }
        }
        setFinder(0, 0); setFinder(0, size - 7); setFinder(size - 7, 0);

        for (var t = 8; t < size - 8; t++) {               // синхрополосы
            var bit = (t % 2 === 0) ? 1 : 0;
            m[6][t] = bit; reserved[6][t] = true;
            m[t][6] = bit; reserved[t][6] = true;
        }
        var alast = ALIGN[version].length ? ALIGN[version][ALIGN[version].length - 1] : 0;
        ALIGN[version].forEach(function (r) {              // выравнивающие квадраты
            ALIGN[version].forEach(function (c) {
                // Пропускаем только три угла, занятые «глазами». Пересечение с синхрополосой
                // пропускать нельзя: с версии 7 квадраты стоят прямо на ней, и если их
                // не нарисовать, код перестаёт читаться.
                if ((r === 6 && c === 6) || (r === 6 && c === alast) || (r === alast && c === 6)) return;
                for (var dr = -2; dr <= 2; dr++) for (var dc = -2; dc <= 2; dc++) {
                    m[r + dr][c + dc] = (Math.abs(dr) === 2 || Math.abs(dc) === 2 || (dr === 0 && dc === 0)) ? 1 : 0;
                    reserved[r + dr][c + dc] = true;
                }
            });
        });
        m[size - 8][8] = 1; reserved[size - 8][8] = true;  // всегда тёмный модуль

        // места под сведения о формате
        for (var f = 0; f <= 8; f++) {
            if (!reserved[8][f]) reserved[8][f] = true;
            if (!reserved[f][8]) reserved[f][8] = true;
        }
        for (var f2 = 0; f2 < 8; f2++) {
            reserved[8][size - 1 - f2] = true;
            reserved[size - 1 - f2][8] = true;
        }

        // С седьмой версии рядом с «глазами» печатается ещё и номер версии — два блока
        // 3×6 с кодом BCH. Без них сканер не поймёт размер сетки и код не прочитает.
        if (version >= 7) {
            var vrem = version;
            for (var vb = 0; vb < 12; vb++) vrem = (vrem << 1) ^ (((vrem >> 11) & 1) * 0x1f25);
            var vbits = (version << 12) | vrem;
            for (var vi = 0; vi < 18; vi++) {
                var vbit = (vbits >> vi) & 1;
                var va = size - 11 + (vi % 3), vv = Math.floor(vi / 3);
                m[vv][va] = vbit; reserved[vv][va] = true;
                m[va][vv] = vbit; reserved[va][vv] = true;
            }
        }

        // данные змейкой снизу вверх, по две колонки
        var bitIdx = 0, dir = -1, row = size - 1;
        for (var col = size - 1; col > 0; col -= 2) {
            if (col === 6) col--;                          // колонку синхрополосы пропускаем
            while (true) {
                for (var k = 0; k < 2; k++) {
                    var cc2 = col - k;
                    if (!reserved[row][cc2]) {
                        var bitVal = 0;
                        if (bitIdx < codewords.length * 8) {
                            bitVal = (codewords[bitIdx >> 3] >> (7 - (bitIdx & 7))) & 1;
                        }
                        bitIdx++;
                        if (maskFn(mask, row, cc2)) bitVal ^= 1;
                        m[row][cc2] = bitVal;
                    }
                }
                row += dir;
                if (row < 0 || row >= size) { row -= dir; dir = -dir; break; }
            }
        }

        // сведения о формате: уровень M (двоично 00) + номер маски, с кодом BCH
        var fmt = ((0 << 3) | mask);
        var rem = fmt;
        for (var b2 = 0; b2 < 10; b2++) rem = (rem << 1) ^ (((rem >> 9) & 1) * 0x537);
        var bitsFmt = ((fmt << 10) | rem) ^ 0x5412;
        // Раскладка строго по стандарту. Она несимметрична: первая копия идёт по столбцу 8
        // сверху вниз, потом по строке 8 справа налево. Строки со столбцами тут легко
        // перепутать — раскладка «зеркалом» даёт почти такую же картинку (расходятся
        // всего 8 модулей из 1369), код выглядит правильным и не читается ни одним сканером.
        for (var i2 = 0; i2 <= 5; i2++) m[i2][8] = (bitsFmt >> i2) & 1;
        m[7][8] = (bitsFmt >> 6) & 1;
        m[8][8] = (bitsFmt >> 7) & 1;
        m[8][7] = (bitsFmt >> 8) & 1;
        for (var i3 = 9; i3 <= 14; i3++) m[8][14 - i3] = (bitsFmt >> i3) & 1;
        // Вторая копия: биты 0–7 по строке 8 справа налево, 8–14 по столбцу 8 снизу вверх.
        for (var i4 = 0; i4 <= 7; i4++) m[8][size - 1 - i4] = (bitsFmt >> i4) & 1;
        for (var i5 = 8; i5 <= 14; i5++) m[size - 15 + i5][8] = (bitsFmt >> i5) & 1;
        m[size - 8][8] = 1;                                // тёмный модуль — всегда последним
        return m;
    }

    function maskFn(mask, r, c) {
        switch (mask) {
            case 0: return (r + c) % 2 === 0;
            case 1: return r % 2 === 0;
            case 2: return c % 3 === 0;
            case 3: return (r + c) % 3 === 0;
            case 4: return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0;
            case 5: return ((r * c) % 2 + (r * c) % 3) === 0;
            case 6: return (((r * c) % 2 + (r * c) % 3) % 2) === 0;
            default: return (((r + c) % 2 + (r * c) % 3) % 2) === 0;
        }
    }

    /** Штраф по правилам стандарта — по нему выбираем лучшую маску. */
    function penalty(m) {
        var n = m.length, p = 0, dark = 0, i, j, run, prev;
        for (i = 0; i < n; i++) {
            run = 1; prev = m[i][0];
            for (j = 1; j < n; j++) {
                if (m[i][j] === prev) { run++; } else { if (run >= 5) p += 3 + (run - 5); run = 1; prev = m[i][j]; }
            }
            if (run >= 5) p += 3 + (run - 5);
        }
        for (j = 0; j < n; j++) {
            run = 1; prev = m[0][j];
            for (i = 1; i < n; i++) {
                if (m[i][j] === prev) { run++; } else { if (run >= 5) p += 3 + (run - 5); run = 1; prev = m[i][j]; }
            }
            if (run >= 5) p += 3 + (run - 5);
        }
        for (i = 0; i < n - 1; i++) for (j = 0; j < n - 1; j++) {
            var s = m[i][j] + m[i][j + 1] + m[i + 1][j] + m[i + 1][j + 1];
            if (s === 0 || s === 4) p += 3;
        }
        for (i = 0; i < n; i++) for (j = 0; j < n; j++) dark += m[i][j];
        p += Math.floor(Math.abs(dark * 100 / (n * n) - 50) / 5) * 10;
        return p;
    }

    /** Матрица QR (массив массивов 0/1) для строки. null — строка не влезла. */
    function qrMatrix(text) {
        var utf8 = unescape(encodeURIComponent(String(text || '')));
        var version = pickVersion(utf8.length);
        if (!version) return null;
        var cw = makeCodewords(String(text || ''), version);
        var best = null, bestScore = Infinity;
        for (var mask = 0; mask < 8; mask++) {
            var m = buildMatrix(version, cw, mask);
            var sc = penalty(m);
            if (sc < bestScore) { bestScore = sc; best = m; }
        }
        return best;
    }

    /** Готовая картинка: SVG-строка с полями (тихой зоной) в 4 модуля. */
    function qrSvg(text, px) {
        var m = qrMatrix(text);
        if (!m) return '';
        var n = m.length, quiet = 4, total = n + quiet * 2;
        var size = px || 240;
        var rects = '';
        for (var r = 0; r < n; r++) {
            var c = 0;
            while (c < n) {
                if (!m[r][c]) { c++; continue; }
                var start = c;
                while (c < n && m[r][c]) c++;
                rects += '<rect x="' + (start + quiet) + '" y="' + (r + quiet) + '" width="' + (c - start) + '" height="1"/>';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
               '" viewBox="0 0 ' + total + ' ' + total + '" shape-rendering="crispEdges">' +
               '<rect width="' + total + '" height="' + total + '" fill="#fff"/>' +
               '<g fill="#000">' + rects + '</g></svg>';
    }

    global.qrMatrix = qrMatrix;
    global.qrSvg = qrSvg;
})(window);

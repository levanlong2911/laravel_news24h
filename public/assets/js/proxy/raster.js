/**
 * Rasterizer JS thuan cho anh kiem proxy (khong can trinh duyet/GPU): mot luot quet moi camera
 * cho ra do sau (met, theo truc nhin), ma bo phan va do sang Lambert; tu do suy ra mask, depth,
 * clay, parts. Chi geometry bo phan duoc ve: helper, nhan, duong nuoc, truc khong co trong mesh.
 *
 * Doi chieu model sheet: can mask proxy vao mask tham chieu bang ti le DONG DEU + tinh tien (gioi
 * han), khong co gian rieng hai chieu; IoU va sai so moc tinh tren anh da can.
 */

export const RENDERER_VERSION = 'proxy-raster-v2';

/** Gioi han hieu chinh ti le khi can mask (ngoai khoang = camera/khung lech, khong do). */
export const ALIGN_SCALE_LIMITS = [0.5, 2.0];

/**
 * @param {object[]} meshes {part, positions, normals, indices}
 * @param {object} camera camera da giai (builder.resolveCamera)
 * @returns {{width: number, height: number, depth: Float32Array, part: Int16Array, shade: Float32Array, parts: string[], near: number, far: number}}
 */
export function rasterize(meshes, camera) {
    const { width, height, near, far } = camera;

    if (!(Number.isFinite(near) && Number.isFinite(far) && near > 0 && far > near)) {
        throw new Error('Camera cần 0 < near < far hữu hạn.');
    }

    const depth = new Float32Array(width * height).fill(Infinity);
    const partBuf = new Int16Array(width * height).fill(-1);
    const shade = new Float32Array(width * height);
    const parts = [];
    const basis = cameraBasis(camera);
    const ortho = camera.projection === 'orthographic';
    const tanV = ortho ? 0 : Math.tan(camera.fov * Math.PI / 360);
    const tanH = tanV * width / height;
    const light = normalize(add(scale(basis.z, 0.8), add(scale(basis.y, 0.5), scale(basis.x, -0.3))));
    // Diem he camera [x, y, d] (d > 0 phia truoc) -> [px, py, d].
    const toScreen = ([x, y, d]) => (ortho
        ? [(x - camera.ortho.left) / (camera.ortho.right - camera.ortho.left) * width, (camera.ortho.top - y) / (camera.ortho.top - camera.ortho.bottom) * height, d]
        : [(x / d / tanH * 0.5 + 0.5) * width, (0.5 - y / d / tanV * 0.5) * height, d]);
    const fill = (pa, pb, pc, pi, lit) => {
        const area = (pb[0] - pa[0]) * (pc[1] - pa[1]) - (pc[0] - pa[0]) * (pb[1] - pa[1]);

        if (!(Math.abs(area) >= 1e-12)) return;

        const x0 = Math.max(0, Math.floor(Math.min(pa[0], pb[0], pc[0])));
        const x1 = Math.min(width - 1, Math.ceil(Math.max(pa[0], pb[0], pc[0])));
        const y0 = Math.max(0, Math.floor(Math.min(pa[1], pb[1], pc[1])));
        const y1 = Math.min(height - 1, Math.ceil(Math.max(pa[1], pb[1], pc[1])));
        const [ia, ib, ic] = ortho ? [pa[2], pb[2], pc[2]] : [1 / pa[2], 1 / pb[2], 1 / pc[2]];

        for (let y = y0; y <= y1; y++) {
            const py = y + 0.5;

            for (let x = x0; x <= x1; x++) {
                const px = x + 0.5;
                const w0 = ((pb[0] - px) * (pc[1] - py) - (pc[0] - px) * (pb[1] - py)) / area;
                const w1 = ((pc[0] - px) * (pa[1] - py) - (pa[0] - px) * (pc[1] - py)) / area;
                const w2 = 1 - w0 - w1;

                if (w0 < 0 || w1 < 0 || w2 < 0) continue;

                const inv = w0 * ia + w1 * ib + w2 * ic;
                const d = ortho ? inv : 1 / inv;
                const k = y * width + x;

                if (d < depth[k]) {
                    depth[k] = d;
                    partBuf[k] = pi;
                    shade[k] = lit;
                }
            }
        }
    };

    for (const m of meshes) {
        let pi = parts.indexOf(m.part);

        if (pi < 0) pi = parts.push(m.part) - 1;

        const n = m.positions.length / 3;
        const view = new Float64Array(n * 3);

        for (let v = 0; v < n; v++) {
            const rel = [m.positions[v * 3] - camera.position[0], m.positions[v * 3 + 1] - camera.position[1], m.positions[v * 3 + 2] - camera.position[2]];
            view.set([dot(rel, basis.x), dot(rel, basis.y), -dot(rel, basis.z)], v * 3);
        }

        const at = (i) => [view[i * 3], view[i * 3 + 1], view[i * 3 + 2]];

        for (let t = 0; t < m.indices.length; t += 3) {
            const [a, b, c] = [m.indices[t], m.indices[t + 1], m.indices[t + 2]];
            const poly = clipDepth([at(a), at(b), at(c)], near, far);

            if (poly.length < 3) continue;

            const pa = [m.positions[a * 3], m.positions[a * 3 + 1], m.positions[a * 3 + 2]];
            const face = normalize(cross(sub([m.positions[b * 3], m.positions[b * 3 + 1], m.positions[b * 3 + 2]], pa), sub([m.positions[c * 3], m.positions[c * 3 + 1], m.positions[c * 3 + 2]], pa)));
            const lit = 0.25 + 0.75 * Math.abs(dot(face, light));
            const screen = poly.map(toScreen);

            for (let i = 1; i < screen.length - 1; i++) fill(screen[0], screen[i], screen[i + 1], pi, lit);
        }
    }

    // Khoang do sau thuc te cua diem trung (cho ma hoa depth), khong phai near/far cua camera.
    let [hitNear, hitFar] = [Infinity, -Infinity];

    for (let k = 0; k < depth.length; k++) {
        if (Number.isFinite(depth[k])) {
            if (depth[k] < hitNear) hitNear = depth[k];
            if (depth[k] > hitFar) hitFar = depth[k];
        }
    }

    return { width, height, depth, part: partBuf, shade, parts, near: Number.isFinite(hitNear) ? hitNear : 0, far: Number.isFinite(hitFar) ? hitFar : 1 };
}

/** Mask 0/255 (1 kenh). */
export function maskOf(r) {
    const out = new Uint8Array(r.width * r.height);

    for (let k = 0; k < out.length; k++) out[k] = r.part[k] >= 0 ? 255 : 0;

    return out;
}

/**
 * Depth 16 bit: gia tri = round((d - near) / (far - near) * 65534); 65535 = nen (vo cuc).
 * Don vi met, d la khoang cach theo truc nhin cua camera.
 */
export function depth16(r, near, far) {
    const out = new Uint16Array(r.width * r.height);
    const span = Math.max(1e-6, far - near);

    for (let k = 0; k < out.length; k++) {
        out[k] = Number.isFinite(r.depth[k]) ? Math.max(0, Math.min(65534, Math.round((r.depth[k] - near) / span * 65534))) : 65535;
    }

    return out;
}

/** Anh RGB: clay (xam theo Lambert) hoac parts (mau co dinh theo ma bo phan). */
export function rgbOf(r, mode) {
    const out = new Uint8Array(r.width * r.height * 3);
    const palette = r.parts.map((p) => colour(p));

    for (let k = 0; k < r.part.length; k++) {
        let rgb = [236, 238, 240];

        if (r.part[k] >= 0) {
            if (mode === 'parts') {
                const c = palette[r.part[k]];
                const s = 0.55 + 0.45 * r.shade[k];
                rgb = [c[0] * s, c[1] * s, c[2] * s];
            } else {
                const g = 40 + 200 * r.shade[k];
                rgb = [g, g, g];
            }
        }

        out.set([Math.round(rgb[0]), Math.round(rgb[1]), Math.round(rgb[2])], k * 3);
    }

    return out;
}

/** Mau on dinh cua mot ma bo phan (bam ten). */
export function colour(part) {
    const base = String(part).split(':')[0];
    let h = 2166136261;

    for (let i = 0; i < base.length; i++) h = Math.imul(h ^ base.charCodeAt(i), 16777619);

    const hue = ((h >>> 0) % 360) / 360;
    const [r, g, b] = hsl(hue, base === 'hull' || base === 'envelope' ? 0.08 : 0.55, base === 'hull' ? 0.45 : 0.6);

    return [r * 255, g * 255, b * 255];
}

function hsl(h, s, l) {
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    const p = 2 * l - q;
    const f = (t) => {
        const x = (t + 1) % 1;

        if (x < 1 / 6) return p + (q - p) * 6 * x;
        if (x < 1 / 2) return q;
        if (x < 2 / 3) return p + (q - p) * (2 / 3 - x) * 6;

        return p;
    };

    return [f(h + 1 / 3), f(h), f(h - 1 / 3)];
}

/** Khung bao mask (pixel) hoac null neu rong. */
export function maskBounds(mask, width, height) {
    let [x0, y0, x1, y1] = [Infinity, Infinity, -Infinity, -Infinity];

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (mask[y * width + x]) {
                if (x < x0) x0 = x;
                if (x > x1) x1 = x;
                if (y < y0) y0 = y;
                if (y > y1) y1 = y;
            }
        }
    }

    return x1 < 0 ? null : { x0, y0, x1: x1 + 1, y1: y1 + 1, w: x1 + 1 - x0, h: y1 + 1 - y0, cx: (x0 + x1 + 1) / 2, cy: (y0 + y1 + 1) / 2 };
}

/**
 * Can mask proxy vao khung mask tham chieu: ti le dong deu (binh phuong toi thieu tren hai canh
 * khung bao) + tinh tien tam. Ti le ngoai ALIGN_SCALE_LIMITS = khong can duoc (camera lech).
 *
 * @returns {{ok: boolean, scale: number, dx: number, dy: number, aligned: ?Uint8Array, reason?: string}}
 */
export function align(proxyMask, refMask, width, height) {
    const p = maskBounds(proxyMask, width, height);
    const r = maskBounds(refMask, width, height);

    if (!p || !r) return { ok: false, scale: NaN, dx: NaN, dy: NaN, aligned: null, reason: !p ? 'mask proxy rỗng' : 'mask tham chiếu rỗng' };

    const s = (r.w * p.w + r.h * p.h) / (p.w * p.w + p.h * p.h);

    if (s < ALIGN_SCALE_LIMITS[0] || s > ALIGN_SCALE_LIMITS[1]) {
        return { ok: false, scale: s, dx: NaN, dy: NaN, aligned: null, reason: 'tỉ lệ căn ' + s.toFixed(3) + ' ngoài giới hạn ' + ALIGN_SCALE_LIMITS.join('–') };
    }

    const aligned = new Uint8Array(width * height);

    for (let y = 0; y < height; y++) {
        const sy = Math.floor((y + 0.5 - r.cy) / s + p.cy);

        if (sy < 0 || sy >= height) continue;

        for (let x = 0; x < width; x++) {
            const sx = Math.floor((x + 0.5 - r.cx) / s + p.cx);

            if (sx >= 0 && sx < width && proxyMask[sy * width + sx]) aligned[y * width + x] = 255;
        }
    }

    return { ok: true, scale: s, dx: r.cx - p.cx * s, dy: r.cy - p.cy * s, aligned };
}

/** Diem pixel proxy -> pixel tham chieu theo phep can. */
export function alignPoint(point, a) {
    return { x: point.x * a.scale + a.dx, y: point.y * a.scale + a.dy };
}

export function iou(a, b) {
    let [inter, union] = [0, 0];

    for (let k = 0; k < a.length; k++) {
        const [x, y] = [a[k] > 0, b[k] > 0];

        if (x && y) inter++;
        if (x || y) union++;
    }

    return union ? inter / union : 0;
}

/** Overlay: xanh la = trung, do = chi tham chieu, xanh duong = chi proxy. */
export function overlay(ref, proxy, width, height) {
    const out = new Uint8Array(width * height * 3).fill(255);

    for (let k = 0; k < width * height; k++) {
        const [r, p] = [ref[k] > 0, proxy[k] > 0];

        if (r && p) out.set([60, 170, 90], k * 3);
        else if (r) out.set([220, 60, 60], k * 3);
        else if (p) out.set([60, 110, 220], k * 3);
    }

    return out;
}

/**
 * Mask de xuat tu anh model sheet: loang nen tu mep anh, moi buoc chi sang diem ke ben lech mau
 * <= tolerance (buoc nho). Nen studio chuyen sac tu tu (vignette, bong mem) nen loang qua duoc;
 * vien tau la buoc nhay mau dot ngot nen chan lai. Chi la DE XUAT — phai duoc nguoi dung xac nhan
 * hoac khoanh lai bang da giac.
 *
 * @param {Uint8Array} rgb anh RGB
 * @returns {Uint8Array} mask 0/255 (255 = tau)
 */
export function proposeMask(rgb, width, height, tolerance) {
    const background = new Uint8Array(width * height);
    const queue = new Int32Array(width * height);
    let [head, tail] = [0, 0];
    const step = (a, b) => Math.hypot(rgb[a * 3] - rgb[b * 3], rgb[a * 3 + 1] - rgb[b * 3 + 1], rgb[a * 3 + 2] - rgb[b * 3 + 2]) <= tolerance;
    const seed = (k, from) => {
        if (!background[k] && (from < 0 || step(k, from))) {
            background[k] = 1;
            queue[tail++] = k;
        }
    };

    for (let x = 0; x < width; x++) {
        seed(x, -1);
        seed((height - 1) * width + x, -1);
    }

    for (let y = 0; y < height; y++) {
        seed(y * width, -1);
        seed(y * width + width - 1, -1);
    }

    while (head < tail) {
        const k = queue[head++];
        const [x, y] = [k % width, Math.floor(k / width)];

        if (x > 0) seed(k - 1, k);
        if (x < width - 1) seed(k + 1, k);
        if (y > 0) seed(k - width, k);
        if (y < height - 1) seed(k + width, k);
    }

    const mask = new Uint8Array(width * height);

    for (let k = 0; k < mask.length; k++) mask[k] = background[k] ? 0 : 255;

    return keepMainComponents(mask, width, height);
}

/** Bo dom nhieu: giu cac vung lien (4 lan can) co dien tich >= 2% vung lon nhat. */
function keepMainComponents(mask, width, height) {
    const label = new Int32Array(width * height).fill(-1);
    const sizes = [];
    const stack = new Int32Array(width * height);

    for (let k = 0; k < mask.length; k++) {
        if (!mask[k] || label[k] >= 0) continue;

        const id = sizes.length;
        let [top, size] = [0, 0];
        stack[top++] = k;
        label[k] = id;

        while (top > 0) {
            const c = stack[--top];
            size++;
            const [x, y] = [c % width, Math.floor(c / width)];

            for (const n of [x > 0 ? c - 1 : -1, x < width - 1 ? c + 1 : -1, y > 0 ? c - width : -1, y < height - 1 ? c + width : -1]) {
                if (n >= 0 && mask[n] && label[n] < 0) {
                    label[n] = id;
                    stack[top++] = n;
                }
            }
        }

        sizes.push(size);
    }

    const keep = Math.max(0, ...sizes) * 0.02;
    const out = new Uint8Array(mask.length);

    for (let k = 0; k < mask.length; k++) out[k] = label[k] >= 0 && sizes[label[k]] >= keep ? 255 : 0;

    return out;
}

/**
 * Cat da giac he camera [x, y, d] theo near <= d <= far (Sutherland–Hodgman, hai mat phang): tam
 * giac cat ngang mat phang chi giu phan nam trong khoang, khong bo ca tam giac.
 */
function clipDepth(poly, near, far) {
    const plane = (list, keep, at) => {
        const out = [];

        for (let i = 0; i < list.length; i++) {
            const [p, q] = [list[i], list[(i + 1) % list.length]];
            const [ip, iq] = [keep(p[2]), keep(q[2])];

            if (ip) out.push(p);

            if (ip !== iq) {
                const t = (at - p[2]) / (q[2] - p[2]);
                out.push([p[0] + (q[0] - p[0]) * t, p[1] + (q[1] - p[1]) * t, at]);
            }
        }

        return out;
    };

    return plane(plane(poly, (d) => d >= near, near), (d) => d <= far, far);
}

function cameraBasis(camera) {
    const z = normalize(sub(camera.position, camera.target));
    const x = normalize(cross(camera.up, z));

    return { x, y: cross(z, x), z };
}

function add(a, b) { return [a[0] + b[0], a[1] + b[1], a[2] + b[2]]; }
function sub(a, b) { return [a[0] - b[0], a[1] - b[1], a[2] - b[2]]; }
function scale(a, s) { return [a[0] * s, a[1] * s, a[2] * s]; }
function dot(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
function cross(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
function normalize(a) { const l = Math.hypot(a[0], a[1], a[2]) || 1; return [a[0] / l, a[1] / l, a[2] / l]; }

// Worker Node cua proxy 3D. Goi: node scripts/proxy/worker.mjs <job.json>
// In ra stdout MOT dong JSON ket qua; loi -> exit 1 va JSON {ok:false, error}.
//
// Job:
//  build   — dung tu ProxySpec, xuat GLB + doc lai kiem tra, GLB tung build state, anh kiem
//            MS1–MS8 (clay, parts, mask, depth), doi chieu mask/moc tham chieu neu co.
//  propose — mask de xuat tu anh o model sheet (cho nguoi dung xac nhan).
//  shot    — clay / mask / depth cho mot camera + build state (dau ra theo shot).
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { buildProxy, project, scene, stateMeshes, BUILDER_VERSION } from '../../public/assets/js/proxy/builder.js';
import { align, alignPoint, depth16, iou, maskOf, overlay, proposeMask, rasterize, rgbOf, RENDERER_VERSION } from '../../public/assets/js/proxy/raster.js';
import { readGlb, toGlb } from '../../public/assets/js/proxy/glb.js';
import { decodePng, encodePng } from './png.mjs';

export const WORKER_VERSION = 'proxy-worker-v1';

const DEPTH_ENCODING = 'uint16: round((d - near) / (far - near) * 65534), 65535 = nền; d = khoảng cách theo trục nhìn, mét';

function sha256(bytes) {
    return createHash('sha256').update(bytes).digest('hex');
}

function main() {
    const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
    const handlers = { build, propose, shot };

    if (!handlers[job.job]) throw new Error('Job không hỗ trợ: ' + job.job);

    return handlers[job.job](job);
}

function writer(dir) {
    mkdirSync(dir, { recursive: true });
    const files = {};

    return {
        files,
        put(name, bytes) {
            const buf = Buffer.from(bytes.buffer ? new Uint8Array(bytes.buffer, bytes.byteOffset, bytes.byteLength) : bytes);
            writeFileSync(join(dir, name), buf);
            files[name] = { sha256: sha256(buf), bytes: buf.length };
        },
        json(name, data) {
            this.put(name, Buffer.from(JSON.stringify(data, null, 1)));
        },
    };
}

function build(job) {
    const { spec, tolerances } = job;
    const out = writer(job.out_dir);
    const base = buildProxy(spec, { tolerances, fill: job.fill });
    const extras = { state: base.state.id, spec_sha256: spec.spec_sha256, builder: BUILDER_VERSION };
    out.put('proxy.glb', toGlb(base.meshes, { extras }));
    const glb = verifyGlb(readGlb(readFileSync(join(job.out_dir, 'proxy.glb'))), base, spec, 1);

    const states = (spec.build_states || []).map((s) => {
        const b = buildProxy(spec, { state: s.id, tolerances, fill: job.fill });
        const name = 'state-' + s.id + '.glb';
        out.put(name, toGlb(b.meshes, { scale: b.state.scale, extras: { state: s.id, spec_sha256: spec.spec_sha256, builder: BUILDER_VERSION } }));
        const back = readGlb(readFileSync(join(job.out_dir, name)));
        const ids = [...new Set(back.nodes.map((n) => n.part.split(':')[0]))];
        const leaked = ids.filter((id) => !b.state.parts.includes(id));
        const absent = spec.geometry.parts.filter((p) => s.absent.includes(p.registry)).map((p) => p.id).filter((id) => ids.includes(id));

        return { id: s.id, kind: s.kind, file: name, scale: b.state.scale, parts: ids, triangles: back.triangles, missing: s.missing, hidden_ok: leaked.length === 0 && absent.length === 0, leaked: [...leaked, ...absent] };
    });

    const cameras = [];
    const renders = {};

    for (const cam of base.cameras) {
        const r = rasterize(base.meshes, cam);
        const [near, far] = [Math.max(0, Math.floor(r.near * 10) / 10 - 0.5), Math.ceil(r.far * 10) / 10 + 0.5];
        out.put('clay-' + cam.view + '.png', encodePng(r.width, r.height, rgbOf(r, 'clay'), { channels: 3 }));
        out.put('parts-' + cam.view + '.png', encodePng(r.width, r.height, rgbOf(r, 'parts'), { channels: 3 }));
        out.put('mask-' + cam.view + '.png', encodePng(r.width, r.height, maskOf(r), { channels: 1 }));
        out.put('depth-' + cam.view + '.png', encodePng(r.width, r.height, depth16(r, near, far), { channels: 1, bitDepth: 16 }));
        cameras.push({ ...cam, depth: { near, far, unit: 'm', encoding: DEPTH_ENCODING } });
        renders[cam.view] = { raster: r, camera: cam };
    }

    const comparison = compare(job, spec, renders, out);
    const report = {
        ...base.report,
        worker: WORKER_VERSION,
        renderer: RENDERER_VERSION,
        glb,
        states,
        comparison,
        pass: base.report.pass && glb.ok && states.every((s) => s.hidden_ok),
    };
    out.json('cameras.json', cameras);
    out.json('proxy-spec.json', spec);
    out.json('report.json', report);
    out.json('manifest.json', {
        spec_sha256: spec.spec_sha256,
        versions: { builder: BUILDER_VERSION, renderer: RENDERER_VERSION, worker: WORKER_VERSION },
        state: base.state.id,
        files: { ...out.files },
    });

    return { ok: true, files: out.files, pass: report.pass, failed: report.checks.filter((c) => !c.pass).map((c) => c.key), comparison: comparison.summary };
}

/**
 * Doc lai GLB vua ghi va doi chieu voi geometry da dung: so node, ten, so tam giac, khung bao
 * theo LOA/be rong/dinh profile (co ti le cua trang thai).
 */
function verifyGlb(back, built, spec, scaleFactor) {
    const hull = spec.geometry.hull;
    const names = built.meshes.map((m) => m.name);
    const triangles = built.meshes.reduce((n, m) => n + m.indices.length / 3, 0);
    const top = Math.max(...hull.profile.map((p) => p[1]));
    // Dinh do tren than + thuong tang (thiet bi nhu guong duoc phep nho tren duong bao).
    const shell = back.nodes.filter((n) => ['hull', 'envelope', 'deck_closure'].includes(n.part));
    const shellTop = Math.max(...shell.map((n) => Math.max(...Array.from({ length: n.positions.length / 3 }, (_, v) => n.positions[v * 3 + 1])))) * back.scale;
    const checks = [
        ['nodes', back.nodes.length === names.length && back.nodes.every((n, i) => n.name === names[i]), back.nodes.length + ' node / ' + names.length + ' mesh'],
        ['triangles', back.triangles === triangles, back.triangles + ' / ' + triangles],
        ['length', Math.abs((back.bounds.max[0] - back.bounds.min[0]) - hull.loa * scaleFactor) <= 0.5 * scaleFactor, 'X ' + (back.bounds.max[0] - back.bounds.min[0]).toFixed(3) + ' m / LOA ' + hull.loa],
        ['beam', hull.beam === null || Math.abs((back.bounds.max[2] - back.bounds.min[2]) - hull.beam * scaleFactor) <= 1.0 * scaleFactor, 'Z ' + (back.bounds.max[2] - back.bounds.min[2]).toFixed(3) + ' m / beam ' + hull.beam],
        ['top', Math.abs(shellTop - top * scaleFactor) <= 0.1 * scaleFactor, 'đỉnh thân + thượng tầng ' + shellTop.toFixed(3) + ' m / profile ' + top],
    ];

    return { ok: checks.every((c) => c[1]), checks: checks.map(([key, pass, measured]) => ({ key, pass, measured })) };
}

/**
 * Doi chieu model sheet: mask tham chieu da xac nhan + moc da danh dau (pixel o 960x1080).
 * IoU chi tinh cho cac goc trong job.iou_views; sai so moc doi ra met theo camera (met/pixel tai
 * do sau cua moc, chia ti le can) so voi landmark_ratio x LOA.
 */
function compare(job, spec, renders, out) {
    const refs = job.references || {};
    const loa = spec.geometry.hull.loa;
    const limit = (job.landmark_ratio ?? 0.03) * loa;
    const marks = Object.fromEntries(spec.geometry.landmarks.map((l) => [l.name, l]));
    const views = {};
    // Vi tri chieu cua moi moc o moi goc (co trong khung hay khong): co so cho yeu cau do phu moc.
    const projected = Object.fromEntries(Object.entries(renders).map(([view, { camera }]) => [view, spec.geometry.landmarks
        .filter((l) => l.X !== null && l.z !== null)
        .map((l) => {
            const p = project(camera, scene(l.X, l.y ?? 0, l.z));

            return { name: l.name, x: p ? round(p.x) : null, y: p ? round(p.y) : null, in_frame: !!p && p.x >= 0 && p.y >= 0 && p.x <= camera.width && p.y <= camera.height };
        })]));

    for (const [view, ref] of Object.entries(refs)) {
        const render = renders[view];

        if (!render) continue;

        const { raster, camera } = render;

        if (!ref.mask_path) {
            // Goc IoU chua co mask: khong do moc bang cach khac (cho mask); goc khac (MS8) khop bang moc.
            views[view] = (job.iou_views || []).includes(view)
                ? { status: 'no_mask', iou: null, landmarks: (ref.landmarks || []).map((lm) => ({ name: lm.name, status: lm.status, error_m: null, pass: null })) }
                : fitLandmarks(view, ref, camera, marks, limit);
            continue;
        }

        const decoded = decodePng(readFileSync(ref.mask_path));

        if (decoded.width !== raster.width || decoded.height !== raster.height) {
            views[view] = { status: 'error', reason: 'mask tham chiếu ' + decoded.width + '×' + decoded.height + ' khác khung ' + raster.width + '×' + raster.height };
            continue;
        }

        const refMask = new Uint8Array(decoded.width * decoded.height);

        for (let k = 0; k < refMask.length; k++) refMask[k] = decoded.rgb[k * 3] > 127 ? 255 : 0;

        const a = align(maskOf(raster), refMask, raster.width, raster.height);

        if (!a.ok) {
            views[view] = { status: 'not_aligned', reason: a.reason, iou: null, landmarks: [] };
            continue;
        }

        out.put('overlay-' + view + '.png', encodePng(raster.width, raster.height, overlay(refMask, a.aligned, raster.width, raster.height), { channels: 3 }));
        const landmarks = (ref.landmarks || []).map((lm) => {
            const l = marks[lm.name];

            if (!l || lm.status !== 'visible') return { name: lm.name, status: lm.status, error_m: null, pass: null };

            const p = project(camera, scene(l.X, l.y ?? 0, l.z));

            if (!p) return { name: lm.name, status: 'behind_camera', error_m: null, pass: false };

            const q = alignPoint(p, a);
            const px = Math.hypot(q.x - lm.x, q.y - lm.y);
            const metres = px * p.metresPerPixel / a.scale;

            return { name: lm.name, status: 'visible', proxy_px: [round(q.x), round(q.y)], reference_px: [lm.x, lm.y], error_px: round(px), error_m: round(metres), limit_m: round(limit), pass: metres <= limit };
        });
        const value = iou(a.aligned, refMask);
        const gated = (job.iou_views || []).includes(view);

        views[view] = {
            status: 'compared',
            iou: round(value),
            iou_gated: gated,
            iou_pass: gated ? value >= job.iou_min : null,
            align: { scale: round(a.scale), dx: round(a.dx), dy: round(a.dy) },
            landmarks,
            reference: { mask_sha256: ref.mask_sha256, landmarks_sha256: ref.landmarks_sha256 ?? null },
        };
    }

    const missing = (job.iou_views || []).filter((v) => !views[v] || views[v].status !== 'compared');
    const iouFail = Object.entries(views).filter(([, v]) => v.iou_gated && v.iou_pass === false).map(([k]) => k);
    const landmarkFail = Object.entries(views).flatMap(([k, v]) => (v.landmarks || []).filter((l) => l.pass === false).map((l) => k + ':' + l.name));

    return { views, projected, summary: { missing, iou_fail: iouFail, landmark_fail: landmarkFail, pass: missing.length === 0 && iouFail.length === 0 && landmarkFail.length === 0 } };
}

/**
 * Goc khong co mask toan than (MS8 can canh): khop moc proxy vao moc da danh dau bang ti le dong
 * deu + tinh tien (binh phuong toi thieu), sai so con lai doi ra met. Can >= 3 moc thay duoc —
 * it hon thi phep khop khong co bac tu do de kiem (status = too_few_landmarks).
 */
function fitLandmarks(view, ref, camera, marks, limit) {
    const pairs = (ref.landmarks || []).filter((lm) => lm.status === 'visible' && marks[lm.name]).map((lm) => {
        const l = marks[lm.name];

        return { lm, p: project(camera, scene(l.X, l.y ?? 0, l.z)) };
    }).filter((x) => x.p);
    const passthrough = (ref.landmarks || []).filter((lm) => lm.status !== 'visible').map((lm) => ({ name: lm.name, status: lm.status, error_m: null, pass: null }));

    if (pairs.length < 3) {
        return { status: 'too_few_landmarks', reason: 'cần ≥ 3 mốc thấy được để khớp', iou: null, landmarks: [...passthrough, ...pairs.map(({ lm }) => ({ name: lm.name, status: 'visible', error_m: null, pass: null }))] };
    }

    const mean = (list) => list.reduce((a, q) => [a[0] + q[0] / list.length, a[1] + q[1] / list.length], [0, 0]);
    const P = pairs.map(({ p }) => [p.x, p.y]);
    const Q = pairs.map(({ lm }) => [lm.x, lm.y]);
    const [pm, qm] = [mean(P), mean(Q)];
    const num = P.reduce((s, p, i) => s + (p[0] - pm[0]) * (Q[i][0] - qm[0]) + (p[1] - pm[1]) * (Q[i][1] - qm[1]), 0);
    const den = P.reduce((s, p) => s + (p[0] - pm[0]) ** 2 + (p[1] - pm[1]) ** 2, 0);
    const s = den > 0 ? num / den : NaN;

    if (!(s > 0)) return { status: 'not_aligned', reason: 'không khớp được mốc', iou: null, landmarks: passthrough };

    const landmarks = pairs.map(({ lm, p }, i) => {
        const q = [(P[i][0] - pm[0]) * s + qm[0], (P[i][1] - pm[1]) * s + qm[1]];
        const px = Math.hypot(q[0] - lm.x, q[1] - lm.y);
        const metres = px * p.metresPerPixel / s;

        return { name: lm.name, status: 'visible', proxy_px: [round(q[0]), round(q[1])], reference_px: [lm.x, lm.y], error_px: round(px), error_m: round(metres), limit_m: round(limit), pass: metres <= limit };
    });

    return { status: 'compared', iou: null, iou_gated: false, align: { scale: round(s), method: 'landmarks' }, landmarks: [...passthrough, ...landmarks], reference: { landmarks_sha256: ref.landmarks_sha256 ?? null } };
}

function propose(job) {
    const img = decodePng(readFileSync(job.crop_path));
    const mask = proposeMask(img.rgb, img.width, img.height, job.tolerance);
    const png = encodePng(img.width, img.height, mask, { channels: 1 });
    writeFileSync(job.out_path, png);
    const covered = mask.reduce((n, v) => n + (v ? 1 : 0), 0) / mask.length;

    return { ok: true, width: img.width, height: img.height, coverage: round(covered), sha256: sha256(png), bytes: png.length };
}

/**
 * Dau ra theo shot: camera da khoa (vi tri/dich/up/fov hoac khung truc giao) + build state ->
 * clay, mask than tau, depth (ma hoa ghi ro). Cache theo hash dau vao do PHP quan ly.
 */
function shot(job) {
    const { spec, camera } = job;
    const b = buildProxy(spec, { state: job.state ?? null, tolerances: job.tolerances || {} });
    const cam = { ...camera, width: job.width ?? camera.width, height: job.height ?? camera.height };
    // Geometry da nhan ti le cua state (BS05 1:25): cung kich thuoc vat ly voi GLB cua state; camera
    // va do sau tinh trong cung he met da nhan ti le do.
    const meshes = stateMeshes(b);
    const r = rasterize(meshes, cam);
    const hull = rasterize(meshes.filter((m) => m.part === 'hull'), cam);
    const [near, far] = [cam.near, cam.far];
    const out = writer(job.out_dir);
    out.put('clay.png', encodePng(r.width, r.height, rgbOf(r, 'clay'), { channels: 3 }));
    out.put('hull-mask.png', encodePng(r.width, r.height, maskOf(hull), { channels: 1 }));
    out.put('depth.png', encodePng(r.width, r.height, depth16(r, near, far), { channels: 1, bitDepth: 16 }));
    const meta = { shot_id: job.shot_id ?? null, state: b.state.id, scale: b.state.scale, units: b.state.scale === 1 ? 'm' : 'm ở tỉ lệ mô hình 1:' + Math.round(1 / b.state.scale), camera: cam, camera_sha256: sha256(Buffer.from(JSON.stringify(cam))), depth: { near, far, unit: 'm', encoding: DEPTH_ENCODING }, spec_sha256: spec.spec_sha256, versions: { builder: BUILDER_VERSION, renderer: RENDERER_VERSION, worker: WORKER_VERSION } };
    out.json('shot.json', meta);

    return { ok: true, files: out.files, state: b.state.id, camera_sha256: meta.camera_sha256 };
}

function round(v) {
    return Number.isFinite(v) ? Math.round(v * 10000) / 10000 : v;
}

try {
    process.stdout.write(JSON.stringify(main()) + '\n');
} catch (e) {
    process.stdout.write(JSON.stringify({ ok: false, error: e.message, stack: String(e.stack).split('\n').slice(0, 4) }) + '\n');
    process.exit(1);
}

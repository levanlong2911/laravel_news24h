// Test bo dung proxy (node --test tests/js/*.test.js). Fixture ProxySpec do compiler PHP tao
// (tests/js/fixtures/make-spec.php) — cung hop dong voi du lieu that.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildProxy, project, scene, stateMeshes } from '../../public/assets/js/proxy/builder.js';
import { readGlb, toGlb } from '../../public/assets/js/proxy/glb.js';
import { align, iou, maskBounds, maskOf, rasterize } from '../../public/assets/js/proxy/raster.js';
import { decodePng, encodePng } from '../../scripts/proxy/png.mjs';

const SPEC = JSON.parse(readFileSync(new URL('./fixtures/proxy-spec.json', import.meta.url)));
const TOL = { profile_m: 0.05, plan_m: 0.05, ratio: 0.02, landmark_m: 0.01, detail_m: 0.1, detail_dim_m: 0.01, pocket_coverage: 0.95, deck_m: 0.3, rib_longitudinal_deg: 10, rib_transverse_deg: 80 };
const WORKER = new URL('../../scripts/proxy/worker.mjs', import.meta.url);
const spec = () => structuredClone(SPEC);
const part = (s, id) => s.geometry.parts.find((p) => p.id === id);
const check = (out, key) => out.report.checks.find((c) => c.key === key);
const meshes = (out, name) => out.meshes.filter((m) => m.part === name);

test('fixture builds and every geometry check passes', () => {
    const out = buildProxy(spec(), { tolerances: TOL });

    assert.deepEqual(out.report.missing, []);
    assert.deepEqual(out.report.checks.filter((c) => !c.pass).map((c) => c.key), []);
    assert.ok(check(out, 'void:N1_slot'), 'void check exists');
    assert.ok(check(out, 'profile').value <= TOL.profile_m, 'cut keeps the rim silhouette');
});

test('changing a dimension in the spec changes the built geometry', () => {
    const wide = spec();
    part(wide, 'N1_slot').params.width.value = 8;
    const out = buildProxy(wide, { tolerances: TOL });
    const cut = check(out, 'cut:N1_slot');

    assert.match(cut.measured, /^20 × 8 m/);
    assert.ok(cut.pass);
    assert.match(check(buildProxy(spec(), { tolerances: TOL }), 'cut:N1_slot').measured, /^20 × 6 m/);
});

test('the cut is a real void, not a shallow mark, and is detected when filled', () => {
    const out = buildProxy(spec(), { tolerances: TOL });
    assert.ok(check(out, 'void:N1_slot').value >= 0.95);

    const shallow = spec();
    part(shallow, 'N1_slot').params.floor_z.value = 9.9;
    const filled = buildProxy(shallow, { tolerances: TOL });
    assert.notEqual(check(filled, 'cut:N1_slot').measured, check(out, 'cut:N1_slot').measured);
});

test('each side uses its own landmark: port stays +Z, starboard −Z, no silent mirror', () => {
    const out = buildProxy(spec(), { tolerances: TOL });
    const facets = meshes(out, 'N2_facets').map((m) => m.center[2]).sort((a, b) => a - b);

    assert.deepEqual(facets, [-3, 3]);

    const single = spec();
    part(single, 'N2_facets').instances = part(single, 'N2_facets').instances.filter((i) => i.side === 'port');
    assert.deepEqual(meshes(buildProxy(single, { tolerances: TOL }), 'N2_facets').map((m) => m.center[2]), [3]);
});

test('hull normals point outward (triangle winding not flipped)', () => {
    const hull = meshes(buildProxy(spec(), { tolerances: TOL }), 'hull')[0];
    let [outward, total] = [0, 0];

    for (let v = 0; v < hull.positions.length / 3; v++) {
        const [y, z] = [hull.positions[v * 3 + 1], hull.positions[v * 3 + 2]];

        if (Math.abs(z) > 5 && y > 0 && y < 3) {
            total++;

            if (Math.sign(hull.normals[v * 3 + 2]) === Math.sign(z)) outward++;
        }
    }

    assert.ok(total > 50);
    assert.ok(outward / total > 0.95, outward + '/' + total);
});

test('box parts (panels, keel blocks) have outward normals', () => {
    const out = buildProxy(spec(), { state: 'BS07', tolerances: TOL });
    const all = [...meshes(buildProxy(spec(), { tolerances: TOL }), 'N2_facets'), ...meshes(out, 'keel_blocks')];

    for (const m of all) {
        for (let t = 0; t < m.indices.length; t += 3) {
            const tri = [0, 1, 2].map((k) => m.indices[t + k]);
            const p = tri.map((i) => [m.positions[i * 3], m.positions[i * 3 + 1], m.positions[i * 3 + 2]]);
            const centre = p.reduce((a, q) => a.map((v, k) => v + q[k] / 3), [0, 0, 0]);
            const box = Array.from({ length: m.positions.length / 3 }, (_, v) => [m.positions[v * 3], m.positions[v * 3 + 1], m.positions[v * 3 + 2]]);
            const u = p[1].map((v, k) => v - p[0][k]);
            const w = p[2].map((v, k) => v - p[0][k]);
            const n = [u[1] * w[2] - u[2] * w[1], u[2] * w[0] - u[0] * w[2], u[0] * w[1] - u[1] * w[0]];
            // Tam hop gan nhat: trung binh cac dinh trong pham vi 3 m quanh tam tam giac.
            const local = box.filter((q) => Math.hypot(q[0] - centre[0], q[1] - centre[1], q[2] - centre[2]) < 3);
            const c = local.reduce((a, q) => a.map((v, k) => v + q[k] / local.length), [0, 0, 0]);

            assert.ok(n[0] * (centre[0] - c[0]) + n[1] * (centre[1] - c[1]) + n[2] * (centre[2] - c[2]) >= -1e-9, m.part + ' tam giác ' + t / 3 + ' quay vào trong');
        }
    }
});

test('panel facing and tilt change the geometry and the centre check follows', () => {
    const base = buildProxy(spec(), { tolerances: TOL });
    const turned = spec();
    part(turned, 'N2_facets').facing = 'side';
    part(turned, 'N2_facets').params.tilt_deg = { value: 45, basis: 'assumption', source: 'mapping' };
    const out = buildProxy(turned, { tolerances: TOL });
    const before = meshes(base, 'N2_facets').find((m) => m.center[2] > 0);
    const after = meshes(out, 'N2_facets').find((m) => m.center[2] > 0);

    assert.notDeepEqual(Array.from(after.positions), Array.from(before.positions));
    assert.deepEqual(before.normal, [0, 1, 0]);
    assert.ok(Math.abs(after.normal[2] - Math.cos(Math.PI / 4)) < 1e-9 && Math.abs(after.normal[1] - Math.sin(Math.PI / 4)) < 1e-9, 'side panel tilted 45° toward up');
    assert.ok(check(out, 'instance:N2_facets:mirror_port').pass);
});

test('opening honours facing: up sinks below the deck surface, side sinks into the hull side', () => {
    const s = spec();
    const port = part(s, 'N2_facets').instances.find((i) => i.side === 'port');
    s.geometry.parts.push({ id: 'hatch_up', op: 'opening', label: 'hatch', registry: 'hull_shell', origin: 'mapping', importance: 'detail', facing: 'up', params: { width: { value: 1, basis: 'assumption', source: 'mapping' }, height: { value: 1, basis: 'assumption', source: 'mapping' }, depth: { value: 0.5, basis: 'assumption', source: 'mapping' } }, instances: [{ ...port, X: 75 }] });
    s.geometry.parts.push({ id: 'door_side', op: 'opening', label: 'door', registry: 'hull_shell', origin: 'mapping', importance: 'detail', facing: 'side', params: { width: { value: 2, basis: 'assumption', source: 'mapping' }, height: { value: 2, basis: 'assumption', source: 'mapping' }, depth: { value: 0.7, basis: 'assumption', source: 'mapping' } }, instances: [{ ...port, z: 3 }] });
    s.build_states.forEach((st) => { if (st.kind === 'finished') st.present.push('hull_shell'); });
    const out = buildProxy(s, { tolerances: TOL });
    const up = meshes(out, 'hatch_up')[0];
    const side = meshes(out, 'door_side')[0];

    assert.deepEqual(up.normal, [0, 1, 0]);
    assert.ok(Math.abs(up.mouth[1] - up.center[1] - 0.5) < 1e-9);
    assert.deepEqual(side.normal, [0, 0, 1]);
    assert.ok(Math.abs(side.mouth[2] - side.center[2] - 0.7) < 1e-9);
    assert.ok(check(out, 'opening:hatch_up:mirror_port').pass && check(out, 'opening:door_side:mirror_port').pass);

    // Vo da duoc khoet: tu camera nhin ve hoc phai thay mat trong hoc tren anh cua ca con tau.
    const visible = (view, name) => {
        const r = rasterize(out.meshes, out.cameras.find((c) => c.view === view));
        const id = r.parts.indexOf(name);

        return id < 0 ? 0 : r.part.reduce((n, p) => n + (p === id ? 1 : 0), 0);
    };
    assert.ok(visible('MS1', 'door_side') > 20, 'hốc mạn nhìn thấy từ MS1');
    assert.ok(visible('MS6', 'hatch_up') > 20, 'nắp trên boong nhìn thấy từ MS6');
});

test('BS05 shot geometry is scaled exactly like the BS05 GLB', () => {
    const out = buildProxy(spec(), { state: 'BS05', tolerances: TOL });
    const scaled = stateMeshes(out);
    const xs = scaled.flatMap((m) => Array.from({ length: m.positions.length / 3 }, (_, v) => m.positions[v * 3]));
    const glb = readGlb(toGlb(out.meshes, { scale: out.state.scale }));

    assert.ok(Math.abs(Math.max(...xs) - Math.min(...xs) - 4) < 1e-6);
    assert.ok(Math.abs(glb.bounds.max[0] - glb.bounds.min[0] - 4) < 1e-4);
});

test('rasterizer agrees with the camera projection used for landmarks (ortho and perspective)', () => {
    const out = buildProxy(spec(), { tolerances: TOL });

    for (const view of ['MS1', 'MS2', 'MS6']) {
        const cam = out.cameras.find((c) => c.view === view);
        const r = rasterize(out.meshes, cam);
        const b = maskBounds(maskOf(r), r.width, r.height);
        let [x0, y0, x1, y1] = [Infinity, Infinity, -Infinity, -Infinity];

        for (const m of out.meshes) {
            for (let v = 0; v < m.positions.length / 3; v++) {
                const p = project(cam, [m.positions[v * 3], m.positions[v * 3 + 1], m.positions[v * 3 + 2]]);
                [x0, y0, x1, y1] = [Math.min(x0, p.x), Math.min(y0, p.y), Math.max(x1, p.x), Math.max(y1, p.y)];
            }
        }

        // Lay mau theo tam pixel (nhu GPU): dau mui nhon thu ve 0 nen vai pixel cuoi mong hon mot
        // pixel khong duoc to — cho phep 4 px o canh do, khong phai lech phep chieu.
        for (const [a, e] of [[b.x0, x0], [b.y0, y0], [b.x1, x1], [b.y1, y1]]) {
            assert.ok(Math.abs(a - e) <= 4, view + ': mask ' + a + ' vs projection ' + e.toFixed(2));
        }
    }
});

test('MS1 is a port profile with the bow on the left and MS6 has the bow up', () => {
    const out = buildProxy(spec(), { tolerances: TOL });
    const ms1 = out.cameras.find((c) => c.view === 'MS1');
    const bow = project(ms1, scene(0, 0, 4));
    const stern = project(ms1, scene(100, 0, 5));

    assert.ok(bow.x < stern.x);
    assert.ok(check(out, 'ms6').pass);
});

test('cameras do not drift: same spec gives the same resolved cameras', () => {
    const a = buildProxy(spec(), { tolerances: TOL }).cameras;
    const b = buildProxy(spec(), { tolerances: TOL }).cameras;

    assert.deepEqual(a, b);
    assert.ok(a.every((c) => c.width === 960 && c.height === 1080));
});

test('GLB round trip keeps nodes, triangles and bounds', () => {
    const out = buildProxy(spec(), { tolerances: TOL });
    const back = readGlb(toGlb(out.meshes, { extras: { state: out.state.id } }));

    assert.deepEqual(back.nodes.map((n) => n.name), out.meshes.map((m) => m.name));
    assert.equal(back.triangles, out.meshes.reduce((n, m) => n + m.indices.length / 3, 0));
    assert.ok(Math.abs(back.bounds.max[0] - back.bounds.min[0] - 100) < 0.01);
    assert.equal(back.root.extras.state, out.state.id);
});

test('a tampered GLB is rejected on read', () => {
    const bytes = toGlb(buildProxy(spec(), { tolerances: TOL }).meshes);
    const cut = bytes.slice(0, bytes.length - 16);

    assert.throws(() => readGlb(cut));
});

test('build states keep stable part ids and hide absent parts; BS05 is 1:25', () => {
    const s = spec();
    const bs07 = buildProxy(s, { state: 'BS07', tolerances: TOL });
    const bs08 = buildProxy(s, { state: 'BS08', tolerances: TOL });
    const bs05 = buildProxy(s, { state: 'BS05', tolerances: TOL });

    assert.ok(bs07.meshes.some((m) => m.part === 'keel_blocks'));
    assert.ok(!bs07.meshes.some((m) => ['envelope', 'N1_slot', 'N2_facets'].includes(m.part)));
    assert.ok(bs08.meshes.some((m) => m.part === 'envelope') && !bs08.meshes.some((m) => m.part === 'keel_blocks'));
    assert.equal(bs05.state.scale, 1 / 25);
    assert.deepEqual([...new Set(bs05.meshes.map((m) => m.part))], ['hull']);
});

test('rasterizer mask covers the hull and IoU of a mask with itself is 1', () => {
    const out = buildProxy(spec(), { tolerances: TOL });
    const r = rasterize(out.meshes, out.cameras.find((c) => c.view === 'MS1'));
    const mask = maskOf(r);
    const b = maskBounds(mask, r.width, r.height);

    assert.ok(b.w > 960 * 0.8, 'fills the frame width');
    assert.equal(iou(mask, mask), 1);

    const a = align(mask, mask, r.width, r.height);
    assert.ok(Math.abs(a.scale - 1) < 1e-9 && iou(a.aligned, mask) > 0.99);
});

const param = (value) => ({ value, basis: 'design', source: 'test' });

/** Fixture + gan N2 (ribs) tren thuong tang va soc (relief) tren hai vach khe N1_slot. */
function textured({ pitch = 0.5, depth = 0.04, reliefPitch = 0.4, reliefDepth = 0.05, of = 'N1_slot' } = {}) {
    const s = spec();
    s.geometry.parts.push(
        { id: 'R_ribs', op: 'ribs', registry: 'superstructure', importance: 'identity', params: { from_x: param(20), to_x: param(80), turn_x: param(60), turn_y: param(3), pitch: param(pitch), depth: param(depth), half_turn: param(4) }, instances: [] },
        { id: 'R_relief', op: 'relief', registry: 'superstructure', importance: 'identity', of, params: { pitch: param(reliefPitch), depth: param(reliefDepth) }, instances: [] },
    );

    return s;
}

const glbNode = (out, name) => readGlb(toGlb(out.meshes, {})).nodes.find((n) => n.name === name);

test('ribs and relief are real geometry in the GLB, measured on the mesh', () => {
    const out = buildProxy(textured(), { tolerances: TOL });

    assert.deepEqual(out.report.missing, []);
    assert.deepEqual(out.report.checks.filter((c) => !c.pass).map((c) => c.key), []);
    assert.match(check(out, 'ribs:R_ribs').measured, /^bước 0\.5 m, nổi 0\.04 m/);
    assert.match(check(out, 'relief:R_relief').measured, /^bước 0\.4 \/ 0\.4 m, nhô 0\.05 m/);

    const ribs = glbNode(out, 'R_ribs');
    const relief = glbNode(out, 'R_relief');
    assert.ok(ribs && ribs.indices.length > 0, 'ribs node in GLB');
    assert.ok(relief && relief.indices.length > 0, 'relief node in GLB');

    // Vay soc nho vao long khe (|z scene| < nua be rong khe 3 m) dung depth, tren ca hai vach.
    const zs = Array.from({ length: relief.positions.length / 3 }, (_, i) => relief.positions[i * 3 + 2]);
    assert.ok(Math.abs(Math.min(...zs.filter((z) => z > 0)) - (3 - 0.05)) < 1e-4);
    assert.ok(Math.abs(Math.max(...zs.filter((z) => z < 0)) + (3 - 0.05)) < 1e-4);
});

test('changing rib/relief pitch or depth changes the GLB and the measured values', () => {
    const base = buildProxy(textured(), { tolerances: TOL });
    const variants = {
        pitch: buildProxy(textured({ pitch: 0.8 }), { tolerances: TOL }),
        depth: buildProxy(textured({ depth: 0.1 }), { tolerances: TOL }),
        reliefPitch: buildProxy(textured({ reliefPitch: 0.6 }), { tolerances: TOL }),
        reliefDepth: buildProxy(textured({ reliefDepth: 0.12 }), { tolerances: TOL }),
    };
    const sameNode = (a, b, name) => {
        const [x, y] = [glbNode(a, name), glbNode(b, name)];

        return x.positions.length === y.positions.length && x.positions.every((v, i) => v === y.positions[i]);
    };

    assert.ok(!sameNode(base, variants.pitch, 'R_ribs'), 'rib pitch changes rib geometry');
    assert.ok(!sameNode(base, variants.depth, 'R_ribs'), 'rib depth changes rib geometry');
    assert.ok(!sameNode(base, variants.reliefPitch, 'R_relief'), 'relief pitch changes fins');
    assert.ok(!sameNode(base, variants.reliefDepth, 'R_relief'), 'relief depth changes fins');
    assert.ok(glbNode(variants.pitch, 'R_ribs').positions.length < glbNode(base, 'R_ribs').positions.length, 'wider pitch, fewer ribs');

    assert.match(check(variants.pitch, 'ribs:R_ribs').measured, /^bước 0\.8 m, nổi 0\.04 m/);
    assert.match(check(variants.depth, 'ribs:R_ribs').measured, /^bước 0\.5 m, nổi 0\.1 m/);
    assert.match(check(variants.reliefPitch, 'relief:R_relief').measured, /^bước 0\.6 \/ 0\.6 m/);
    assert.match(check(variants.reliefDepth, 'relief:R_relief').measured, /nhô 0\.12 m/);
    assert.ok(Object.values(variants).every((o) => o.report.pass), 'every variant still passes its own checks');
});

test('ribs run longitudinal ahead of the turn line and transverse behind it', () => {
    const ribs = meshes(buildProxy(textured(), { tolerances: TOL }), 'R_ribs')[0];
    const centres = [];

    // Moi mat cat gan = 4 dinh lien tiep; tam day = trung diem hai dinh day (scene X, Z = y).
    for (let v = 0; v < ribs.positions.length / 3; v += 4) {
        centres.push([(ribs.positions[v * 3] + ribs.positions[v * 3 + 3]) / 2, (ribs.positions[v * 3 + 2] + ribs.positions[v * 3 + 5]) / 2]);
    }

    // Day gan lun 1 cm theo phap tuyen mat (mat nghieng lech tam vai mm): sai so 2 cm << pitch.
    const onGrid = (v) => Math.abs(v - Math.round(v / 0.5) * 0.5) < 0.02;
    // Phia truoc (X < turn_x - turn_y = 57): duong gan doc o |y| = boi so cua pitch.
    const ahead = centres.filter(([X]) => Math.abs(X - 30) < 0.26).map(([, y]) => Math.abs(y));
    assert.ok(ahead.length > 4 && ahead.every(onGrid), 'longitudinal ribs ahead: ' + ahead.slice(0, 6));
    // Phia sau, gan truc giua: duong ngang o X = 57 + j × pitch.
    const behind = centres.filter(([X, y]) => Math.abs(y) < 0.3 && X > 62).map(([X]) => X - 57);
    assert.ok(behind.length > 4 && behind.every(onGrid), 'transverse ribs behind: ' + behind.slice(0, 6));
});

test('ribs/relief without a surface to sit on fail their required check instead of passing silently', () => {
    const offRoof = textured();
    Object.assign(part(offRoof, 'R_ribs').params, { from_x: param(200), to_x: param(220) });
    const out = buildProxy(offRoof, { tolerances: TOL });
    assert.equal(check(out, 'ribs:R_ribs').pass, false);
    assert.match(check(out, 'ribs:R_ribs').measured, /không dựng được gân/);
    assert.equal(meshes(out, 'R_ribs').length, 0);

    const notCut = buildProxy(textured({ of: 'N2_facets' }), { tolerances: TOL });
    assert.equal(check(notCut, 'relief:R_relief').pass, false);
    assert.ok(notCut.report.missing.some((m) => m.startsWith('R_relief: relief chỉ dựng được trên vách phép cut')));
    assert.equal(notCut.report.pass, false);
});

/** Camera phoi canh nhin −Z tu goc toa do; tam giac vuong goc truc nhin o khoang cach d. */
const clipCamera = { projection: 'perspective', position: [0, 0, 0], target: [0, 0, -1], up: [0, 1, 0], fov: 90, width: 64, height: 64, near: 1, far: 10 };
const triangleAt = (d) => ({ part: 'q', positions: Float32Array.from([-1, -1, -d, 1, -1, -d, 0, 1, -d]), indices: Uint32Array.from([0, 1, 2]) });
const covered = (r) => maskOf(r).filter((v) => v > 0).length;

test('rasterizer drops geometry nearer than near or farther than far', () => {
    assert.equal(covered(rasterize([triangleAt(0.5)], clipCamera)), 0, 'triangle at 0.5 m with near = 1');
    assert.equal(covered(rasterize([triangleAt(20)], clipCamera)), 0, 'triangle at 20 m with far = 10');
    assert.ok(covered(rasterize([triangleAt(5)], clipCamera)) > 0, 'triangle at 5 m is drawn');
    assert.throws(() => rasterize([triangleAt(5)], { ...clipCamera, near: 0 }), /near < far/);
    assert.throws(() => rasterize([triangleAt(5)], { ...clipCamera, far: 0.5 }), /near < far/);
});

test('rasterizer clips triangles crossing near and far instead of keeping or dropping them whole', () => {
    // Tam giac nghieng: canh day o d = 0.2 (truoc near), dinh o d = 30 (sau far).
    const slanted = { part: 'q', positions: Float32Array.from([-0.1, -0.1, -0.2, 0.1, -0.1, -0.2, 0, 15, -30]), indices: Uint32Array.from([0, 1, 2]) };
    const cam = { ...clipCamera, width: 128, height: 128 };
    const r = rasterize([slanted], cam);
    const depths = Array.from(r.depth).filter(Number.isFinite);

    assert.ok(depths.length > 0, 'the part between near and far is drawn');
    assert.ok(Math.min(...depths) >= 1 - 1e-3, 'nothing nearer than near: ' + Math.min(...depths));
    assert.ok(Math.max(...depths) <= 10 + 1e-3, 'nothing farther than far: ' + Math.max(...depths));
    assert.ok(covered(rasterize([slanted], { ...cam, near: 0.1, far: 100 })) > covered(r), 'only the out-of-range part is removed');
});

test('PNG round trip (RGB and 16-bit grey)', () => {
    const rgb = Uint8Array.from({ length: 4 * 3 * 3 }, (_, i) => (i * 37) % 256);
    assert.deepEqual(decodePng(encodePng(4, 3, rgb, { channels: 3 })).rgb, rgb);

    const grey = Uint16Array.from([0, 65535, 1234, 40000]);
    const back = decodePng(encodePng(2, 2, grey, { channels: 1, bitDepth: 16 }));
    assert.deepEqual(Array.from(back.rgb.filter((_, i) => i % 3 === 0)), Array.from(grey, (v) => v >> 8));
});

test('worker shot job writes clay, hull mask and decodable depth', () => {
    const dir = mkdtempSync(join(tmpdir(), 'proxy-shot-'));
    const cam = buildProxy(spec(), { tolerances: TOL }).cameras.find((c) => c.view === 'MS2');
    const job = join(dir, 'job.json');
    writeFileSync(job, JSON.stringify({ job: 'shot', spec: spec(), state: 'BS08', camera: { ...cam, near: 1, far: 400 }, width: 320, height: 360, out_dir: join(dir, 'out'), shot_id: 'S01' }));
    const result = JSON.parse(execFileSync(process.execPath, [fileURLToPath(WORKER), job], { encoding: 'utf8' }));

    assert.equal(result.ok, true);
    assert.deepEqual(Object.keys(result.files).sort(), ['clay.png', 'depth.png', 'hull-mask.png', 'shot.json']);

    const meta = JSON.parse(readFileSync(join(dir, 'out', 'shot.json'), 'utf8'));
    assert.equal(meta.state, 'BS08');
    assert.equal(meta.depth.unit, 'm');

    const depth = decodePng(readFileSync(join(dir, 'out', 'depth.png')));
    const values = Array.from(depth.rgb.filter((_, i) => i % 3 === 0));
    assert.ok(values.some((v) => v < 255), 'some pixels hit geometry');
    assert.ok(values.includes(255), 'background encoded as far');
});

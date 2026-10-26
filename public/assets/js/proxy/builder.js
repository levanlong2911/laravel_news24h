/**
 * Bo dung proxy 3D tu ProxySpec (App\Video\Proxy\ProxySpecCompiler). Thuan du lieu: khong DOM,
 * khong three.js — chay nhu nhau trong trinh duyet (xem truoc) va Node (artifact chinh thuc).
 *
 * He spec: X met tu mui ve duoi, y met duong man trai, z met tren duong nuoc. He scene (glTF):
 * scene(X, y, z) = [X, z, y] (+Y len, +Z man trai) — dung ma tran to_gltf cua spec.
 *
 * Bo dung khong suy lai don vi, khong doc config: moi so lay tu spec; nguong kiem truyen vao.
 */

export const BUILDER_VERSION = 'proxy-builder-v3';

const STATION_STEP_M = 0.5;
const WALL_GAP_M = 0.01;
const UNDERWATER_POINTS = 16;
const SIDE_POINTS = 8;
const ENVELOPE_POINTS = 96;
const CAP_SAMPLES = 48;
const EDGE_EPS = 1e-3;
const DISC_THICKNESS_M = 0.2;
const PANEL_THICKNESS_M = 0.1;
const CYLINDER_SEGMENTS = 32;
const FRAME_M = 0.15;
const FRAME_LIFT_M = 0.01;
const CAVITY_M = 0.6;
const OPENING_LEAF_M = 0.08;
const FLUSH_LIFT_M = 0.005;
const COAMING_SAMPLES = 8;
const SCAFFOLD_SLAB_M = 0.1;
const RIB_STEP_M = 0.5;
const RIB_SINK_M = 0.01;
const RELIEF_RIM_M = 0.05;
const FORWARD = [-1, 0, 0];
const PORT = [0, 0, 1];
const UP = [0, 1, 0];

/** Phep khoet (doi mat vo) theo thu tu ap dung; phan con lai la bo phan cong them. */
const CARVING = ['pocket', 'basin', 'cut', 'recess', 'steps', 'channel'];
/** Phep khoet khong giu duong bao tai truc giua (tram cua no khong dua vao kiem profile). */
const LOWERS_CENTRE = ['pocket', 'basin', 'recess', 'steps', 'channel'];

/**
 * @param {object} spec ProxySpec
 * @param {{state?: string|null, tolerances?: object, fill?: number}} options
 * @returns {{meshes: object[], markers: object[], cameras: object[], report: object, state: object}}
 */
export function buildProxy(spec, options = {}) {
    const state = resolveState(spec, options.state ?? null);
    const ctx = context(spec, state, options.tolerances || {});
    const features = partFeatures(spec, ctx);
    const meshes = [];
    let stations = [];
    let surface = null;
    let windows = { results: [], openings: [], meshes: [] };
    let textures = [];

    if (state.has('hull_shell')) {
        ctx.edges = [...new Set(features.carving.flatMap((f) => (f.half > 0 ? [f.half] : (f.op === 'groove' ? [f.lo, f.hi] : []))))].sort((a, b) => b - a);

        for (const X of stationPositions(ctx, features)) {
            stations.push(section(ctx, features, X));
        }

        for (const feature of features.carving) {
            carve(stations, feature, ctx);
        }

        surface = surfaceSampler(stations);
        const hull = loft(stations, ctx, features);
        windows = buildWindows(features.windows, ctx, stations, surface);
        const holes = features.openings.map((o) => openingMeshes(o, stations, surface, ctx));
        // Khoet vo cho ca cua kinh va hoc mo truoc khi tach mesh theo bo phan.
        punch(hull, [...windows.openings, ...holes.map((h) => h.opening).filter(Boolean)]);
        meshes.push(...splitByPart(hull));
        meshes.push(...windows.meshes);
        meshes.push(...buildDetails(features.details, features.carving, ctx, surface, hull));
        meshes.push(...features.bridges.map((b) => bridgeMesh(b)));
        meshes.push(...features.panels.flatMap((p) => panelMeshes(p)));
        meshes.push(...holes.flatMap((h) => h.meshes));
        meshes.push(...features.discs.flatMap((d) => discMeshes(d, surface)));
        textures = [...features.ribs.map((r) => ribMeshes(r, surface, ctx)), ...features.relief.map((r) => reliefMeshes(r, features.carving, surface, ctx))];
        meshes.push(...textures.map((t) => t.mesh).filter(Boolean));
    } else {
        ctx.missing.push(...features.windows.length ? ['hull_shell vắng mặt ở trạng thái này — bỏ qua cửa'] : []);
        ctx.missing.push(...[...features.ribs, ...features.relief].map((r) => r.part + ': hull_shell vắng mặt — không có mặt để dựng gân'));
    }

    meshes.push(...features.keelBlocks.flatMap((k) => keelBlockMeshes(k, ctx)));
    meshes.push(...features.scaffolds.flatMap((s) => scaffoldMeshes(s, ctx)));

    const solid = meshes.filter((m) => m.positions.length > 0 && m.indices.length > 0);
    const cameras = (spec.cameras || []).map((c) => resolveCamera(c, solid, ctx, options.fill ?? 0.9));
    const report = buildReport(spec, ctx, features, stations, solid, windows.results, textures, cameras, state);

    return { meshes: solid, markers: features.markers, cameras, report, state: { id: state.id, scale: state.scale, parts: [...state.parts] } };
}

/**
 * Trang thai dung: null = mo hinh doi chieu model sheet (trang thai "finished" cuoi cung neu
 * thiet ke co, khong thi moi bo phan tru gian giao). Bo phan theo registry co mat.
 *
 * @returns {{id: ?string, scale: number, registries: Set<string>, parts: Set<string>, has: function(string): boolean}}
 */
export function resolveState(spec, id) {
    const states = spec.build_states || [];
    const parts = spec.geometry.parts;
    let chosen = null;

    if (id !== null) {
        chosen = states.find((s) => s.id === id) || null;

        if (!chosen) throw new Error('Không có trạng thái ' + id + ' trong spec.');
    } else {
        chosen = [...states].reverse().find((s) => s.kind === 'finished') || null;
    }

    const registries = new Set(chosen ? chosen.present : parts.map((p) => p.registry).filter((r) => r && r !== 'scaffolding_on_hull'));

    return {
        id: chosen ? chosen.id : null,
        scale: chosen && chosen.kind === 'scale_model' ? 1 / chosen.scale : 1,
        registries,
        parts: new Set(parts.filter((p) => p.op !== 'omit' && registries.has(p.registry)).map((p) => p.id)),
        has: (registry) => registries.has(registry),
    };
}

function context(spec, state, tolerances) {
    const hull = spec.geometry.hull;
    const loa = hull.loa;
    const shape = (code) => hull.shape[code].value;
    const [stem, flatFrom, flatTo] = shape('G3');
    const [ratio, full] = shape('G11');
    const profile = hull.profile.map((p) => [p[0], p[1]]).sort((a, b) => a[0] - b[0]);
    const port = hull.plan_port.map((p) => [p[0], p[1]]).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    const superstructure = state.has('superstructure');

    return {
        spec,
        state,
        loa,
        beam: hull.beam,
        draft: hull.draft || 0,
        tolerances,
        missing: [],
        profile,
        port,
        exponent: shape('G1'),
        sideHeight: shape('G2'),
        stem, flatFrom, flatTo, ratio, full,
        decks: spec.geometry.decks,
        landmarks: Object.fromEntries(spec.geometry.landmarks.map((l) => [l.name, l])),
        top: (X) => (superstructure ? interpolate(profile, X) : Math.min(shape('G2'), interpolate(profile, X))),
        profileTop: (X) => interpolate(profile, X),
        width: (X) => Math.max(0, interpolate(port, X)),
        sideTop: (X) => Math.min(shape('G2'), interpolate(profile, X)),
        edges: [],
    };
}

const num = (part, name) => (part.params[name] ? part.params[name].value : undefined);

/**
 * Bo phan cua spec (da co so met) -> dac trung noi bo theo phep dung. Chi lay bo phan co mat
 * o trang thai dang dung.
 */
function partFeatures(spec, ctx) {
    const out = { carving: [], windows: [], details: [], bridges: [], panels: [], openings: [], discs: [], ribs: [], keelBlocks: [], scaffolds: [], markers: [], relief: [] };
    const parts = spec.geometry.parts.filter((p) => ctx.state.parts.has(p.id));
    const byId = Object.fromEntries(spec.geometry.parts.map((p) => [p.id, p]));

    for (const p of parts) {
        const v = (name) => num(p, name);

        switch (p.op) {
            case 'base':
                break;
            case 'pocket':
                out.carving.push({ op: 'pocket', part: p.id, from: v('from_x'), to: v('to_x'), floor: v('floor_z'), wall: v('wall'), half: 0 });
                break;
            case 'basin':
                out.carving.push({ op: 'basin', part: p.id, from: v('center_x') - v('length') / 2, to: v('center_x') + v('length') / 2, floor: v('rim_z') - v('depth'), half: v('width') / 2, design: { length: v('length'), width: v('width'), depth: v('depth'), rim: v('rim_z') } });
                break;
            case 'cut':
                out.carving.push({ op: 'cut', part: p.id, from: v('from_x'), to: v('to_x'), floor: v('floor_z'), half: v('width') / 2, radius: v('radius') ?? 0 });
                break;
            case 'recess':
                out.carving.push({ op: 'recess', part: p.id, from: v('from_x'), to: v('to_x'), depth: v('depth'), half: v('width') / 2, radius: v('radius') ?? 0 });
                break;
            case 'steps':
                for (const i of p.instances) out.carving.push({ op: 'steps', part: p.id, X: i.X, y: Math.abs(i.y), stepWidth: v('width'), tread: v('tread'), half: 0 });
                break;
            case 'channel':
                out.carving.push({ op: 'channel', part: p.id, from: v('from_x'), to: v('to_x'), fromZ: v('from_z'), toZ: v('to_z'), half: v('width') / 2 });
                break;
            case 'window':
                out.windows.push({ part: p, id: p.id });
                break;
            case 'bulwark': case 'coaming': case 'edge': case 'rails':
                out.details.push({ part: p, id: p.id, type: p.op, of: p.of ? byId[p.of] : null });
                break;
            case 'bridge':
                out.bridges.push({ part: p.id, X: v('center_x'), top: v('top_z'), thickness: v('thickness'), length: v('length'), half: byId[p.spans] ? num(byId[p.spans], 'width') / 2 : 0, spans: p.spans });
                break;
            case 'panel':
                for (const i of p.instances) out.panels.push({ part: p.id, at: i, sizeX: v('size_x'), sizeY: v('size_y'), recess: v('recess') ?? 0, tilt: v('tilt_deg') ?? 0, facing: p.facing });
                break;
            case 'opening':
                for (const i of p.instances) out.openings.push({ part: p.id, at: i, width: v('width'), height: v('height'), depth: v('depth') ?? 0, facing: p.facing });
                break;
            case 'disc':
                for (const i of p.instances) out.discs.push({ part: p.id, at: i, diameter: v('diameter'), tilt: v('tilt_deg'), pedestal: v('pedestal') });
                break;
            case 'ribs':
                out.ribs.push({ part: p.id, from: v('from_x'), to: v('to_x'), turn: v('turn_x'), turnY: Math.abs(v('turn_y')), pitch: v('pitch'), depth: v('depth'), halfTurn: v('half_turn') });
                break;
            case 'relief':
                out.relief.push({ part: p.id, of: p.of, pitch: v('pitch'), depth: v('depth') });
                break;
            case 'keel_blocks':
                out.keelBlocks.push({ part: p.id, spacing: v('spacing'), height: v('height'), width: v('width'), from: v('from_x'), to: v('to_x') });
                break;
            case 'scaffold':
                out.scaffolds.push({ part: p.id, offset: v('offset'), height: v('height'), from: v('from_x'), to: v('to_x') });
                break;
            case 'marking':
                for (const i of p.instances) out.markers.push({ part: p.id, landmark: i.landmark, position: scene(i.X, i.y ?? 0, i.z), display_state: p.display_state || null });
                break;
            default:
                ctx.missing.push(p.id + ': phép dựng ' + p.op + ' chưa hỗ trợ');
        }
    }

    for (const d of out.details) {
        if (d.type === 'rails') {
            const channel = out.carving.find((f) => f.op === 'channel' && f.part === d.of?.id);
            const [spacing, rail, depth] = [num(d.part, 'spacing'), num(d.part, 'rail'), num(d.part, 'depth')];

            if (channel && depth > 0) {
                out.carving.push({ op: 'groove', part: d.id + ':groove', of: channel.part, from: channel.from, to: channel.to, lo: spacing / 2 - rail / 2, hi: spacing / 2 + rail / 2, depth, half: 0 });
            }
        }
    }

    const order = [...CARVING, 'groove'];
    out.carving.sort((a, b) => order.indexOf(a.op) - order.indexOf(b.op));

    return out;
}

/**
 * Vi tri tram: luoi deu STATION_STEP_M, them cap tram sat nhau tai moi mep phep khoet doc tau de
 * vach khoet thang dung va dai dung thiet ke (khong lech theo buoc luoi).
 */
function stationPositions(ctx, features) {
    const count = Math.max(2, Math.round(ctx.loa / STATION_STEP_M));
    const xs = Array.from({ length: count + 1 }, (_, i) => ctx.loa * i / count);

    for (const f of features.carving) {
        if (isNumber(f.from) && isNumber(f.to)) {
            xs.push(f.from - WALL_GAP_M, f.from, f.to, f.to + WALL_GAP_M);
        }
    }

    const sorted = xs.filter((X) => X >= 0 && X <= ctx.loa).sort((a, b) => a - b);

    return sorted.filter((X, i) => i === 0 || X - sorted[i - 1] > 1e-4);
}

/** Vi tri trong day tram (khoang cach khong deu): chi so tram truoc va ti le noi suy. */
function locate(stations, X) {
    let [lo, hi] = [0, stations.length - 1];

    if (X <= stations[0].X) return { i: 0, t: 0 };
    if (X >= stations[hi].X) return { i: hi - 1, t: 1 };

    while (hi - lo > 1) {
        const m = (lo + hi) >> 1;

        if (stations[m].X <= X) lo = m;
        else hi = m;
    }

    return { i: lo, t: (X - stations[lo].X) / Math.max(1e-9, stations[lo + 1].X - stations[lo].X) };
}

function nearest(stations, X) {
    const { i, t } = locate(stations, X);

    return stations[t < 0.5 ? i : i + 1];
}

/**
 * Mat cat tai X. Trong pham vi phep `cut` co san thap hon duong bao, dinh mat cat dat o mep khe
 * (y = nua be rong khe) chu khong o truc giua: hai vach khe giu duong bao canh ben, khe khoet xuong
 * san — khong lap khe, khong ha silhouette.
 */
function section(ctx, features, X) {
    const x = X / ctx.loa;
    const w = ctx.width(X);
    const zt = ctx.top(X);
    const zb = ctx.sideTop(X);
    const depth = keelDepth(ctx, x);
    const bottom = x < ctx.stem ? zb * (1 - x / ctx.stem) : -depth;
    const entry = smoothstep((x - ctx.stem) / (ctx.full - ctx.stem));
    const waterline = w * (ctx.ratio + (1 - ctx.ratio) * entry);
    const exponent = 2 / (1.5 + 2.5 * entry);
    const half = [];

    for (let j = 0; j < UNDERWATER_POINTS; j++) {
        const t = j / (UNDERWATER_POINTS - 1);
        const angle = t * Math.PI / 2;
        const y = bottom < 0 ? waterline * Math.pow(Math.sin(angle), exponent) : waterline * t;
        const z = bottom < 0 ? bottom * Math.pow(Math.cos(angle), exponent) : bottom;
        half.push({ y, z, part: 'hull' });
    }

    const sideFrom = Math.max(0, bottom);

    for (let j = 1; j <= SIDE_POINTS; j++) {
        half.push({ y: waterline + (w - waterline) * j / SIDE_POINTS, z: sideFrom + (zb - sideFrom) * j / SIDE_POINTS, part: 'hull' });
    }

    const rim = Math.max(0, ...features.carving.filter((f) => f.op === 'cut' && X >= f.from - EDGE_EPS && X <= f.to + EDGE_EPS && f.floor < zt).map((f) => f.half));
    const topPart = ctx.state.has('superstructure') ? 'envelope' : 'deck_closure';
    const envelope = resample(envelopeOutline(ctx, X, w, zb, zt, rim), ENVELOPE_POINTS);

    for (const edge of ctx.edges || []) {
        const at = envelope.findIndex((p, j) => j < envelope.length - 1 && p[0] >= edge && envelope[j + 1][0] < edge);

        if (at < 0) {
            const last = envelope[envelope.length - 1];
            envelope.splice(envelope.length - 1, 0, [last[0], last[1]], [last[0], last[1]]);
            continue;
        }

        const [a, b] = [envelope[at], envelope[at + 1]];
        const z = a[1] + (b[1] - a[1]) * (a[0] - edge) / (a[0] - b[0]);
        envelope.splice(at + 1, 0, [edge, z, 'hi'], [edge, z, 'lo']);
    }

    for (let j = 1; j < envelope.length; j++) {
        half.push({ y: envelope[j][0], z: envelope[j][1], part: topPart, copy: envelope[j][2] || null });
    }

    return { X, w, zt, zb, half, rim };
}

function keelDepth(ctx, x) {
    if (x <= ctx.stem) return 0;
    if (x < ctx.flatFrom) return ctx.draft * smoothstep((x - ctx.stem) / (ctx.flatFrom - ctx.stem));
    if (x <= ctx.flatTo) return ctx.draft;

    const t = (x - ctx.flatTo) / (1 - ctx.flatTo);

    return ctx.draft * (1 - t) * (1 - t);
}

/** Duong bao thuong tang: bac theo boong (chi doan kin), roi bo sieu elip len dinh. */
function envelopeOutline(ctx, X, w, zb, zt, rim) {
    const points = [[w, zb]];

    if (zt - zb < 1e-6) {
        return [[w, zb], [0, zt]];
    }

    for (const deck of ctx.decks) {
        const enclosed = deck.segments.some(([a, b]) => X >= a && X <= b);

        if (enclosed && deck.floor >= zb && deck.floor < zt - 0.5) {
            const last = points[points.length - 1];
            points.push([Math.min(last[0], Math.max(0, w - deck.setback)), Math.max(last[1], deck.floor)]);
        }
    }

    const [yk, zk] = points[points.length - 1];
    const topHalf = Math.max(0, Math.min(rim, yk - 0.1));
    const n = ctx.exponent;

    for (let k = 1; k <= CAP_SAMPLES; k++) {
        const a = (k / CAP_SAMPLES) * Math.PI / 2;
        points.push([topHalf + (yk - topHalf) * Math.pow(Math.cos(a), 2 / n), zk + (zt - zk) * Math.pow(Math.sin(a), 2 / n)]);
    }

    points[points.length - 1] = [topHalf, zt];

    if (topHalf > 0) points.push([0, zt]);

    return points;
}

function carve(stations, f, ctx) {
    const inside = (X, from, to) => X >= from - EDGE_EPS && X <= to + EDGE_EPS;
    const within = (p, lo, hi) => p.y >= lo - EDGE_EPS && p.y <= hi + EDGE_EPS
        && !(p.copy === 'hi' && Math.abs(p.y - hi) < EDGE_EPS) && !(p.copy === 'lo' && Math.abs(p.y - lo) < EDGE_EPS);
    const inRounded = (X, y) => {
        const r = Math.min(f.radius || 0, f.half);
        const dx = Math.max(f.from + r - X, X - (f.to - r), 0);
        const dy = Math.max(y - (f.half - r), 0);

        return inside(X, f.from, f.to) && y <= f.half + EDGE_EPS && dx * dx + dy * dy <= r * r + EDGE_EPS;
    };

    for (const s of stations) {
        const X = s.X;

        for (const p of s.half) {
            if (p.part === 'hull') continue;

            let z = p.z;

            if (f.op === 'pocket' && inside(X, f.from, f.to) && p.y < s.w - f.wall) {
                z = Math.min(z, f.floor);
            } else if ((f.op === 'basin' || f.op === 'cut') && inRounded(X, p.y) && within(p, -1, f.half)) {
                z = Math.min(z, f.floor);
            } else if (f.op === 'recess' && inRounded(X, p.y) && within(p, -1, f.half)) {
                z = z - f.depth;
            } else if (f.op === 'steps' && X >= 2 * f.X - ctx.loa - EDGE_EPS && Math.abs(p.y - f.y) <= f.stepWidth / 2) {
                z = Math.floor(z / f.tread) * f.tread;
            } else if (f.op === 'channel' && inside(X, f.from, f.to) && within(p, -1, f.half)) {
                const t = (X - f.from) / Math.max(1e-6, f.to - f.from);
                z = Math.min(z, f.fromZ + (f.toZ - f.fromZ) * t);
            } else if (f.op === 'groove' && p.part === f.of && inside(X, f.from, f.to) && within(p, f.lo, f.hi)) {
                z = z - f.depth;
            } else {
                continue;
            }

            if (z < p.z - 1e-6 || f.op === 'recess') {
                p.z = z;
                p.part = f.part;
                p.op = f.op;
            }
        }
    }
}

/** Loft cac mat cat thanh mot luoi; nhom tam giac theo bo phan (uu tien phep khoet). */
function loft(stations, ctx, features) {
    const ringSize = 2 * (stations[0].half.length - 1);
    const positions = new Float32Array((stations.length * ringSize + 1) * 3);
    const parts = new Array(stations.length * ringSize + 1);
    let v = 0;

    for (const s of stations) {
        for (const p of ring(s.half)) {
            positions.set(scene(s.X, p.y, p.z), v * 3);
            parts[v] = p.part;
            v++;
        }
    }

    const last = stations[stations.length - 1];
    positions.set(scene(last.X, 0, last.half.reduce((acc, p) => acc + p.z, 0) / last.half.length), v * 3);
    parts[v] = 'hull';

    const rank = new Map([...features.carving.map((f, i) => [f.part, i]), ['envelope', 1000], ['deck_closure', 1001], ['hull', 1002]]);
    const dominant = (list) => list.reduce((best, p) => ((rank.get(p) ?? 999) < (rank.get(best) ?? 999) ? p : best), list[0]);
    const groups = new Map();
    const add3 = (part, a, b, c) => {
        if (!groups.has(part)) groups.set(part, []);
        groups.get(part).push(a, b, c);
    };
    // BS khong co mat boong chinh: mat tren de ho (bo tam giac cua deck_closure).
    const open = !ctx.state.has('main_deck') && !ctx.state.has('superstructure');

    for (let i = 0; i < stations.length - 1; i++) {
        for (let k = 0; k < ringSize; k++) {
            const a = i * ringSize + k;
            const b = i * ringSize + ((k + 1) % ringSize);
            const c = a + ringSize;
            const d = b + ringSize;
            const part = dominant([parts[a], parts[b], parts[c], parts[d]]);

            if (open && part === 'deck_closure') continue;

            add3(part, a, c, b);
            add3(part, b, c, d);
        }
    }

    const base = (stations.length - 1) * ringSize;

    for (let k = 0; k < ringSize; k++) {
        add3('hull', base + k, base + ((k + 1) % ringSize), v);
    }

    return { positions, parts, groups: [...groups.entries()].map(([part, list]) => ({ part, indices: Uint32Array.from(list) })) };
}

function ring(half) {
    const starboard = half.map((p) => ({ y: -p.y, z: p.z, part: p.part })).reverse();

    return [...starboard, ...half.slice(1, -1)];
}

/** Tach luoi chung thanh mesh rieng tung bo phan (moi node GLB = mot bo phan). */
function splitByPart(mesh) {
    return mesh.groups.map((g) => {
        const map = new Map();
        const positions = [];
        const indices = new Uint32Array(g.indices.length);

        for (let t = 0; t < g.indices.length; t++) {
            const i = g.indices[t];

            if (!map.has(i)) {
                map.set(i, positions.length / 3);
                positions.push(mesh.positions[i * 3], mesh.positions[i * 3 + 1], mesh.positions[i * 3 + 2]);
            }

            indices[t] = map.get(i);
        }

        return finish({ name: g.part, part: g.part, positions: Float32Array.from(positions), indices });
    });
}

/** Them phap tuyen theo dien tich mat (tam giac quay nguoc chieu kim dong ho nhin tu ngoai). */
function finish(mesh) {
    const { positions, indices } = mesh;
    const normals = new Float32Array(positions.length);

    for (let t = 0; t < indices.length; t += 3) {
        const [a, b, c] = [indices[t], indices[t + 1], indices[t + 2]];
        const pa = [positions[a * 3], positions[a * 3 + 1], positions[a * 3 + 2]];
        const face = cross(sub([positions[b * 3], positions[b * 3 + 1], positions[b * 3 + 2]], pa), sub([positions[c * 3], positions[c * 3 + 1], positions[c * 3 + 2]], pa));

        for (const i of [a, b, c]) {
            normals[i * 3] += face[0];
            normals[i * 3 + 1] += face[1];
            normals[i * 3 + 2] += face[2];
        }
    }

    for (let i = 0; i < normals.length / 3; i++) {
        normals.set(normalize([normals[i * 3], normals[i * 3 + 1], normals[i * 3 + 2]]), i * 3);
    }

    return Object.assign(mesh, { normals });
}

/** Mesh tu danh sach tam giac roi (moi 9 so mot tam giac). */
function soup(part, list) {
    return finish({ name: part, part, positions: Float32Array.from(list), indices: Uint32Array.from({ length: list.length / 3 }, (_, k) => k) });
}

function surfaceSampler(stations) {
    const topOf = (s) => s.half.filter((p) => !(p.part === 'hull' && p.z < s.zb - 1e-6));
    const atStation = (s, y) => {
        const top = topOf(s);

        if (y > s.w + 1e-6) return null;

        for (let j = 0; j < top.length - 1; j++) {
            const [a, b] = [top[j], top[j + 1]];

            if ((a.y - y) * (b.y - y) <= 0 && Math.abs(a.y - b.y) > 1e-9) {
                const t = (a.y - y) / (a.y - b.y);

                return { z: a.z + (b.z - a.z) * t, part: t < 0.5 ? a.part : b.part };
            }
        }

        const last = top[top.length - 1];

        return { z: last.z, part: last.part };
    };
    const sample = (X, y) => {
        const { i, t } = locate(stations, X);
        const [a, b] = [atStation(stations[i], Math.abs(y)), atStation(stations[i + 1], Math.abs(y))];

        if (!a || !b) return null;

        return { z: a.z + (b.z - a.z) * t, part: t < 0.5 ? a.part : b.part };
    };

    return { z: (X, y) => sample(X, y)?.z ?? null, part: (X, y) => sample(X, y)?.part ?? null };
}

function cylinder(part, centre, axis, radius, height) {
    const n = normalize(axis);
    const helper = Math.abs(n[1]) < 0.9 ? UP : [1, 0, 0];
    const u = normalize(cross(n, helper));
    const w = cross(n, u);
    const positions = [];
    const indices = [];
    const top = add(centre, scale(n, height / 2));
    const bottom = sub(centre, scale(n, height / 2));

    for (let k = 0; k < CYLINDER_SEGMENTS; k++) {
        const a = (k / CYLINDER_SEGMENTS) * Math.PI * 2;
        const r = add(scale(u, Math.cos(a) * radius), scale(w, Math.sin(a) * radius));
        positions.push(...add(top, r), ...add(bottom, r));
    }

    positions.push(...top, ...bottom);
    const [tc, bc] = [CYLINDER_SEGMENTS * 2, CYLINDER_SEGMENTS * 2 + 1];

    for (let k = 0; k < CYLINDER_SEGMENTS; k++) {
        const [a, b] = [k * 2, ((k + 1) % CYLINDER_SEGMENTS) * 2];
        indices.push(a, b, a + 1, b, b + 1, a + 1, tc, b, a, bc, a + 1, b + 1);
    }

    return finish({ name: part, part, positions: Float32Array.from(positions), indices: Uint32Array.from(indices) });
}

/** Hop chu nhat truc toa do (scene) tu goc min/max. */
function box(part, min, max) {
    const [x0, y0, z0] = min;
    const [x1, y1, z1] = max;
    const c = [[x0, y0, z0], [x1, y0, z0], [x1, y1, z0], [x0, y1, z0], [x0, y0, z1], [x1, y0, z1], [x1, y1, z1], [x0, y1, z1]];
    const faces = [[0, 3, 2, 1], [4, 5, 6, 7], [0, 1, 5, 4], [2, 3, 7, 6], [1, 2, 6, 5], [0, 4, 7, 3]];
    const list = [];

    for (const [a, b, d, e] of faces) list.push(...c[a], ...c[b], ...c[d], ...c[a], ...c[d], ...c[e]);

    return soup(part, list);
}

function discMeshes(disc, surface) {
    const tilt = disc.tilt * Math.PI / 180;
    const axis = [Math.sin(tilt), Math.cos(tilt), 0];
    const centre = scene(disc.at.X, disc.at.y, disc.at.z);
    const base = surface.z(disc.at.X, disc.at.y);
    const bottomOfDisc = sub(centre, scale(axis, DISC_THICKNESS_M / 2));
    const foot = scene(disc.at.X, disc.at.y, base === null ? disc.at.z - 1 : base - 0.3);

    return [
        Object.assign(cylinder(disc.part, centre, axis, disc.diameter / 2, DISC_THICKNESS_M), { center: centre, landmark: disc.at.landmark }),
        Object.assign(cylinder(disc.part + ':pedestal', mid(foot, bottomOfDisc), normalize(sub(bottomOfDisc, foot)), disc.pedestal / 2, length(sub(bottomOfDisc, foot))), { owner: disc.part }),
    ];
}

/** Tam phang (vd. mat guong) tai moc: size_x doc tau, size_y ngang tau; mat tren o z moc - recess. */
function panelMeshes(p) {
    const f = faceBasis(p.facing, p.at.y, p.tilt);
    const centre = sub(scene(p.at.X, p.at.y, p.at.z), scale(f.n, p.recess));

    return [Object.assign(orientedBox(p.part, centre, f, p.sizeX / 2, p.sizeY / 2, PANEL_THICKNESS_M), { center: centre, normal: f.n, landmark: p.at.landmark })];
}

/**
 * He truc cua mot mat theo huong (scene): n phap tuyen ra ngoai, u = canh size_x / width, v = canh
 * size_y / height. up: n len, u doc tau, v ngang tau ve man cua moc; side: n ra man cua moc, u doc
 * tau, v len; forward / aft: n ve mui / duoi, u ngang tau, v len. tilt_deg xoay n va v quanh u
 * (mat nghieng ve phia v). Huong khac: loi, khong doan.
 */
export function faceBasis(facing, y, tiltDeg = 0) {
    const s = (y ?? 0) < 0 ? -1 : 1;
    const axes = {
        up: [[0, 1, 0], [1, 0, 0], [0, 0, s]],
        side: [[0, 0, s], [1, 0, 0], [0, 1, 0]],
        forward: [[-1, 0, 0], [0, 0, 1], [0, 1, 0]],
        aft: [[1, 0, 0], [0, 0, 1], [0, 1, 0]],
    }[facing];

    if (!axes) throw new Error('Hướng mặt ' + facing + ' không hỗ trợ.');

    const [n0, u, v0] = axes;
    const t = (tiltDeg || 0) * Math.PI / 180;

    return { n: add(scale(n0, Math.cos(t)), scale(v0, Math.sin(t))), u, v: sub(scale(v0, Math.cos(t)), scale(n0, Math.sin(t))) };
}

/** Hop dinh huong: mat ngoai qua `centre` vuong goc n, day `depth` lui vao trong; moi mat quay ra ngoai. */
function orientedBox(part, centre, f, hu, hv, depth) {
    const corner = (a, b, d) => add(centre, add(add(scale(f.u, a * hu), scale(f.v, b * hv)), scale(f.n, -d * depth)));
    const c = [corner(-1, -1, 0), corner(1, -1, 0), corner(1, 1, 0), corner(-1, 1, 0), corner(-1, -1, 1), corner(1, -1, 1), corner(1, 1, 1), corner(-1, 1, 1)];
    const mid3 = sub(centre, scale(f.n, depth / 2));
    const faces = [[0, 1, 2, 3], [4, 5, 6, 7], [0, 1, 5, 4], [1, 2, 6, 5], [2, 3, 7, 6], [3, 0, 4, 7]];
    const list = [];

    for (const [a, b, d, e] of faces) {
        const [pa, pb, pd, pe] = [c[a], c[b], c[d], c[e]];
        const out = dot(cross(sub(pb, pa), sub(pd, pa)), sub(scale(add(add(pa, pb), add(pd, pe)), 0.25), mid3)) >= 0;
        list.push(...(out ? [...pa, ...pb, ...pd, ...pa, ...pd, ...pe] : [...pa, ...pd, ...pb, ...pa, ...pe, ...pd]));
    }

    return soup(part, list);
}

/** Cau ngang khe: hop tu mep khe ben nay sang ben kia, mat tren o top_z. */
function bridgeMesh(b) {
    return Object.assign(box(b.part, [b.X - b.length / 2, b.top - b.thickness, -b.half], [b.X + b.length / 2, b.top, b.half]), { center: scene(b.X, 0, b.top) });
}

/**
 * Hoc mo (vd. hoc len tau, nap): khoet vo theo mieng hoc bam mat vo tai moc, dung thanh hoc va day
 * phang lui vao trong `depth` tinh tu tam mieng, quay theo `facing`: side = mat vo canh man, up =
 * mat vo phia tren, forward / aft = mat vo phia mui / duoi theo phuong doc tau. Khong tim duoc mat
 * vo -> bao thieu (khong dat bua).
 *
 * @returns {{meshes: object[], opening: ?object}} opening = vung khoet cho punch()
 */
function openingMeshes(o, stations, surface, ctx) {
    const f = faceBasis(o.facing, o.at.y, 0);
    const side = (o.at.y ?? 0) < 0 ? -1 : 1;
    // Chieu mot diem (scene) len mat vo theo phuong n cua mat.
    const onSurface = (p) => {
        if (o.facing === 'side') {
            const hit = lateralAt(stations, p[0], p[1]);

            return hit ? [p[0], p[1], hit.y * side] : null;
        }

        if (o.facing === 'up') {
            const z = surface.z(p[0], p[2]);

            return z === null ? null : [p[0], z, p[2]];
        }

        const dir = o.facing === 'forward' ? -1 : 1;
        const inside = (X) => X > 0 && X < ctx.loa && (surface.z(X, p[2]) ?? -Infinity) > p[1];
        let X = p[0];

        if (!inside(X)) {
            X -= dir * 3;

            if (!inside(X)) return null;
        }

        while (inside(X)) X += dir * 0.05;

        return [X, p[1], p[2]];
    };
    const nominal = onSurface(scene(o.at.X, o.at.y ?? 0, o.at.z));

    if (nominal === null) {
        ctx.missing.push(o.part + ': không tìm được mặt vỏ tại mốc ' + o.at.landmark + ' theo hướng ' + o.facing);

        return { meshes: [], opening: null };
    }

    const SAMPLES = 4;
    const perimeter = [];

    for (const [a0, b0, a1, b1] of [[-1, -1, 1, -1], [1, -1, 1, 1], [1, 1, -1, 1], [-1, 1, -1, -1]]) {
        for (let k = 0; k < SAMPLES; k++) {
            const t = k / SAMPLES;
            perimeter.push([(a0 + (a1 - a0) * t) * o.width / 2, (b0 + (b1 - b0) * t) * o.height / 2]);
        }
    }

    const plane = (q, origin) => add(origin, add(scale(f.u, q[0]), scale(f.v, q[1])));
    const mouthRing = perimeter.map((q) => onSurface(plane(q, nominal)));

    if (mouthRing.some((p) => p === null)) {
        ctx.missing.push(o.part + ': miệng hốc tại mốc ' + o.at.landmark + ' không bám được mặt vỏ');

        return { meshes: [], opening: null };
    }

    const floorCentre = sub(nominal, scale(f.n, o.depth));
    const floorRing = perimeter.map((q) => plane(q, floorCentre));
    const tris = [];
    const push = (a, b, c, outward) => {
        const ok = dot(cross(sub(b, a), sub(c, a)), outward) >= 0;
        tris.push(...a, ...(ok ? b : c), ...(ok ? c : b));
    };

    for (let k = 0; k < perimeter.length; k++) {
        const j = (k + 1) % perimeter.length;
        const [a, b, c, d] = [mouthRing[k], mouthRing[j], floorRing[k], floorRing[j]];
        // Thanh hoc quay vao truc hoc (nhin tu mieng hoc vao thay mat trong).
        const axis = (p) => sub(add(floorCentre, scale(f.n, dot(sub(p, floorCentre), f.n))), p);
        push(a, b, c, axis(scale(add(add(a, b), c), 1 / 3)));
        push(b, d, c, axis(scale(add(add(b, d), c), 1 / 3)));
        push(floorCentre, floorRing[k], floorRing[j], f.n);
    }

    const n = f.n;
    const span = mouthRing.map((p) => dot(sub(p, nominal), n));
    const [dmin, dmax] = [Math.min(...span) - 0.5, Math.max(...span) + 0.5];
    const project = (p) => {
        const rel = sub(p, nominal);
        const d = dot(rel, n);

        return d < dmin || d > dmax ? null : [dot(rel, f.u), dot(rel, f.v)];
    };
    const bb = bounds(mouthRing);
    const mesh = Object.assign(soup(o.part, tris), { center: floorCentre, normal: f.n, mouth: nominal, landmark: o.at.landmark, opening: true });

    return { meshes: [mesh], opening: { project, outline: mouthRing.map(project), min: sub(bb.min, [0.5, 0.5, 0.5]), max: add(bb.max, [0.5, 0.5, 0.5]) } };
}

/** Ban sao mesh da nhan ti le cua build state (BS05 1:25): cung kich thuoc vat ly voi GLB. */
export function stateMeshes(out) {
    const s = out.state.scale;

    return s === 1 ? out.meshes : out.meshes.map((m) => ({ ...m, positions: Float32Array.from(m.positions, (v) => v * s) }));
}

/** Keel blocks: khoi duoi day tau doc duong ky; khi co vo thi nam trong vo (khong doi silhouette). */
function keelBlockMeshes(k, ctx) {
    const from = k.from ?? ctx.loa * ctx.flatFrom;
    const to = k.to ?? ctx.loa * ctx.flatTo;
    const out = [];
    const bottom = -ctx.draft + 0.05;

    for (let X = from; X <= to + 1e-6; X += k.spacing) {
        out.push(box(k.part, [X - 0.6, bottom, -k.width / 2], [X + 0.6, bottom + k.height, k.width / 2]));
    }

    return out.length ? [merge(k.part, out)] : [];
}

/** Gian giao: tam mong hai man cach vo `offset`, cao tu duong nuoc len `height`. */
function scaffoldMeshes(sc, ctx) {
    const from = sc.from ?? ctx.loa * 0.05;
    const to = sc.to ?? ctx.loa * 0.95;
    const y = Math.max(...ctx.port.map((p) => p[1])) + sc.offset;

    return [merge(sc.part, [1, -1].map((side) => box(sc.part, [from, 0, side * y - SCAFFOLD_SLAB_M / 2], [to, sc.height, side * y + SCAFFOLD_SLAB_M / 2])))];
}

function merge(part, meshes) {
    const positions = [];
    const indices = [];

    for (const m of meshes) {
        const base = positions.length / 3;
        positions.push(...m.positions);
        indices.push(...Array.from(m.indices, (i) => i + base));
    }

    return finish({ name: part, part, positions: Float32Array.from(positions), indices: Uint32Array.from(indices) });
}

/**
 * Gan ribs (vd. N2 Ripple Shell) la hinh hoc noi that, khong phai normal map: ho duong chu U long
 * nhau tren mat thuong tang, duong thu j o a = j × pitch. Canh doc tau o |y| = a phia truoc, bo
 * cung ban kinh min(half_turn / 2, a) tai duong cheo X − turn_x = |y| − turn_y (qua moc doi huong),
 * roi chay ngang tau o X = turn_x − turn_y + a ve truc giua — doc phia truoc, ngang phia sau (G4).
 * Moi duong la dai tiet dien chu nhat rong pitch / 2, noi `depth` theo phap tuyen mat; chi bam mat
 * thuong tang (envelope) trong from_x..to_x, khong phu len phan khoet. Do lai tren chinh mesh.
 */
function ribMeshes(rib, surface, ctx) {
    const k = rib.halfTurn / 2;
    const hw = rib.pitch / 4;
    const reach = Math.max(...ctx.port.map((p) => p[1]), rib.to - rib.turn + rib.turnY);
    const runs = [];

    for (let j = 0; j * rib.pitch <= reach + 1e-9; j++) {
        let run = [];
        const flush = () => {
            if (run.length > 1) runs.push(run);
            run = [];
        };

        for (const [X, y] of ribPath(rib, j * rib.pitch)) {
            // Bo lay mau mat kep X ngoai than tau ve tram dau / cuoi: tu loai phan ngoai [0, LOA].
            const inside = X >= Math.max(0, rib.from) - EDGE_EPS && X <= Math.min(ctx.loa, rib.to) + EDGE_EPS;
            const z = inside && surface.part(X, y) === 'envelope' ? surface.z(X, y) : null;

            if (z === null) {
                flush();
                continue;
            }

            run.push({ X, y, z, d: X - rib.turn - (Math.abs(y) - rib.turnY) });
        }

        flush();
    }

    const positions = [];
    const indices = [];
    const rings = [];
    const toScene = (v) => [v[0], v[2], v[1]];

    for (const run of runs) {
        const first = positions.length / 3;

        run.forEach((p, i) => {
            const n = surfaceNormal(surface, p.X, p.y);
            const [a, b] = [run[Math.max(0, i - 1)], run[Math.min(run.length - 1, i + 1)]];
            const t = normalize([b.X - a.X, b.y - a.y, b.z - a.z]);
            const side = normalize(cross(t, n));
            const up = normalize(cross(side, t));
            const at = (s, h) => toScene(add(add([p.X, p.y, p.z], scale(side, s * hw)), scale(up, h)));
            rings.push(positions.length / 3);
            positions.push(...at(-1, -RIB_SINK_M), ...at(1, -RIB_SINK_M), ...at(1, rib.depth), ...at(-1, rib.depth));
        });

        const v = (i, c) => first + i * 4 + c;
        const centre = (i) => scale([0, 1, 2, 3].reduce((acc, c) => add(acc, vertex(positions, v(i, c))), [0, 0, 0]), 0.25);

        for (let i = 0; i < run.length - 1; i++) {
            const inside = mid(centre(i), centre(i + 1));
            outwardQuad(indices, positions, [v(i, 3), v(i, 2), v(i + 1, 2), v(i + 1, 3)], inside);
            outwardQuad(indices, positions, [v(i, 1), v(i + 1, 1), v(i + 1, 2), v(i, 2)], inside);
            outwardQuad(indices, positions, [v(i, 0), v(i, 3), v(i + 1, 3), v(i + 1, 0)], inside);
        }

        outwardQuad(indices, positions, [v(0, 0), v(0, 1), v(0, 2), v(0, 3)], centre(1));
        outwardQuad(indices, positions, [v(run.length - 1, 0), v(run.length - 1, 1), v(run.length - 1, 2), v(run.length - 1, 3)], centre(run.length - 2));
    }

    const mesh = indices.length ? finish({ name: rib.part, part: rib.part, positions: Float32Array.from(positions), indices: Uint32Array.from(indices) }) : null;
    const base = (r) => mid(vertex(mesh.positions, r), vertex(mesh.positions, r + 1));
    const top = (r) => mid(vertex(mesh.positions, r + 2), vertex(mesh.positions, r + 3));
    let pitch = NaN;

    if (mesh) {
        const probe = (rib.from + Math.min(rib.to, rib.turn - rib.turnY)) / 2;
        const ahead = rings.map(base).filter((c) => Math.abs(c[0] - probe) <= RIB_STEP_M / 2 + EDGE_EPS && probe - rib.from > RIB_STEP_M);
        // Khong co vung doc phia truoc: do buoc cac duong ngang phia sau, sat truc giua.
        pitch = ahead.length > 1
            ? spacing(ahead.map((c) => c[2]), hw)
            : spacing(rings.map(base).filter((c) => Math.abs(c[2]) <= RIB_STEP_M / 2 && c[0] - rib.turn - (Math.abs(c[2]) - rib.turnY) > k).map((c) => c[0]), hw);
    }

    const zones = { forward: [0, 0], aft: [0, 0] };
    const [along, across] = [ctx.tolerances.rib_longitudinal_deg, ctx.tolerances.rib_transverse_deg];

    for (const run of runs) {
        for (let i = 0; i < run.length - 1; i++) {
            const [a, b] = [run[i], run[i + 1]];
            const deg = Math.atan2(Math.abs(b.y - a.y), Math.abs(b.X - a.X)) * 180 / Math.PI;
            const d = (a.d + b.d) / 2;

            if (d < -k) zones.forward = [zones.forward[0] + (deg <= along ? 1 : 0), zones.forward[1] + 1];
            if (d > k) zones.aft = [zones.aft[0] + (deg >= across ? 1 : 0), zones.aft[1] + 1];
        }
    }

    return {
        kind: 'ribs',
        part: rib.part,
        design: { pitch: rib.pitch, depth: rib.depth },
        mesh,
        measured: mesh ? { pitch, depth: median(rings.map((r) => length(sub(top(r), base(r))))) - RIB_SINK_M, lines: runs.length, ...zones } : null,
    };
}

/** Duong gan thu a (mat bang X, y): canh man trai -> cung -> ngang -> cung -> canh man phai. */
function ribPath(rib, a) {
    const X0 = rib.turn - rib.turnY + a;
    const r = Math.min(rib.halfTurn / 2, a);
    const points = [];
    const line = (p, q) => {
        const n = Math.max(1, Math.ceil(Math.hypot(q[0] - p[0], q[1] - p[1]) / RIB_STEP_M));

        for (let i = points.length ? 1 : 0; i <= n; i++) points.push([p[0] + (q[0] - p[0]) * i / n, p[1] + (q[1] - p[1]) * i / n]);
    };
    const arc = (s, reverse) => {
        const n = Math.max(2, Math.ceil(r * Math.PI / 2 / (RIB_STEP_M / 2)));

        for (let i = 1; i <= n; i++) {
            const theta = (reverse ? i / n : 1 - i / n) * Math.PI / 2;
            points.push([X0 - r + r * Math.cos(theta), s * (a - r + r * Math.sin(theta))]);
        }
    };

    if (a === 0) {
        line([rib.from, 0], [X0, 0]);

        return points;
    }

    line([Math.min(rib.from, X0 - r), a], [X0 - r, a]);
    if (r > 0) arc(1, false);
    line([X0, a - r], [X0, -(a - r)]);
    if (r > 0) arc(-1, true);
    line([X0 - r, -a], [Math.min(rib.from, X0 - r), -a]);

    return points;
}

/**
 * Gan relief (vd. soc hai vach khe N3) la hinh hoc noi: vay dung tren hai vach cua phep cut `of`,
 * chay theo chieu cao vach (san -> mep tren), cach nhau `pitch` doc tau, rong pitch / 2, nho vao
 * long khe `depth`. Chi ho tro vach cut (thang dung tai |y| = nua be rong khe); khac -> bao thieu.
 */
function reliefMeshes(relief, carving, surface, ctx) {
    const result = { kind: 'relief', part: relief.part, design: { pitch: relief.pitch, depth: relief.depth }, mesh: null, measured: null };
    const cut = carving.find((f) => f.part === relief.of && f.op === 'cut');

    if (!cut) {
        ctx.missing.push(relief.part + ': relief chỉ dựng được trên vách phép cut (' + relief.of + ' không phải cut có mặt)');

        return result;
    }

    const r = Math.min(cut.radius || 0, cut.half);
    const count = Math.floor((cut.to - cut.from - 2 * r) / relief.pitch);
    const start = (cut.from + cut.to) / 2 - (count - 1) * relief.pitch / 2;
    const fins = { 1: [], [-1]: [] };

    for (let i = 0; i < count; i++) {
        const X = start + i * relief.pitch;

        for (const s of [1, -1]) {
            // Mep tren vach: mat vo ngay ngoai khe.
            const top = surface.z(X, cut.half + RELIEF_RIM_M);

            if (top === null || top - cut.floor < 2 * relief.depth) continue;

            const f = { n: [0, 0, -s], u: [1, 0, 0], v: [0, 1, 0] };
            fins[s].push(orientedBox(relief.part, scene(X, s * (cut.half - relief.depth), (cut.floor + top) / 2), f, relief.pitch / 4, (top - cut.floor) / 2, relief.depth + RIB_SINK_M));
        }
    }

    const all = [...fins[1], ...fins[-1]];

    if (!all.length) return result;

    const centreX = (m) => median(Array.from({ length: m.positions.length / 3 }, (_, i) => m.positions[i * 3]));
    const inner = (m) => cut.half - Math.min(...Array.from({ length: m.positions.length / 3 }, (_, i) => Math.abs(m.positions[i * 3 + 2])));
    const sides = [1, -1].map((s) => (fins[s].length > 1 ? spacing(fins[s].map(centreX), relief.pitch / 4) : NaN));

    return Object.assign(result, {
        mesh: merge(relief.part, all),
        measured: { pitch: sides, depth: median(all.map(inner)), fins: [fins[1].length, fins[-1].length] },
    });
}

/** Phap tuyen mat tren (he spec) bang sai phan; khong lay mau duoc -> thang dung. */
function surfaceNormal(surface, X, y) {
    const h = 0.05;
    const [x1, x2, y1, y2] = [surface.z(X + h, y), surface.z(X - h, y), surface.z(X, y + h), surface.z(X, y - h)];

    if ([x1, x2, y1, y2].some((v) => v === null)) return [0, 0, 1];

    return normalize([-(x1 - x2) / (2 * h), -(y1 - y2) / (2 * h), 1]);
}

/** Them tu giac (2 tam giac), dao chieu neu mat quay vao diem `inside`. */
function outwardQuad(indices, positions, quad, inside) {
    const [p, q, r] = quad.slice(0, 3).map((i) => vertex(positions, i));
    const centre = scale(quad.reduce((acc, i) => add(acc, vertex(positions, i)), [0, 0, 0]), 0.25);
    const [a, b, c, d] = dot(cross(sub(q, p), sub(r, p)), sub(centre, inside)) >= 0 ? quad : [...quad].reverse();

    indices.push(a, b, c, a, c, d);
}

function vertex(positions, i) { return [positions[i * 3], positions[i * 3 + 1], positions[i * 3 + 2]]; }

/** Buoc trung vi giua cac cum gia tri (gia tri cach nhau < gap la cung mot cum). */
function spacing(values, gap) {
    const sorted = [...values].sort((a, b) => a - b);
    const groups = [];

    for (const v of sorted) {
        const last = groups[groups.length - 1];

        if (last && v - last[last.length - 1] < gap) last.push(v);
        else groups.push([v]);
    }

    const centres = groups.map((g) => g.reduce((s, v) => s + v, 0) / g.length);

    return median(centres.slice(1).map((c, i) => c - centres[i]));
}

function median(list) {
    if (!list.length) return NaN;

    const sorted = [...list].sort((a, b) => a - b);
    const m = sorted.length >> 1;

    return sorted.length % 2 ? sorted[m] : (sorted[m - 1] + sorted[m]) / 2;
}

function lateralAt(stations, X, z) {
    const { i, t } = locate(stations, X);
    const [a, b] = [lateralHit(stations[i], z), lateralHit(stations[i + 1], z)];

    if (!a || !b) return null;

    const [ny, nz] = [a.ny + (b.ny - a.ny) * t, a.nz + (b.nz - a.nz) * t];
    const l = Math.hypot(ny, nz) || 1;

    return { y: a.y + (b.y - a.y) * t, ny: ny / l, nz: nz / l, part: t < 0.5 ? a.part : b.part };
}

function lateralHit(station, z) {
    let best = null;

    for (let j = 0; j < station.half.length - 1; j++) {
        const [a, b] = [station.half[j], station.half[j + 1]];

        if ((a.z - z) * (b.z - z) > 0 || Math.abs(b.z - a.z) < 1e-9) continue;

        const t = (z - a.z) / (b.z - a.z);
        const y = a.y + (b.y - a.y) * t;

        if (best === null || y > best.y) {
            const [dy, dz] = [b.y - a.y, b.z - a.z];
            const l = Math.hypot(dy, dz) || 1;
            best = { y, ny: dz / l, nz: -dy / l, part: a.part === 'hull' ? b.part : a.part };
        }
    }

    return best;
}

/**
 * Cua kinh: dat tai moc (instances) hoac theo dai boong (deck, from_x..to_x, spacing|count, sides).
 * Cua co moc: kinh dat dung moc, `depth` la do lom toi da (sau hon la mau thuan, khong dung).
 * Cua theo dai: kinh lui sau mieng hoc dung `depth`.
 */
function buildWindows(list, ctx, stations, surface) {
    const groups = { glass: [], clear: [], reveal: [], cavity: [] };
    const results = [];
    const openings = [];
    const out = [];

    for (const item of list) {
        const p = item.part;
        const v = (name) => num(p, name);
        const w = { width: v('width'), height: v('height'), depth: v('depth'), tilt: v('tilt_deg') ?? 0, facets: v('facets') ?? 1, facetStep: v('facet_step') ?? 0, spread: v('spread') ?? 0, spacing: v('spacing') ?? null, count: v('count') ?? null, stagger: v('stagger') ?? 0, shape: p.shape || 'rect', glass: p.glass || 'smoke' };
        const centres = [];
        const placed = [];
        const failed = [];
        let conflict = null;

        if (p.instances.length && p.facing === 'forward') {
            for (const i of p.instances) {
                for (let k = 0; k < w.facets; k++) {
                    centres.push({ X: i.X, z: i.z, y: (i.y ?? 0) + (w.facets > 1 ? -w.spread / 2 + k * w.spread / (w.facets - 1) : 0), forward: true, landmark: i.landmark });
                }
            }
        } else if (p.instances.length) {
            for (const i of p.instances) {
                for (let k = 0; k < w.facets; k++) {
                    centres.push({ X: i.X + (k - (w.facets - 1) / 2) * w.facetStep, z: i.z, side: i.y < 0 ? 'starboard' : 'port', landmarkY: Math.abs(i.y), landmark: i.landmark });
                }
            }
        } else {
            const deck = ctx.decks.find((d) => d.name === p.deck);
            const [x0, x1] = [v('from_x'), v('to_x')];

            if (!deck) {
                ctx.missing.push(p.id + ': không có boong ' + p.deck);
            } else {
                const n = w.count !== null ? Math.max(1, Math.round(w.count)) : Math.max(1, Math.floor((x1 - x0) / w.spacing));
                const gap = (x1 - x0) / n;
                const zc = (deck.floor + deck.ceiling) / 2;

                for (const side of p.sides) {
                    for (let k = 0; k < n; k++) {
                        centres.push({ X: x0 + gap * (k + 0.5), z: zc + (w.stagger ? (k % 2 ? -w.stagger : w.stagger) : 0), side, deck });
                    }
                }
            }
        }

        const outline = (grow) => panelOutline(w.shape, w.width + grow, w.height + grow, w.tilt);
        const flat = outline(0);

        for (const c of centres) {
            let pane;

            if (c.forward) {
                const exit = (y, z) => {
                    if ((surface.z(c.X, y) ?? -Infinity) <= z) return c.X;

                    let X = c.X;

                    while (X > 0 && (surface.z(X, y) ?? -Infinity) > z) X -= 0.25;

                    let [lo, hi] = [Math.max(0, X), Math.min(c.X, X + 0.25)];

                    for (let k = 0; k < 20; k++) {
                        const m = (lo + hi) / 2;

                        if ((surface.z(m, y) ?? -Infinity) > z) hi = m;
                        else lo = m;
                    }

                    return (lo + hi) / 2;
                };
                const mouthX = exit(c.y, c.z);

                if (c.X - mouthX > w.depth) {
                    conflict = { depth: round(c.X - mouthX, 2), surfaceX: round(mouthX, 2) };
                    continue;
                }

                const design = scene(c.X, c.y, c.z);
                const frame = panelBasis(FORWARD, PORT);
                const at = (q) => add(design, add(scale(frame.right, q[0]), scale(frame.up, q[1])));
                const onSurface = (q) => {
                    const pt = at(q);

                    return { point: [exit(pt[2], pt[1]), pt[1], pt[2]], normal: FORWARD };
                };
                pane = { design, frame, mouthCentre: scene(mouthX, c.y, c.z), mouth: flat.map(onSurface), rim: outline(2 * FRAME_M).map(onSurface), glass: flat.map(at), inward: [1, 0, 0] };
            } else {
                const s = c.side === 'starboard' ? -1 : 1;
                const hit = lateralAt(stations, c.X, c.z);

                if (!hit) {
                    failed.push({ side: c.side, reason: 'không có mặt vỏ tại tâm cửa' });
                    continue;
                }

                const mouthCentre = scene(c.X, hit.y * s, c.z);
                const frame = panelBasis([0, hit.nz, hit.ny * s], [1, 0, 0]);
                const onSurface = (q) => {
                    const pt = add(mouthCentre, add(scale(frame.right, q[0]), scale(frame.up, q[1])));
                    const local = lateralAt(stations, pt[0], pt[1]);

                    return local ? { point: scene(pt[0], local.y * s, pt[1]), part: local.part, normal: [0, local.nz, local.ny * s] } : null;
                };
                const mouth = flat.map(onSurface);
                const inward = [0, 0, -s];
                const design = c.landmarkY !== undefined ? scene(c.X, c.landmarkY * s, c.z) : null;

                if (design && hit.y - c.landmarkY > w.depth) {
                    conflict = { depth: round(hit.y - c.landmarkY, 2), surfaceX: round(c.X, 2) };
                    continue;
                }

                const glass = design
                    ? flat.map((q) => add(design, add(scale(frame.right, q[0]), scale(frame.up, q[1]))))
                    : mouth.map((r) => (r ? add(r.point, scale(inward, w.depth)) : null));
                pane = { design, frame, mouthCentre, mouth, rim: outline(2 * FRAME_M).map(onSurface), glass, inward, side: c.side };
            }

            if (pane.mouth.some((r) => r === null) || pane.rim.some((r) => r === null) || pane.glass.some((pt) => pt === null)) {
                failed.push({ side: c.side || 'forward', reason: 'mép cửa không bám được mặt vỏ' });
                continue;
            }

            const lift = (r) => add(r.point, scale(normalize(r.normal), FRAME_LIFT_M));
            const mouthLift = pane.mouth.map(lift);
            const rimLift = pane.rim.map(lift);
            const glassCentre = centroid(pane.glass);
            const cavity = pane.glass.map((pt) => add(pt, scale(pane.inward, CAVITY_M)));
            band(groups.reveal, rimLift, mouthLift);
            band(groups.reveal, mouthLift, pane.glass);
            fan(w.glass === 'clear' ? groups.clear : groups.glass, pane.glass);
            band(groups.cavity, pane.glass, cavity);
            fan(groups.cavity, cavity);

            const solid = [...pane.glass, ...pane.rim.map((r) => r.point)];
            const extents = { xmin: Math.min(...solid.map((pt) => pt[0])), xmax: Math.max(...solid.map((pt) => pt[0])), zmin: Math.min(...solid.map((pt) => pt[1])), zmax: Math.max(...solid.map((pt) => pt[1])) };
            const cuts = [...new Set(pane.mouth.concat(pane.rim).map((r) => r.part).filter((part) => part && part !== 'hull' && part !== 'envelope' && part !== 'deck_closure'))];
            placed.push({ side: c.side || 'forward', glass: glassCentre, design: pane.design, landmark: c.landmark || null, deviation: pane.design ? length(sub(glassCentre, pane.design)) : null, extents, deck: c.deck || null, failures: cuts.map((part) => 'cắt vào ' + part) });

            const n = pane.frame.normal;
            const span = pane.mouth.concat(pane.rim).map((r) => dot(sub(r.point, pane.mouthCentre), n));
            const [dmin, dmax] = [Math.min(...span) - 0.5, Math.max(...span) + 0.5];
            const project = (pt) => {
                const rel = sub(pt, pane.mouthCentre);
                const d = dot(rel, n);

                return d < dmin || d > dmax ? null : [dot(rel, pane.frame.right), dot(rel, pane.frame.up)];
            };
            const bb = bounds(pane.mouth.concat(pane.rim).map((r) => r.point));
            openings.push({ project, outline: pane.mouth.map((r) => project(r.point)), min: sub(bb.min, [0.5, 0.5, 0.5]), max: add(bb.max, [0.5, 0.5, 0.5]) });
        }

        results.push({ id: p.id, expected: centres.length, placed, failed, conflict, maxDepth: w.depth, landmark: p.instances.length > 0, deck: p.deck || null, from: v('from_x') ?? null, to: v('to_x') ?? null });

        for (const [key, list] of Object.entries(groups)) {
            if (list.length) out.push(Object.assign(soup(p.id + ':' + key, list.splice(0)), { owner: p.id }));
        }
    }

    return { meshes: out, results, openings };
}

function panelBasis(normal, along) {
    const n = normalize(normal);
    let up = normalize(cross(n, along));

    if (up[1] < 0) up = scale(up, -1);

    return { normal: n, up, right: normalize(cross(up, n)) };
}

function band(target, a, b) {
    for (let k = 0; k < a.length; k++) {
        const j = (k + 1) % a.length;
        target.push(...a[k], ...a[j], ...b[k], ...a[j], ...b[j], ...b[k]);
    }
}

function fan(target, ringPoints) {
    const c = centroid(ringPoints);

    for (let k = 0; k < ringPoints.length; k++) target.push(...c, ...ringPoints[k], ...ringPoints[(k + 1) % ringPoints.length]);
}

function centroid(points) {
    return scale(points.reduce((acc, p) => add(acc, p), [0, 0, 0]), 1 / points.length);
}

/** Duc lo mieng cua tren luoi vo (chia nho tam giac o mep). */
function punch(mesh, openings) {
    if (!openings.length) return;

    const positions = Array.from(mesh.positions);
    const parts = mesh.parts.slice();
    const cache = new Map();
    const at = (i) => [positions[i * 3], positions[i * 3 + 1], positions[i * 3 + 2]];
    const middle = (a, b, part) => {
        const key = a < b ? a + '_' + b : b + '_' + a;

        if (!cache.has(key)) {
            positions.push(...mid(at(a), at(b)));
            parts.push(part);
            cache.set(key, parts.length - 1);
        }

        return cache.get(key);
    };
    const classify = (tri, o) => {
        const q = tri.map((i) => o.project(at(i)));

        if (q.some((p) => p === null)) return 'out';

        const inside = q.filter((p) => inPolygon(p, o.outline)).length;

        if (inside === 3) return 'in';
        if (inside > 0) return 'edge';

        return o.outline.some((p) => inTriangle(p, q)) || crosses(q, o.outline) ? 'edge' : 'out';
    };
    const split = (tri, candidates, part, out) => {
        let edge = false;

        for (const o of candidates) {
            const verdict = classify(tri, o);

            if (verdict === 'in') return;
            if (verdict === 'edge') edge = true;
        }

        if (!edge) {
            out.push(...tri);

            return;
        }

        const [a, b, c] = tri;

        if (Math.max(length(sub(at(a), at(b))), length(sub(at(b), at(c))), length(sub(at(c), at(a)))) <= OPENING_LEAF_M) return;

        const [ab, bc, ca] = [middle(a, b, part), middle(b, c, part), middle(c, a, part)];

        for (const child of [[a, ab, ca], [ab, b, bc], [ca, bc, c], [ab, bc, ca]]) split(child, candidates, part, out);
    };

    mesh.groups = mesh.groups.map((g) => {
        const out = [];

        for (let t = 0; t < g.indices.length; t += 3) {
            const tri = [g.indices[t], g.indices[t + 1], g.indices[t + 2]];
            const bb = bounds(tri.map(at));
            const candidates = openings.filter((o) => [0, 1, 2].every((k) => bb.min[k] <= o.max[k] && bb.max[k] >= o.min[k]));

            if (candidates.length) split(tri, candidates, g.part, out);
            else out.push(...tri);
        }

        return { part: g.part, indices: Uint32Array.from(out) };
    });
    mesh.positions = Float32Array.from(positions);
    mesh.parts = parts;
}

function inPolygon(p, polygon) {
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [a, b] = [polygon[i], polygon[j]];

        if ((a[1] > p[1]) !== (b[1] > p[1]) && p[0] < (b[0] - a[0]) * (p[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
    }

    return inside;
}

function inTriangle(p, tri) {
    const side = (a, b) => (b[0] - a[0]) * (p[1] - a[1]) - (b[1] - a[1]) * (p[0] - a[0]);
    const [d1, d2, d3] = [side(tri[0], tri[1]), side(tri[1], tri[2]), side(tri[2], tri[0])];

    return !((d1 < 0 || d2 < 0 || d3 < 0) && (d1 > 0 || d2 > 0 || d3 > 0));
}

function crosses(tri, polygon) {
    const orient = (a, b, c) => Math.sign((b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]));

    for (let i = 0; i < 3; i++) {
        const [a, b] = [tri[i], tri[(i + 1) % 3]];

        for (let j = 0; j < polygon.length; j++) {
            const [c, d] = [polygon[j], polygon[(j + 1) % polygon.length]];

            if (orient(a, b, c) * orient(a, b, d) < 0 && orient(c, d, a) * orient(c, d, b) < 0) return true;
        }
    }

    return false;
}

function panelOutline(shape, width, height, tilt) {
    if (shape === 'oval') {
        return Array.from({ length: 24 }, (_, k) => [Math.cos(k / 24 * Math.PI * 2) * width / 2, Math.sin(k / 24 * Math.PI * 2) * height / 2]);
    }

    const shift = shape === 'oblique' ? height * Math.tan(tilt * Math.PI / 180) / 2 : 0;

    return [[-width / 2 - shift, -height / 2], [width / 2 - shift, -height / 2], [width / 2 + shift, height / 2], [-width / 2 + shift, height / 2]];
}

/** Chi tiet bam vo: lan can, go mieng khe, vien mep terrace, ray ranh truot. Do tren mesh. */
function buildDetails(list, carving, ctx, surface, hull) {
    const meshes = [];

    for (const d of list) {
        const v = (name) => num(d.part, name);
        const tris = [];
        const quad = (a, b, c, e) => tris.push(...a, ...b, ...c, ...b, ...e, ...c);
        const span = (from, to) => {
            const xs = [];

            for (let X = from; X < to; X += STATION_STEP_M) xs.push(X);
            xs.push(to);

            return xs;
        };
        const strip = (from, to, inner, outer) => {
            const xs = span(from, to);

            for (const s of [1, -1]) {
                for (let k = 0; k < xs.length - 1; k++) {
                    const middle = (X) => (inner(X) + outer(X)) / 2;
                    const corner = (X, y) => {
                        const z = surface.z(X, middle(X));

                        return z === null ? null : scene(X, y * s, z + FLUSH_LIFT_M);
                    };
                    const pts = [corner(xs[k], inner(xs[k])), corner(xs[k], outer(xs[k])), corner(xs[k + 1], inner(xs[k + 1])), corner(xs[k + 1], outer(xs[k + 1]))];

                    if (pts.every(Boolean)) quad(...pts);
                }
            }
        };

        if (d.type === 'bulwark') {
            const xs = span(v('from_x'), v('to_x'));
            const [height, thickness] = [v('height'), v('thickness')];

            for (const s of [1, -1]) {
                const at = (X) => {
                    const [w, z] = [ctx.width(X), ctx.sideTop(X)];

                    return { outer: scene(X, w * s, z), outerTop: scene(X, w * s, z + height), inner: scene(X, (w - thickness) * s, z), innerTop: scene(X, (w - thickness) * s, z + height) };
                };

                for (let k = 0; k < xs.length - 1; k++) {
                    const [a, b] = [at(xs[k]), at(xs[k + 1])];
                    quad(a.outer, a.outerTop, b.outer, b.outerTop);
                    quad(a.inner, a.innerTop, b.inner, b.innerTop);
                    quad(a.outerTop, a.innerTop, b.outerTop, b.innerTop);
                }
            }
        } else if (d.type === 'coaming') {
            const slot = carving.find((f) => (f.op === 'recess' || f.op === 'cut') && f.part === d.of?.id);

            if (!slot) {
                ctx.missing.push(d.id + ': không có khe ' + d.part.of);
                continue;
            }

            const [rimW, height] = [v('rim'), v('height')];
            const half = slot.half;
            const r = Math.min(slot.radius || 0, half);
            const offset = rimW / 2;
            const R = r + offset;
            const path = [];
            const line = (x0, y0, x1, y1, nX, ny) => {
                const n = Math.max(1, Math.ceil(Math.hypot(x1 - x0, y1 - y0) / 0.25));

                for (let k = 0; k < n; k++) path.push({ X: x0 + (x1 - x0) * k / n, y: y0 + (y1 - y0) * k / n, nX, ny });
            };
            const arc = (cx, cy, from) => {
                const n = Math.max(2, Math.ceil(R * Math.PI / 2 / 0.25));

                for (let k = 0; k < n; k++) {
                    const a = (from + 90 * k / n) * Math.PI / 180;
                    path.push({ X: cx + R * Math.cos(a), y: cy + R * Math.sin(a), nX: Math.cos(a), ny: Math.sin(a) });
                }
            };
            line(slot.to + offset, -(half - r), slot.to + offset, half - r, 1, 0);
            arc(slot.to - r, half - r, 0);
            line(slot.to - r, half + offset, slot.from + r, half + offset, 0, 1);
            arc(slot.from + r, half - r, 90);
            line(slot.from - offset, half - r, slot.from - offset, -(half - r), -1, 0);
            arc(slot.from + r, -(half - r), 180);
            line(slot.from + r, -(half + offset), slot.to - r, -(half + offset), 0, -1);
            arc(slot.to - r, -(half - r), 270);

            const rings = path.map((P) => {
                const ringPoints = [];

                for (let k = 0; k <= COAMING_SAMPLES; k++) {
                    const a = Math.PI * k / COAMING_SAMPLES;
                    const o = -Math.cos(a) * offset;
                    const base = surface.z(P.X + P.nX * Math.max(o, -offset + 0.03), P.y + P.ny * Math.max(o, -offset + 0.03));

                    if (base === null) return null;

                    ringPoints.push(scene(P.X + P.nX * o, P.y + P.ny * o, base + height * Math.sin(a)));
                }

                return ringPoints;
            });

            for (let i = 0; i < rings.length; i++) {
                const [a, b] = [rings[i], rings[(i + 1) % rings.length]];

                if (a && b) for (let k = 0; k < COAMING_SAMPLES; k++) quad(a[k], a[k + 1], b[k], b[k + 1]);
            }
        } else if (d.type === 'edge') {
            const pocket = carving.find((f) => f.op === 'pocket' && f.part === d.of?.id);

            if (!pocket) {
                ctx.missing.push(d.id + ': không có hốc ' + d.part.of);
                continue;
            }

            strip(pocket.from, pocket.to, (X) => ctx.width(X) - pocket.wall - v('width'), (X) => ctx.width(X) - pocket.wall);
        } else if (d.type === 'rails') {
            const channel = carving.find((f) => f.op === 'channel' && f.part === d.of?.id);

            if (!channel) {
                ctx.missing.push(d.id + ': không có rãnh ' + d.part.of);
                continue;
            }

            strip(channel.from, channel.to, () => channel.half - v('marker'), () => channel.half);
        }

        if (tris.length) meshes.push(Object.assign(soup(d.id, tris), { detail: d.type }));
    }

    return meshes;
}

/**
 * Camera day du (vi tri, dich, up, fov hoac khung truc giao, near/far, kich thuoc) tu camera spec
 * va geometry cuoi. Lay khung `fill` theo doi tuong muc tieu, khung anh dung kich thuoc o (khong
 * co gian rieng hai chieu).
 */
export function resolveCamera(c, meshes, ctx, fill) {
    const width = c.width;
    const height = c.height;
    const aspect = width / height;
    const points = targetPoints(meshes, c.target, ctx);
    const azimuth = c.azimuth_deg * Math.PI / 180;
    const horizontal = add(scale(FORWARD, Math.cos(azimuth)), scale(PORT, Math.sin(azimuth)));
    const bb = bounds(points);
    let target = scale(add(bb.min, bb.max), 0.5);
    const base = { view: c.view, width, height, source: c.status };

    if (c.projection === 'orthographic') {
        const elevation = (c.elevation_deg ?? 0) * Math.PI / 180;
        const dir = normalize(add(scale(horizontal, Math.cos(elevation)), scale(UP, Math.sin(elevation))));
        const up = Math.abs(Math.sin(elevation)) > 0.999 ? FORWARD : UP;
        const basis = cameraBasis(dir, up);
        const xs = points.map((p) => dot(sub(p, target), basis.x));
        const ys = points.map((p) => dot(sub(p, target), basis.y));
        const [cx, cy] = [(Math.max(...xs) + Math.min(...xs)) / 2, (Math.max(...ys) + Math.min(...ys)) / 2];
        target = add(target, add(scale(basis.x, cx), scale(basis.y, cy)));
        const halfW = Math.max((Math.max(...xs) - Math.min(...xs)) / 2 / fill, (Math.max(...ys) - Math.min(...ys)) / 2 / fill * aspect);
        const halfH = halfW / aspect;
        const distance = length(sub(bb.max, bb.min)) * 2 + 50;

        return { ...base, projection: 'orthographic', up, position: add(target, scale(dir, distance)), target, ortho: { left: -halfW, right: halfW, top: halfH, bottom: -halfH }, near: 0.1, far: distance * 2 };
    }

    const filmHeight = 36 / Math.max(aspect, 1);
    const tanV = filmHeight / 2 / c.focal_mm;
    const tanH = tanV * aspect;
    const fov = 2 * Math.atan(tanV) * 180 / Math.PI;
    const place = (d) => {
        if (c.eye_height_m !== null && c.eye_height_m !== undefined) {
            const pos = add(target, scale(horizontal, d));
            pos[1] = c.eye_height_m;

            return pos;
        }

        const elevation = (c.elevation_deg ?? 0) * Math.PI / 180;

        return add(target, scale(normalize(add(scale(horizontal, Math.cos(elevation)), scale(UP, Math.sin(elevation)))), d));
    };
    const extents = (pos) => {
        const basis = cameraBasis(normalize(sub(pos, target)), UP);
        let [minX, maxX, minY, maxY] = [Infinity, -Infinity, Infinity, -Infinity];

        for (const p of points) {
            const rel = sub(p, pos);
            const depth = -dot(rel, basis.z);

            if (depth <= 0.01) return null;

            const nx = dot(rel, basis.x) / depth / tanH;
            const ny = dot(rel, basis.y) / depth / tanV;
            [minX, maxX, minY, maxY] = [Math.min(minX, nx), Math.max(maxX, nx), Math.min(minY, ny), Math.max(maxY, ny)];
        }

        return { minX, maxX, minY, maxY, basis };
    };
    const fit = () => {
        let [lo, hi] = [1, 20000];

        for (let k = 0; k < 60; k++) {
            const d = (lo + hi) / 2;
            const e = extents(place(d));

            if (e === null || Math.max(e.maxX - e.minX, e.maxY - e.minY) / 2 > fill) lo = d;
            else hi = d;
        }

        return hi;
    };
    let distance = fit();

    for (let k = 0; k < 3; k++) {
        const pos = place(distance);
        const e = extents(pos);
        const depth = length(sub(target, pos));
        target = add(target, add(scale(e.basis.x, (e.maxX + e.minX) / 2 * tanH * depth), scale(e.basis.y, (e.maxY + e.minY) / 2 * tanV * depth)));
        distance = fit();
    }

    return { ...base, projection: 'perspective', up: UP, position: place(distance), target, fov, focal_mm: c.focal_mm, near: 0.5, far: distance * 4 + 500 };
}

function targetPoints(meshes, target, ctx) {
    const points = [];

    if (target && target.kind === 'entities') {
        for (const name of target.landmarks || []) {
            const l = ctx.landmarks[name];

            if (l && l.X !== null && l.z !== null) {
                const c = scene(l.X, l.y ?? 0, l.z);

                for (const d of [[-3, 0, 0], [3, 0, 0], [0, -1.5, 0], [0, 1.5, 0], [0, 0, -3], [0, 0, 3]]) points.push(add(c, d));
            }
        }

        if (points.length) return points;
    }

    for (const m of meshes) {
        const stride = m.positions.length > 30000 ? 5 : 1;

        for (let v = 0; v < m.positions.length / 3; v += stride) points.push([m.positions[v * 3], m.positions[v * 3 + 1], m.positions[v * 3 + 2]]);
    }

    return points;
}

function cameraBasis(dir, up) {
    const z = normalize(dir);
    const x = normalize(cross(up, z));

    return { x, y: cross(z, x), z };
}

/** Chieu diem scene -> pixel (x phai, y xuong) va do sau, theo camera da giai. */
export function project(camera, p) {
    const basis = cameraBasis(normalize(sub(camera.position, camera.target)), camera.up);
    const rel = sub(p, camera.position);
    const depth = -dot(rel, basis.z);

    if (camera.projection === 'orthographic') {
        const { left, right, top, bottom } = camera.ortho;
        const nx = (dot(rel, basis.x) - left) / (right - left);
        const ny = (top - dot(rel, basis.y)) / (top - bottom);

        return { x: nx * camera.width, y: ny * camera.height, depth, metresPerPixel: (right - left) / camera.width };
    }

    const tanV = Math.tan(camera.fov * Math.PI / 360);
    const tanH = tanV * camera.width / camera.height;

    if (depth <= 1e-6) return null;

    return {
        x: (dot(rel, basis.x) / depth / tanH * 0.5 + 0.5) * camera.width,
        y: (0.5 - dot(rel, basis.y) / depth / tanV * 0.5) * camera.height,
        depth,
        metresPerPixel: 2 * depth * tanH / camera.width,
    };
}

/**
 * Bang kiem do tren geometry cuoi (luoi da khoet, da duc cua). Nguong lay tu `tolerances`.
 */
function buildReport(spec, ctx, features, stations, meshes, windows, textures, cameras, state) {
    const tol = ctx.tolerances;
    const checks = [];
    const check = (key, label, design, measured, tolerance, value, min, max) => {
        const pass = isNumber(value) && (min === null || value >= min - 1e-9) && (max === null || value <= max + 1e-9);
        checks.push({ key, label, design: String(design), measured: String(measured), tolerance: String(tolerance), value: isNumber(value) ? value : null, min, max, pass });
    };
    const need = (name) => {
        if (!isNumber(tol[name])) ctx.missing.push('ngưỡng ' + name);

        return tol[name];
    };

    if (stations.length) {
        const silhouette = (s) => Math.max(...s.half.map((p) => p.z));
        const at = (X) => nearest(stations, X);
        const open = stations.filter((s) => !s.half.some((p) => p.y < 1e-6 && LOWERS_CENTRE.includes(p.op)));
        const profileError = open.length ? Math.max(...open.map((s) => Math.abs(silhouette(s) - ctx.top(s.X)))) : NaN;
        const planError = Math.max(...stations.map((s) => Math.abs(Math.max(...s.half.map((p) => p.y)) - ctx.width(s.X))));
        const maxHalf = Math.max(...stations.map((s) => s.w));

        check('profile', 'Đường bao mặt bên (điểm cao nhất mỗi mặt cắt, gồm cả vách khe) so với profile_curve — ' + open.length + ' trạm', '0 m', round(profileError, 3) + ' m', '≤ ' + tol.profile_m + ' m', profileError, 0, need('profile_m'));
        check('plan', 'Bề rộng so với plan_outline (mọi trạm)', '0 m', round(planError, 3) + ' m', '≤ ' + tol.plan_m + ' m', planError, 0, need('plan_m'));

        if (isNumber(ctx.beam) && ctx.beam > 0) {
            const ratio = ctx.loa / (2 * maxHalf);
            check('ratio', 'Tỉ lệ dài / rộng', round(ctx.loa / ctx.beam, 3), round(ratio, 3), '± ' + tol.ratio, Math.abs(ratio - ctx.loa / ctx.beam), 0, need('ratio'));
        }

        if (ctx.state.has('superstructure')) {
            const fitted = ctx.decks.filter((deck) => deck.segments.every(([a, b]) => Array.from({ length: 11 }, (_, k) => a + (b - a) * k / 10)
                .every((X) => deck.ceiling <= silhouette(at(X)) + need('deck_m') && deck.floor >= -ctx.draft - need('deck_m'))));
            check('decks', 'Số boong có mọi đoạn kín nằm trong vỏ proxy (trần ≤ đường bao + ' + tol.deck_m + ' m; khoảng rỗng không tính)', spec.geometry.deck_count + ' boong', fitted.length + ' boong', 'bằng nhau', fitted.length, spec.geometry.deck_count, spec.geometry.deck_count);

            for (const l of spec.geometry.landmarks.filter((m) => m.role === 'profile')) {
                const z = silhouette(at(l.X));
                check('profile_landmark:' + l.name, 'Mốc đường bao ' + l.name + ' (X ' + round(l.X, 2) + ' m)', l.z + ' m', round(z, 3) + ' m', '± ' + tol.profile_m + ' m', Math.abs(z - l.z), 0, need('profile_m'));
            }
        }

        for (const f of features.carving.filter((c) => c.op === 'cut')) {
            const inside = stations.filter((s) => s.X > f.from + 0.5 && s.X < f.to - 0.5);
            const band = inside.flatMap((s) => s.half.filter((p) => p.part !== 'hull' && p.y < f.half - 0.05));
            const voided = band.length ? band.filter((p) => p.z <= f.floor + 1e-6).length / band.length : 0;
            // Do tren dinh luoi mang nhan cua khe (dinh vo = diem mat cat), khong theo nhom tam giac
            // (tam giac o mep lan sang bo phan ben canh).
            const own = stations.flatMap((s) => s.half.filter((p) => p.part === f.part).map((p) => [s.X, p.y, p.z]));
            const len = own.length ? Math.max(...own.map((p) => p[0])) - Math.min(...own.map((p) => p[0])) : 0;
            const wid = own.length ? 2 * Math.max(...own.map((p) => p[1])) : 0;
            const floorZ = own.length ? Math.min(...own.map((p) => p[2])) : NaN;

            check('void:' + f.part, 'Khoảng rỗng ' + f.part + ': tỉ lệ mặt trong khe hạ đúng sàn (không bị lấp)', '100%', round(voided * 100, 1) + '%', '≥ 95%', voided, 0.95, null);
            check('cut:' + f.part, 'Khe ' + f.part + ' đo trên mesh: dài × rộng, sàn', round(f.to - f.from, 2) + ' × ' + round(2 * f.half, 2) + ' m, sàn ' + f.floor + ' m', round(len, 2) + ' × ' + round(wid, 2) + ' m, sàn ' + round(floorZ, 2) + ' m', '± ' + tol.detail_m + ' m',
                Math.max(Math.abs(len - (f.to - f.from)), Math.abs(wid - 2 * f.half), Math.abs(floorZ - f.floor)), 0, need('detail_m'));
        }

        for (const f of features.carving.filter((c) => c.op === 'pocket' || c.op === 'basin')) {
            const inside = stations.filter((s) => s.X >= f.from + 0.25 && s.X <= f.to - 0.25);
            const pts = inside.flatMap((s) => s.half.filter((p) => p.part !== 'hull' && (f.op === 'pocket' ? p.y < s.w - f.wall : p.y < f.half - 0.05)));
            const coverage = pts.length ? pts.filter((p) => p.z <= f.floor + 1e-6).length / pts.length : 0;
            check(f.op + ':' + f.part, (f.op === 'pocket' ? 'Hốc terrace ' : 'Bể ') + f.part + ': tỉ lệ mặt hạ đúng cao độ', '100%', round(coverage * 100, 1) + '%', '≥ ' + round(need('pocket_coverage') * 100, 0) + '%', coverage, need('pocket_coverage'), null);
        }
    }

    for (const m of meshes.filter((mesh) => mesh.landmark && mesh.center)) {
        const l = ctx.landmarks[m.landmark];
        const part = spec.geometry.parts.find((p) => p.id === m.part);
        const y = partInstanceY(part, m);

        if (part && part.op === 'opening') {
            const depth = dot(sub(m.mouth, m.center), m.normal);
            const facing = dot(m.normal, faceBasis(part.facing, y, 0).n);
            check('opening:' + m.part + ':' + m.landmark + (m.center[2] < 0 ? ':s' : ''), 'Hốc ' + m.part + ' tại mốc ' + m.landmark + ': lõm vào vỏ theo hướng ' + part.facing, num(part, 'depth') + ' m', round(depth, 3) + ' m', '± ' + tol.detail_m + ' m', facing > 0.999 ? Math.abs(depth - (num(part, 'depth') ?? 0)) : NaN, 0, need('detail_m'));
            continue;
        }

        const expected = part && part.op === 'panel'
            ? sub(scene(l.X, y, l.z), scale(faceBasis(part.facing, y, num(part, 'tilt_deg') ?? 0).n, num(part, 'recess') ?? 0))
            : scene(l.X, y, l.z);

        if (expected) {
            const dev = length(sub(m.center, expected));
            check('instance:' + m.part + ':' + m.landmark + (m.center[2] < 0 ? ':s' : ''), 'Tâm ' + m.part + ' tại mốc ' + m.landmark, fmtPoint(expected), fmtPoint(m.center), '≤ ' + tol.landmark_m + ' m', dev, 0, need('landmark_m'));
        }
    }

    for (const w of windows) {
        const failing = w.placed.filter((p) => p.failures.length);
        const notes = [...w.failed.map((f) => f.side + ': ' + f.reason), ...failing.map((p) => p.side + ': ' + p.failures.join(', '))];

        if (w.conflict) {
            check('window:' + w.id, w.id + ' — mốc kính nằm sâu trong vỏ proxy quá độ lõm cho phép: mâu thuẫn giữa mốc và đường bao, không dựng (sửa thiết kế, mapping hoặc giả định)', w.expected + ' ô tại mốc', 'mốc sâu ' + w.conflict.depth + ' m trong vỏ, không dựng', 'lõm ≤ ' + w.maxDepth + ' m', null, 0, 0);
            continue;
        }

        if (w.landmark) {
            const dev = w.placed.length === w.expected && !failing.length ? Math.max(...w.placed.map((p) => p.deviation ?? 0)) : NaN;
            check('window:' + w.id, w.id + ' — tâm kính so với mốc' + (notes.length ? ' · ' + notes.join('; ') : ''), w.expected + ' ô', w.placed.length + ' ô · lệch ' + round(dev, 4) + ' m', '≤ ' + tol.landmark_m + ' m', dev, 0, need('landmark_m'));
        } else {
            const outside = w.placed.filter((p) => p.deck && (p.extents.zmin < p.deck.floor - 1e-6 || p.extents.zmax > p.deck.ceiling + 1e-6 || p.extents.xmin < w.from - EDGE_EPS || p.extents.xmax > w.to + EDGE_EPS));
            const bad = new Set([...failing, ...outside]).size + (w.expected - w.placed.length);
            check('window:' + w.id, w.id + ' — mọi ô nằm trong dải boong ' + w.deck + ' và khoảng x, không cắt vào phần khoét' + (notes.length ? ' · ' + notes.join('; ') : ''), w.expected + ' ô đạt', (w.expected - bad) + '/' + w.expected + ' ô đạt', '0 ô lỗi', bad, 0, 0);
        }
    }

    for (const b of features.bridges) {
        const cut = features.carving.find((f) => f.part === b.spans);
        check('bridge:' + b.part, 'Cầu ' + b.part + ' bắc ngang khe ' + b.spans + ' (rộng = bề rộng khe, nằm trong phạm vi khe)', round(2 * (cut ? cut.half : 0), 2) + ' m', round(2 * b.half, 2) + ' m', 'trong khe', cut && b.X >= cut.from && b.X <= cut.to && b.top > cut.floor ? 0 : 1, 0, 0);
    }

    for (const t of textures) {
        const m = t.measured;
        const design = 'bước ' + t.design.pitch + ' m, nổi ' + t.design.depth + ' m';
        const dim = need('detail_dim_m');

        if (t.kind === 'ribs') {
            const [along, across] = [need('rib_longitudinal_deg'), need('rib_transverse_deg')];
            const bad = m ? (m.forward[1] - m.forward[0]) + (m.aft[1] - m.aft[0]) : 1;
            const dev = m && !bad && m.forward[1] + m.aft[1] > 0 ? Math.max(Math.abs(m.pitch - t.design.pitch), Math.abs(m.depth - t.design.depth)) : NaN;
            check('ribs:' + t.part, 'Gân ' + t.part + ' đo trên mesh: bước, độ nổi; đoạn dọc phía trước / ngang phía sau đường đổi hướng', design + '; dọc trước, ngang sau',
                m ? 'bước ' + round(m.pitch, 3) + ' m, nổi ' + round(m.depth, 3) + ' m; ' + m.lines + ' đường; dọc ' + m.forward[0] + '/' + m.forward[1] + ', ngang ' + m.aft[0] + '/' + m.aft[1] + ' đoạn' : 'không dựng được gân trên mặt thượng tầng',
                '± ' + dim + ' m; dọc ≤ ' + along + '°, ngang ≥ ' + across + '°', dev, 0, dim);
        } else {
            const dev = m && m.pitch.every(isNumber) ? Math.max(...m.pitch.map((p) => Math.abs(p - t.design.pitch)), Math.abs(m.depth - t.design.depth)) : NaN;
            check('relief:' + t.part, 'Gân ' + t.part + ' đo trên mesh: vây trên hai vách khe, bước dọc tàu, độ nhô vào lòng khe', design + ', hai vách',
                m ? 'bước ' + m.pitch.map((p) => round(p, 3)).join(' / ') + ' m, nhô ' + round(m.depth, 3) + ' m; ' + m.fins.join(' + ') + ' vây' : 'không dựng được vây trên vách khe',
                '± ' + dim + ' m', dev, 0, dim);
        }
    }

    const plan = cameras.find((c) => c.view === 'MS6');

    if (plan) {
        const basis = cameraBasis(normalize(sub(plan.position, plan.target)), plan.up);
        const right = dot(basis.y, FORWARD) > 0.999 && dot(basis.x, PORT) < -0.999;
        check('ms6', 'MS6: mũi lên trên, mạn trái bên trái ảnh', 'đúng', right ? 'đúng' : 'sai', '—', right ? 1 : 0, 1, 1);
    }

    return {
        builder: BUILDER_VERSION,
        spec_sha256: spec.spec_sha256,
        state: state.id,
        checks,
        missing: [...new Set(ctx.missing)],
        pass: checks.every((c) => c.pass) && ctx.missing.length === 0,
        markers: features.markers.map((m) => ({ part: m.part, landmark: m.landmark, display_state: m.display_state })),
        stats: {
            stations: stations.length,
            meshes: meshes.length,
            vertices: meshes.reduce((n, m) => n + m.positions.length / 3, 0),
            triangles: meshes.reduce((n, m) => n + m.indices.length / 3, 0),
        },
    };
}

function partInstanceY(part, mesh) {
    const inst = part ? part.instances.find((i) => i.landmark === mesh.landmark && Math.sign(i.y || 0) === Math.sign(mesh.center[2] || 0)) : null;

    return inst ? inst.y ?? 0 : mesh.center[2];
}

function resample(outline, count) {
    const lengths = [0];

    for (let i = 1; i < outline.length; i++) {
        lengths.push(lengths[i - 1] + Math.hypot(outline[i][0] - outline[i - 1][0], outline[i][1] - outline[i - 1][1]));
    }

    const total = lengths[lengths.length - 1];
    const out = [];

    for (let k = 0; k < count; k++) {
        const target = total * k / (count - 1);
        let i = 1;

        while (i < lengths.length - 1 && lengths[i] < target) i++;

        const spanLength = lengths[i] - lengths[i - 1];
        const t = spanLength > 0 ? (target - lengths[i - 1]) / spanLength : 0;
        out.push([outline[i - 1][0] + (outline[i][0] - outline[i - 1][0]) * t, outline[i - 1][1] + (outline[i][1] - outline[i - 1][1]) * t]);
    }

    return out;
}

export function interpolate(points, x) {
    if (!points.length) return NaN;
    if (x <= points[0][0]) return points[0][1];

    for (let i = 1; i < points.length; i++) {
        if (x <= points[i][0]) {
            const [a, b] = [points[i - 1], points[i]];

            return b[0] === a[0] ? Math.max(a[1], b[1]) : a[1] + (b[1] - a[1]) * (x - a[0]) / (b[0] - a[0]);
        }
    }

    return points[points.length - 1][1];
}

/** He spec (X, y, z) -> scene/glTF [X, z, y]. */
export function scene(X, y, z) {
    return [X, z, y];
}

function isNumber(v) { return typeof v === 'number' && Number.isFinite(v); }
function smoothstep(t) { const c = Math.min(1, Math.max(0, t)); return c * c * (3 - 2 * c); }
function round(v, d) { return Number.isFinite(v) ? Math.round(v * Math.pow(10, d)) / Math.pow(10, d) : v; }
function fmtPoint(p) { return '(' + p.map((v) => round(v, 2)).join('; ') + ')'; }
function add(a, b) { return [a[0] + b[0], a[1] + b[1], a[2] + b[2]]; }
function sub(a, b) { return [a[0] - b[0], a[1] - b[1], a[2] - b[2]]; }
function scale(a, s) { return [a[0] * s, a[1] * s, a[2] * s]; }
function mid(a, b) { return scale(add(a, b), 0.5); }
function dot(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
function cross(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
function length(a) { return Math.hypot(a[0], a[1], a[2]); }
function normalize(a) { const l = length(a) || 1; return [a[0] / l, a[1] / l, a[2] / l]; }
export function bounds(points) {
    const min = [Infinity, Infinity, Infinity];
    const max = [-Infinity, -Infinity, -Infinity];

    for (const p of points) {
        for (let k = 0; k < 3; k++) {
            min[k] = Math.min(min[k], p[k]);
            max[k] = Math.max(max[k], p[k]);
        }
    }

    return { min, max };
}

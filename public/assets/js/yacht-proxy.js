const STATION_STEP_M = 0.5;
const UNDERWATER_POINTS = 16;
const SIDE_POINTS = 8;
const ENVELOPE_POINTS = 96;
const CAP_SAMPLES = 48;
const RIB_CELL_M = 0.15;
const RIB_LIFT_M = 0.05;
const EDGE_EPS = 1e-3;
const DISC_THICKNESS_M = 0.2;
const CYLINDER_SEGMENTS = 32;
const FRAME_M = 0.15;
const FRAME_LIFT_M = 0.01;
const CAVITY_M = 0.6;
const OPENING_LEAF_M = 0.08;
const FLUSH_LIFT_M = 0.005;
const COAMING_SAMPLES = 8;
const FORWARD = [-1, 0, 0];
const PORT = [0, 0, 1];
const UP = [0, 1, 0];
const CARVED = ['terrace', 'pool', 'n1_cap', 'steps', 'slipway', 'slipway_rail'];
const PRIORITY = ['n1_cap', 'pool', 'slipway_rail', 'slipway', 'steps', 'terrace', 'envelope', 'hull_side', 'hull_underwater'];

export const PART_LABELS = {
    hull_underwater: 'Vỏ dưới nước',
    hull_side: 'Thân vỏ',
    envelope: 'Vỏ đụn cát',
    terrace: 'Terrace hở',
    pool: 'Bể bơi',
    n1_cap: 'Nắp khe canyon N1',
    n1_mirror: 'Gương N1',
    n1_pedestal: 'Trụ gương N1',
    steps: 'Bậc thang đuôi',
    slipway: 'Rãnh trượt tender',
    n2_ribs: 'Gân N2 — đường kiểm; bề mặt dùng normal map từ ripple_depth, không phải hình học nổi',
    window_glass: 'Kính smoke-grey (W1, W4–W8)',
    window_clear: 'Kính trong cầu lái (W2, W3)',
    window_reveal: 'Mép và thành hốc cửa',
    window_cavity: 'Khoang tối sau kính (chỉ để hiển thị)',
    bulwark: 'Thành chắn boong mũi (E1)',
    n1_coaming: 'Gờ bo tròn khe canyon (E2)',
    accent_edge: 'Viền Oxidized Copper phẳng sàn (E3, E4)',
    slipway_rail: 'Rãnh ray lõm của rãnh trượt (E4)',
};

export const ASSUMED_PARTS = {
    hull_underwater: ['G3'],
    hull_side: ['G2'],
    envelope: ['G1'],
    terrace: ['G8'],
    n1_mirror: ['G5'],
    n1_pedestal: ['G5'],
    steps: ['G6'],
    slipway: ['G6'],
    n2_ribs: ['G4'],
    window_glass: ['kích thước W1–W8'],
    window_clear: ['kích thước W2–W3'],
    window_reveal: ['G10', 'G13'],
    window_cavity: ['hiển thị'],
    bulwark: ['E1'],
    n1_coaming: ['E2'],
    accent_edge: ['E3', 'E4'],
    slipway_rail: ['E4', 'G14'],
};

/**
 * @param {{canonical: object, features?: object[], views?: object, assumptions?: object, tolerances?: object, fill?: number, builder?: string, input_sha256?: string}} input
 * @returns {{meshes: object[], lines: object[], landmarks: object[], cameras: object[], report: object}}
 */
export function buildProxy(input) {
    const ctx = context(input);
    const declared = Array.isArray(input.features) && input.features.length ? input.features : null;

    if (declared === null) {
        ctx.missing.push('model_sheet.approved[' + (input.design_canonical_hash || '?') + '].proxy');
    }

    const features = resolveFeatures(declared || [{ part: 'terrace', type: 'pocket', from: 'open_terraces', wall: 'G8' }], ctx);

    for (const d of features.details.filter((item) => item.type === 'rails')) {
        const channel = features.carving.find((f) => f.type === 'channel' && f.part === d.of);

        if (channel && d.depth > 0) {
            features.carving.push({ type: 'groove', part: 'slipway_rail', of: channel.part, from: channel.from.X, to: channel.to.X, lo: d.spacing / 2 - d.rail / 2, hi: d.spacing / 2 + d.rail / 2, depth: d.depth });
        }
    }

    ctx.edges = [...new Set(features.carving.flatMap((f) => {
        if (f.type === 'groove') return [f.lo, f.hi];

        return ['slot', 'basin', 'channel'].includes(f.type) && f.width > 0 ? [f.width / 2] : [];
    }))].sort((a, b) => b - a);
    const count = Math.max(2, Math.round(ctx.loa / STATION_STEP_M));
    const stations = [];

    for (let i = 0; i <= count; i++) {
        stations.push(section(ctx, i / count));
    }

    for (const feature of features.carving) {
        carve(stations, feature, ctx);
    }

    const surface = surfaceSampler(stations, ctx);
    const hull = loft(stations);
    const windows = buildWindows(features.windows, ctx, stations, surface);
    punch(hull, windows.openings);
    const details = buildDetails(features.details, features.carving, ctx, stations, surface, hull);
    const meshes = [hull, ...features.discs.flatMap((disc) => discMeshes(disc, surface)), windows.mesh, details].filter((m) => m.positions.length > 0);
    const step = ctx.loa / (stations.length - 1);
    const limits = stations.map((s) => (s.zt - s.zb > 0.3 ? girthAtHeight(s, s.zb + 0.3) ?? 0 : 0));
    const gmax = Math.max(...limits) + 0.5;

    for (const rib of features.ribs) {
        rib.turnG = girthAtY(stations[Math.min(stations.length - 1, Math.max(0, Math.round(rib.turn * ctx.loa / step)))], rib.turnY) ?? rib.turnY;
    }

    const lines = features.ribs.map((rib) => ribLines(rib, ctx, stations, gmax));
    hull.uvs = Float32Array.from({ length: hull.positions.length / 3 * 2 }, (_, k) => (k % 2 === 0
        ? hull.positions[(k >> 1) * 3] / ctx.loa
        : (hull.girth[k >> 1] + gmax) / (2 * gmax)));
    const ribField = features.ribs.length ? { ...features.ribs[0], loa: ctx.loa, gmax, step, limits } : null;
    const landmarks = Object.entries(ctx.canonical.landmarks || {})
        .filter(([, mark]) => mark && isNumber(mark.x) && isNumber(mark.z))
        .map(([name, mark]) => ({ name, position: scene(mark.x * ctx.loa, isNumber(mark.y) ? mark.y : 0, mark.z), above_profile: mark.above_profile === true }));
    const cameras = Object.entries(input.views || {}).map(([view, frame]) => camera(view, frame, meshes, ctx, input.fill || 0.9));
    const report = buildReport(input, ctx, features, stations, meshes, lines, cameras, windows.results, details.measures);

    return { meshes, lines, landmarks, cameras, report, windows: windows.results, ribField };
}

/**
 * @param {object} input
 * @returns {object}
 */
function context(input) {
    const canonical = input.canonical || {};
    const dims = canonical.dimensions || {};
    const loa = metres(dims.loa);
    const draft = metres(dims.draft);
    const beam = metres(dims.beam);
    const missing = [];

    if (!(loa > 0)) missing.push('canonical.dimensions.loa');
    if (!(draft >= 0)) missing.push('canonical.dimensions.draft');

    const profile = pairs(canonical.profile_curve, 'canonical.profile_curve', missing).sort((a, b) => a[0] - b[0]);
    const port = pairs(canonical.plan_outline, 'canonical.plan_outline', missing).filter((p) => p[1] >= 0).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    const decks = (Array.isArray(canonical.decks) ? canonical.decks : [])
        .filter((deck) => deck && isNumber(deck.floor_z_m) && isNumber(deck.start_x) && isNumber(deck.end_x))
        .map((deck) => ({ name: String(deck.name), floor: deck.floor_z_m, ceiling: isNumber(deck.ceiling_z_m) ? deck.ceiling_z_m : NaN, setback: isNumber(deck.setback_m) ? deck.setback_m : 0, from: deck.start_x, to: deck.end_x }))
        .sort((a, b) => a.floor - b.floor);
    const assumptions = input.assumptions || {};
    const assumption = (code) => {
        const [key, index] = String(code).split('.');
        const value = assumptions[key] ? assumptions[key].value : undefined;

        if (value === undefined) {
            missing.push('assumption ' + code);

            return NaN;
        }

        return index === undefined ? Number(Array.isArray(value) ? value[0] : value) : Number(value[Number(index)]);
    };

    return {
        canonical,
        loa: loa > 0 ? loa : 1,
        draft: draft >= 0 ? draft : 0,
        beam,
        missing,
        decks,
        tolerances: input.tolerances || {},
        assumption,
        top: (x) => interpolate(profile, x),
        width: (x) => Math.max(0, interpolate(port, x)),
        sideTop: (x) => Math.min(assumption('G2'), interpolate(profile, x)),
        profile,
        port,
    };
}

/**
 * @param {object[]} list
 * @param {object} ctx
 * @returns {{carving: object[], discs: object[], ribs: object[]}}
 */
function resolveFeatures(list, ctx) {
    const resolve = (ref, label) => {
        const value = lookup(ref, ctx);

        if (value === undefined || (typeof value === 'number' && !Number.isFinite(value))) {
            ctx.missing.push(label + ': ' + JSON.stringify(ref));
        }

        return value;
    };
    const point = (ref, label) => {
        const mark = resolve(ref, label);

        return mark && isNumber(mark.x) && isNumber(mark.z) ? { X: mark.x * ctx.loa, y: isNumber(mark.y) ? mark.y : 0, z: mark.z } : null;
    };
    const out = { carving: [], discs: [], ribs: [], windows: [], details: [] };
    const optional = (ref, label, fallback) => (ref === undefined ? fallback : resolve(ref, label));

    for (const item of list) {
        const label = item.part + '.' + item.type;

        if (item.type === 'pocket') {
            const terraces = resolve(item.from, label);

            for (const terrace of Array.isArray(terraces) ? terraces : []) {
                if (isNumber(terrace.start_x) && isNumber(terrace.end_x) && isNumber(terrace.floor_z_m)) {
                    out.carving.push({ type: 'pocket', part: item.part, from: terrace.start_x * ctx.loa, to: terrace.end_x * ctx.loa, floor: terrace.floor_z_m, wall: resolve(item.wall, label), name: String(terrace.name || '') });
                }
            }
        } else if (item.type === 'basin') {
            const at = point(item.at, label);

            if (at) {
                out.carving.push({ type: 'basin', part: item.part, X: at.X, rim: at.z, length: resolve(item.length, label), width: resolve(item.width, label), depth: resolve(item.depth, label) });
            }
        } else if (item.type === 'slot') {
            const from = point(item.from, label);
            const to = point(item.to, label);

            if (from && to) {
                out.carving.push({ type: 'slot', part: item.part, from: Math.min(from.X, to.X), to: Math.max(from.X, to.X), width: resolve(item.width, label), recess: resolve(item.recess, label), radius: optional(item.radius, label, 0) });
            }
        } else if (item.type === 'steps') {
            for (const ref of [].concat(item.at)) {
                const at = point(ref, label);

                if (at) {
                    out.carving.push({ type: 'steps', part: item.part, X: at.X, y: Math.abs(at.y), width: resolve(item.width, label), tread: resolve(item.tread, label) });
                }
            }
        } else if (item.type === 'channel') {
            const from = point(item.from, label);
            const to = point(item.to, label);

            if (from && to) {
                out.carving.push({ type: 'channel', part: item.part, from, to, width: resolve(item.width, label) });
            }
        } else if (item.type === 'disc') {
            const diameter = resolve(item.diameter, label);
            const tilt = resolve(item.tilt_deg, label);
            const pedestal = resolve(item.pedestal, label);

            for (const ref of [].concat(item.at)) {
                const at = point(ref, label);

                if (at) {
                    out.discs.push({ part: item.part, name: String(ref), center: at, diameter, tilt, pedestal });
                }
            }
        } else if (item.type === 'ribs') {
            out.ribs.push({ part: item.part, from: resolve(item.from_x, label), turn: resolve(item.turn_x, label), to: resolve(item.to_x, label), pitch: resolve(item.pitch, label), halfTurn: resolve(item.half_turn, label), depth: optional(item.depth, label, 0), turnY: Math.abs(optional(item.turn_y, label, 0)) });
        } else if (item.type === 'window') {
            const deck = item.deck === undefined ? null : ctx.decks.find((d) => d.name === item.deck) || null;

            if (item.deck !== undefined && deck === null) ctx.missing.push(label + ': boong ' + item.deck);

            out.windows.push({
                id: String(item.id || item.part), label: String(item.label || ''), part: item.part, deck, optional: item.optional === true,
                at: item.at === undefined ? null : point(item.at, label),
                facing: item.facing || 'side', sides: [].concat(item.sides || ['port']), shape: item.shape || 'rect', glass: item.glass || 'smoke',
                tilt: optional(item.tilt_deg, label, 0), width: resolve(item.width, label), height: resolve(item.height, label),
                facets: optional(item.facets, label, 1), facetStep: optional(item.facet_step, label, 0), spread: optional(item.spread, label, 0),
                from: optional(item.from_x, label, null), to: optional(item.to_x, label, null),
                spacing: optional(item.spacing, label, null), count: optional(item.count, label, null), stagger: optional(item.stagger, label, 0),
            });
        } else if (['bulwark', 'coaming', 'edge', 'rails'].includes(item.type)) {
            out.details.push({
                id: String(item.id || item.part), part: item.part, type: item.type, of: item.of,
                from: optional(item.from_x, label, null), to: optional(item.to_x, label, null),
                height: optional(item.height, label, 0), thickness: optional(item.thickness, label, 0), rim: optional(item.rim, label, 0), width: optional(item.width, label, 0),
                spacing: optional(item.spacing, label, 0), rail: optional(item.rail, label, 0), depth: optional(item.depth, label, 0), marker: optional(item.marker, label, 0),
            });
        } else {
            ctx.missing.push(label + ': loại chi tiết không hỗ trợ');
        }
    }

    const order = ['pocket', 'basin', 'slot', 'steps', 'channel', 'groove'];
    out.carving.sort((a, b) => order.indexOf(a.type) - order.indexOf(b.type));

    return out;
}

/**
 * @param {*} ref
 * @param {object} ctx
 * @returns {*}
 */
function lookup(ref, ctx) {
    if (typeof ref === 'number') return ref;
    if (typeof ref !== 'string') return undefined;
    if (/^G\d+(\.\d+)?$/.test(ref)) return ctx.assumption(ref);

    let node = ctx.canonical;

    for (const key of ref.split('.')) {
        if (node === null || typeof node !== 'object' || !(key in node)) return undefined;
        node = node[key];
    }

    return node && typeof node === 'object' && 'value' in node && !Array.isArray(node) && !('x' in node) ? metres(node) : node;
}

/**
 * @param {object} ctx
 * @param {number} x
 * @returns {object}
 */
function section(ctx, x) {
    const w = ctx.width(x);
    const zt = ctx.top(x);
    const zb = ctx.sideTop(x);
    const depth = keelDepth(ctx, x);
    const stem = ctx.assumption('G3.0');
    const bottom = x < stem ? zb * (1 - x / stem) : -depth;
    const [ratio, full] = [ctx.assumption('G11.0'), ctx.assumption('G11.1')];
    const entry = smoothstep((x - stem) / (full - stem));
    const waterline = w * (ratio + (1 - ratio) * entry);
    const exponent = 2 / (1.5 + 2.5 * entry);
    const half = [];

    for (let j = 0; j < UNDERWATER_POINTS; j++) {
        const t = j / (UNDERWATER_POINTS - 1);
        const angle = t * Math.PI / 2;
        const y = bottom < 0 ? waterline * Math.pow(Math.sin(angle), exponent) : waterline * t;
        const z = bottom < 0 ? bottom * Math.pow(Math.cos(angle), exponent) : bottom;
        half.push({ y, z, part: bottom < 0 ? 'hull_underwater' : 'hull_side' });
    }

    const sideFrom = Math.max(0, bottom);

    for (let j = 1; j <= SIDE_POINTS; j++) {
        half.push({ y: waterline + (w - waterline) * j / SIDE_POINTS, z: sideFrom + (zb - sideFrom) * j / SIDE_POINTS, part: 'hull_side' });
    }

    const envelope = resample(envelopeOutline(ctx, x, w, zb, zt), ENVELOPE_POINTS);

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
        half.push({ y: envelope[j][0], z: envelope[j][1], part: 'envelope', copy: envelope[j][2] || null });
    }

    half[half.length - 1].g = 0;

    for (let j = half.length - 2; j >= 0; j--) {
        half[j].g = half[j + 1].g + Math.hypot(half[j + 1].y - half[j].y, half[j + 1].z - half[j].z);
    }

    return { x, X: x * ctx.loa, w, zt, zb, half, carvedCentre: false };
}

/**
 * @param {object} ctx
 * @param {number} x
 * @returns {number}
 */
function keelDepth(ctx, x) {
    const [stem, flatFrom, flatTo] = [ctx.assumption('G3.0'), ctx.assumption('G3.1'), ctx.assumption('G3.2')];

    if (x <= stem) return 0;
    if (x < flatFrom) return ctx.draft * smoothstep((x - stem) / (flatFrom - stem));
    if (x <= flatTo) return ctx.draft;

    const t = (x - flatTo) / (1 - flatTo);

    return ctx.draft * (1 - t) * (1 - t);
}

/**
 * @param {object} ctx
 * @param {number} x
 * @param {number} w
 * @param {number} zb
 * @param {number} zt
 * @returns {number[][]}
 */
function envelopeOutline(ctx, x, w, zb, zt) {
    const points = [[w, zb]];

    if (zt - zb < 1e-6) {
        return [[w, zb], [0, zt]];
    }

    for (const deck of ctx.decks) {
        if (x >= deck.from && x <= deck.to && deck.floor >= zb && deck.floor < zt - 0.5) {
            const last = points[points.length - 1];
            points.push([Math.min(last[0], Math.max(0, w - deck.setback)), Math.max(last[1], deck.floor)]);
        }
    }

    const [yk, zk] = points[points.length - 1];
    const n = ctx.assumption('G1');

    for (let k = 1; k <= CAP_SAMPLES; k++) {
        const a = (k / CAP_SAMPLES) * Math.PI / 2;
        points.push([yk * Math.pow(Math.cos(a), 2 / n), zk + (zt - zk) * Math.pow(Math.sin(a), 2 / n)]);
    }

    points[points.length - 1] = [0, zt];

    return points;
}

/**
 * @param {object[]} stations
 * @param {object} feature
 * @param {object} ctx
 */
function carve(stations, feature, ctx) {
    const inside = (X, from, to) => X >= from - EDGE_EPS && X <= to + EDGE_EPS;
    const inSlot = (X, y) => {
        const r = Math.min(feature.radius || 0, feature.width / 2);
        const dx = Math.max(feature.from + r - X, X - (feature.to - r), 0);
        const dy = Math.max(y - (feature.width / 2 - r), 0);

        return inside(X, feature.from, feature.to) && y <= feature.width / 2 + EDGE_EPS && dx * dx + dy * dy <= r * r + EDGE_EPS;
    };
    const within = (point, lo, hi) => point.y >= lo - EDGE_EPS && point.y <= hi + EDGE_EPS
        && !(point.copy === 'hi' && Math.abs(point.y - hi) < EDGE_EPS) && !(point.copy === 'lo' && Math.abs(point.y - lo) < EDGE_EPS);

    for (const station of stations) {
        const X = station.X;

        for (const point of station.half) {
            if (point.part === 'hull_underwater' || point.part === 'hull_side') continue;

            let z = point.z;

            if (feature.type === 'pocket' && inside(X, feature.from, feature.to) && point.y < station.w - feature.wall) {
                z = Math.min(z, feature.floor);
            } else if (feature.type === 'basin' && Math.abs(X - feature.X) <= feature.length / 2 + EDGE_EPS && within(point, -1, feature.width / 2)) {
                z = Math.min(z, feature.rim - feature.depth);
            } else if (feature.type === 'slot' && inSlot(X, point.y) && within(point, -1, feature.width / 2)) {
                z = z - feature.recess;
            } else if (feature.type === 'steps' && X >= 2 * feature.X - ctx.loa - EDGE_EPS && Math.abs(point.y - feature.y) <= feature.width / 2) {
                z = Math.floor(z / feature.tread) * feature.tread;
            } else if (feature.type === 'channel' && inside(X, feature.from.X, feature.to.X) && within(point, -1, feature.width / 2)) {
                const t = (X - feature.from.X) / Math.max(1e-6, feature.to.X - feature.from.X);
                z = Math.min(z, feature.from.z + (feature.to.z - feature.from.z) * t);
            } else if (feature.type === 'groove' && point.part === feature.of && inside(X, feature.from, feature.to) && within(point, feature.lo, feature.hi)) {
                z = z - feature.depth;
            } else {
                continue;
            }

            if (z < point.z - 1e-6 || feature.type === 'slot') {
                point.z = z;
                point.part = feature.part;

                if (point.y < 1e-6) station.carvedCentre = true;
            }
        }
    }
}

/**
 * @param {object[]} stations
 * @returns {object}
 */
function loft(stations) {
    const ringSize = 2 * (stations[0].half.length - 1);
    const positions = new Float32Array((stations.length * ringSize + 1) * 3);
    const parts = new Array(stations.length * ringSize + 1);
    const girth = new Array(stations.length * ringSize + 1);
    let v = 0;

    for (const station of stations) {
        for (const point of ring(station.half)) {
            positions.set(scene(station.X, point.y, point.z), v * 3);
            parts[v] = point.part;
            girth[v] = point.g;
            v++;
        }
    }

    const last = stations[stations.length - 1];
    const centre = last.half.reduce((acc, p) => acc + p.z, 0) / last.half.length;
    positions.set(scene(last.X, 0, centre), v * 3);
    parts[v] = 'hull_side';
    girth[v] = 0;

    const groups = new Map();
    const add = (part, a, b, c) => {
        if (!groups.has(part)) groups.set(part, []);
        groups.get(part).push(a, b, c);
    };

    for (let i = 0; i < stations.length - 1; i++) {
        for (let k = 0; k < ringSize; k++) {
            const a = i * ringSize + k;
            const b = i * ringSize + ((k + 1) % ringSize);
            const c = a + ringSize;
            const d = b + ringSize;
            const part = dominant([parts[a], parts[b], parts[c], parts[d]]);
            add(part, a, b, c);
            add(part, b, d, c);
        }
    }

    const base = (stations.length - 1) * ringSize;

    for (let k = 0; k < ringSize; k++) {
        add('hull_side', base + k, v, base + ((k + 1) % ringSize));
    }

    const normals = new Float32Array(positions.length);
    const at = (i) => [positions[i * 3], positions[i * 3 + 1], positions[i * 3 + 2]];

    for (const list of groups.values()) {
        for (let t = 0; t < list.length; t += 3) {
            const [a, b, c] = [list[t], list[t + 1], list[t + 2]];
            const face = cross(sub(at(b), at(a)), sub(at(c), at(a)));

            for (const i of [a, b, c]) {
                normals[i * 3] += face[0];
                normals[i * 3 + 1] += face[1];
                normals[i * 3 + 2] += face[2];
            }
        }
    }

    for (let i = 0; i < normals.length / 3; i++) {
        normals.set(normalize([normals[i * 3], normals[i * 3 + 1], normals[i * 3 + 2]]), i * 3);
    }

    return {
        name: 'hull',
        positions,
        normals,
        ringSize,
        vertexParts: parts,
        girth,
        groups: [...groups.entries()].map(([part, list]) => ({ part, indices: Uint32Array.from(list) })),
    };
}

/**
 * @param {object[]} half
 * @returns {object[]}
 */
function ring(half) {
    const starboard = half.map((p) => ({ y: -p.y, z: p.z, part: p.part, g: -p.g })).reverse();

    return [...starboard, ...half.slice(1, -1)];
}

/**
 * @param {string[]} list
 * @returns {string}
 */
function dominant(list) {
    for (const part of PRIORITY) {
        if (list.includes(part)) return part;
    }

    return list[0];
}

/**
 * @param {object[]} stations
 * @param {object} ctx
 * @returns {{z: function(number, number): ?number, part: function(number, number): ?string}}
 */
function surfaceSampler(stations, ctx) {
    const step = ctx.loa / (stations.length - 1);
    const topOf = (station) => station.half.filter((p) => p.part !== 'hull_underwater' && !(p.part === 'hull_side' && p.z < station.zb - 1e-6));
    const atStation = (station, y) => {
        const top = topOf(station);

        if (y > station.w + 1e-6) return null;

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
        const f = Math.min(stations.length - 1, Math.max(0, X / step));
        const i = Math.min(stations.length - 2, Math.floor(f));
        const t = f - i;
        const [a, b] = [atStation(stations[i], Math.abs(y)), atStation(stations[i + 1], Math.abs(y))];

        if (!a || !b) return null;

        return { z: a.z + (b.z - a.z) * t, part: t < 0.5 ? a.part : b.part, zb: stations[i].zb + (stations[i + 1].zb - stations[i].zb) * t };
    };

    return {
        z: (X, y) => {
            const s = sample(X, y);

            return s ? s.z : null;
        },
        part: (X, y) => {
            const s = sample(X, y);

            return s ? s.part : null;
        },
        sample,
    };
}

/**
 * @param {object} disc
 * @param {object} surface
 * @returns {object[]}
 */
function discMeshes(disc, surface) {
    const tilt = disc.tilt * Math.PI / 180;
    const axis = [Math.sin(tilt), Math.cos(tilt), 0];
    const centre = scene(disc.center.X, disc.center.y, disc.center.z);
    const base = surface.z(disc.center.X, disc.center.y);
    const bottom = sub(centre, scale(axis, DISC_THICKNESS_M / 2));
    const foot = scene(disc.center.X, disc.center.y, base === null ? disc.center.z - 1 : base - 0.3);

    return [
        Object.assign(cylinder(disc.part, centre, axis, disc.diameter / 2, DISC_THICKNESS_M), { name: disc.part + ':' + disc.name, center: centre }),
        Object.assign(cylinder('n1_pedestal', mid(foot, bottom), normalize(sub(bottom, foot)), disc.pedestal / 2, length(sub(bottom, foot))), { name: 'n1_pedestal:' + disc.name }),
    ];
}

/**
 * @param {string} part
 * @param {number[]} centre
 * @param {number[]} axis
 * @param {number} radius
 * @param {number} height
 * @returns {object}
 */
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
        indices.push(a, a + 1, b, b, a + 1, b + 1, tc, a, b, bc, b + 1, a + 1);
    }

    return { name: part, positions: Float32Array.from(positions), groups: [{ part, indices: Uint32Array.from(indices) }] };
}

/**
 * @param {object} rib
 * @param {object} ctx
 * @param {object[]} stations
 * @param {number} gmax
 * @returns {object}
 */
function ribLines(rib, ctx, stations, gmax) {
    const [x0, x1] = [rib.from * ctx.loa, rib.to * ctx.loa];
    const step = ctx.loa / (stations.length - 1);
    const nx = Math.ceil((x1 - x0) / RIB_CELL_M);
    const ny = Math.ceil((2 * gmax) / RIB_CELL_M);
    const point = (X, g) => girthPoint(stations, step, X, g);
    const valid = (X, g) => {
        const s = point(X, g);

        return s !== null && s.part === 'envelope' && s.z > s.zb + 0.3;
    };
    const metric = (X, g) => {
        const along = Math.abs(g);
        const probes = [point(X - 0.05, along), point(X + 0.05, along), point(X, along - 0.05), point(X, along + 0.05)];
        const [a, b, c, d] = probes;

        if (along < 0.05 || probes.some((s) => s === null || s.part !== 'envelope')) return null;

        const rx = [1, (b.y - a.y) / 0.1, (b.z - a.z) / 0.1];
        const rg = [0, (d.y - c.y) / 0.1, (d.z - c.z) / 0.1];
        const [E, F, G] = [dot(rx, rx), dot(rx, rg), dot(rg, rg)];

        return E * G - F * F > 1e-9 ? { E, F, G } : null;
    };
    const grid = [];
    const gradients = [];

    for (let i = 0; i <= nx; i++) {
        grid.push([]);

        for (let j = 0; j <= ny; j++) {
            const [X, g] = [x0 + i * RIB_CELL_M, -gmax + j * RIB_CELL_M];
            const field = ribPhi(rib, ctx.loa, X, g);
            grid[i].push(field.phi);

            const m = i % 4 === 0 && j % 4 === 0 && valid(X, g) ? metric(X, g) : null;

            if (m) {
                const [gx, gg] = [field.gx, field.gy * Math.sign(g)];
                gradients.push({ x: X / ctx.loa, g: Math.sqrt(Math.max(0, (m.G * gx * gx - 2 * m.F * gx * gg + m.E * gg * gg) / (m.E * m.G - m.F * m.F))) });
            }
        }
    }

    const segments = [];
    const angles = [];

    for (let i = 0; i < nx; i++) {
        for (let j = 0; j < ny; j++) {
            const corners = [
                [x0 + i * RIB_CELL_M, -gmax + j * RIB_CELL_M, grid[i][j]],
                [x0 + (i + 1) * RIB_CELL_M, -gmax + j * RIB_CELL_M, grid[i + 1][j]],
                [x0 + (i + 1) * RIB_CELL_M, -gmax + (j + 1) * RIB_CELL_M, grid[i + 1][j + 1]],
                [x0 + i * RIB_CELL_M, -gmax + (j + 1) * RIB_CELL_M, grid[i][j + 1]],
            ];
            const values = corners.map((c) => c[2]);
            const low = Math.ceil(Math.min(...values) / rib.pitch);
            const high = Math.floor(Math.max(...values) / rib.pitch);

            for (let k = low; k <= high; k++) {
                const level = k * rib.pitch;
                const hits = [];

                for (let e = 0; e < 4; e++) {
                    const [a, b] = [corners[e], corners[(e + 1) % 4]];

                    if ((a[2] - level) * (b[2] - level) < 0) {
                        const t = (level - a[2]) / (b[2] - a[2]);
                        hits.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]);
                    }
                }

                if (hits.length < 2 || !valid(hits[0][0], hits[0][1]) || !valid(hits[1][0], hits[1][1])) continue;

                const [p, q] = hits;
                const [pp, qp] = [point(p[0], p[1]), point(q[0], q[1])];
                const side = (g) => (g < 0 ? -1 : 1);
                segments.push(...scene(p[0], pp.y * side(p[1]), pp.z + RIB_LIFT_M), ...scene(q[0], qp.y * side(q[1]), qp.z + RIB_LIFT_M));
                angles.push({ x: (p[0] + q[0]) / 2 / ctx.loa, g: (p[1] + q[1]) / 2, deg: Math.atan2(Math.abs(q[1] - p[1]), Math.abs(q[0] - p[0])) * 180 / Math.PI });
            }
        }
    }

    return { name: rib.part, part: rib.part, positions: Float32Array.from(segments), angles, gradients, rib };
}

/**
 * @param {object[]} stations
 * @param {number} step
 * @param {number} X
 * @param {number} g
 * @returns {?{y: number, z: number, zb: number, part: string}}
 */
function girthPoint(stations, step, X, g) {
    const f = Math.min(stations.length - 1, Math.max(0, X / step));
    const i = Math.min(stations.length - 2, Math.floor(f));
    const t = f - i;
    const [a, b] = [pointAtGirth(stations[i], Math.abs(g)), pointAtGirth(stations[i + 1], Math.abs(g))];

    if (!a || !b) return null;

    return { y: a.y + (b.y - a.y) * t, z: a.z + (b.z - a.z) * t, zb: stations[i].zb + (stations[i + 1].zb - stations[i].zb) * t, part: t < 0.5 ? a.part : b.part };
}

/**
 * @param {object} station
 * @param {number} along
 * @returns {?{y: number, z: number, part: string}}
 */
function pointAtGirth(station, along) {
    const h = station.half;

    for (let j = h.length - 1; j > 0; j--) {
        const [p, q] = [h[j], h[j - 1]];

        if (q.g > p.g && along >= p.g && along <= q.g) {
            const t = (along - p.g) / (q.g - p.g);

            return { y: p.y + (q.y - p.y) * t, z: p.z + (q.z - p.z) * t, part: dominant([p.part, q.part]) };
        }
    }

    return null;
}

/**
 * @param {object} station
 * @param {number} z
 * @returns {?number}
 */
function girthAtHeight(station, z) {
    let best = null;

    for (let j = 0; j < station.half.length - 1; j++) {
        const [a, b] = [station.half[j], station.half[j + 1]];

        if ((a.z - z) * (b.z - z) > 0 || Math.abs(b.z - a.z) < 1e-9) continue;

        const t = (z - a.z) / (b.z - a.z);
        const y = a.y + (b.y - a.y) * t;

        if (best === null || y > best.y) best = { y, g: a.g + (b.g - a.g) * t };
    }

    return best ? best.g : null;
}

/**
 * @param {object} station
 * @param {number} y
 * @returns {?number}
 */
function girthAtY(station, y) {
    const h = station.half;

    for (let j = h.length - 1; j > 0; j--) {
        const [p, q] = [h[j], h[j - 1]];

        if (q.y > p.y && y >= p.y && y <= q.y) return p.g + (q.g - p.g) * (y - p.y) / (q.y - p.y);
    }

    return null;
}

/**
 * @param {object} rib
 * @param {number} loa
 * @param {number} X
 * @param {number} g
 * @returns {{phi: number, gx: number, gy: number}}
 */
function ribPhi(rib, loa, X, g) {
    const k = rib.halfTurn * loa / 2;
    const u = X - rib.turn * loa;
    const v = Math.abs(g) - (rib.turnG ?? rib.turnY ?? 0);
    const d = u - v;
    const sign = g < 0 ? -1 : 1;

    if (d >= k) return { phi: u, gx: 1, gy: 0 };
    if (d <= -k) return { phi: v, gx: 0, gy: sign };

    const w = 0.5 + d / (2 * k);

    return { phi: (u + v) / 2 + (d * d + k * k) / (4 * k), gx: w, gy: (1 - w) * sign };
}

/**
 * @param {object[]} list
 * @param {object} ctx
 * @param {object[]} stations
 * @param {object} surface
 * @returns {{mesh: object, results: object[], openings: object[]}}
 */
function buildWindows(list, ctx, stations, surface) {
    const groups = { window_glass: [], window_clear: [], window_reveal: [], window_cavity: [] };
    const results = [];
    const openings = [];
    const boxes = [];
    const step = ctx.loa / (stations.length - 1);
    const maxDepth = ctx.assumption('G10');
    const rowDepth = ctx.assumption('G13');

    for (const w of list) {
        const centres = [];
        const placed = [];
        const failed = [];
        let conflict = null;

        if (w.at && w.facing === 'forward') {
            for (let k = 0; k < w.facets; k++) {
                centres.push({ X: w.at.X, z: w.at.z, y: w.facets > 1 ? -w.spread / 2 + k * w.spread / (w.facets - 1) : 0, forward: true });
            }
        } else if (w.at) {
            for (const side of w.sides) {
                for (let k = 0; k < w.facets; k++) {
                    centres.push({ X: w.at.X + (k - (w.facets - 1) / 2) * w.facetStep, z: w.at.z, side, landmarkY: Math.abs(w.at.y) });
                }
            }
        } else if (w.deck && w.from !== null && w.to !== null) {
            const [x0, x1] = [w.from * ctx.loa, w.to * ctx.loa];
            const n = w.count !== null ? Math.max(1, Math.round(w.count)) : Math.max(1, Math.floor((x1 - x0) / w.spacing));
            const gap = (x1 - x0) / n;
            const zc = (w.deck.floor + w.deck.ceiling) / 2;

            for (const side of w.sides) {
                for (let k = 0; k < n; k++) {
                    centres.push({ X: x0 + gap * (k + 0.5), z: zc + (w.stagger ? (k % 2 ? -w.stagger : w.stagger) : 0), side });
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

                if (c.X - mouthX > maxDepth) {
                    conflict = { depth: round(c.X - mouthX, 2), surfaceX: round(mouthX / ctx.loa, 3) };
                    continue;
                }

                const design = scene(c.X, c.y, c.z);
                const frame = panelBasis(FORWARD, PORT);
                const at = (q) => add(design, add(scale(frame.right, q[0]), scale(frame.up, q[1])));
                const onSurface = (q) => {
                    const p = at(q);
                    const X = exit(p[2], p[1]);

                    return { point: [X, p[1], p[2]], part: surface.part(Math.min(c.X, X + 0.05), p[2]) || 'envelope', normal: FORWARD };
                };
                pane = { design, frame, mouthCentre: scene(mouthX, c.y, c.z), mouth: flat.map(onSurface), rim: outline(2 * FRAME_M).map(onSurface), glass: flat.map(at), inward: [1, 0, 0], landmark: true };
            } else {
                const s = c.side === 'starboard' ? -1 : 1;
                const hit = lateralAt(stations, step, c.X, c.z);

                if (!hit) {
                    failed.push({ side: c.side, reason: 'không có mặt vỏ tại tâm cửa' });
                    continue;
                }

                const mouthCentre = scene(c.X, hit.y * s, c.z);
                const frame = panelBasis([0, hit.nz, hit.ny * s], [1, 0, 0]);
                const onSurface = (q) => {
                    const p = add(mouthCentre, add(scale(frame.right, q[0]), scale(frame.up, q[1])));
                    const local = lateralAt(stations, step, p[0], p[1]);

                    return local ? { point: scene(p[0], local.y * s, p[1]), part: local.part, normal: [0, local.nz, local.ny * s] } : null;
                };
                const mouth = flat.map(onSurface);
                const inward = [0, 0, -s];
                const landmark = c.landmarkY !== undefined;
                const design = landmark ? scene(c.X, c.landmarkY * s, c.z) : null;
                const glass = landmark
                    ? flat.map((q) => add(design, add(scale(frame.right, q[0]), scale(frame.up, q[1]))))
                    : mouth.map((r) => (r ? add(r.point, scale(inward, rowDepth)) : null));
                pane = { design, frame, mouthCentre, mouth, rim: outline(2 * FRAME_M).map(onSurface), glass, inward, landmark, side: c.side, target: { X: c.X, z: c.z } };
            }

            if (pane.mouth.some((r) => r === null) || pane.rim.some((r) => r === null) || pane.glass.some((p) => p === null)) {
                failed.push({ side: c.side || 'forward', reason: 'mép cửa không bám được mặt vỏ' });
                continue;
            }

            const lift = (r) => add(r.point, scale(normalize(r.normal), FRAME_LIFT_M));
            const mouthLift = pane.mouth.map(lift);
            const rimLift = pane.rim.map(lift);
            const glassCentre = centroid(pane.glass);
            const cavity = pane.glass.map((p) => add(p, scale(pane.inward, CAVITY_M)));
            band(groups.window_reveal, rimLift, mouthLift);
            band(groups.window_reveal, mouthLift, pane.glass);
            fan(w.glass === 'clear' ? groups.window_clear : groups.window_glass, pane.glass);
            band(groups.window_cavity, pane.glass, cavity);
            fan(groups.window_cavity, cavity);

            const stretch = Math.max(...flat.map((q, k) => {
                const next = flat[(k + 1) % flat.length];
                const plain = Math.hypot(next[0] - q[0], next[1] - q[1]);

                return plain > 1e-9 ? Math.abs(length(sub(pane.mouth[(k + 1) % flat.length].point, pane.mouth[k].point)) / plain - 1) : 0;
            }));
            const solid = [...pane.glass, ...pane.rim.map((r) => r.point)];
            const extents = { xmin: Math.min(...solid.map((p) => p[0])), xmax: Math.max(...solid.map((p) => p[0])), zmin: Math.min(...solid.map((p) => p[1])), zmax: Math.max(...solid.map((p) => p[1])) };
            const cuts = [...new Set([...pane.mouth, ...pane.rim].map((r) => r.part).filter((part) => part !== 'hull_side' && part !== 'envelope'))];
            const depth = dot(sub(pane.mouthCentre, glassCentre), scale(pane.inward, -1));
            const edgeDepths = pane.mouth.map((r, k) => dot(sub(r.point, pane.glass[k]), scale(pane.inward, -1)));
            const entry = {
                side: c.side || 'forward', X: glassCentre[0], z: glassCentre[1], y: glassCentre[2], glass: glassCentre,
                design: pane.design, target: pane.target || null, deviation: pane.design ? length(sub(glassCentre, pane.design)) : null,
                depth, edgeDepth: [Math.min(...edgeDepths), Math.max(...edgeDepths)], stretch, extents, failures: cuts.map((part) => 'cắt vào ' + (PART_LABELS[part] || part)),
            };
            placed.push(entry);
            boxes.push({ id: w.id, entry });

            const n = pane.frame.normal;
            const span = [...pane.mouth, ...pane.rim].map((r) => dot(sub(r.point, pane.mouthCentre), n));
            const [dmin, dmax] = [Math.min(...span) - 0.5, Math.max(...span) + 0.5];
            const project = (p) => {
                const rel = sub(p, pane.mouthCentre);
                const d = dot(rel, n);

                return d < dmin || d > dmax ? null : [dot(rel, pane.frame.right), dot(rel, pane.frame.up)];
            };
            const box = bounds([...pane.mouth, ...pane.rim].map((r) => r.point));
            openings.push({ project, outline: pane.mouth.map((r) => project(r.point)), min: sub(box.min, [0.5, 0.5, 0.5]), max: add(box.max, [0.5, 0.5, 0.5]) });
        }

        results.push({ id: w.id, label: w.label, optional: w.optional, deck: w.deck ? w.deck.name : null, landmark: w.at !== null, forward: w.facing === 'forward', from: w.from, to: w.to, height: w.height, placed, failed, expected: centres.length, conflict });
    }

    for (const a of boxes) {
        for (const b of boxes) {
            const [p, q] = [a.entry.extents, b.entry.extents];

            if (a !== b && a.entry.side === b.entry.side && p.xmin < q.xmax && q.xmin < p.xmax && p.zmin < q.zmax && q.zmin < p.zmax) {
                a.entry.failures.push('chồng lên một ô ' + b.id);
            }
        }
    }

    return { mesh: meshFrom('windows', groups), results, openings };
}

/**
 * @param {number[]} normal
 * @param {number[]} along
 * @returns {{normal: number[], up: number[], right: number[]}}
 */
function panelBasis(normal, along) {
    const n = normalize(normal);
    let up = normalize(cross(n, along));

    if (up[1] < 0) up = scale(up, -1);

    return { normal: n, up, right: normalize(cross(up, n)) };
}

/**
 * @param {number[]} target
 * @param {number[][]} a
 * @param {number[][]} b
 */
function band(target, a, b) {
    for (let k = 0; k < a.length; k++) {
        const j = (k + 1) % a.length;
        target.push(...a[k], ...a[j], ...b[k], ...a[j], ...b[j], ...b[k]);
    }
}

/**
 * @param {number[]} target
 * @param {number[][]} ring
 */
function fan(target, ring) {
    const c = centroid(ring);

    for (let k = 0; k < ring.length; k++) {
        target.push(...c, ...ring[k], ...ring[(k + 1) % ring.length]);
    }
}

/**
 * @param {number[][]} points
 * @returns {number[]}
 */
function centroid(points) {
    return scale(points.reduce((acc, p) => add(acc, p), [0, 0, 0]), 1 / points.length);
}

/**
 * @param {object[]} stations
 * @param {number} step
 * @param {number} X
 * @param {number} z
 * @returns {?{y: number, ny: number, nz: number, part: string}}
 */
function lateralAt(stations, step, X, z) {
    const f = Math.min(stations.length - 1, Math.max(0, X / step));
    const i = Math.min(stations.length - 2, Math.floor(f));
    const t = f - i;
    const [a, b] = [lateralHit(stations[i], z), lateralHit(stations[i + 1], z)];

    if (!a || !b) return null;

    const [ny, nz] = [a.ny + (b.ny - a.ny) * t, a.nz + (b.nz - a.nz) * t];
    const l = Math.hypot(ny, nz) || 1;

    return { y: a.y + (b.y - a.y) * t, ny: ny / l, nz: nz / l, part: t < 0.5 ? a.part : b.part };
}

/**
 * @param {object} station
 * @param {number} z
 * @returns {?{y: number, ny: number, nz: number, part: string}}
 */
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
            best = { y, ny: dz / l, nz: -dy / l, part: dominant([a.part, b.part]) };
        }
    }

    return best;
}

/**
 * @param {object} mesh
 * @param {object[]} openings
 */
function punch(mesh, openings) {
    if (!openings.length) return;

    const positions = Array.from(mesh.positions);
    const parts = mesh.vertexParts.slice();
    const girth = mesh.girth.slice();
    const normals = Array.from(mesh.normals);
    const cache = new Map();
    const at = (i) => [positions[i * 3], positions[i * 3 + 1], positions[i * 3 + 2]];
    const normalAt = (i) => [normals[i * 3], normals[i * 3 + 1], normals[i * 3 + 2]];
    const middle = (a, b, part) => {
        const key = a < b ? a + '_' + b : b + '_' + a;

        if (!cache.has(key)) {
            positions.push(...mid(at(a), at(b)));
            normals.push(...normalize(add(normalAt(a), normalAt(b))));
            parts.push(part);
            girth.push((girth[a] + girth[b]) / 2);
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
            const box = bounds(tri.map(at));
            const candidates = openings.filter((o) => [0, 1, 2].every((k) => box.min[k] <= o.max[k] && box.max[k] >= o.min[k]));

            if (candidates.length) split(tri, candidates, g.part, out);
            else out.push(...tri);
        }

        return { part: g.part, indices: Uint32Array.from(out) };
    });
    mesh.positions = Float32Array.from(positions);
    mesh.normals = Float32Array.from(normals);
    mesh.vertexParts = parts;
    mesh.girth = girth;
}

/**
 * @param {number[]} p
 * @param {number[][]} polygon
 * @returns {boolean}
 */
function inPolygon(p, polygon) {
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [a, b] = [polygon[i], polygon[j]];

        if ((a[1] > p[1]) !== (b[1] > p[1]) && p[0] < (b[0] - a[0]) * (p[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
    }

    return inside;
}

/**
 * @param {number[]} p
 * @param {number[][]} tri
 * @returns {boolean}
 */
function inTriangle(p, tri) {
    const side = (a, b) => (b[0] - a[0]) * (p[1] - a[1]) - (b[1] - a[1]) * (p[0] - a[0]);
    const [d1, d2, d3] = [side(tri[0], tri[1]), side(tri[1], tri[2]), side(tri[2], tri[0])];

    return !((d1 < 0 || d2 < 0 || d3 < 0) && (d1 > 0 || d2 > 0 || d3 > 0));
}

/**
 * @param {number[][]} tri
 * @param {number[][]} polygon
 * @returns {boolean}
 */
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

/**
 * @param {string} shape
 * @param {number} width
 * @param {number} height
 * @param {number} tilt
 * @returns {number[][]}
 */
function panelOutline(shape, width, height, tilt) {
    if (shape === 'oval') {
        return Array.from({ length: 24 }, (_, k) => [Math.cos(k / 24 * Math.PI * 2) * width / 2, Math.sin(k / 24 * Math.PI * 2) * height / 2]);
    }

    const shift = shape === 'oblique' ? height * Math.tan(tilt * Math.PI / 180) / 2 : 0;

    return [[-width / 2 - shift, -height / 2], [width / 2 - shift, -height / 2], [width / 2 + shift, height / 2], [-width / 2 + shift, height / 2]];
}

/**
 * @param {object[]} list
 * @param {object[]} carving
 * @param {object} ctx
 * @param {object[]} stations
 * @param {object} surface
 * @param {object} hull
 * @returns {object}
 */
function buildDetails(list, carving, ctx, stations, surface, hull) {
    const groups = {};
    const measures = {};
    let current = null;
    const record = (name, design, value) => {
        const dims = measures[current].dims;
        let dim = dims.find((item) => item.name === name);

        if (!dim) {
            dim = { name, design, min: null, max: null };
            dims.push(dim);
        }

        if (isNumber(value)) {
            dim.min = dim.min === null ? value : Math.min(dim.min, value);
            dim.max = dim.max === null ? value : Math.max(dim.max, value);
        }
    };
    const quad = (part, a, b, c, d) => {
        groups[part] = groups[part] || [];
        groups[part].push(...a, ...b, ...c, ...b, ...d, ...c);
    };
    const span = (from, to) => {
        const out = [];

        for (let X = from; X < to; X += STATION_STEP_M) out.push(X);
        out.push(to);

        return out;
    };
    const strip = (part, from, to, inner, outer, name, design) => {
        const xs = span(from, to);

        for (const s of [1, -1]) {
            for (let k = 0; k < xs.length - 1; k++) {
                const middle = (X) => (inner(X) + outer(X)) / 2;
                const corner = (X, y) => {
                    const z = surface.z(X, middle(X));

                    return z === null ? null : scene(X, y * s, z + FLUSH_LIFT_M);
                };
                const pts = [corner(xs[k], inner(xs[k])), corner(xs[k], outer(xs[k])), corner(xs[k + 1], inner(xs[k + 1])), corner(xs[k + 1], outer(xs[k + 1]))];

                if (!pts.every(Boolean)) continue;

                quad(part, ...pts);
                record(name, design, Math.abs(pts[1][2] - pts[0][2]));

                for (const p of pts) record('nổi so với mặt sàn', 0, p[1] - surface.z(p[0], middle(p[0])));
            }
        }
    };

    for (const d of list) {
        current = d.id;
        measures[current] = measures[current] || { type: d.type, dims: [] };

        if (d.type === 'bulwark') {
            const xs = span(d.from * ctx.loa, d.to * ctx.loa);

            for (const s of [1, -1]) {
                const section = (X) => {
                    const [w, z] = [ctx.width(X / ctx.loa), ctx.sideTop(X / ctx.loa)];

                    return { outer: scene(X, w * s, z), outerTop: scene(X, w * s, z + d.height), inner: scene(X, (w - d.thickness) * s, z), innerTop: scene(X, (w - d.thickness) * s, z + d.height) };
                };

                for (let k = 0; k < xs.length - 1; k++) {
                    const [a, b] = [section(xs[k]), section(xs[k + 1])];
                    quad(d.part, a.outer, a.outerTop, b.outer, b.outerTop);
                    quad(d.part, a.inner, a.innerTop, b.inner, b.innerTop);
                    quad(d.part, a.outerTop, a.innerTop, b.outerTop, b.innerTop);

                    for (const [end, X] of [[a, xs[k]], [b, xs[k + 1]]]) {
                        record('dày', d.thickness, Math.abs(end.outer[2] - end.inner[2]));
                        record('cao trên mép thân vỏ', d.height, end.outerTop[1] - ctx.sideTop(X / ctx.loa));
                    }
                }

                for (const end of [section(xs[0]), section(xs[xs.length - 1])]) {
                    quad(d.part, end.outer, end.outerTop, end.inner, end.innerTop);
                }
            }
        } else if (d.type === 'coaming') {
            const slot = carving.find((f) => f.type === 'slot' && f.part === d.of);

            if (!slot) {
                ctx.missing.push(d.id + ': không có khe ' + d.of);
                continue;
            }

            const half = slot.width / 2;
            const r = Math.min(slot.radius || 0, half);
            const offset = d.rim / 2;
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
                const ring = [];

                for (let k = 0; k <= COAMING_SAMPLES; k++) {
                    const a = Math.PI * k / COAMING_SAMPLES;
                    const o = -Math.cos(a) * offset;
                    const e = Math.max(o, -offset + 0.03);
                    const base = surface.z(P.X + P.nX * e, P.y + P.ny * e);

                    if (base === null) return null;

                    ring.push({ point: scene(P.X + P.nX * o, P.y + P.ny * o, base + d.height * Math.sin(a)), base });
                }

                return ring;
            });

            for (let i = 0; i < rings.length; i++) {
                const [a, b] = [rings[i], rings[(i + 1) % rings.length]];

                if (!a || !b) continue;

                for (let k = 0; k < COAMING_SAMPLES; k++) quad(d.part, a[k].point, a[k + 1].point, b[k].point, b[k + 1].point);

                record('rộng', d.rim, Math.hypot(a[COAMING_SAMPLES].point[0] - a[0].point[0], a[COAMING_SAMPLES].point[2] - a[0].point[2]));
                record('cao (đỉnh bo tròn)', d.height, Math.max(...a.map((p) => p.point[1] - p.base)));
            }

            if (rings.some((ring) => ring === null)) measures[current].broken = true;
        } else if (d.type === 'edge') {
            const pockets = carving.filter((f) => f.type === 'pocket' && f.part === d.of);

            if (!pockets.length) ctx.missing.push(d.id + ': không có hốc ' + d.of);

            for (const p of pockets) {
                strip(d.part, p.from, p.to, (X) => ctx.width(X / ctx.loa) - p.wall - d.width, (X) => ctx.width(X / ctx.loa) - p.wall, 'rộng', d.width);
            }
        } else if (d.type === 'rails') {
            const channel = carving.find((f) => f.type === 'channel' && f.part === d.of);

            if (!channel) {
                ctx.missing.push(d.id + ': không có rãnh ' + d.of);
                continue;
            }

            const floor = (X) => channel.from.z + (channel.to.z - channel.from.z) * (X - channel.from.X) / Math.max(1e-6, channel.to.X - channel.from.X);
            const grooves = vertices(hull, 'slipway_rail');
            const lateral = grooves.map((p) => Math.abs(p[2]));

            record('khoảng cách hai ray', d.spacing, grooves.length ? Math.min(...lateral) + Math.max(...lateral) : NaN);
            record('rộng rãnh ray', d.rail, grooves.length ? Math.max(...lateral) - Math.min(...lateral) : NaN);

            for (const p of grooves) record('sâu rãnh ray', d.depth, floor(p[0]) - p[1]);

            strip('accent_edge', channel.from.X, channel.to.X, () => channel.width / 2 - d.marker, () => channel.width / 2, 'rộng viền', d.marker);
        }
    }

    return Object.assign(meshFrom('details', groups), { measures });
}

/**
 * @param {string} name
 * @param {Object<string, number[]>} groups
 * @returns {object}
 */
function meshFrom(name, groups) {
    const parts = Object.entries(groups).filter(([, list]) => list.length);
    const positions = new Float32Array(parts.reduce((n, [, list]) => n + list.length, 0));
    let offset = 0;

    return {
        name,
        positions,
        groups: parts.map(([part, list]) => {
            positions.set(list, offset);
            const first = offset / 3;
            offset += list.length;

            return { part, indices: Uint32Array.from({ length: list.length / 3 }, (_, k) => first + k) };
        }),
    };
}

/**
 * @param {object} field
 * @param {number} width
 * @param {number} height
 * @returns {Uint8Array}
 */
export function ribNormalMap(field, width, height) {
    const data = new Uint8Array(width * height * 4);
    const [x0, x1] = [field.from * field.loa, field.to * field.loa];
    const amplitude = field.depth / 2 * 2 * Math.PI / field.pitch;

    for (let j = 0; j < height; j++) {
        const y = -field.gmax + (j + 0.5) / height * 2 * field.gmax;

        for (let i = 0; i < width; i++) {
            const X = (i + 0.5) / width * field.loa;
            const o = (j * width + i) * 4;
            let [nx, ny, nz] = [0, 0, 1];
            const limit = field.limits[Math.min(field.limits.length - 1, Math.round(X / field.step))];

            if (X >= x0 && X <= x1 && Math.abs(y) < limit) {
                const rib = ribPhi(field, field.loa, X, y);
                const slope = amplitude * Math.cos(2 * Math.PI * rib.phi / field.pitch);
                [nx, ny] = [-slope * rib.gx, -slope * rib.gy];
                const l = Math.hypot(nx, ny, 1);
                [nx, ny, nz] = [nx / l, ny / l, 1 / l];
            }

            data[o] = Math.round((nx * 0.5 + 0.5) * 255);
            data[o + 1] = Math.round((ny * 0.5 + 0.5) * 255);
            data[o + 2] = Math.round((nz * 0.5 + 0.5) * 255);
            data[o + 3] = 255;
        }
    }

    return data;
}

/**
 * @param {string} view
 * @param {object} frame
 * @param {object[]} meshes
 * @param {object} ctx
 * @param {number} fill
 * @returns {object}
 */
function camera(view, frame, meshes, ctx, fill) {
    const value = (ref) => (typeof ref === 'number' ? ref : lookup(ref, ctx));
    const width = Number(frame.width);
    const height = Number(frame.height);
    const aspect = width / height;
    const points = targetPoints(meshes, frame.target);
    const azimuth = value(frame.azimuth) * Math.PI / 180;
    const horizontal = add(scale(FORWARD, Math.cos(azimuth)), scale(PORT, Math.sin(azimuth)));
    const box = bounds(points);
    let target = scale(add(box.min, box.max), 0.5);

    if (frame.projection === 'orthographic') {
        const elevation = value(frame.elevation) * Math.PI / 180;
        const dir = normalize(add(scale(horizontal, Math.cos(elevation)), scale(UP, Math.sin(elevation))));
        const up = Math.abs(Math.sin(elevation)) > 0.999 ? FORWARD : UP;
        const basis = cameraBasis(dir, up);
        const xs = points.map((p) => dot(sub(p, target), basis.x));
        const ys = points.map((p) => dot(sub(p, target), basis.y));
        const [cx, cy] = [(Math.max(...xs) + Math.min(...xs)) / 2, (Math.max(...ys) + Math.min(...ys)) / 2];
        target = add(target, add(scale(basis.x, cx), scale(basis.y, cy)));
        const halfW = Math.max((Math.max(...xs) - Math.min(...xs)) / 2 / fill, (Math.max(...ys) - Math.min(...ys)) / 2 / fill * aspect);
        const halfH = halfW / aspect;
        const distance = length(sub(box.max, box.min)) * 2 + 50;

        return {
            view, projection: 'orthographic', width, height, up,
            position: add(target, scale(dir, distance)), target,
            ortho: { left: -halfW, right: halfW, top: halfH, bottom: -halfH },
            near: 0.1, far: distance * 2,
        };
    }

    const filmHeight = 36 / Math.max(aspect, 1);
    const tanV = filmHeight / 2 / Number(frame.focal_mm);
    const tanH = tanV * aspect;
    const fov = 2 * Math.atan(tanV) * 180 / Math.PI;
    const place = (d) => {
        if (frame.eye_height !== undefined) {
            const pos = add(target, scale(horizontal, d));
            pos[1] = value(frame.eye_height);

            return pos;
        }

        const elevation = value(frame.elevation) * Math.PI / 180;

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

    const position = place(distance);

    return { view, projection: 'perspective', width, height, up: UP, position, target, fov, focal_mm: Number(frame.focal_mm), near: 0.5, far: distance * 4 + 500 };
}

/**
 * @param {object[]} meshes
 * @param {string} target
 * @returns {number[][]}
 */
function targetPoints(meshes, target) {
    const points = [];
    const n1 = target === 'n1';

    for (const mesh of meshes) {
        const wanted = n1 ? new Set(mesh.groups.filter((g) => g.part.startsWith('n1_')).flatMap((g) => Array.from(g.indices))) : null;
        const stride = n1 ? 1 : (mesh.name === 'hull' ? 5 : 1);

        for (let v = 0; v < mesh.positions.length / 3; v += stride) {
            if (wanted && !wanted.has(v)) continue;
            points.push([mesh.positions[v * 3], mesh.positions[v * 3 + 1], mesh.positions[v * 3 + 2]]);
        }
    }

    return points;
}

/**
 * @param {number[]} dir
 * @param {number[]} up
 * @returns {{x: number[], y: number[], z: number[]}}
 */
function cameraBasis(dir, up) {
    const z = normalize(dir);
    const x = normalize(cross(up, z));

    return { x, y: cross(z, x), z };
}

/**
 * @param {object} input
 * @param {object} ctx
 * @param {object} features
 * @param {object[]} stations
 * @param {object[]} meshes
 * @param {object[]} lines
 * @param {object[]} cameras
 * @param {object[]} windows
 * @param {Object<string, object>} detailMeasures
 * @returns {object}
 */
function buildReport(input, ctx, features, stations, meshes, lines, cameras, windows, detailMeasures) {
    const tol = ctx.tolerances;
    const checks = [];
    const check = (key, label, design, measured, tolerance, value, min, max, optional = false) => {
        const bounded = isNumber(value) && (min === null || value >= min - 1e-9) && (max === null || value <= max + 1e-9);
        checks.push({ key, label, design: String(design), measured: String(measured), tolerance: String(tolerance), value: isNumber(value) ? value : null, min, max, optional, pass: bounded, warn: optional && !bounded });
    };
    const silhouette = (station) => Math.max(...station.half.map((p) => p.z));
    const open = stations.filter((s) => !s.half.some((p) => CARVED.includes(p.part)));
    const profileError = Math.max(...open.map((s) => Math.abs(silhouette(s) - ctx.top(s.x))));
    const planError = Math.max(...stations.map((s) => Math.abs(Math.max(...s.half.map((p) => p.y)) - ctx.width(s.x))));
    const crestStation = open.reduce((best, s) => (silhouette(s) > silhouette(best) ? s : best), open[0]);
    const crestMark = (ctx.canonical.landmarks || {}).crest;
    const maxHalf = Math.max(...stations.map((s) => s.w));
    const ratio = ctx.loa / (2 * maxHalf);

    check('profile', 'Đường bao mặt bên (điểm cao nhất mỗi mặt cắt) so với profile_curve, ' + open.length + ' trạm ngoài vùng khoét', '0 m', round(profileError, 3) + ' m', '≤ ' + tol.profile_m + ' m', profileError, 0, tol.profile_m);
    check('plan', 'Bề rộng so với plan_outline (sai số lớn nhất, mọi trạm)', '0 m', round(planError, 3) + ' m', '≤ ' + tol.plan_m + ' m', planError, 0, tol.plan_m);

    if (crestMark && isNumber(crestMark.x) && isNumber(crestMark.z)) {
        check('crest_x', 'Vị trí đỉnh vỏ (x)', crestMark.x, round(crestStation.x, 4), '± ' + tol.crest_x, Math.abs(crestStation.x - crestMark.x), 0, tol.crest_x);
        check('crest_z', 'Độ cao đỉnh vỏ', crestMark.z + ' m', round(silhouette(crestStation), 3) + ' m', '± ' + tol.crest_z_m + ' m', Math.abs(silhouette(crestStation) - crestMark.z), 0, tol.crest_z_m);
    } else {
        ctx.missing.push('canonical.landmarks.crest');
    }

    if (ctx.beam > 0) {
        check('ratio', 'Tỉ lệ dài / rộng', round(ctx.loa / ctx.beam, 3), round(ratio, 3), '± ' + tol.ratio, Math.abs(ratio - ctx.loa / ctx.beam), 0, tol.ratio);
    }

    const fitted = ctx.decks.filter((deck) => Array.from({ length: 21 }, (_, k) => deck.from + (deck.to - deck.from) * k / 20)
        .every((x) => isNumber(deck.ceiling) && deck.ceiling <= ctx.top(x) + tol.deck_m && deck.floor >= -ctx.draft - tol.deck_m));
    check('decks', 'Số boong nằm trọn trong vỏ proxy (trần ≤ đường bao + ' + tol.deck_m + ' m trên cả khoảng x)', ctx.decks.length + ' boong', fitted.length + ' boong', 'bằng nhau', fitted.length, ctx.decks.length, ctx.decks.length);

    for (const slot of features.carving.filter((f) => f.type === 'slot')) {
        const cap = vertices(meshes[0], slot.part);
        const len = cap.length ? Math.max(...cap.map((p) => p[0])) - Math.min(...cap.map((p) => p[0])) : 0;
        const wid = cap.length ? 2 * Math.max(...cap.map((p) => Math.abs(p[2]))) : 0;
        const expected = slot.to - slot.from;
        check('slot', 'Khe canyon (dài × rộng), bo góc ' + slot.radius + ' m', round(expected, 2) + ' × ' + round(slot.width, 2) + ' m', round(len, 2) + ' × ' + round(wid, 2) + ' m', '± ' + tol.detail_m + ' m',
            Math.max(Math.abs(len - expected), Math.abs(wid - slot.width)), 0, tol.detail_m);
    }

    for (const disc of meshes.filter((m) => m.center)) {
        const source = features.discs.find((d) => disc.name === d.part + ':' + d.name);
        const expected = scene(source.center.X, source.center.y, source.center.z);
        check('disc:' + source.name, 'Tâm ' + source.name.replace('landmarks.', ''), fmtPoint(expected), fmtPoint(disc.center), '≤ ' + tol.landmark_m + ' m', length(sub(disc.center, expected)), 0, tol.landmark_m);
    }

    const pockets = features.carving.filter((f) => f.type === 'pocket');

    if (pockets.length) {
        const coverage = pockets.map((p) => {
            const inside = stations.filter((s) => s.X >= p.from - EDGE_EPS && s.X <= p.to + EDGE_EPS)
                .flatMap((s) => s.half.filter((h) => h.part !== 'hull_underwater' && h.part !== 'hull_side' && h.y < s.w - p.wall));

            return inside.length ? inside.filter((h) => h.z <= p.floor + 1e-6).length / inside.length : 0;
        });
        check('pockets', 'Hốc terrace: tỉ lệ mặt sàn hạ đúng cao độ trong khung hốc (hốc kém nhất), ' + pockets.length + ' hốc', '100%', coverage.map((c) => round(c * 100, 1) + '%').join(' · '), '≥ ' + round(tol.pocket_coverage * 100, 0) + '%', Math.min(...coverage), tol.pocket_coverage, null);
    }

    for (const line of lines) {
        const longEnd = line.rib.turn - line.rib.halfTurn - 0.04;
        const transStart = line.rib.turn + line.rib.halfTurn + 0.04;
        const longAngles = line.angles.filter((a) => a.x <= longEnd).map((a) => a.deg);
        const transAngles = line.angles.filter((a) => a.x >= transStart).map((a) => a.deg);
        const longMax = longAngles.length ? Math.max(...longAngles) : NaN;
        const transMin = transAngles.length ? Math.min(...transAngles) : NaN;
        const g = line.gradients.map((s) => s.g).filter((v) => v > 0);
        const spread = g.length ? Math.max(Math.max(...g), 1 / Math.min(...g)) : NaN;
        check('rib_long', 'Gân N2 chạy dọc tàu (x ≤ ' + round(longEnd, 2) + ')', '0°', round(longMax, 1) + '°', '< ' + tol.rib_longitudinal_deg + '°', longMax, 0, tol.rib_longitudinal_deg);
        check('rib_trans', 'Gân N2 chạy ngang tàu (x ≥ ' + round(transStart, 2) + ')', '90°', round(transMin, 1) + '°', '> ' + tol.rib_transverse_deg + '°', transMin, tol.rib_transverse_deg, 90);
        check('rib_spacing', 'Khoảng cách gân N2 đo dọc mặt vỏ trên toàn vùng, gồm vùng xoay hình quạt (bước ' + line.rib.pitch + ' m; gân hiển thị bằng normal map, không phải hình học)', line.rib.pitch + ' m',
            g.length ? round(line.rib.pitch / Math.max(...g), 3) + '–' + round(line.rib.pitch / Math.min(...g), 3) + ' m' : '—', 'lệch ≤ ×' + tol.rib_spacing_factor, spread, 1, tol.rib_spacing_factor);
    }

    const plan = cameras.find((c) => c.view === 'MS6');

    if (plan) {
        const basis = cameraBasis(normalize(sub(plan.position, plan.target)), plan.up);
        const right = dot(basis.y, FORWARD) > 0.999 && dot(basis.x, PORT) < -0.999 && plan.height > plan.width;
        check('ms6', 'MS6: mũi lên trên, mạn trái bên trái ảnh, khung dọc', 'đúng', right ? 'đúng' : 'sai', '—', right ? 1 : 0, 1, 1);
    }

    const maxDepth = ctx.assumption('G10');
    const rowDepth = ctx.assumption('G13');
    const stretchLimit = tol.window_stretch;
    const dimTol = tol.detail_dim_m;

    for (const w of windows) {
        const name = w.id + ' · ' + w.label + (w.optional ? ' (tuỳ chọn)' : '');
        const missing = w.expected - w.placed.length;
        const notes = w.failed.map((f) => f.side + ': ' + f.reason);

        if (w.conflict) {
            check('window:' + w.id, name + ' — tâm kính tại mốc thiết kế; mốc nằm sâu ' + w.conflict.depth + ' m trong vỏ proxy (mặt vỏ gần nhất phía trước ở x ' + w.conflict.surfaceX + ') nên không dựng — cần sửa dữ liệu thiết kế, đổi giả định hoặc khai chi tiết là tuỳ chọn',
                'mốc thiết kế', 'không dựng', '≤ ' + tol.landmark_m + ' m', null, 0, tol.landmark_m, w.optional);
            check('recess:' + w.id, name + ' — độ lõm của kính sau mặt vỏ', '0–' + maxDepth + ' m', w.conflict.depth + ' m', '0–' + maxDepth + ' m', w.conflict.depth, 0, maxDepth, w.optional);
            continue;
        }

        const failing = w.placed.filter((p) => p.failures.length || (!w.landmark && p.stretch > stretchLimit));
        const stretch = w.placed.length ? Math.max(...w.placed.map((p) => p.stretch)) : NaN;
        notes.push(...failing.map((p) => p.side + ' x ' + round(p.X / ctx.loa, 3) + ': ' + [...p.failures, ...(!w.landmark && p.stretch > stretchLimit ? ['méo ' + round(p.stretch * 100, 1) + '%'] : [])].join(', ')));
        const depths = w.placed.map((p) => p.depth);
        const edgeMin = w.placed.length ? Math.min(...w.placed.map((p) => p.edgeDepth[0])) : NaN;
        const edgeMax = w.placed.length ? Math.max(...w.placed.map((p) => p.edgeDepth[1])) : NaN;
        const depthText = depths.length ? 'tâm ' + round(Math.min(...depths), 3) + '–' + round(Math.max(...depths), 3) + ' m · mép ' + round(edgeMin, 3) + '–' + round(edgeMax, 3) + ' m' : '—';

        if (w.landmark) {
            const deviation = missing === 0 && failing.length === 0 && w.placed.length ? Math.max(...w.placed.map((p) => p.deviation)) : NaN;
            const target = w.placed[0] && w.placed[0].design ? fmtPoint(w.placed[0].design) : '—';
            check('window:' + w.id, name + ' — tâm kính đo trên mesh so với mốc thiết kế' + (w.expected > 1 ? ' (từng ô, theo bước/độ trải đã duyệt)' : ''),
                w.expected + ' ô · mốc ' + target, w.placed.length + ' ô · lệch tối đa ' + round(deviation, 4) + ' m · méo miệng hốc ' + round(stretch * 100, 1) + '%' + (notes.length ? ' · ' + notes.join('; ') : ''), '≤ ' + tol.landmark_m + ' m', deviation, 0, tol.landmark_m, w.optional);
            const depth = depths.length ? (edgeMin < 0 ? edgeMin : Math.max(...depths)) : NaN;
            check('recess:' + w.id, name + ' — độ lõm của kính sau mặt vỏ, đo trên mesh (tâm: mặt vỏ → tâm kính; mép: từng đỉnh miệng hốc → đỉnh kính, không đỉnh nào lòi ra ngoài)', '0–' + maxDepth + ' m', depthText, '0–' + maxDepth + ' m', depth, 0, maxDepth, w.optional);
        } else {
            const deck = ctx.decks.find((d) => d.name === w.deck);
            const outside = deck ? w.placed.filter((p) => p.extents.zmin < deck.floor - 1e-6 || p.extents.zmax > deck.ceiling + 1e-6 || p.extents.xmin < w.from * ctx.loa - EDGE_EPS || p.extents.xmax > w.to * ctx.loa + EDGE_EPS) : w.placed;

            for (const p of outside) {
                if (!failing.includes(p)) {
                    failing.push(p);
                    notes.push(p.side + ' x ' + round(p.X / ctx.loa, 3) + ': kính/khung ra ngoài dải (x ' + round(p.extents.xmin / ctx.loa, 3) + '–' + round(p.extents.xmax / ctx.loa, 3) + ', z ' + round(p.extents.zmin, 2) + '–' + round(p.extents.zmax, 2) + ')');
                } else {
                    notes.push(p.side + ' x ' + round(p.X / ctx.loa, 3) + ': kính/khung ra ngoài dải');
                }
            }

            check('window:' + w.id, name + ' — mọi đỉnh kính và khung nằm trong dải boong ' + w.deck + (deck ? ' (z ' + deck.floor + '–' + deck.ceiling + ' m)' : '') + ' và khoảng x ' + w.from + '–' + w.to + '; méo ≤ ' + round(stretchLimit * 100, 0) + '%; không chồng ô khác; không cắt vào phần khoét',
                w.expected + ' ô đạt', (w.placed.length - failing.length) + '/' + w.expected + ' ô đạt · méo tối đa ' + round(stretch * 100, 1) + '%' + (notes.length ? ' · ' + notes.join('; ') : ''), '0 ô lỗi', failing.length + missing, 0, 0, w.optional);
            const recess = depths.length === w.expected ? Math.max(Math.abs(edgeMin - rowDepth), Math.abs(edgeMax - rowDepth)) : NaN;
            check('recess:' + w.id, name + ' — độ lõm của kính sau mặt vỏ, đo trên mesh từng đỉnh miệng hốc → đỉnh kính (G13)', rowDepth + ' m', depthText, '± ' + dimTol + ' m', recess, 0, dimTol, w.optional);
        }
    }

    for (const d of features.details) {
        const m = detailMeasures[d.id] || { dims: [] };
        const value = m.broken || !m.dims.length || m.dims.some((dim) => dim.min === null) ? NaN : Math.max(...m.dims.map((dim) => Math.max(Math.abs(dim.min - dim.design), Math.abs(dim.max - dim.design))));
        check('detail:' + d.id, d.id + ' · ' + d.type + ' — kích thước đo trên mesh',
            m.dims.map((dim) => dim.name + ' ' + round(dim.design, 3) + ' m').join(' · ') || '—',
            m.dims.map((dim) => dim.name + ' ' + (dim.min === null ? '—' : round(dim.min, 3) + (round(dim.max, 3) !== round(dim.min, 3) ? '–' + round(dim.max, 3) : '')) + ' m').join(' · ') + (m.broken ? ' · có đoạn không bám được mặt vỏ' : ''),
            '± ' + dimTol + ' m', value, 0, dimTol);
    }

    return {
        builder: input.builder || '',
        builder_sha256: input.builder_sha256 || '',
        input_sha256: input.input_sha256 || '',
        checks,
        missing: [...new Set(ctx.missing)],
        pass: checks.filter((c) => !c.optional).every((c) => c.pass) && ctx.missing.length === 0,
        assumptions: Object.entries(input.assumptions || {}).map(([code, a]) => ({ code, label: a.label, value: a.value, unit: a.unit })),
        stats: {
            stations: stations.length,
            vertices: meshes.reduce((n, m) => n + m.positions.length / 3, 0),
            triangles: meshes.reduce((n, m) => n + m.groups.reduce((k, g) => k + g.indices.length / 3, 0), 0),
            rib_segments: lines.reduce((n, l) => n + l.positions.length / 6, 0),
        },
    };
}

/**
 * @param {object} mesh
 * @param {string} part
 * @returns {number[][]}
 */
function vertices(mesh, part) {
    const out = [];

    for (let v = 0; v < mesh.vertexParts.length; v++) {
        if (mesh.vertexParts[v] === part) out.push([mesh.positions[v * 3], mesh.positions[v * 3 + 1], mesh.positions[v * 3 + 2]]);
    }

    return out;
}

/**
 * @param {number[][]} outline
 * @param {number} count
 * @returns {number[][]}
 */
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

        const span = lengths[i] - lengths[i - 1];
        const t = span > 0 ? (target - lengths[i - 1]) / span : 0;
        out.push([outline[i - 1][0] + (outline[i][0] - outline[i - 1][0]) * t, outline[i - 1][1] + (outline[i][1] - outline[i - 1][1]) * t]);
    }

    return out;
}

/**
 * @param {number[][]} points
 * @param {number} x
 * @returns {number}
 */
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

/**
 * @param {*} list
 * @param {string} label
 * @param {string[]} missing
 * @returns {number[][]}
 */
function pairs(list, label, missing) {
    const out = (Array.isArray(list) ? list : []).filter((p) => Array.isArray(p) && isNumber(p[0]) && isNumber(p[1])).map((p) => [Number(p[0]), Number(p[1])]);

    if (out.length < 3) missing.push(label);

    return out;
}

/**
 * @param {*} entry
 * @returns {number}
 */
function metres(entry) {
    if (isNumber(entry)) return Number(entry);
    if (!entry || !isNumber(entry.value)) return NaN;

    return String(entry.unit || 'm').trim() === 'mm' ? entry.value / 1000 : Number(entry.value);
}

/**
 * @param {number} X
 * @param {number} y
 * @param {number} z
 * @returns {number[]}
 */
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
function bounds(points) {
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

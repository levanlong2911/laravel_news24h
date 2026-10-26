/**
 * Ghi / doc glTF 2.0 nhi phan (GLB) cho proxy. Thuan JS (DataView, TextEncoder): worker Node
 * dung de xuat artifact chinh thuc va doc lai kiem tra; viewer trinh duyet dung de hien thi.
 *
 * Moi mesh cua bo dung = mot node ten = ma bo phan (extras.part), mot primitive tam giac voi
 * POSITION, NORMAL, chi so uint32. Node goc "proxy" mang ti le (BS05) va extras (trang thai, spec).
 */

const GLB_MAGIC = 0x46546c67;
const CHUNK_JSON = 0x4e4f534a;
const CHUNK_BIN = 0x004e4942;
const FLOAT = 5126;
const UINT32 = 5125;

/**
 * @param {object[]} meshes {name, part, owner?, positions: Float32Array, normals: Float32Array, indices: Uint32Array}
 * @param {{scale?: number, extras?: object}} options
 * @returns {Uint8Array}
 */
export function toGlb(meshes, options = {}) {
    const views = [];
    const accessors = [];
    const chunks = [];
    let offset = 0;
    const push = (array, target) => {
        const bytes = new Uint8Array(array.buffer, array.byteOffset, array.byteLength);
        views.push({ buffer: 0, byteOffset: offset, byteLength: bytes.byteLength, target });
        chunks.push(bytes);
        offset += bytes.byteLength;
        const pad = (4 - (offset % 4)) % 4;

        if (pad) {
            chunks.push(new Uint8Array(pad));
            offset += pad;
        }

        return views.length - 1;
    };
    const gltfMeshes = [];
    const nodes = [];

    for (const m of meshes) {
        const [min, max] = range(m.positions);
        const pos = accessors.push({ bufferView: push(m.positions, 34962), componentType: FLOAT, count: m.positions.length / 3, type: 'VEC3', min, max }) - 1;
        const nor = accessors.push({ bufferView: push(m.normals, 34962), componentType: FLOAT, count: m.normals.length / 3, type: 'VEC3' }) - 1;
        const idx = accessors.push({ bufferView: push(m.indices, 34963), componentType: UINT32, count: m.indices.length, type: 'SCALAR' }) - 1;
        gltfMeshes.push({ name: m.name, primitives: [{ attributes: { POSITION: pos, NORMAL: nor }, indices: idx, mode: 4 }] });
        nodes.push({ name: m.name, mesh: gltfMeshes.length - 1, extras: { part: m.part, owner: m.owner || m.part } });
    }

    const s = options.scale ?? 1;
    const root = { name: 'proxy', children: nodes.map((_, i) => i), extras: options.extras || {} };

    if (s !== 1) root.scale = [s, s, s];

    nodes.push(root);

    const json = {
        asset: { version: '2.0', generator: 'yacht proxy builder' },
        scene: 0,
        scenes: [{ nodes: [nodes.length - 1] }],
        nodes,
        meshes: gltfMeshes,
        accessors,
        bufferViews: views,
        buffers: [{ byteLength: offset }],
    };
    let text = new TextEncoder().encode(JSON.stringify(json));
    const jsonPad = (4 - (text.byteLength % 4)) % 4;

    if (jsonPad) {
        const padded = new Uint8Array(text.byteLength + jsonPad).fill(0x20);
        padded.set(text);
        text = padded;
    }

    const total = 12 + 8 + text.byteLength + 8 + offset;
    const out = new Uint8Array(total);
    const view = new DataView(out.buffer);
    view.setUint32(0, GLB_MAGIC, true);
    view.setUint32(4, 2, true);
    view.setUint32(8, total, true);
    view.setUint32(12, text.byteLength, true);
    view.setUint32(16, CHUNK_JSON, true);
    out.set(text, 20);
    let at = 20 + text.byteLength;
    view.setUint32(at, offset, true);
    view.setUint32(at + 4, CHUNK_BIN, true);
    at += 8;

    for (const c of chunks) {
        out.set(c, at);
        at += c.byteLength;
    }

    return out;
}

/**
 * Doc lai GLB va kiem cau truc. Loi cau truc -> throw (khong doan).
 *
 * @param {Uint8Array} bytes
 * @returns {{json: object, root: object, nodes: object[], bounds: {min: number[], max: number[]}, triangles: number}}
 */
export function readGlb(bytes) {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);

    if (bytes.byteLength < 28 || view.getUint32(0, true) !== GLB_MAGIC) throw new Error('không phải glTF nhị phân');
    if (view.getUint32(4, true) !== 2 || view.getUint32(8, true) !== bytes.byteLength) throw new Error('sai phiên bản hoặc độ dài khai báo');

    const jsonLength = view.getUint32(12, true);

    if (view.getUint32(16, true) !== CHUNK_JSON || 20 + jsonLength > bytes.byteLength) throw new Error('thiếu chunk JSON');

    const json = JSON.parse(new TextDecoder().decode(bytes.subarray(20, 20 + jsonLength)));
    const binAt = 20 + jsonLength;

    if (binAt + 8 > bytes.byteLength || view.getUint32(binAt + 4, true) !== CHUNK_BIN) throw new Error('thiếu chunk BIN');

    const binLength = view.getUint32(binAt, true);
    const bin = bytes.subarray(binAt + 8, binAt + 8 + binLength);

    if (bin.byteLength !== binLength || (json.buffers?.[0]?.byteLength ?? -1) > binLength) throw new Error('chunk BIN ngắn hơn khai báo');
    if (json.asset?.version !== '2.0' || !Array.isArray(json.nodes) || !Array.isArray(json.meshes)) throw new Error('JSON thiếu asset 2.0, node hoặc mesh');

    const read = (index, Type, width) => {
        const acc = json.accessors[index];
        const bv = acc ? json.bufferViews[acc.bufferView] : null;

        if (!bv || bv.byteOffset + bv.byteLength > bin.byteLength || acc.count * width * Type.BYTES_PER_ELEMENT > bv.byteLength) throw new Error('accessor ' + index + ' vượt buffer');

        const copy = bin.slice(bv.byteOffset, bv.byteOffset + acc.count * width * Type.BYTES_PER_ELEMENT);

        return new Type(copy.buffer, copy.byteOffset, acc.count * width);
    };
    const rootIndex = json.scenes?.[json.scene ?? 0]?.nodes?.[0];
    const root = json.nodes[rootIndex];

    if (!root || root.name !== 'proxy') throw new Error('thiếu node gốc proxy');

    const nodes = (root.children || []).map((i) => {
        const node = json.nodes[i];
        const prim = json.meshes[node.mesh]?.primitives?.[0];

        if (!prim || prim.mode !== 4) throw new Error('node ' + node.name + ' không có primitive tam giác');

        const positions = read(prim.attributes.POSITION, Float32Array, 3);
        const normals = read(prim.attributes.NORMAL, Float32Array, 3);
        const indices = read(prim.indices, Uint32Array, 1);

        for (let k = 0; k < indices.length; k++) {
            if (indices[k] >= positions.length / 3) throw new Error('node ' + node.name + ' có chỉ số đỉnh vượt mảng');
        }

        return { name: node.name, part: node.extras?.part ?? node.name, owner: node.extras?.owner ?? node.name, positions, normals, indices };
    });
    const s = root.scale ? root.scale[0] : 1;
    const all = nodes.map((n) => range(n.positions));
    const min = [0, 1, 2].map((k) => Math.min(...all.map((r) => r[0][k])) * s);
    const max = [0, 1, 2].map((k) => Math.max(...all.map((r) => r[1][k])) * s);

    return { json, root, nodes, bounds: { min, max }, scale: s, triangles: nodes.reduce((n, node) => n + node.indices.length / 3, 0) };
}

function range(positions) {
    const min = [Infinity, Infinity, Infinity];
    const max = [-Infinity, -Infinity, -Infinity];

    for (let i = 0; i < positions.length; i += 3) {
        for (let k = 0; k < 3; k++) {
            min[k] = Math.min(min[k], positions[i + k]);
            max[k] = Math.max(max[k], positions[i + k]);
        }
    }

    return [min, max];
}

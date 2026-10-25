import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFExporter } from 'three/addons/exporters/GLTFExporter.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { buildProxy, ribNormalMap, PART_LABELS, ASSUMED_PARTS } from 'yacht-proxy';

const PART_COLORS = {
    hull_underwater: 0x2f4858,
    hull_side: 0x3d6b8c,
    envelope: 0xcfd6dc,
    terrace: 0xd9a15b,
    pool: 0x3fb3d6,
    n1_cap: 0x2bb673,
    n1_mirror: 0xe5484d,
    n1_pedestal: 0xf2a541,
    steps: 0x9b6fd1,
    slipway: 0xe07ab4,
    n2_ribs: 0x4b5563,
    window_glass: 0x1f2937,
    window_clear: 0x60a5fa,
    window_reveal: 0x111827,
    window_cavity: 0x0b0f14,
    bulwark: 0x0e7490,
    n1_coaming: 0x65a30d,
    accent_edge: 0xb45309,
    slipway_rail: 0x7c3aed,
};
const CLAY_DARK = { window_glass: 0x5d646b, window_clear: 0x6f7b84, window_reveal: 0x878c91, window_cavity: 0x3a3e42 };
const TINTED = ['hull_underwater', 'n1_pedestal', 'steps', 'slipway', 'bulwark', 'n1_coaming', 'accent_edge', 'slipway_rail'];
const CLAY = 0xbdbfc2;
const TINT = 0xf0c9a0;
const BACKGROUND = 0xe9ebee;
const STUDIO = 0xd4d6d9;
const RIB_MAP = [4096, 1024];
const RIB_NORMAL_SCALE = 1.5;

const root = document.getElementById('proxyPage');
const dataNode = document.getElementById('yachtProxyData');
const environments = new WeakMap();

if (root && dataNode) {
    start(JSON.parse(dataNode.textContent || 'null'));
}

/**
 * @param {?object} payload
 */
function start(payload) {
    const stage = document.getElementById('proxyViewer');
    const status = document.getElementById('proxyStatus');

    if (!payload || !stage) return;

    if (!webglAvailable()) {
        status.textContent = 'Trình duyệt không hỗ trợ WebGL — không dựng được proxy. Hãy mở trang bằng Chrome hoặc Edge.';
        root.querySelectorAll('[data-proxy-needs-webgl]').forEach((el) => { el.disabled = true; });

        return;
    }

    status.textContent = 'Đang dựng proxy từ dữ liệu thiết kế…';

    requestAnimationFrame(() => {
        const built = buildProxy(payload);
        const viewer = createViewer(stage, built, payload);
        renderReport(built.report);
        wireControls(viewer, built, payload);
        status.textContent = built.report.pass
            ? 'Proxy dựng xong — bảng kiểm đạt hết' + (built.report.checks.some((c) => c.warn) ? ' (có cảnh báo, xem bảng kiểm).' : '.')
            : 'Proxy dựng xong — còn mục chưa đạt hoặc thiếu dữ liệu (xem bảng kiểm).';
    });
}

/**
 * @returns {boolean}
 */
function webglAvailable() {
    try {
        const canvas = document.createElement('canvas');

        return Boolean(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch (e) {
        return false;
    }
}

/**
 * @param {object} payload
 * @returns {function(string): object}
 */
function designLook(payload) {
    const colors = {};

    for (const c of (payload.canonical.surfaces || {}).colors || []) {
        if (c && c.role && /^#[0-9a-f]{6}$/i.test(c.hex || '')) colors[c.role] = parseInt(c.hex.slice(1), 16);
    }

    const a = (code) => (payload.assumptions[code] || {}).value;
    const [paintRough, paintCoat] = a('M1') || [0.35, 0.8];
    const [shellRough, shellMetal] = a('M2') || [0.6, 0.2];
    const floor = parseInt(String(a('M3') || '#D9D6CF').slice(1), 16);
    const running = parseInt(String(a('M5') || '#9A9EA2').slice(1), 16);
    const hull = colors.hull ?? 0x344c59;
    const shell = colors.superstructure ?? 0xcdd4d3;
    const accent = colors.accent ?? 0x547a76;
    const brushed = { color: 0xc4c8cc, roughness: 0.35, metalness: 1 };
    const table = {
        hull_underwater: { color: a('M4') === 'hull' ? hull : 0x2b2b2b, roughness: paintRough, clearcoat: paintCoat },
        hull_side: { color: hull, roughness: paintRough, clearcoat: paintCoat },
        bulwark: { color: hull, roughness: paintRough, clearcoat: paintCoat },
        envelope: { color: shell, roughness: shellRough, metalness: shellMetal, ribs: true },
        n1_pedestal: brushed,
        n1_coaming: { color: shell, roughness: shellRough, metalness: shellMetal },
        terrace: { color: floor, roughness: 0.85 },
        steps: { color: shell, roughness: 0.95 },
        slipway: { color: running, roughness: 0.9 },
        pool: { color: 0x2f9fc4, roughness: 0.05, clearcoat: 1 },
        n1_cap: { color: 0xffffff, roughness: 0.02, transmission: 0.95, ior: 1.5, thickness: 0.03 },
        n1_mirror: brushed,
        window_glass: { color: 0x6b7378, roughness: 0.04, transmission: 0.55, ior: 1.5, thickness: 0.02, clearcoat: 1 },
        window_clear: { color: 0xffffff, roughness: 0.02, transmission: 0.95, ior: 1.5, thickness: 0.02 },
        window_reveal: { color: 0x1c2226, roughness: 0.6 },
        window_cavity: { color: 0x0d1013, roughness: 0.95 },
        accent_edge: { color: accent, roughness: 0.45, metalness: 0.6 },
        slipway_rail: { color: 0x3b4045, roughness: 0.4, metalness: 0.8 },
    };

    return (part) => table[part] || { color: shell, roughness: 0.6 };
}

/**
 * @param {THREE.WebGLRenderer} renderer
 * @returns {THREE.Texture}
 */
function environmentFor(renderer) {
    if (!environments.has(renderer)) {
        environments.set(renderer, new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture);
    }

    return environments.get(renderer);
}

/**
 * @param {?object} field
 * @returns {?THREE.DataTexture}
 */
function ribTexture(field) {
    if (!field || !(field.depth > 0)) return null;

    const texture = new THREE.DataTexture(ribNormalMap(field, RIB_MAP[0], RIB_MAP[1]), RIB_MAP[0], RIB_MAP[1], THREE.RGBAFormat);
    texture.generateMipmaps = true;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.magFilter = THREE.LinearFilter;
    texture.anisotropy = 8;
    texture.needsUpdate = true;

    return texture;
}

/**
 * @param {HTMLElement} stage
 * @param {object} built
 * @param {object} payload
 * @returns {object}
 */
function createViewer(stage, built, payload) {
    const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    stage.appendChild(renderer.domElement);

    const loa = Number(payload.canonical.dimensions.loa.value) || 150;
    const draft = Number(payload.canonical.dimensions.draft.value) || 5;
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(BACKGROUND);
    const hemi = new THREE.HemisphereLight(0xffffff, 0x8a8f96, 1.15);
    scene.add(hemi);
    const sun = new THREE.DirectionalLight(0xffffff, 1.5);
    sun.position.set(loa * 0.15, 140, 110);
    sun.target.position.set(loa / 2, 0, 0);
    sun.shadow.mapSize.set(2048, 2048);
    Object.assign(sun.shadow.camera, { left: -loa * 0.7, right: loa * 0.7, top: loa * 0.4, bottom: -loa * 0.4, near: 10, far: 500 });
    scene.add(sun, sun.target);

    const look = designLook(payload);
    const ribMap = ribTexture(built.ribField);
    const model = new THREE.Group();
    const meshes = built.meshes.map((m) => {
        const geometry = new THREE.BufferGeometry();
        const indices = [];
        geometry.setAttribute('position', new THREE.BufferAttribute(m.positions, 3));

        if (m.uvs) {
            geometry.setAttribute('uv', new THREE.BufferAttribute(m.uvs, 2));
        }

        m.groups.forEach((g, i) => {
            geometry.addGroup(indices.length, g.indices.length, i);
            for (let k = 0; k < g.indices.length; k++) indices.push(g.indices[k]);
        });

        geometry.setIndex(indices);

        if (m.normals) {
            geometry.setAttribute('normal', new THREE.BufferAttribute(m.normals, 3));
        } else {
            geometry.computeVertexNormals();
        }

        if (m.uvs) {
            geometry.computeTangents();
        }

        return m.groups.map((g) => {
            const part = new THREE.BufferGeometry();

            for (const [name, attribute] of Object.entries(geometry.attributes)) part.setAttribute(name, attribute);

            part.setIndex(new THREE.BufferAttribute(g.indices, 1));
            const mesh = new THREE.Mesh(part, new THREE.MeshPhysicalMaterial({ color: CLAY, roughness: 0.9, metalness: 0, side: THREE.DoubleSide }));
            mesh.name = m.name + ':' + g.part;
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            mesh.userData.parts = [g.part];
            model.add(mesh);

            return mesh;
        });
    }).flat();
    const ribs = built.lines.map((line) => {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(line.positions, 3));
        const segments = new THREE.LineSegments(geometry, new THREE.LineBasicMaterial({ color: 0x6b7280 }));
        segments.name = line.name;
        segments.userData.parts = [line.part];
        model.add(segments);

        return segments;
    });
    scene.add(model);

    const ground = new THREE.Mesh(new THREE.PlaneGeometry(loa * 3, loa * 3), new THREE.MeshStandardMaterial({ color: STUDIO, roughness: 1, metalness: 0 }));
    ground.rotation.x = -Math.PI / 2;
    ground.position.set(loa / 2, -draft - 0.02, 0);
    ground.receiveShadow = true;
    ground.visible = false;
    scene.add(ground);

    const helpers = new THREE.Group();
    const water = new THREE.Mesh(new THREE.PlaneGeometry(loa * 1.5, loa * 0.6), new THREE.MeshBasicMaterial({ color: 0x5fa8d3, transparent: true, opacity: 0.22, depthWrite: false, side: THREE.DoubleSide }));
    water.rotation.x = -Math.PI / 2;
    water.position.set(loa / 2, 0, 0);
    helpers.add(water);
    helpers.add(new THREE.AxesHelper(loa * 0.12));
    helpers.add(label('X → đuôi', [loa * 0.13, 0, 0]), label('z ↑ lên', [0, loa * 0.13, 0]), label('y → mạn trái', [0, 0, loa * 0.13]));

    for (const mark of built.landmarks) {
        const visible = new THREE.Mesh(new THREE.SphereGeometry(0.45, 12, 8), new THREE.MeshBasicMaterial({ color: 0xd9480f }));
        const hidden = new THREE.Mesh(new THREE.SphereGeometry(0.45, 12, 8), new THREE.MeshBasicMaterial({ color: 0xd9480f, transparent: true, opacity: 0.25, depthTest: false }));
        visible.position.set(...mark.position);
        hidden.position.set(...mark.position);
        helpers.add(visible, hidden, label(mark.name, [mark.position[0], mark.position[1] + 1.4, mark.position[2]], 0.6));
    }

    scene.add(helpers);

    const free = new THREE.PerspectiveCamera(30, 1.5, 0.5, 5000);
    free.position.set(-loa * 0.6, loa * 0.45, loa * 0.9);
    const controls = new OrbitControls(free, renderer.domElement);
    controls.target.set(loa / 2, 6, 0);
    controls.enableDamping = true;
    controls.autoRotateSpeed = 1.2;
    controls.update();

    const state = { camera: free, view: 'free', mode: 'clay', tint: true };
    const resize = () => {
        const width = stage.clientWidth || 900;
        const frame = state.view === 'free' ? null : built.cameras.find((c) => c.view === state.view);
        const height = frame ? Math.min(width * frame.height / frame.width, window.innerHeight * 0.78) : Math.round(width * 0.58);
        const fitWidth = frame ? height * frame.width / frame.height : width;
        renderer.setSize(fitWidth, height);
        renderer.domElement.style.margin = '0 auto';
        renderer.domElement.style.display = 'block';

        if (state.view === 'free') {
            free.aspect = fitWidth / height;
            free.updateProjectionMatrix();
        }
    };
    const stageFor = (target, mode) => {
        const design = mode === 'design';
        target.toneMapping = design ? THREE.ACESFilmicToneMapping : THREE.NoToneMapping;
        target.toneMappingExposure = 1.0;
        target.shadowMap.enabled = true;
        scene.environment = design ? environmentFor(target) : null;
        scene.background = new THREE.Color(design ? STUDIO : (mode === 'parts' ? 0xffffff : BACKGROUND));
        sun.castShadow = design;
        sun.intensity = design ? 2.2 : 1.5;
        hemi.intensity = design ? 0.35 : 1.15;
        ground.visible = design;
        water.visible = !design;
    };
    const paint = (mode = state.mode, tint = state.tint) => {
        model.traverse((node) => {
            if (!node.userData.parts) return;

            if (node.isLineSegments) {
                node.visible = mode !== 'design';
                node.material.color.setHex(mode === 'parts' ? PART_COLORS.n2_ribs : 0x6b7280);

                return;
            }

            [node.material].forEach((material, i) => {
                const part = node.userData.parts[i];
                const spec = mode === 'design' ? look(part) : { color: mode === 'parts' ? PART_COLORS[part] : (tint && TINTED.includes(part) ? TINT : (CLAY_DARK[part] ?? CLAY)), roughness: 0.9 };
                material.color.setHex(spec.color ?? CLAY);
                material.roughness = spec.roughness ?? 0.9;
                material.metalness = spec.metalness ?? 0;
                material.clearcoat = spec.clearcoat ?? 0;
                material.transmission = spec.transmission ?? 0;
                material.ior = spec.ior ?? 1.5;
                material.thickness = spec.thickness ?? 0;
                material.opacity = spec.opacity ?? 1;
                material.transparent = (spec.opacity ?? 1) < 1;
                material.envMapIntensity = mode === 'design' ? 1 : 0;
                const normalMap = spec.ribs && ribMap ? ribMap : null;

                if (material.normalMap !== normalMap) {
                    material.normalMap = normalMap;
                    material.normalScale.set(RIB_NORMAL_SCALE, RIB_NORMAL_SCALE);
                    material.needsUpdate = true;
                }
            });
        });
    };

    window.addEventListener('resize', resize);
    resize();
    stageFor(renderer, state.mode);
    paint();

    const loop = () => {
        if (state.view === 'free') controls.update();
        renderer.render(scene, state.camera);
        requestAnimationFrame(loop);
    };
    loop();

    return {
        renderer, scene, model, meshes, ribs, helpers, controls, free, state, resize, paint, stageFor, ground, water, sun,
        cameraFor: (view) => lockedCamera(built.cameras.find((c) => c.view === view)),
    };
}

/**
 * @param {object} cam
 * @returns {THREE.Camera}
 */
function lockedCamera(cam) {
    const camera = cam.projection === 'orthographic'
        ? new THREE.OrthographicCamera(cam.ortho.left, cam.ortho.right, cam.ortho.top, cam.ortho.bottom, cam.near, cam.far)
        : new THREE.PerspectiveCamera(cam.fov, cam.width / cam.height, cam.near, cam.far);
    camera.position.set(...cam.position);
    camera.up.set(...cam.up);
    camera.lookAt(new THREE.Vector3(...cam.target));
    camera.updateProjectionMatrix();

    return camera;
}

/**
 * @param {string} text
 * @param {number[]} position
 * @param {number} [size]
 * @returns {THREE.Sprite}
 */
function label(text, position, size = 1) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.font = '28px sans-serif';
    canvas.width = Math.ceil(ctx.measureText(text).width) + 16;
    canvas.height = 40;
    ctx.font = '28px sans-serif';
    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#1f2937';
    ctx.fillText(text, 8, 29);
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(canvas), depthTest: false, transparent: true }));
    sprite.scale.set(canvas.width / 40 * 2.4 * size, 2.4 * size, 1);
    sprite.position.set(...position);

    return sprite;
}

/**
 * @param {object} viewer
 * @param {object} built
 * @param {object} payload
 */
function wireControls(viewer, built, payload) {
    const buttons = root.querySelectorAll('[data-proxy-view]');
    const select = (view) => {
        viewer.state.view = view;
        viewer.state.camera = view === 'free' ? viewer.free : viewer.cameraFor(view);
        viewer.controls.enabled = view === 'free';
        buttons.forEach((b) => b.classList.toggle('pri', b.dataset.proxyView === view));
        viewer.resize();
    };

    buttons.forEach((b) => b.addEventListener('click', () => select(b.dataset.proxyView)));
    root.querySelectorAll('[data-proxy-mode]').forEach((b) => b.addEventListener('click', () => {
        viewer.state.mode = b.dataset.proxyMode;
        root.querySelectorAll('[data-proxy-mode]').forEach((x) => x.classList.toggle('pri', x === b));
        viewer.stageFor(viewer.renderer, viewer.state.mode);
        viewer.paint();
    }));
    on('proxyTint', 'change', (e) => { viewer.state.tint = e.target.checked; viewer.paint(); });
    on('proxyHelpers', 'change', (e) => { viewer.helpers.visible = e.target.checked; });
    on('proxyAutoRotate', 'click', (e) => {
        select('free');
        viewer.controls.autoRotate = !viewer.controls.autoRotate;
        e.target.classList.toggle('pri', viewer.controls.autoRotate);
    });
    on('proxyDownloadView', 'click', async () => {
        const view = viewer.state.view === 'free' ? 'MS1' : viewer.state.view;

        for (const kind of ['clay', 'parts', 'design']) {
            download(await snapshot(viewer, built, view, kind), view + '_' + kind + '.png');
        }
    });
    on('proxyDownloadAll', 'click', async () => {
        for (const cam of built.cameras) {
            for (const kind of ['clay', 'parts', 'design']) {
                download(await snapshot(viewer, built, cam.view, kind), cam.view + '_' + kind + '.png');
            }
        }
    });
    on('proxyDownloadGlb', 'click', async () => download(await glb(viewer), 'proxy.glb'));
    on('proxySave', 'click', async (e) => save(e.target, viewer, built, payload));
    renderLegend();
}

/**
 * @param {string} id
 * @param {string} event
 * @param {function(Event): void} handler
 */
function on(id, event, handler) {
    const el = document.getElementById(id);

    if (el) el.addEventListener(event, handler);
}

/**
 * @param {object} viewer
 * @param {object} built
 * @param {string} view
 * @param {string} kind
 * @returns {Promise<Blob>}
 */
async function snapshot(viewer, built, view, kind) {
    const cam = built.cameras.find((c) => c.view === view);
    const renderer = viewer.exporter || (viewer.exporter = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true }));
    renderer.setPixelRatio(1);
    renderer.setSize(cam.width, cam.height, false);
    const helpers = viewer.helpers.visible;
    viewer.helpers.visible = false;
    viewer.stageFor(renderer, kind);
    viewer.paint(kind, false);
    renderer.render(viewer.scene, lockedCamera(cam));
    const blob = await new Promise((resolve) => renderer.domElement.toBlob(resolve, 'image/png'));
    viewer.helpers.visible = helpers;
    viewer.stageFor(viewer.renderer, viewer.state.mode);
    viewer.paint();

    return blob;
}

/**
 * @param {object} viewer
 * @returns {Promise<Blob>}
 */
function glb(viewer) {
    return new Promise((resolve, reject) => {
        new GLTFExporter().parse(viewer.model, (result) => resolve(new Blob([result], { type: 'model/gltf-binary' })), reject, { binary: true });
    });
}

/**
 * @param {Blob} blob
 * @param {string} name
 */
function download(blob, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/**
 * @param {HTMLButtonElement} button
 * @param {object} viewer
 * @param {object} built
 * @param {object} payload
 */
async function save(button, viewer, built, payload) {
    const status = document.getElementById('proxySaveStatus');
    const form = new FormData();
    button.disabled = true;
    status.textContent = 'Đang render 16 ảnh kiểm và file .glb…';

    try {
        for (const cam of built.cameras) {
            for (const kind of ['clay', 'parts']) {
                form.append('images[' + cam.view + '_' + kind + ']', await snapshot(viewer, built, cam.view, kind), cam.view + '_' + kind + '.png');
            }
        }

        form.append('glb', await glb(viewer), 'proxy.glb');
        form.append('input_sha256', payload.input_sha256);
        form.append('report', JSON.stringify(built.report));
        form.append('cameras', JSON.stringify(built.cameras));
        status.textContent = 'Đang gửi lên server…';

        const response = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' },
            body: form,
        });
        const answer = await response.json().catch(() => ({ message: 'Server trả về lỗi ' + response.status }));

        if (!response.ok || !answer.ok) {
            status.textContent = answer.message || 'Không lưu được revision.';
            button.disabled = false;

            return;
        }

        status.textContent = answer.message;
        window.location.reload();
    } catch (e) {
        status.textContent = 'Không lưu được revision: ' + e.message;
        button.disabled = false;
    }
}

/**
 * @param {object} report
 */
function renderReport(report) {
    const body = document.getElementById('proxyReport');

    if (!body) return;

    body.innerHTML = '';

    for (const c of report.checks) {
        const row = document.createElement('tr');
        [c.label, String(c.design), String(c.measured), String(c.tolerance)].forEach((text) => {
            const cell = document.createElement('td');
            cell.textContent = text;
            row.appendChild(cell);
        });
        const verdict = document.createElement('td');
        const [tone, text] = c.pass ? ['ok', 'Đạt'] : (c.warn ? ['amber', 'Cảnh báo'] : ['dg', 'Chưa đạt']);
        verdict.innerHTML = '<span class="va-tag ' + tone + '">' + text + '</span>';
        row.appendChild(verdict);
        body.appendChild(row);
    }

    const missing = document.getElementById('proxyMissing');

    if (missing) {
        missing.textContent = report.missing.length ? 'Thiếu dữ liệu: ' + report.missing.join(', ') : '';
    }
}

function renderLegend() {
    const legend = document.getElementById('proxyLegend');

    if (!legend) return;

    legend.innerHTML = Object.entries(PART_LABELS).map(([part, name]) => {
        const color = '#' + (PART_COLORS[part] ?? CLAY).toString(16).padStart(6, '0');
        const codes = (ASSUMED_PARTS[part] || []).join(', ');

        return '<span class="vp-proxy-chip"><i style="background:' + color + '"></i>' + name + (codes ? ' <em>(' + codes + ')</em>' : '') + (TINTED.includes(part) ? ' <b>giả định chính</b>' : '') + '</span>';
    }).join('');
}

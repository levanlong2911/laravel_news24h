// Trang proxy 3D: viewer GLB da luu cua revision (khong dung lai o trinh duyet) va cong cu tham
// chieu tren o model sheet (khoanh da giac -> mask, dat moc). Moi so lieu luu o server.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { buildProxy } from './builder.js';
import { readGlb } from './glb.js';
import { colour } from './raster.js';

// frame: ti le khung cua camera kiem dang xem (null = xoay tu do, khung theo panel).
const viewer = {
    renderer: null, scene: null, camera: null, controls: null, root: null, cameras: [], states: {}, frame: null,
};
const LETTERBOX = 0x2b3038;

function initViewer() {
    if (viewer.renderer) return;

    const host = document.getElementById('proxyViewer');
    viewer.renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
    viewer.renderer.setPixelRatio(window.devicePixelRatio || 1);
    host.appendChild(viewer.renderer.domElement);
    viewer.scene = new THREE.Scene();
    viewer.scene.background = new THREE.Color(0xeceef0);
    viewer.scene.add(new THREE.HemisphereLight(0xffffff, 0x8899aa, 1.6));
    const sun = new THREE.DirectionalLight(0xffffff, 1.4);
    sun.position.set(-60, 120, 80);
    viewer.scene.add(sun);
    viewer.camera = new THREE.PerspectiveCamera(30, 1, 0.5, 5000);
    viewer.controls = new OrbitControls(viewer.camera, viewer.renderer.domElement);
    const resize = () => {
        const w = host.clientWidth || 800;
        const h = Math.round(w * 0.56);
        viewer.renderer.setSize(w, h);

        if (viewer.frame === null && viewer.camera.isPerspectiveCamera) {
            viewer.camera.aspect = w / h;
            viewer.camera.updateProjectionMatrix();
        }
    };
    window.addEventListener('resize', resize);
    resize();
    const loop = () => {
        viewer.controls.update();
        draw();
        requestAnimationFrame(loop);
    };
    loop();
}

/**
 * Ve mot khung. Camera kiem giu dung ti le khung o model sheet (width / height cua camera): chen
 * vien (letterbox) trong panel, khong keo gian fov hay khung truc giao theo panel.
 */
function draw() {
    const r = viewer.renderer;
    const size = r.getSize(new THREE.Vector2());

    if (viewer.frame === null) {
        r.setScissorTest(false);
        r.setViewport(0, 0, size.x, size.y);
        r.render(viewer.scene, viewer.camera);

        return;
    }

    const [w, h] = size.x / size.y > viewer.frame ? [size.y * viewer.frame, size.y] : [size.x, size.x / viewer.frame];
    const [x, y] = [(size.x - w) / 2, (size.y - h) / 2];
    r.setScissorTest(true);
    r.setScissor(0, 0, size.x, size.y);
    r.setViewport(0, 0, size.x, size.y);
    r.setClearColor(LETTERBOX);
    r.clear();
    r.setScissor(x, y, w, h);
    r.setViewport(x, y, w, h);
    r.render(viewer.scene, viewer.camera);
}

async function loadGlb(url) {
    const res = await fetch(url, { credentials: 'same-origin' });

    if (!res.ok) throw new Error('Không tải được GLB (' + res.status + ')');

    const glb = readGlb(new Uint8Array(await res.arrayBuffer()));

    return groupFrom(glb.nodes, glb.scale);
}

/** Mesh Three.js tu cac node {name, part, positions, normals, indices} (GLB hoac bo dung). */
function groupFrom(nodes, scaleFactor) {
    const group = new THREE.Group();

    for (const node of nodes) {
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(node.positions, 3));
        geo.setAttribute('normal', new THREE.BufferAttribute(node.normals, 3));
        geo.setIndex(new THREE.BufferAttribute(node.indices, 1));
        const [r, g, b] = colour(node.part);
        const glass = /:(glass|clear)$/.test(node.part);
        const mat = new THREE.MeshStandardMaterial({ color: new THREE.Color(r / 255, g / 255, b / 255), roughness: glass ? 0.1 : 0.7, metalness: 0.05, side: THREE.DoubleSide, transparent: glass, opacity: glass ? 0.6 : 1 });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.name = node.name;
        group.add(mesh);
    }

    group.scale.setScalar(scaleFactor);

    return group;
}

async function show(url) {
    place(await loadGlb(url));
}

function place(group) {
    if (viewer.root) {
        viewer.scene.remove(viewer.root);
        viewer.root.traverse((o) => { if (o.isMesh) { o.geometry.dispose(); o.material.dispose(); } });
    }

    viewer.root = group;
    viewer.scene.add(viewer.root);
    const box = new THREE.Box3().setFromObject(viewer.root);
    const centre = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length();
    useFree(centre, size);
}

function useFree(centre, size) {
    const host = document.getElementById('proxyViewer');
    const aspect = host.clientWidth / Math.max(1, Math.round(host.clientWidth * 0.56));
    viewer.frame = null;
    viewer.camera = new THREE.PerspectiveCamera(30, aspect, 0.5, size * 20);
    viewer.camera.position.copy(centre).add(new THREE.Vector3(-size * 0.6, size * 0.35, size * 0.8));
    viewer.controls.object = viewer.camera;
    viewer.controls.target.copy(centre);
    viewer.controls.update();
}

/**
 * Camera kiem da giai cua revision (cameras.json): dung vi tri, dich, fov / khung truc giao va ti le
 * khung width / height da luu — panel khac ti le thi chen vien (draw), khong doi khung camera.
 */
function useCamera(c) {
    const cam = c.projection === 'orthographic'
        ? new THREE.OrthographicCamera(c.ortho.left, c.ortho.right, c.ortho.top, c.ortho.bottom, c.near, c.far)
        : new THREE.PerspectiveCamera(c.fov, c.width / c.height, c.near, c.far);

    cam.position.set(...c.position);
    cam.up.set(...c.up);
    cam.lookAt(new THREE.Vector3(...c.target));
    cam.updateProjectionMatrix();
    viewer.frame = c.width / c.height;
    viewer.camera = cam;
    viewer.controls.object = cam;
    viewer.controls.target.set(...c.target);
    viewer.controls.update();
}

function openRevision(btn) {
    openViewer(btn.dataset.title + ' — GLB đã lưu', JSON.parse(btn.dataset.cameras || '[]'));
    viewer.states = JSON.parse(btn.dataset.states || '{}');
    const select = document.getElementById('proxyStateSelect');
    select.hidden = false;
    select.innerHTML = '<option value="' + btn.dataset.glb + '">Mô hình đối chiếu</option>'
        + Object.entries(viewer.states).filter(([, url]) => url).map(([id, url]) => '<option value="' + url + '">' + id + '</option>').join('');
    select.onchange = () => show(select.value).catch((e) => alert(e.message));
    show(btn.dataset.glb).catch((e) => alert(e.message));
}

/** Xem truoc: chay bo dung chung trong trinh duyet tren ProxySpec dang hien — khong luu gi. */
function preview(btn) {
    const spec = JSON.parse(document.getElementById('proxySpecData').textContent);
    const out = buildProxy(spec, { tolerances: JSON.parse(btn.dataset.tolerances), fill: Number(btn.dataset.fill) });
    openViewer('Xem trước — bộ dựng chạy trong trình duyệt, chưa lưu (' + out.report.checks.filter((c) => c.pass).length + '/' + out.report.checks.length + ' phép kiểm đạt)', out.cameras);
    document.getElementById('proxyStateSelect').hidden = true;
    place(groupFrom(out.meshes, 1));
}

function openViewer(title, cameras) {
    const panel = document.getElementById('proxyViewerPanel');
    panel.hidden = false;
    initViewer();
    viewer.cameras = cameras;
    document.getElementById('proxyViewerTitle').textContent = title;
    const buttons = document.getElementById('proxyViewButtons');
    buttons.innerHTML = '';
    const free = document.createElement('button');
    free.type = 'button';
    free.className = 'vp-btn sm pri';
    free.textContent = 'Xoay tự do';
    free.onclick = () => {
        const box = new THREE.Box3().setFromObject(viewer.root);
        useFree(box.getCenter(new THREE.Vector3()), box.getSize(new THREE.Vector3()).length());
    };
    buttons.appendChild(free);

    for (const c of viewer.cameras) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'vp-btn sm';
        b.textContent = c.view;
        b.onclick = () => useCamera(c);
        buttons.appendChild(b);
    }

    panel.scrollIntoView({ behavior: 'smooth' });
}

/** O tham chieu: khoanh da giac (mask) va dat moc, toa do pixel goc cua o. */
function initReference(card) {
    const stage = card.querySelector('[data-ref-stage]');
    const img = stage.querySelector('img');
    const canvas = stage.querySelector('[data-ref-canvas]');
    const width = Number(card.dataset.width);
    const height = Number(card.dataset.height);
    const polygonForm = card.querySelector('[data-polygon-form]');
    const landmarkForm = card.querySelector('[data-landmark-form]');
    const nameSelect = landmarkForm.querySelector('[data-landmark-name]');
    const list = landmarkForm.querySelector('[data-landmark-list]');
    let points = JSON.parse(card.dataset.landmarks || '[]');
    let polygon = [];
    let mode = null;
    canvas.width = width;
    canvas.height = height;

    const draw = () => {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, width, height);
        ctx.lineWidth = 3;

        if (polygon.length) {
            ctx.strokeStyle = '#ff6a00';
            ctx.fillStyle = 'rgba(255,106,0,0.18)';
            ctx.beginPath();
            polygon.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
            ctx.closePath();
            ctx.fill();
            ctx.stroke();
        }

        ctx.font = '22px sans-serif';

        for (const p of points.filter((q) => q.status === 'visible')) {
            ctx.fillStyle = '#e0245e';
            ctx.beginPath();
            ctx.arc(p.x, p.y, 7, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillText(p.name, p.x + 10, p.y - 8);
        }

        list.textContent = points.length ? points.map((p) => p.name + (p.status === 'visible' ? ' (' + Math.round(p.x) + ', ' + Math.round(p.y) + ')' : p.status === 'occluded' ? ' — bị che' : ' — ngoài khung')).join(' · ') : 'Chưa đặt mốc.';
        landmarkForm.querySelector('input[name=points]').value = JSON.stringify(points);

        if (polygonForm) polygonForm.querySelector('input[name=points]').value = JSON.stringify(polygon);
    };
    const setPoint = (entry) => {
        points = points.filter((p) => p.name !== entry.name).concat([entry]);
        draw();
    };

    canvas.addEventListener('click', (e) => {
        const r = canvas.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width * width;
        const y = (e.clientY - r.top) / r.height * height;

        if (mode === 'polygon') {
            polygon.push([Math.round(x), Math.round(y)]);
            polygonForm.querySelector('[data-polygon-save]').hidden = polygon.length < 3;
            draw();
        } else if (mode === 'landmark') {
            setPoint({ name: nameSelect.value, status: 'visible', x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 });
        }
    });

    card.querySelectorAll('[data-mode]').forEach((btn) => btn.addEventListener('click', () => {
        mode = mode === btn.dataset.mode ? null : btn.dataset.mode;

        if (mode === 'polygon') polygon = [];

        card.querySelectorAll('[data-mode]').forEach((b) => b.classList.toggle('pri', b.dataset.mode === mode));
        stage.classList.toggle('picking', mode !== null);
        draw();
    }));
    card.querySelectorAll('[data-status]').forEach((btn) => btn.addEventListener('click', () => setPoint({ name: nameSelect.value, status: btn.dataset.status })));
    card.querySelector('[data-remove]').addEventListener('click', () => {
        points = points.filter((p) => p.name !== nameSelect.value);
        draw();
    });
    img.addEventListener('load', draw);
    draw();
}

document.querySelectorAll('[data-proxy-open]').forEach((btn) => btn.addEventListener('click', () => openRevision(btn)));
document.querySelectorAll('[data-proxy-preview]').forEach((btn) => btn.addEventListener('click', () => preview(btn)));
document.querySelectorAll('[data-ref-card]').forEach(initReference);

// PNG cho worker proxy (Node): ghi RGB 8 bit, xam 8 bit, xam 16 bit; doc anh khong xen dong
// (8 bit: xam, xam+alpha, RGB, RGBA; 16 bit doc lay byte cao). Khong phu thuoc goi ngoai.
import { deflateSync, inflateSync } from 'node:zlib';

const SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
const CRC = new Int32Array(256).map((_, n) => {
    let c = n;

    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;

    return c;
});

function crc32(bytes) {
    let c = -1;

    for (let i = 0; i < bytes.length; i++) c = CRC[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);

    return (c ^ -1) >>> 0;
}

function chunk(type, data) {
    const out = Buffer.alloc(12 + data.length);
    out.writeUInt32BE(data.length, 0);
    out.write(type, 4, 'ascii');
    data.copy(out, 8);
    out.writeUInt32BE(crc32(out.subarray(4, 8 + data.length)), 8 + data.length);

    return out;
}

/**
 * @param {number} width
 * @param {number} height
 * @param {Uint8Array|Uint16Array} data
 * @param {{channels: 1|3, bitDepth?: 8|16}} format
 * @returns {Buffer}
 */
export function encodePng(width, height, data, format) {
    const bitDepth = format.bitDepth ?? 8;
    const channels = format.channels;
    const bytesPerPixel = channels * bitDepth / 8;
    const stride = width * bytesPerPixel;
    const raw = Buffer.alloc((stride + 1) * height);

    for (let y = 0; y < height; y++) {
        const row = y * (stride + 1);

        for (let x = 0; x < width * channels; x++) {
            const v = data[y * width * channels + x];

            if (bitDepth === 16) raw.writeUInt16BE(v, row + 1 + x * 2);
            else raw[row + 1 + x] = v;
        }
    }

    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = bitDepth;
    header[9] = channels === 3 ? 2 : 0;

    return Buffer.concat([SIGNATURE, chunk('IHDR', header), chunk('IDAT', deflateSync(raw, { level: 6 })), chunk('IEND', Buffer.alloc(0))]);
}

/** @returns {{width: number, height: number, rgb: Uint8Array}} */
export function decodePng(buffer) {
    if (!buffer.subarray(0, 8).equals(SIGNATURE)) throw new Error('không phải PNG');

    let offset = 8;
    let header = null;
    const idat = [];

    while (offset < buffer.length) {
        const length = buffer.readUInt32BE(offset);
        const type = buffer.toString('ascii', offset + 4, offset + 8);
        const data = buffer.subarray(offset + 8, offset + 8 + length);

        if (type === 'IHDR') header = { width: data.readUInt32BE(0), height: data.readUInt32BE(4), bitDepth: data[8], colorType: data[9], interlace: data[12] };
        else if (type === 'IDAT') idat.push(data);
        else if (type === 'IEND') break;

        offset += 12 + length;
    }

    if (!header || header.interlace !== 0) throw new Error('PNG thiếu IHDR hoặc xen dòng (không hỗ trợ)');

    const channels = { 0: 1, 2: 3, 4: 2, 6: 4 }[header.colorType];

    if (!channels || ![8, 16].includes(header.bitDepth)) throw new Error('PNG loại màu ' + header.colorType + ', ' + header.bitDepth + ' bit không hỗ trợ');

    const bpp = channels * header.bitDepth / 8;
    const stride = header.width * bpp;
    const raw = inflateSync(Buffer.concat(idat));
    const pixels = Buffer.alloc(stride * header.height);

    for (let y = 0; y < header.height; y++) {
        const filter = raw[y * (stride + 1)];
        const line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
        const out = pixels.subarray(y * stride, (y + 1) * stride);
        const prev = y > 0 ? pixels.subarray((y - 1) * stride, y * stride) : Buffer.alloc(stride);

        for (let x = 0; x < stride; x++) {
            const a = x >= bpp ? out[x - bpp] : 0;
            const b = prev[x];
            const c = x >= bpp ? prev[x - bpp] : 0;
            let v = line[x];

            if (filter === 1) v += a;
            else if (filter === 2) v += b;
            else if (filter === 3) v += (a + b) >> 1;
            else if (filter === 4) {
                const p = a + b - c;
                const [pa, pb, pc] = [Math.abs(p - a), Math.abs(p - b), Math.abs(p - c)];
                v += pa <= pb && pa <= pc ? a : (pb <= pc ? b : c);
            }

            out[x] = v & 0xff;
        }
    }

    const rgb = new Uint8Array(header.width * header.height * 3);
    const step = header.bitDepth / 8;

    for (let k = 0; k < header.width * header.height; k++) {
        const base = k * bpp;
        const at = (ch) => pixels[base + ch * step];
        const [r, g, b] = channels >= 3 ? [at(0), at(1), at(2)] : [at(0), at(0), at(0)];
        rgb[k * 3] = r;
        rgb[k * 3 + 1] = g;
        rgb[k * 3 + 2] = b;
    }

    return { width: header.width, height: header.height, rgb };
}

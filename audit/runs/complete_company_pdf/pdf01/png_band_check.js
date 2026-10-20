// Decodes an 8-bit RGB PNG (pdftoppm output) without any image library and measures the header band:
// share of yellow (#FBB805-like) pixels on chosen rows. Usage: node png_band.js file.png...
const fs = require('fs'), zlib = require('zlib');
function decode(file) {
  const b = fs.readFileSync(file); let o = 8, idat = [], w, h, ct;
  while (o < b.length) { const len = b.readUInt32BE(o), t = b.toString('ascii', o + 4, o + 8);
    if (t === 'IHDR') { w = b.readUInt32BE(o + 8); h = b.readUInt32BE(o + 12); ct = b[o + 17]; }
    if (t === 'IDAT') idat.push(b.subarray(o + 8, o + 8 + len)); o += 12 + len; }
  if (ct !== 2) throw new Error('not RGB');
  const raw = zlib.inflateSync(Buffer.concat(idat)), bpp = 3, stride = w * bpp, px = Buffer.alloc(h * stride);
  for (let y = 0; y < h; y++) { const f = raw[y * (stride + 1)], line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
    for (let x = 0; x < stride; x++) { const a = x >= bpp ? px[y * stride + x - bpp] : 0, up = y ? px[(y - 1) * stride + x] : 0, c = (y && x >= bpp) ? px[(y - 1) * stride + x - bpp] : 0;
      let v = line[x]; if (f === 1) v += a; else if (f === 2) v += up; else if (f === 3) v += (a + up) >> 1; else if (f === 4) { const p = a + up - c, pa = Math.abs(p - a), pb = Math.abs(p - up), pc = Math.abs(p - c); v += (pa <= pb && pa <= pc) ? a : (pb <= pc ? up : c); }
      px[y * stride + x] = v & 255; } }
  return { w, h, px };
}
const yellow = (r, g, b) => r > 220 && g > 150 && g < 215 && b < 80;
for (const file of process.argv.slice(2)) {
  const { w, h, px } = decode(file);
  const share = (y) => { let n = 0; for (let x = 0; x < w; x++) { const i = (y * w + x) * 3; if (yellow(px[i], px[i + 1], px[i + 2])) n++; } return n / w; };
  const k = h / 792; // rows per PDF point (letter page)
  const band = share(Math.round(20 * k)), gap = share(Math.round(100 * k));
  console.log(`${file.split('/').pop()}  band@20pt=${(band * 100).toFixed(0)}%  gap@100pt=${(gap * 100).toFixed(0)}%  ${band > 0.6 && gap === 0 ? 'HEADER_OK' : 'HEADER_MISSING_OR_OVERLAP'}`);
}

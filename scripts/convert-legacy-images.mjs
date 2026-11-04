// One-off: convierte las fotos de producto "legacy" (public/assets/products/*.jpg|png,
// empaquetadas con el frontend desde antes de la migración a WebP) a .webp, mismo
// basename. NO toca las fotos subidas vía admin (esas ya se guardan en WebP solas).
// Imprime un mapping.json con el rename, que el comando backend usa para
// actualizar products.image_path en la misma extensión.
import { readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';

const DIR = path.resolve('public/assets/products');
const MAX_BYTES = 150 * 1024;

async function convertOne(file) {
  const ext = path.extname(file).toLowerCase();
  if (!['.jpg', '.jpeg', '.png'].includes(ext)) return null;

  const base = path.basename(file, ext);
  const srcPath = path.join(DIR, file);
  const outPath = path.join(DIR, `${base}.webp`);

  let quality = 80;
  let buffer;
  do {
    buffer = await sharp(srcPath).webp({ quality }).toBuffer();
    quality -= 10;
  } while (buffer.length > MAX_BYTES && quality >= 40);

  await writeFile(outPath, buffer);
  return { from: file, to: `${base}.webp`, bytes: buffer.length };
}

async function main() {
  const files = await readdir(DIR);
  const results = [];
  for (const file of files) {
    const result = await convertOne(file);
    if (result) {
      results.push(result);
      console.log(`[convert] ${result.from} -> ${result.to} (${(result.bytes / 1024).toFixed(1)} KiB)`);
    }
  }
  await writeFile(path.join(DIR, '..', '..', '..', 'legacy-image-rename-map.json'), JSON.stringify(results, null, 2));
  console.log(`[convert] ${results.length} imagenes convertidas. Mapping en legacy-image-rename-map.json`);
}

main().catch((error) => {
  console.error('[convert] Error fatal:', error);
  process.exitCode = 1;
});

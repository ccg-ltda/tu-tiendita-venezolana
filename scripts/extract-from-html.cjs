const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const sourcePath = path.join(root, 'legacy', 'tu-tiendita-venezolana-FINAL.html');
const source = fs.readFileSync(sourcePath, 'utf8');

const ensure = (directory) => fs.mkdirSync(directory, { recursive: true });
const assetsDir = path.join(root, 'public', 'assets');
const productsDir = path.join(assetsDir, 'products');
const dataDir = path.join(root, 'src', 'data');
const stylesDir = path.join(root, 'src', 'styles');
ensure(productsDir);
ensure(dataDir);
ensure(stylesDir);

const extensionFor = (mime) => ({ png: 'png', jpeg: 'jpg', jpg: 'jpg', webp: 'webp' }[mime] || mime);
const saveDataUrl = (dataUrl, destinationWithoutExtension) => {
  const match = dataUrl.match(/^data:image\/([^;]+);base64,(.+)$/s);
  if (!match) throw new Error(`Formato de imagen no reconocido: ${destinationWithoutExtension}`);
  const extension = extensionFor(match[1]);
  const destination = `${destinationWithoutExtension}.${extension}`;
  fs.writeFileSync(destination, Buffer.from(match[2], 'base64'));
  return path.relative(path.join(root, 'public'), destination).replaceAll('\\', '/');
};

const htmlImages = [...source.matchAll(/<img\s+src="(data:image\/[^;]+;base64,[^"]+)"/g)];
if (htmlImages.length < 2) throw new Error('No se encontraron el logo y el banner.');
const branding = {
  logo: saveDataUrl(htmlImages[0][1], path.join(assetsDir, 'logo')),
  banner: saveDataUrl(htmlImages[1][1], path.join(assetsDir, 'banner')),
};

const imageObjectMatch = source.match(/const IMAGE_DATA = \{([\s\S]*?)\n\};/);
if (!imageObjectMatch) throw new Error('No se encontró IMAGE_DATA.');
const imageMap = {};
for (const match of imageObjectMatch[1].matchAll(/"([^"]+)"\s*:\s*"(data:image\/[^;]+;base64,[^"]+)"/g)) {
  imageMap[match[1]] = saveDataUrl(match[2], path.join(productsDir, match[1]));
}

const productsMatch = source.match(/const PRODUCTS = (\[[\s\S]*?\n\])/);
if (!productsMatch) throw new Error('No se encontró PRODUCTS.');
const products = JSON.parse(productsMatch[1]).map((product) => ({
  ...product,
  image: imageMap[product.img] || null,
}));

fs.writeFileSync(path.join(dataDir, 'products.json'), `${JSON.stringify(products, null, 2)}\n`);
fs.writeFileSync(path.join(dataDir, 'branding.json'), `${JSON.stringify(branding, null, 2)}\n`);

const stylesMatch = source.match(/<style>([\s\S]*?)<\/style>/);
if (stylesMatch) {
  fs.writeFileSync(path.join(stylesDir, 'global.css'), `${stylesMatch[1].trim()}\n`);
}

console.log(`Extraídos ${products.length} productos y ${Object.keys(imageMap).length} imágenes de producto.`);

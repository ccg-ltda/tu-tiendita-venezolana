export function productImageSrc(product) {
  const image = product?.image || product?.img;

  if (!image) return '';

  return /^https?:\/\//i.test(image) || image.startsWith('/') ? image : `/${image}`;
}

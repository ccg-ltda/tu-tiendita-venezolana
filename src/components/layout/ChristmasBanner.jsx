import { Link } from 'react-router-dom';

// Banner de Navidad: 3 tamaños por dispositivo en AVIF + WebP; el navegador
// descarga uno solo. El corte 767px coincide con .banner-wrap en global.css y
// con los <link rel="preload"> de index.html: si se cambia uno, cambiar todos.
// Imagen nueva = nombre nuevo (los assets tienen caché de 1 año).
const MOBILE = [480, 640, 780];
const DESKTOP = [1280, 1920, 2560];
const srcSet = (kind, widths, ext) => widths.map((w) => `/assets/banner-navidad-${kind}-${w}.${ext} ${w}w`).join(', ');
const DESKTOP_SIZES = '(max-width: 1240px) calc(100vw - 48px), 1192px';

export function ChristmasBanner({ href = '/categoria/alimentos' }) {
  return (
    <div className='banner-wrap'>
      <Link to={href} aria-label='Pide tu mercado navideño'>
        <picture>
          <source media='(max-width: 767px)' type='image/avif' srcSet={srcSet('mobile', MOBILE, 'avif')} sizes='100vw' width='780' height='1360' />
          <source media='(max-width: 767px)' type='image/webp' srcSet={srcSet('mobile', MOBILE, 'webp')} sizes='100vw' width='780' height='1360' />
          <source type='image/avif' srcSet={srcSet('desktop', DESKTOP, 'avif')} sizes={DESKTOP_SIZES} />
          <img
            src='/assets/banner-navidad-desktop-1280.webp'
            srcSet={srcSet('desktop', DESKTOP, 'webp')}
            sizes={DESKTOP_SIZES}
            width='1280'
            height='400'
            alt='Esta Navidad, el sabor de Venezuela en tu mesa: Harina PAN, pan de jamón, ponche crema y más, con envío a toda Colombia'
            fetchPriority='high'
            decoding='async'
          />
        </picture>
      </Link>
    </div>
  );
}

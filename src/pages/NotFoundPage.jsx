import { Link } from 'react-router-dom';
import { SeoHead } from '../components/common/SeoHead';

export function NotFoundPage() {
  return <main className='not-found-page'>
    <SeoHead title='Página no encontrada | Tu Tiendita Venezolana' robots='noindex,nofollow' />
    <section className='not-found-card'>
      <p className='not-found-code'>404</p>
      <h1>Página no encontrada</h1>
      <p>La página que buscas no existe o ya no está disponible.</p>
      <Link to='/'>Volver al inicio</Link>
    </section>
  </main>;
}

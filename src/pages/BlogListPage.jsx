import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, CalendarDays, FileText } from 'lucide-react';
import { Header } from '../components/layout/Header';
import { Footer } from '../components/layout/Footer';
import { PolicyModal } from '../components/common/PolicyModal';
import { Awning } from '../components/layout/Awning';
import { categoryIcons } from '../data/categories';
import { policies } from '../data/policies';
import { api } from '../services/api';
import { SeoHead } from '../components/common/SeoHead';
import { demoPosts, isPreviewHost } from '../data/blogDemo';
import '../styles/blog.css';

const spanishDate = new Intl.DateTimeFormat('es-CO', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
});

export function BlogListPage() {
  const [articles, setArticles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [isDemo, setIsDemo] = useState(false);
  const [activePolicy, setActivePolicy] = useState(null);
  const navigate = useNavigate();
  const categories = Object.keys(categoryIcons).map((name) => ({ name, slug: null }));

  useEffect(() => {
    let active = true;

    api.listBlog()
      .then((result) => {
        if (active) setArticles(Array.isArray(result) ? result : []);
      })
      .catch(() => {
        if (!active) return;
        // En los links de prueba de Vercel, sin servidor del blog, se muestran artículos de ejemplo.
        if (isPreviewHost()) { setArticles(demoPosts); setIsDemo(true); } else setFailed(true);
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  return (
    <div className='blog-page' id='inicio'>
      <SeoHead
        title='Blogs | Tu Tiendita Venezolana'
        description='Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.'
        canonical='https://tutienditavenezolana.com/blogs'
        robots='index,follow'
        openGraph={{ title: 'Blogs | Tu Tiendita Venezolana', description: 'Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.', image: 'https://tutienditavenezolana.com/assets/banner.webp', url: 'https://tutienditavenezolana.com/blogs', type: 'website' }}
        twitter={{ card: 'summary_large_image', title: 'Blogs | Tu Tiendita Venezolana', description: 'Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.', image: 'https://tutienditavenezolana.com/assets/banner.webp' }}
      />
      <Awning />
      <Header
        cartCount={0}
        onCartOpen={() => navigate('/')}
        search=''
        onSearch={() => {}}
        homeHref='/'
        showSearch={false}
      />

      <main className='blog-main'>
        <Link className='blog-store-link' to='/'><ArrowLeft size={17} aria-hidden='true' />Volver a la tienda</Link>
        <header className='blog-hero blog-hero-v2'>
          <div className='blog-hero-v2-stars' aria-hidden='true'>{Array.from({ length: 8 }, (_, i) => <span key={i} style={{ '--i': i }}>★</span>)}</div>
          <p className='blog-eyebrow'>El blog de Tu Tiendita</p>
          <h1>Sabores de Venezuela, <span>historias de casa</span></h1>
          <p>Recetas, tradiciones y los productos que nos conectan con Venezuela, desde Colombia.</p>
        </header>
        {isDemo && <p className='blog-demo' role='note'><b>Artículos de ejemplo.</b> Así se verán los artículos que publiquen desde Bunzy cuando el servidor del blog esté activo.</p>}

        {loading && <section className='blog-status' role='status'>Cargando artículos…</section>}

        {!loading && failed && <section className='blog-status blog-status-error' role='alert'>
          No fue posible cargar el blog.
        </section>}

        {!loading && !failed && articles.length === 0 && <section className='blog-status'>
          Aún no hay artículos publicados.
        </section>}

        {!loading && !failed && articles.length > 0 && <FeaturedArticle article={articles[0]} />}
        {!loading && !failed && articles.length > 1 && <section className='blog-grid' aria-label='Más artículos'>
          {articles.slice(1).map((article) => <article className='blog-card' key={article.id}>
            <BlogImage imageUrl={article.image_url} title={article.title} />
            <div className='blog-card-body'>
              <p className='blog-card-date'><CalendarDays size={15} aria-hidden='true' />{formatPublishedAt(article.published_at)}</p>
              <h2>{article.title}</h2>
              <Link className='blog-card-link' to={`/blogs/${encodeURIComponent(article.slug)}`}>
                Leer artículo <ArrowRight size={16} aria-hidden='true' />
              </Link>
            </div>
          </article>)}
        </section>}
        {!loading && <section className='blog-cta' aria-label='Ir a la tienda'>
          <div><p className='blog-eyebrow'>¿Se te antojó?</p><h2>Los sabores de estas historias, a un clic</h2><p>Enviamos desde Bogotá a toda Colombia.</p></div>
          <div className='blog-cta-actions'><Link className='blog-btn' to='/'>Ir a la tienda</Link><a className='blog-btn blog-btn-wa' href='https://wa.me/573107359944?text=%C2%A1Hola%2C%20Tu%20Tiendita%20Venezolana!%20%F0%9F%87%BB%F0%9F%87%AA%20Vengo%20del%20blog%20y%20quiero%20que%20me%20ayuden%20a%20comprar.' target='_blank' rel='noreferrer'>Escríbenos por WhatsApp</a></div>
        </section>}
      </main>

      <Footer
        categories={categories}
        onCategory={() => navigate('/')}
        onPolicy={(key) => setActivePolicy(policies[key])}
      />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
    </div>
  );
}

function FeaturedArticle({ article }) {
  return <article className='blog-featured'>
    <BlogImage imageUrl={article.image_url} title={article.title} />
    <div className='blog-featured-body'>
      <p className='blog-featured-tag'>Lo más reciente</p>
      <p className='blog-card-date'><CalendarDays size={15} aria-hidden='true' />{formatPublishedAt(article.published_at)}</p>
      <h2>{article.title}</h2>
      <Link className='blog-btn' to={`/blogs/${encodeURIComponent(article.slug)}`}>Leer artículo <ArrowRight size={16} aria-hidden='true' /></Link>
    </div>
  </article>;
}

function BlogImage({ imageUrl, title }) {
  const [failed, setFailed] = useState(false);

  if (!imageUrl || failed) {
    return <div className='blog-card-image blog-card-image-fallback' role='img' aria-label={`Imagen no disponible para ${title}`}>
      <FileText size={34} aria-hidden='true' />
    </div>;
  }

  return <div className='blog-card-image'>
    <img src={imageUrl} alt={title} loading='lazy' onError={() => setFailed(true)} />
  </div>;
}

function formatPublishedAt(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Fecha de publicación no disponible';

  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
    return 'Fecha de publicación no disponible';
  }

  return spanishDate.format(date);
}

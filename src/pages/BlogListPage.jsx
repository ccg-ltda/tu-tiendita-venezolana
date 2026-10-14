import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, CalendarDays, FileText } from 'lucide-react';
import { Header } from '../components/layout/Header';
import { Footer } from '../components/layout/Footer';
import { PolicyModal } from '../components/common/PolicyModal';
import { Awning } from '../components/layout/Awning';
import { categoryIcons } from '../data/categories';
import { policies } from '../data/policies';
import { api } from '../services/api';
import { SeoHead } from '../components/common/SeoHead';
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
  const [activePolicy, setActivePolicy] = useState(null);
  const navigate = useNavigate();
  const categories = Object.keys(categoryIcons);

  useEffect(() => {
    let active = true;

    api.listBlog()
      .then((result) => {
        if (active) setArticles(Array.isArray(result) ? result : []);
      })
      .catch(() => {
        if (active) setFailed(true);
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
        canonical='https://tutienditavenezolana.com/blog'
        robots='index,follow'
        openGraph={{ title: 'Blogs | Tu Tiendita Venezolana', description: 'Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.', image: 'https://tutienditavenezolana.com/assets/banner.png', url: 'https://tutienditavenezolana.com/blog', type: 'website' }}
        twitter={{ card: 'summary_large_image', title: 'Blogs | Tu Tiendita Venezolana', description: 'Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.', image: 'https://tutienditavenezolana.com/assets/banner.png' }}
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
        <header className='blog-hero'>
          <p className='blog-eyebrow'>Sabores, historias y consejos</p>
          <h1>Blogs</h1>
          <p>Descubre recetas, productos, cultura y sabores que nos conectan con Venezuela.</p>
        </header>

        {loading && <section className='blog-status' role='status'>Cargando artículos…</section>}

        {!loading && failed && <section className='blog-status blog-status-error' role='alert'>
          No fue posible cargar el blog.
        </section>}

        {!loading && !failed && articles.length === 0 && <section className='blog-status'>
          Aún no hay artículos publicados.
        </section>}

        {!loading && !failed && articles.length > 0 && <section className='blog-grid' aria-label='Artículos publicados'>
          {articles.map((article) => <article className='blog-card' key={article.id}>
            <BlogImage imageUrl={article.image_url} title={article.title} />
            <div className='blog-card-body'>
              <p className='blog-card-date'><CalendarDays size={15} aria-hidden='true' />{formatPublishedAt(article.published_at)}</p>
              <h2>{article.title}</h2>
              <Link className='blog-card-link' to={`/blog/${encodeURIComponent(article.slug)}`}>
                Leer artículo <ArrowRight size={16} aria-hidden='true' />
              </Link>
            </div>
          </article>)}
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

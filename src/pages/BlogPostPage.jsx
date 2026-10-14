import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CalendarDays, FileText } from 'lucide-react';
import { Header } from '../components/layout/Header';
import { Footer } from '../components/layout/Footer';
import { PolicyModal } from '../components/common/PolicyModal';
import { Awning } from '../components/layout/Awning';
import { categoryIcons } from '../data/categories';
import { policies } from '../data/policies';
import { api } from '../services/api';
import { sanitizeBlogHtml } from '../utils/sanitizeBlogHtml';
import { SeoHead } from '../components/common/SeoHead';
import '../styles/blog.css';

const spanishDate = new Intl.DateTimeFormat('es-CO', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
});

export function BlogPostPage() {
  const { slug } = useParams();
  const [article, setArticle] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [failed, setFailed] = useState(false);
  const [activePolicy, setActivePolicy] = useState(null);
  const navigate = useNavigate();
  const categories = Object.keys(categoryIcons);

  useEffect(() => {
    let active = true;
    setArticle(null);
    setLoading(true);
    setNotFound(false);
    setFailed(false);

    api.getBlogPost(slug)
      .then((result) => {
        if (!active) return;
        if (!result || typeof result !== 'object') throw new Error('Invalid blog post response');
        setArticle(result);
      })
      .catch((error) => {
        if (!active) return;

        if (error.status === 404) {
          setNotFound(true);
        } else {
          setFailed(true);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [slug]);

  const canonical = article ? `https://tutienditavenezolana.com/blog/${encodeURIComponent(article.slug)}` : undefined;
  const description = article ? articleDescription(article.content) : undefined;
  const title = article ? `${article.title} | Tu Tiendita Venezolana` : notFound ? 'Artículo no encontrado | Tu Tiendita Venezolana' : 'Blogs | Tu Tiendita Venezolana';
  const robots = article ? 'index,follow' : 'noindex,nofollow';
  const jsonLd = article ? blogPostingJsonLd(article, canonical) : undefined;

  return (
    <div className='blog-page' id='inicio'>
      <SeoHead
        title={title}
        description={description}
        canonical={canonical}
        robots={robots}
        openGraph={{ title, description, image: article?.image_url, url: canonical, type: article ? 'article' : undefined }}
        twitter={{ card: article ? 'summary_large_image' : undefined, title, description, image: article?.image_url }}
        jsonLd={jsonLd}
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

      <main className='blog-main blog-post-main'>
        <Link className='blog-back-link' to='/blog'><ArrowLeft size={17} aria-hidden='true' />Volver al blog</Link>

        {loading && <section className='blog-status' role='status'>Cargando artículo…</section>}

        {!loading && notFound && <BlogMessage>Artículo no encontrado.</BlogMessage>}
        {!loading && failed && <BlogMessage>No fue posible cargar el artículo.</BlogMessage>}

        {!loading && article && <article className='blog-post'>
          <header className='blog-post-header'>
            <p className='blog-card-date'><CalendarDays size={15} aria-hidden='true' />{formatPublishedAt(article.published_at)}</p>
            <h1>{article.title}</h1>
          </header>
          <BlogPostImage key={article.image_url || 'fallback'} imageUrl={article.image_url} title={article.title} />
          <div className='blog-content' dangerouslySetInnerHTML={{ __html: sanitizeBlogHtml(article.content) }} />
        </article>}
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

function BlogMessage({ children }) {
  return <section className='blog-status blog-status-error' role='alert'>
    <p>{children}</p>
    <Link className='blog-back-link blog-status-link' to='/blog'><ArrowLeft size={17} aria-hidden='true' />Volver al blog</Link>
  </section>;
}

function BlogPostImage({ imageUrl, title }) {
  const [failed, setFailed] = useState(false);

  if (!imageUrl || failed) {
    return <div className='blog-post-image blog-card-image-fallback' role='img' aria-label={`Imagen no disponible para ${title}`}>
      <FileText size={42} aria-hidden='true' />
    </div>;
  }

  return <div className='blog-post-image'>
    <img src={imageUrl} alt={title} onError={() => setFailed(true)} />
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

function articleDescription(content) {
  if (typeof content !== 'string' || content.trim() === '') return undefined;

  const text = new DOMParser().parseFromString(content, 'text/html').body.textContent.replace(/\s+/g, ' ').trim();

  return text === '' ? undefined : text.slice(0, 160).trimEnd();
}

function blogPostingJsonLd(article, canonical) {
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: article.title,
    mainEntityOfPage: { '@type': 'WebPage', '@id': canonical },
    publisher: {
      '@type': 'Organization',
      name: 'Tu Tiendita Venezolana',
      url: 'https://tutienditavenezolana.com/',
    },
  };

  if (article.image_url) jsonLd.image = article.image_url;
  if (article.published_at) jsonLd.datePublished = article.published_at;

  return jsonLd;
}

import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import App from './App';
import { AdminDataProvider } from './context/AdminDataContext';
import { CategoryPage } from './pages/CategoryPage';
import { ProductPage } from './pages/ProductPage';
import { BlogListPage } from './pages/BlogListPage';
import { BlogPostPage } from './pages/BlogPostPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { SeoHead } from './components/common/SeoHead';
import { ScrollToTop } from './components/common/ScrollToTop';
import './styles/global.css';
import './styles/react.css';

// El admin no lo carga el storefront publico: separarlo en su propio chunk
// achica el bundle que SI se descarga en cada visita al catalogo (PageSpeed).
const AdminLoginPage = lazy(() => import('./pages/admin/AdminLoginPage').then((m) => ({ default: m.AdminLoginPage })));
const AdminDashboardPage = lazy(() => import('./pages/admin/AdminPage').then((m) => ({ default: m.AdminDashboardPage })));
const AdminLayout = lazy(() => import('./pages/admin/AdminPage').then((m) => ({ default: m.AdminLayout })));
const AdminProductsPage = lazy(() => import('./pages/admin/products/AdminProductsPage').then((m) => ({ default: m.AdminProductsPage })));
const AdminPromotionsPage = lazy(() => import('./pages/admin/promotions/AdminPromotionsPage').then((m) => ({ default: m.AdminPromotionsPage })));
const AdminCouponsPage = lazy(() => import('./pages/admin/coupons/AdminCouponsPage').then((m) => ({ default: m.AdminCouponsPage })));
const AdminOrdersPage = lazy(() => import('./pages/admin/orders/AdminOrdersPage').then((m) => ({ default: m.AdminOrdersPage })));
const AdminActivityPage = lazy(() => import('./pages/admin/activity/AdminActivityPage').then((m) => ({ default: m.AdminActivityPage })));

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <ScrollToTop />
      <Suspense fallback={null}>
        <Routes>
          <Route path='/' element={<App />} />
          <Route path='/blogs' element={<BlogListPage />} />
          <Route path='/blogs/:slug' element={<BlogPostPage />} />
          <Route path='/categoria/:slug' element={<CategoryPage />} />
          <Route path='/producto/:slug' element={<ProductPage />} />
          <Route path='/admin/login' element={<><SeoHead title='Administración | Tu Tiendita Venezolana' robots='noindex,nofollow' /><AdminLoginPage /></>} />
          <Route path='/admin' element={<AdminDataProvider><SeoHead title='Administración | Tu Tiendita Venezolana' robots='noindex,nofollow' /><AdminLayout /></AdminDataProvider>}>
            <Route index element={<AdminDashboardPage />} />
            <Route path='products' element={<AdminProductsPage />} />
            <Route path='promotions' element={<AdminPromotionsPage />} />
            <Route path='coupons' element={<AdminCouponsPage />} />
            <Route path='orders' element={<AdminOrdersPage />} />
            <Route path='activity' element={<AdminActivityPage />} />
          </Route>
          <Route path='*' element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  </StrictMode>,
);

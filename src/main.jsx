import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import App from './App';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';
import { AdminDashboardPage, AdminLayout } from './pages/admin/AdminPage';
import { AdminProductsPage } from './pages/admin/products/AdminProductsPage';
import { AdminPromotionsPage } from './pages/admin/promotions/AdminPromotionsPage';
import { AdminCouponsPage } from './pages/admin/coupons/AdminCouponsPage';
import { AdminOrdersPage } from './pages/admin/orders/AdminOrdersPage';
import { AdminActivityPage } from './pages/admin/activity/AdminActivityPage';
import { AdminDataProvider } from './context/AdminDataContext';
import { BlogListPage } from './pages/BlogListPage';
import { BlogPostPage } from './pages/BlogPostPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { SeoHead } from './components/common/SeoHead';
import './styles/global.css';
import './styles/react.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path='/' element={<App />} />
        <Route path='/blog' element={<BlogListPage />} />
        <Route path='/blog/:slug' element={<BlogPostPage />} />
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
    </BrowserRouter>
  </StrictMode>,
);

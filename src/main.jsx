import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import App from './App';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';
import { AdminDashboardPage, AdminLayout } from './pages/admin/AdminPage';
import { AdminProductsPage } from './pages/admin/products/AdminProductsPage';
import { AdminOrdersPage } from './pages/admin/orders/AdminOrdersPage';
import { AdminDataProvider } from './context/AdminDataContext';
import './styles/global.css';
import './styles/react.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path='/' element={<App />} />
        <Route path='/admin/login' element={<AdminLoginPage />} />
        <Route path='/admin' element={<AdminDataProvider><AdminLayout /></AdminDataProvider>}>
          <Route index element={<AdminDashboardPage />} />
          <Route path='products' element={<AdminProductsPage />} />
          <Route path='orders' element={<AdminOrdersPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  </StrictMode>,
);

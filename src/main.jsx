import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import App from './App';
import { AdminForgotPasswordPage } from './pages/admin/AdminForgotPasswordPage';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';
import { AdminPage } from './pages/admin/AdminPage';
import { AdminProductsPage } from './pages/admin/products/AdminProductsPage';
import { AdminResetPasswordPage } from './pages/admin/AdminResetPasswordPage';
import './styles/global.css';
import './styles/react.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path='/' element={<App />} />
        <Route path='/admin/login' element={<AdminLoginPage />} />
        <Route path='/admin/forgot-password' element={<AdminForgotPasswordPage />} />
        <Route path='/admin/reset-password' element={<AdminResetPasswordPage />} />
        <Route path='/admin' element={<AdminPage />} />
        <Route path='/admin/products' element={<AdminProductsPage />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>,
);

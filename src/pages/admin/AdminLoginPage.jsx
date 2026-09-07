import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import branding from '../../data/branding.json';
import { api } from '../../services/api';
import '../../styles/admin.css';

export function AdminLoginPage() {
    const navigate = useNavigate();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
    const adminVisualSrc = '/assets/admin-login-visual.jpg';

    // Redirige al panel si ya existe una sesión administrativa válida.
    useEffect(() => {
        const checkSession = async () => {
            try {
                await api.me();
                navigate('/admin', { replace: true });
            } catch (requestError) {
                if (requestError.status !== 401 && requestError.status !== 403) {
                    setError('No se pudo verificar la sesión.');
                }
            }
        };

        checkSession();
    }, [navigate]);

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (!email.trim() || !password) {
            setError('Ingresa tu correo y contraseña.');
            return;
        }

        setLoading(true);
        setError('');

        try {
            await api.login({
                email: email.trim(),
                password,
            });

            navigate('/admin', { replace: true });
        } catch (requestError) {
            if (requestError.status === 401) {
                setError('Correo o contraseña incorrectos.');
            } else if (requestError.status === 422) {
                setError('Verifica los datos ingresados.');
            } else if (requestError.status === 429) {
                setError('Demasiados intentos. Intenta nuevamente más tarde.');
            } else {
                setError('No fue posible iniciar sesión.');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className='admin-login-page'>
            <section className='admin-login-content'>
                <div className='admin-login-form-wrapper'>
                    <div className='admin-login-brand'>
                        <img
                            className='admin-login-logo'
                            src={logoSrc}
                            alt='Tu Tiendita Venezolana'
                        />

                        <div>
                            <strong>Tu Tiendita Venezolana</strong>
                            <span>Panel administrativo</span>
                        </div>
                    </div>

                    <div className='admin-login-heading'>
                        <p className='admin-login-eyebrow'>
                            Acceso administrativo
                        </p>

                        <h1>Bienvenido de nuevo</h1>

                        <p>
                            Ingresa tus credenciales para continuar al panel de administración.
                        </p>
                    </div>

                    <form className='admin-login-form' onSubmit={handleSubmit}>
                        <div className='admin-login-field'>
                            <div className='admin-input-shell'>
                                <svg className='admin-input-icon' viewBox='0 0 24 24' aria-hidden='true'>
                                    <path d='M3.75 6.75h16.5v10.5H3.75z' />
                                    <path d='m4.5 7.5 7.5 5.25 7.5-5.25' />
                                </svg>

                                <input
                                    id='admin-email'
                                    type='email'
                                    value={email}
                                    onChange={(event) => setEmail(event.target.value)}
                                    autoComplete='username'
                                    placeholder=' '
                                    required
                                />

                                <label htmlFor='admin-email'>
                                    Correo electrónico
                                </label>
                            </div>
                        </div>

                        <div className='admin-login-field'>
                            <div className='admin-input-shell admin-password-field'>
                                <svg className='admin-input-icon' viewBox='0 0 24 24' aria-hidden='true'>
                                    <rect x='5.5' y='10' width='13' height='10' rx='2' />
                                    <path d='M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10' />
                                    <path d='M12 14v2' />
                                </svg>

                                <input
                                    id='admin-password'
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(event) => setPassword(event.target.value)}
                                    autoComplete='current-password'
                                    placeholder=' '
                                    required
                                />

                                <label htmlFor='admin-password'>
                                    Contraseña
                                </label>

                                <button
                                    type='button'
                                    className='admin-password-toggle'
                                    onClick={() => setShowPassword((current) => !current)}
                                    aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                                >
                                    {showPassword ? (
                                        <svg viewBox='0 0 24 24' aria-hidden='true'>
                                            <path d='M3 3 21 21' />
                                            <path d='M10.6 10.6a2 2 0 0 0 2.8 2.8' />
                                            <path d='M9.9 5.1A10.8 10.8 0 0 1 12 4.9c5.3 0 8.8 4.4 9.8 7.1a.9.9 0 0 1 0 .6 14.7 14.7 0 0 1-2.1 3.4' />
                                            <path d='M6.2 6.2A14.8 14.8 0 0 0 2.2 12a.9.9 0 0 0 0 .6C3.2 15.3 6.7 19.7 12 19.7c1.4 0 2.7-.3 3.8-.9' />
                                        </svg>
                                    ) : (
                                        <svg viewBox='0 0 24 24' aria-hidden='true'>
                                            <path d='M2.2 12a.9.9 0 0 0 0 .6c1 2.7 4.5 7.1 9.8 7.1s8.8-4.4 9.8-7.1a.9.9 0 0 0 0-.6c-1-2.7-4.5-7.1-9.8-7.1S3.2 9.3 2.2 12Z' />
                                            <circle cx='12' cy='12.3' r='3' />
                                        </svg>
                                    )}
                                </button>
                            </div>
                        </div>

                        <div className='admin-login-forgot-link'>
                            <Link to='/admin/forgot-password'>
                                ¿Olvidaste tu contraseña?
                            </Link>
                        </div>

                        {error && (
                            <p className='admin-login-error' role='alert'>
                                {error}
                            </p>
                        )}

                        <button
                            className='admin-login-submit'
                            type='submit'
                            disabled={loading}
                        >
                            {loading ? 'Ingresando...' : 'Iniciar sesión'}
                        </button>
                    </form>

                    <p className='admin-login-security'>
                        Acceso exclusivo para personal autorizado.
                    </p>
                </div>
            </section>

            <aside className='admin-login-visual' aria-hidden='true'>
                <img
                    className='admin-login-banner'
                    src={adminVisualSrc}
                    alt=''
                />
            </aside>
        </main>
    );
}

import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import branding from '../../data/branding.json';
import { api } from '../../services/api';
import '../../styles/admin.css';
import '../../styles/admin-login-card.css';

export function AdminLoginPage() {
    const navigate = useNavigate();

    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
    const logoDarkSrc = '/assets/logo-blanco.webp';
    const adminVisualSrc = '/assets/admin-login-visual.webp';

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

        if (!username.trim() || !password) {
            setError('Ingresa tu usuario y contraseña.');
            return;
        }

        setLoading(true);
        setError('');

        try {
            await api.login({
                username: username.trim().toLowerCase(),
                password,
            });

            navigate('/admin', { replace: true });
        } catch (requestError) {
            if (requestError.status === 401) {
                setError('Usuario o contraseña incorrectos.');
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
        <main className='login-page'>
            <section className='login-side'>
                <div className='login-card'>
                    <div className='login-brand'>
                        <picture>
                            <source srcSet={logoDarkSrc} media='(prefers-color-scheme: dark)' />
                            <img src={logoSrc} alt='Tu Tiendita Venezolana' width='52' height='52' />
                        </picture>
                        <div>
                            <strong>Tu Tiendita Venezolana</strong>
                            <span>Panel administrativo</span>
                        </div>
                    </div>

                    <h1>Iniciar sesión</h1>
                    <p className='login-sub'>Solo para el equipo de Tu Tiendita Venezolana.</p>

                    <form onSubmit={handleSubmit} autoComplete='on'>
                        <label htmlFor='admin-username'>Usuario</label>
                        <input
                            id='admin-username'
                            type='text'
                            value={username}
                            onChange={(event) => setUsername(event.target.value)}
                            autoComplete='username'
                            autoCapitalize='none'
                            required
                        />

                        <label htmlFor='admin-password'>Contraseña</label>
                        <div className='login-password'>
                            <input
                                id='admin-password'
                                type={showPassword ? 'text' : 'password'}
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                                autoComplete='current-password'
                                required
                            />
                            <button type='button' onClick={() => setShowPassword((current) => !current)} aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}>
                                {showPassword ? 'Ocultar' : 'Ver'}
                            </button>
                        </div>

                        {error && <p className='login-error' role='alert'>{error}</p>}

                        <button className='login-submit' type='submit' disabled={loading}>
                            {loading ? 'Entrando…' : 'Entrar'}
                        </button>
                    </form>
                </div>
            </section>

            <aside className='login-art' aria-hidden='true'>
                <img src={adminVisualSrc} alt='' />
            </aside>
        </main>
    );
}

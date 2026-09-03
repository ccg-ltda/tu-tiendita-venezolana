import { useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import branding from '../../data/branding.json';
import { api } from '../../services/api';
import '../../styles/admin.css';

function PasswordIcon() {
    return (
        <svg className='admin-input-icon' viewBox='0 0 24 24' aria-hidden='true'>
            <rect x='5.5' y='10' width='13' height='10' rx='2' />
            <path d='M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10' />
            <path d='M12 14v2' />
        </svg>
    );
}

function PasswordVisibilityIcon({ visible }) {
    if (visible) {
        return (
            <svg viewBox='0 0 24 24' aria-hidden='true'>
                <path d='M3 3 21 21' />
                <path d='M10.6 10.6a2 2 0 0 0 2.8 2.8' />
                <path d='M9.9 5.1A10.8 10.8 0 0 1 12 4.9c5.3 0 8.8 4.4 9.8 7.1a.9.9 0 0 1 0 .6 14.7 14.7 0 0 1-2.1 3.4' />
                <path d='M6.2 6.2A14.8 14.8 0 0 0 2.2 12a.9.9 0 0 0 0 .6C3.2 15.3 6.7 19.7 12 19.7c1.4 0 2.7-.3 3.8-.9' />
            </svg>
        );
    }

    return (
        <svg viewBox='0 0 24 24' aria-hidden='true'>
            <path d='M2.2 12a.9.9 0 0 0 0 .6c1 2.7 4.5 7.1 9.8 7.1s8.8-4.4 9.8-7.1a.9.9 0 0 0 0-.6c-1-2.7-4.5-7.1-9.8-7.1S3.2 9.3 2.2 12Z' />
            <circle cx='12' cy='12.3' r='3' />
        </svg>
    );
}

export function AdminResetPasswordPage() {
    const navigate = useNavigate();
    const location = useLocation();
    const [searchParams] = useSearchParams();
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
    const [loading, setLoading] = useState(false);
    const [success, setSuccess] = useState(() => Boolean(location.state?.resetSuccess));
    const [error, setError] = useState('');
    const [showNewLink, setShowNewLink] = useState(false);

    const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
    const adminVisualSrc = '/assets/admin-login-visual.jpg';
    const token = searchParams.get('token')?.trim() ?? '';
    const email = searchParams.get('email')?.trim() ?? '';
    const hasValidLink = Boolean(token && email);

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (loading) return;

        if (!password || !passwordConfirmation) {
            setError('Completa ambos campos de contraseña.');
            return;
        }

        if (password.length < 12) {
            setError('La contraseña debe tener al menos 12 caracteres.');
            return;
        }

        if (password !== passwordConfirmation) {
            setError('Las contraseñas no coinciden.');
            return;
        }

        setLoading(true);
        setError('');
        setShowNewLink(false);

        try {
            await api.resetPassword({
                email,
                token,
                password,
                password_confirmation: passwordConfirmation,
            });

            setSuccess(true);
            navigate('/admin/reset-password', {
                replace: true,
                state: { resetSuccess: true },
            });
        } catch (requestError) {
            if (requestError.status === 422) {
                setError('El enlace de recuperación no es válido o ha expirado.');
                setShowNewLink(true);
            } else if (requestError.status === 429) {
                setError('Has realizado demasiados intentos. Inténtalo nuevamente más tarde.');
            } else {
                setError('No pudimos actualizar la contraseña en este momento. Inténtalo nuevamente.');
            }
        } finally {
            setLoading(false);
        }
    };

    const heading = (title, description) => (
        <div className='admin-login-heading'>
            <p className='admin-login-eyebrow'>Recuperar acceso</p>
            <h1>{title}</h1>
            <p>{description}</p>
        </div>
    );

    let content;

    if (success) {
        content = (
            <div className='admin-forgot-password-success'>
                {heading(
                    'Contraseña actualizada',
                    'Tu contraseña fue actualizada correctamente. Ya puedes iniciar sesión con tus nuevas credenciales.',
                )}
                <Link className='admin-login-submit admin-reset-password-action' to='/admin/login'>
                    Iniciar sesión
                </Link>
            </div>
        );
    } else if (!hasValidLink) {
        content = (
            <div className='admin-forgot-password-success'>
                {heading(
                    'Enlace no válido',
                    'Este enlace de recuperación está incompleto o no es válido. Solicita uno nuevo para continuar.',
                )}
                <Link className='admin-login-submit admin-reset-password-action' to='/admin/forgot-password'>
                    Solicitar un nuevo enlace
                </Link>
                <Link className='admin-login-back-link' to='/admin/login'>
                    Volver al inicio de sesión
                </Link>
            </div>
        );
    } else {
        content = (
            <>
                {heading(
                    'Crea una nueva contraseña',
                    'Establece una nueva contraseña para recuperar el acceso a tu cuenta administrativa.',
                )}

                <form className='admin-login-form' onSubmit={handleSubmit}>
                    <div className='admin-login-field'>
                        <div className='admin-input-shell admin-password-field'>
                            <PasswordIcon />
                            <input
                                id='admin-reset-password'
                                type={showPassword ? 'text' : 'password'}
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                                autoComplete='new-password'
                                placeholder=' '
                                required
                            />
                            <label htmlFor='admin-reset-password'>Nueva contraseña</label>
                            <button
                                type='button'
                                className='admin-password-toggle'
                                onClick={() => setShowPassword((current) => !current)}
                                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                            >
                                <PasswordVisibilityIcon visible={showPassword} />
                            </button>
                        </div>
                    </div>

                    <div className='admin-login-field'>
                        <div className='admin-input-shell admin-password-field'>
                            <PasswordIcon />
                            <input
                                id='admin-reset-password-confirmation'
                                type={showPasswordConfirmation ? 'text' : 'password'}
                                value={passwordConfirmation}
                                onChange={(event) => setPasswordConfirmation(event.target.value)}
                                autoComplete='new-password'
                                placeholder=' '
                                required
                            />
                            <label htmlFor='admin-reset-password-confirmation'>Confirmar contraseña</label>
                            <button
                                type='button'
                                className='admin-password-toggle'
                                onClick={() => setShowPasswordConfirmation((current) => !current)}
                                aria-label={showPasswordConfirmation ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                            >
                                <PasswordVisibilityIcon visible={showPasswordConfirmation} />
                            </button>
                        </div>
                    </div>

                    {error && (
                        <p className='admin-login-error' role='alert'>
                            {error}
                        </p>
                    )}

                    {showNewLink && (
                        <Link className='admin-reset-password-retry-link' to='/admin/forgot-password'>
                            Solicitar un nuevo enlace
                        </Link>
                    )}

                    <button className='admin-login-submit' type='submit' disabled={loading}>
                        {loading ? 'Actualizando...' : 'Actualizar contraseña'}
                    </button>
                </form>
            </>
        );
    }

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

                    {content}
                </div>
            </section>

            <aside className='admin-login-visual' aria-hidden='true'>
                <img className='admin-login-banner' src={adminVisualSrc} alt='' />
            </aside>
        </main>
    );
}

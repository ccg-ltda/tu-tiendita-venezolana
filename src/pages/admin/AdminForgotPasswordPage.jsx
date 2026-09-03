import { useState } from 'react';
import { Link } from 'react-router-dom';
import branding from '../../data/branding.json';
import { api } from '../../services/api';
import '../../styles/admin.css';

export function AdminForgotPasswordPage() {
    const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
    const adminVisualSrc = '/assets/admin-login-visual.jpg';
    const [email, setEmail] = useState('');
    const [loading, setLoading] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState('');

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (loading || !event.currentTarget.checkValidity()) {
            event.currentTarget.reportValidity();
            return;
        }

        setLoading(true);
        setError('');

        try {
            await api.forgotPassword(email.trim());
            setSuccess(true);
        } catch (requestError) {
            if (requestError.status === 422) {
                setError('Ingresa un correo electrónico válido.');
            } else if (requestError.status === 429) {
                setError('Has realizado demasiadas solicitudes. Inténtalo nuevamente más tarde.');
            } else {
                setError('No pudimos procesar la solicitud en este momento. Inténtalo nuevamente.');
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

                    {success ? (
                        <div className='admin-forgot-password-success'>
                            <div className='admin-login-heading'>
                                <p className='admin-login-eyebrow'>
                                    Recuperar acceso
                                </p>

                                <h1>Revisa tu correo</h1>

                                <p>
                                    Si existe una cuenta administrativa asociada a ese correo,
                                    recibirás un enlace para restablecer tu contraseña.
                                </p>
                            </div>

                            <Link className='admin-login-back-link' to='/admin/login'>
                                Volver al inicio de sesión
                            </Link>
                        </div>
                    ) : (
                        <>
                            <div className='admin-login-heading'>
                                <p className='admin-login-eyebrow'>
                                    Recuperar acceso
                                </p>

                                <h1>¿Olvidaste tu contraseña?</h1>

                                <p>
                                    Ingresa el correo asociado a tu cuenta administrativa.
                                    Te enviaremos un enlace para restablecer tu contraseña.
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
                                            id='admin-forgot-password-email'
                                            name='email'
                                            type='email'
                                            value={email}
                                            onChange={(event) => setEmail(event.target.value)}
                                            autoComplete='email'
                                            placeholder=' '
                                            required
                                        />

                                        <label htmlFor='admin-forgot-password-email'>
                                            Correo electrónico
                                        </label>
                                    </div>
                                </div>

                                {error && (
                                    <p className='admin-login-error' role='alert'>
                                        {error}
                                    </p>
                                )}

                                <button className='admin-login-submit' type='submit' disabled={loading}>
                                    {loading ? 'Enviando...' : 'Enviar enlace'}
                                </button>
                            </form>

                            <Link className='admin-login-back-link' to='/admin/login'>
                                ← Volver al inicio de sesión
                            </Link>
                        </>
                    )}
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

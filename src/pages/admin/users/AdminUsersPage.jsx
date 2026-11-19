import { useCallback, useEffect, useState } from 'react';
import { AdminToast } from '../../../components/admin/AdminToast';
import { useAdminData } from '../../../context/AdminDataContext';
import { api } from '../../../services/api';
import '../../../styles/admin-today.css';

const ROLES = [
  ['ADMIN', 'Administrador', 'Ve y hace todo.'],
  ['DESPACHO', 'Despacho y entrega', 'Prepara, envía y entrega pedidos. No ve correo ni documento.'],
  ['ENTREGA', 'Solo entrega', 'Ve lo que va en camino y lo marca entregado.'],
];
const roleLabel = (role) => ROLES.find(([value]) => value === role)?.[1] || role;
const fail = (problem) => (problem?.details ? Object.values(problem.details)[0]?.[0] : '') || problem?.message || 'No fue posible guardar.';

export function AdminUsersPage() {
  const { user: me } = useAdminData();
  const [users, setUsers] = useState(null);
  const [error, setError] = useState('');
  const [toast, setToast] = useState(null);
  const [form, setForm] = useState({ name: '', username: '', role: 'DESPACHO', password: '' });
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');
  const [editing, setEditing] = useState(null);
  const [newPassword, setNewPassword] = useState('');

  const load = useCallback(() => api.listAdminUsers().then((data) => { setUsers(data.users); setError(''); }).catch((problem) => setError(fail(problem))), []);
  useEffect(() => { load(); }, [load]);

  const say = (title, message, tone = 'success') => setToast({ id: `${title}-${Date.now()}`, tone, variant: tone === 'error' ? 'error' : 'success', title, message });

  const create = async (event) => {
    event.preventDefault();
    setSaving(true); setFormError('');
    try {
      await api.createAdminUser(form);
      setForm({ name: '', username: '', role: 'DESPACHO', password: '' });
      say('Usuario creado', 'Ya puede entrar con su usuario y contraseña.');
      load();
    } catch (problem) { setFormError(fail(problem)); } finally { setSaving(false); }
  };

  const update = async (target, changes, okMessage) => {
    try {
      await api.updateAdminUser(target.id, changes);
      say('Listo', okMessage);
      setEditing(null); setNewPassword('');
      load();
    } catch (problem) { say('No se pudo guardar', fail(problem), 'error'); }
  };

  return <>
    <div className='admin-content manual-page'>
      <div className='today-heading'>
        <p className='admin-eyebrow'>Equipo del panel</p>
        <h1>Usuarios</h1>
        <p>Quién entra al panel y qué puede hacer. Las contraseñas nunca se muestran.</p>
      </div>

      <section className='manual-box'>
        <h2 style={{ marginLeft: 0 }}>Agregar persona</h2>
        <form onSubmit={create} autoComplete='off'>
          <label htmlFor='u-name'>Nombre</label>
          <input id='u-name' required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder='Ej: Carlos Rojas' />
          <label htmlFor='u-user'>Usuario (para entrar)</label>
          <input id='u-user' required value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} placeholder='carlos.rojas' autoCapitalize='none' />
          <label htmlFor='u-role'>Rol</label>
          <select id='u-role' value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })}>{ROLES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
          <p className='today-muted'>{ROLES.find(([value]) => value === form.role)?.[2]}</p>
          <label htmlFor='u-pass'>Contraseña (mínimo 10 caracteres)</label>
          <input id='u-pass' type='password' required minLength={10} autoComplete='new-password' value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
          <button className='today-action manual-save' type='submit' disabled={saving} style={{ marginInline: 0, width: '100%' }}>{saving ? 'Guardando…' : 'Crear usuario'}</button>
          {formError && <div className='manual-error' role='alert' style={{ marginTop: 10 }}>{formError}</div>}
        </form>
      </section>

      {error && <div className='manual-error' role='alert'>{error}</div>}
      {!users && !error && <div className='admin-orders-state'>Cargando…</div>}
      <div className='today-list' style={{ marginTop: 14 }}>
        {(users || []).map((person) => <article key={person.id} className={`today-card ${person.active ? 'today-card--ready' : 'today-card--delivered'}`}>
          <div className='today-card-head' style={{ cursor: 'default' }}>
            <span className='today-card-ref'>{person.username}{person.id === me?.id ? ' · eres tú' : ''}</span>
            <strong className='today-card-name'>{person.name}</strong>
            <span className='today-card-total'>{person.active ? roleLabel(person.role) : 'Desactivado'}</span>
          </div>
          <div className='today-card-body'>
            {editing === person.id ? <>
              <label className='today-items-title' htmlFor={`r-${person.id}`}>Rol</label>
              <select id={`r-${person.id}`} defaultValue={person.role} onChange={(event) => update(person, { role: event.target.value }, 'Rol actualizado.')} style={{ width: '100%', minHeight: 48, borderRadius: 12, padding: '0 12px', marginBottom: 10 }}>
                {ROLES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
              <label className='today-items-title' htmlFor={`p-${person.id}`}>Nueva contraseña (opcional)</label>
              <input id={`p-${person.id}`} type='password' minLength={10} autoComplete='new-password' value={newPassword} onChange={(event) => setNewPassword(event.target.value)} style={{ width: '100%', minHeight: 48, borderRadius: 12, padding: '0 12px', border: '1.5px solid rgba(15,35,80,.18)', marginBottom: 10, boxSizing: 'border-box' }} />
              <div className='today-links'>
                <button type='button' className='today-links-btn' onClick={() => newPassword ? update(person, { password: newPassword }, 'Contraseña cambiada. Deberá entrar de nuevo.') : setEditing(null)}>{newPassword ? 'Guardar contraseña' : 'Cerrar'}</button>
                {person.id !== me?.id && <button type='button' className='today-links-btn' onClick={() => update(person, { active: !person.active }, person.active ? 'Usuario desactivado.' : 'Usuario activado.')}>{person.active ? 'Desactivar' : 'Activar'}</button>}
              </div>
            </> : <button type='button' className='today-links-btn' onClick={() => { setEditing(person.id); setNewPassword(''); }}>Editar</button>}
          </div>
        </article>)}
      </div>
    </div>
    <AdminToast toast={toast} onDismiss={() => setToast(null)} />
  </>;
}

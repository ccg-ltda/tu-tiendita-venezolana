export function PolicyModal({ policy, onClose }) {
  if (!policy) return null;
  return <div className="policy-overlay open" onClick={(event) => event.target === event.currentTarget && onClose()}><article className="policy-modal"><div className="policy-modal-head"><h3>{policy.title}</h3><button className="policy-modal-close" onClick={onClose}>✕</button></div><div className="policy-modal-body">{policy.sections.map(([title, body]) => <section key={title}><h4>{title}</h4><p>{body}</p></section>)}</div></article></div>;
}

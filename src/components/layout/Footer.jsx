const helpLinks = [['envio', 'Política de envío'], ['reembolso', 'Política de reembolso'], ['privacidad', 'Política de privacidad'], ['terminos', 'Términos del servicio'], ['faq', 'Preguntas frecuentes']];

export function Footer({ categories, onCategory, onPolicy }) {
  return (
    <footer>
      <div className="footer-inner">
        <div><h5>Tu Tiendita Venezolana</h5><ul><li>Bogotá, Colombia</li><li>Desde Bogotá para toda Colombia</li><li>🇻🇪 El sabor de Venezuela</li></ul></div>
        <div><h5>Categorías</h5><ul>{categories.map((cat) => <li key={cat}><a href="#catalogo" onClick={() => onCategory(cat)}>{cat}</a></li>)}</ul></div>
        <div><h5>Ayuda</h5><ul>{helpLinks.map(([key, label]) => <li key={key}><button className="footer-link" onClick={() => onPolicy(key)}>{label}</button></li>)}</ul></div>
      </div>
      <div className="footer-bottom"><span>© {new Date().getFullYear()} Tu Tiendita Venezolana.</span><span>Todos los derechos reservados.</span></div>
    </footer>
  );
}

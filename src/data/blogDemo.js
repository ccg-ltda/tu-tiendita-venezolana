// Artículos de EJEMPLO para revisar el diseño del blog en los links de prueba de Vercel
// mientras el servidor (Bunzy → /api/blog) no está publicado. En la tienda real nunca se muestran.
export const isPreviewHost = () => typeof window !== 'undefined' && /(\.vercel\.app|localhost|127\.0\.0\.1)$/.test(window.location.hostname);

export const demoPosts = [
  {
    id: 'demo-1', slug: 'arepas-perfectas-harina-pan', title: 'Cómo hacer arepas perfectas con Harina PAN', published_at: '2026-11-08',
    image_url: null,
    content: '<p>La arepa es el pan de cada día en Venezuela. Con <strong>Harina PAN</strong>, agua tibia y una pizca de sal tienes la base perfecta.</p><h2>Ingredientes</h2><ul><li>2 tazas de Harina PAN</li><li>2 ½ tazas de agua tibia</li><li>1 cucharadita de sal</li></ul><h2>Paso a paso</h2><ol><li>Mezcla el agua con la sal y agrega la harina poco a poco.</li><li>Amasa hasta que la masa no se pegue en las manos y déjala reposar 5 minutos.</li><li>Forma bolas, aplánalas y ásalas en budare o sartén 6 minutos por lado.</li></ol><p>Rellénalas con queso llanero, caraotas, carne mechada o lo que más te guste.</p>',
  },
  {
    id: 'demo-2', slug: 'hallacas-receta-navidena', title: 'Hallacas: la receta navideña que nos une', published_at: '2026-11-03',
    image_url: null,
    content: '<p>En diciembre, la hallaca reúne a la familia alrededor de la mesa. Es un trabajo en equipo: unos preparan el guiso, otros la masa y otros amarran.</p><h2>Lo que no puede faltar</h2><ul><li>Masa de maíz con onoto</li><li>Guiso de res, cerdo y pollo</li><li>Adornos: aceitunas, pasas, alcaparras</li><li>Hojas de plátano</li></ul><p>En Tu Tiendita encuentras los ingredientes venezolanos para que tu hallaca sepa a casa.</p>',
  },
  {
    id: 'demo-3', slug: 'productos-venezolanos-en-colombia', title: '10 productos venezolanos que puedes comprar en Colombia', published_at: '2026-10-28',
    image_url: null,
    content: '<p>Extrañar los sabores de casa ya no es necesario. Estos son los favoritos de nuestros clientes:</p><ol><li>Harina PAN</li><li>Toddy</li><li>Diablitos Underwood</li><li>Pepitonas</li><li>Malta</li><li>Samba</li><li>Rikesa</li><li>Queso llanero</li><li>Café venezolano</li><li>Tequeños</li></ol><p>Enviamos desde Bogotá a toda Colombia.</p>',
  },
];

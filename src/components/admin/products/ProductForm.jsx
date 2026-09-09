import { useEffect, useMemo, useRef, useState } from 'react';

const emptyProduct = { name: '', category: '', subcategory: '', presentation: 'Unidad', price: '' };

export function ProductForm({ mode, product, products, saving, error, errors, onCancel, onSubmit }) {
  const [values, setValues] = useState(emptyProduct);
  const [imageFile, setImageFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState('');
  const [localErrors, setLocalErrors] = useState({});
  const fileInput = useRef(null);
  const categories = useMemo(() => optionsFrom(products, (item) => item.category), [products]);
  const subcategories = useMemo(() => optionsFrom(products.filter((item) => item.category === values.category), (item) => item.subcategory), [products, values.category]);

  useEffect(() => {
    setValues(product ? { name: product.name || '', category: product.category || '', subcategory: product.subcategory || '', presentation: product.presentation || '', price: String(product.price ?? '') } : emptyProduct);
    setImageFile(null);
    setLocalErrors({});
  }, [product, mode]);

  useEffect(() => {
    if (!imageFile) {
      setPreviewUrl(imageUrl(product?.image));
      return undefined;
    }

    const objectUrl = URL.createObjectURL(imageFile);
    setPreviewUrl(objectUrl);

    return () => URL.revokeObjectURL(objectUrl);
  }, [imageFile, product?.image]);

  const update = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));
  const updateCategory = (event) => {
    const category = event.target.value;

    setValues((current) => ({
      ...current,
      category,
      subcategory: products.some((item) => item.category === category && item.subcategory === current.subcategory) ? current.subcategory : '',
    }));
  };
  const selectImage = (event) => setImageFile(event.target.files?.[0] || null);
  const submit = (event) => {
    event.preventDefault();
    const nextErrors = {};
    ['name', 'category', 'subcategory', 'presentation'].forEach((field) => { if (!values[field].trim()) nextErrors[field] = 'Este campo es obligatorio.'; });
    const price = Number(values.price);
    if (!Number.isInteger(price) || price < 0) nextErrors.price = 'Ingresa un precio entero igual o mayor a 0.';
    setLocalErrors(nextErrors);
    if (Object.keys(nextErrors).length) return;
    onSubmit({ ...values, price, image: imageFile });
  };
  const fieldError = (field) => localErrors[field] || errors?.[field]?.[0];
  const imageLabel = mode === 'create' ? 'Seleccionar imagen' : 'Cambiar imagen';

  return <aside className='admin-product-drawer' aria-label={mode === 'create' ? 'Nuevo producto' : 'Editar producto'} aria-modal='true' role='dialog'>
    <div className='admin-product-drawer-header'><h2>{mode === 'create' ? 'Nuevo producto' : 'Editar producto'}</h2><button type='button' className='admin-product-drawer-close' onClick={onCancel} disabled={saving} aria-label='Cerrar'>×</button></div>
    <form className='admin-product-form' onSubmit={submit} noValidate>
      {error && <p className='admin-product-form-error' role='alert'>{error}</p>}
      <Field label='Nombre' value={values.name} onChange={update('name')} error={fieldError('name')} />
      <SelectField label='Categoría' value={values.category} onChange={updateCategory} error={fieldError('category')} placeholder='Selecciona una categoría' options={categories} />
      <SelectField label='Subcategoría' value={values.subcategory} onChange={update('subcategory')} error={fieldError('subcategory')} placeholder='Selecciona una subcategoría' options={subcategories} disabled={!values.category} />
      <Field label='Presentación' value={values.presentation} onChange={update('presentation')} error={fieldError('presentation')} />
      <Field label='Precio' type='number' min='0' step='1' value={values.price} onChange={update('price')} error={fieldError('price')} />
      <section className='admin-product-image-field' aria-labelledby='product-image-label'>
        <span id='product-image-label'>{mode === 'edit' && previewUrl ? 'Imagen actual' : 'Imagen del producto'}</span>
        {previewUrl && <img src={previewUrl} alt='Vista previa del producto' className='admin-product-image-preview' />}
        <input ref={fileInput} type='file' accept='image/jpeg,image/png,image/webp' onChange={selectImage} disabled={saving} />
        <button type='button' onClick={() => fileInput.current?.click()} disabled={saving}>{imageLabel}</button>
        <small>JPG, PNG o WEBP. Máximo 5 MB.</small>
        {fieldError('image') && <small className='admin-product-image-error'>{fieldError('image')}</small>}
      </section>
      <div className='admin-product-form-actions'><button type='button' onClick={onCancel} disabled={saving}>Cancelar</button><button type='submit' className='is-primary' disabled={saving}>{saving ? 'Guardando...' : mode === 'create' ? 'Crear producto' : 'Guardar cambios'}</button></div>
    </form>
  </aside>;
}

function Field({ label, error, ...inputProps }) {
  return <label className='admin-product-form-field'><span>{label}</span><input {...inputProps} aria-invalid={Boolean(error)} />{error && <small>{error}</small>}</label>;
}

function SelectField({ label, error, placeholder, options, ...selectProps }) {
  return <label className='admin-product-form-field'><span>{label}</span><select {...selectProps} aria-invalid={Boolean(error)}><option value=''>{placeholder}</option>{options.map((option) => <option key={option} value={option}>{option}</option>)}</select>{error && <small>{error}</small>}</label>;
}

function optionsFrom(products, value) {
  return [...new Set(products.map(value).filter(Boolean))].sort((first, second) => first.localeCompare(second, 'es'));
}

function imageUrl(image) {
  if (!image) return '';

  return image.startsWith('http') || image.startsWith('/') ? image : `/${image}`;
}

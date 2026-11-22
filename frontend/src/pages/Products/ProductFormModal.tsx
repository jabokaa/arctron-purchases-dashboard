import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { AuthError } from '../../services/auth'
import {
  createProduct,
  deleteProductImage,
  IMAGE_MAX_BYTES,
  IMAGE_TYPES,
  updateProduct,
  uploadProductImage,
  type Product,
} from '../../services/products'

type Field = 'name' | 'internal_code' | 'image_url'
type FormErrors = Partial<Record<Field, string>>

const FIELDS: Field[] = ['name', 'internal_code', 'image_url']

function validateImage(file: File): string | null {
  if (!IMAGE_TYPES.includes(file.type))
    return 'O arquivo deve ser uma imagem JPG, PNG, WebP ou GIF.'
  if (file.size > IMAGE_MAX_BYTES) return 'A imagem deve ter no máximo 2 MB.'
  return null
}

function validate(name: string, internalCode: string) {
  const errors: FormErrors = {}

  if (!name.trim()) errors.name = 'O nome do produto é obrigatório.'
  else if (name.trim().length > 255)
    errors.name = 'O nome deve ter no máximo 255 caracteres.'

  if (internalCode.trim().length > 100)
    errors.internal_code = 'O código interno deve ter no máximo 100 caracteres.'

  return errors
}

type Props = {
  /** Produto em edição; sem ele, o modal cria um novo */
  product: Product | null
  /** `changed`: algo foi gravado (o produto salvo, mas a imagem falhou), então a lista precisa ser recarregada */
  onClose: (changed: boolean) => void
  onSaved: (product: Product) => void
  onUnauthorized: () => void
}

function ProductFormModal({ product, onClose, onSaved, onUnauthorized }: Props) {
  const [name, setName] = useState(product?.name ?? '')
  const [internalCode, setInternalCode] = useState(product?.internal_code ?? '')
  // Produto já gravado: se a imagem falhar, o reenvio atualiza este produto em vez de criar outro
  const [saved, setSaved] = useState<Product | null>(product)
  const [file, setFile] = useState<File | null>(null)
  const [removeImage, setRemoveImage] = useState(false)
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const nameRef = useRef<HTMLInputElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    nameRef.current?.focus()
  }, [])

  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])

  useEffect(() => {
    return () => {
      if (preview) URL.revokeObjectURL(preview)
    }
  }, [preview])

  const changed = saved !== product

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape' && !submitting) onClose(changed)
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [onClose, submitting, changed])

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const chosen = event.target.files?.[0] ?? null
    event.target.value = ''
    if (!chosen) return

    const problem = validateImage(chosen)
    setErrors((current) => ({ ...current, image_url: problem ?? undefined }))
    if (problem) return

    setFile(chosen)
    setRemoveImage(false)
  }

  function clearImage() {
    setFile(null)
    setRemoveImage(true)
    setErrors((current) => ({ ...current, image_url: undefined }))
  }

  const currentImage = file
    ? preview
    : removeImage
      ? null
      : (saved?.image_url ?? null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const validationErrors = validate(name, internalCode)
    setErrors(validationErrors)
    if (Object.keys(validationErrors).length > 0) return

    const input = {
      name: name.trim(),
      internal_code: internalCode.trim() || null,
    }

    setSubmitting(true)
    let step: 'product' | 'image' = 'product'
    try {
      let result = saved
        ? await updateProduct(saved.id, input)
        : await createProduct(input)
      setSaved(result)

      step = 'image'
      if (file) result = await uploadProductImage(result.id, file)
      else if (removeImage && result.image_url)
        result = await deleteProductImage(result.id)

      onSaved(result)
    } catch (error) {
      if (error instanceof AuthError && error.status === 401) {
        onUnauthorized()
        return
      }
      if (step === 'image') {
        const reason =
          error instanceof AuthError
            ? (error.violations?.[0]?.message ?? error.message)
            : 'Ocorreu um erro inesperado.'
        setFormError(
          `O produto foi salvo, mas a imagem não pôde ser enviada: ${reason}`,
        )
      } else if (error instanceof AuthError && error.violations?.length) {
        const fieldErrors: FormErrors = {}
        for (const violation of error.violations) {
          const field = violation.field as Field
          if (FIELDS.includes(field)) fieldErrors[field] ??= violation.message
        }
        setErrors(fieldErrors)
      } else if (error instanceof AuthError && error.status === 409) {
        setErrors({ internal_code: error.message })
      } else {
        setFormError(
          error instanceof AuthError
            ? error.message
            : 'Ocorreu um erro inesperado. Tente novamente.',
        )
      }
      setSubmitting(false)
    }
  }

  return (
    <div
      className="modal"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !submitting)
          onClose(changed)
      }}
    >
      <div
        className="modal__card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="product-form-title"
      >
        <h2 id="product-form-title">
          {product ? 'Editar produto' : 'Novo produto'}
        </h2>

        <form onSubmit={handleSubmit} noValidate>
          {formError && (
            <div className="alert" role="alert">
              {formError}
            </div>
          )}

          <div className="field">
            <label htmlFor="product-name">Nome *</label>
            <input
              id="product-name"
              ref={nameRef}
              value={name}
              maxLength={255}
              aria-invalid={!!errors.name}
              onChange={(event) => setName(event.target.value)}
            />
            {errors.name && <span className="field__error">{errors.name}</span>}
          </div>

          <div className="field">
            <label htmlFor="product-code">Código interno</label>
            <input
              id="product-code"
              value={internalCode}
              maxLength={100}
              placeholder="Ex.: CHU-001"
              aria-invalid={!!errors.internal_code}
              onChange={(event) => setInternalCode(event.target.value)}
            />
            {errors.internal_code && (
              <span className="field__error">{errors.internal_code}</span>
            )}
          </div>

          <div className="field">
            <label htmlFor="product-image">Imagem</label>
            <div className="image-picker">
              {currentImage ? (
                <img
                  className="image-picker__preview"
                  src={currentImage}
                  alt="Imagem do produto"
                />
              ) : (
                <div className="image-picker__preview image-picker__preview--empty">
                  Sem imagem
                </div>
              )}
              <div className="image-picker__actions">
                <input
                  id="product-image"
                  ref={fileRef}
                  type="file"
                  accept={IMAGE_TYPES.join(',')}
                  hidden
                  onChange={handleFileChange}
                />
                <button
                  type="button"
                  className="btn btn--small"
                  disabled={submitting}
                  onClick={() => fileRef.current?.click()}
                >
                  {currentImage ? 'Trocar imagem' : 'Escolher imagem'}
                </button>
                {currentImage && (
                  <button
                    type="button"
                    className="btn btn--small btn--danger"
                    disabled={submitting}
                    onClick={clearImage}
                  >
                    Remover
                  </button>
                )}
                <small>JPG, PNG, WebP ou GIF, até 2 MB.</small>
              </div>
            </div>
            {errors.image_url && (
              <span className="field__error">{errors.image_url}</span>
            )}
          </div>

          <div className="modal__actions">
            <button
              type="button"
              className="btn btn--ghost"
              onClick={() => onClose(changed)}
              disabled={submitting}
            >
              Cancelar
            </button>
            <button type="submit" className="btn btn--primary" disabled={submitting}>
              {submitting ? 'Salvando...' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default ProductFormModal

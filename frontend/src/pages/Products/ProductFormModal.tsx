import { CircleAlert, ImagePlus, Loader2, Trash2, X } from 'lucide-react'
import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
  type DragEvent,
  type FormEvent,
} from 'react'
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
  const [dragging, setDragging] = useState(false)
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const nameRef = useRef<HTMLInputElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    nameRef.current?.focus()
  }, [])

  const preview = useMemo(
    () => (file ? URL.createObjectURL(file) : null),
    [file],
  )

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

  function chooseFile(chosen: File | undefined) {
    if (!chosen) return

    const problem = validateImage(chosen)
    setErrors((current) => ({ ...current, image_url: problem ?? undefined }))
    if (problem) return

    setFile(chosen)
    setRemoveImage(false)
  }

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    chooseFile(event.target.files?.[0])
    event.target.value = ''
  }

  function handleDrop(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    setDragging(false)
    if (!submitting) chooseFile(event.dataTransfer.files[0])
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
      className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-navy-950/60 p-4 backdrop-blur-sm"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !submitting)
          onClose(changed)
      }}
    >
      <div
        className="w-full max-w-lg rounded-2xl bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="product-form-title"
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2
            id="product-form-title"
            className="text-lg font-semibold text-slate-900"
          >
            {product ? 'Editar produto' : 'Novo produto'}
          </h2>
          <button
            type="button"
            className="icon-btn"
            aria-label="Fechar"
            disabled={submitting}
            onClick={() => onClose(changed)}
          >
            <X className="size-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} noValidate>
          <div className="space-y-5 px-6 py-5">
            {formError && (
              <div className="alert alert-error" role="alert">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                {formError}
              </div>
            )}

            <div>
              <label htmlFor="product-name" className="label">
                Nome <span className="text-red-500">*</span>
              </label>
              <input
                id="product-name"
                ref={nameRef}
                className="input"
                value={name}
                maxLength={255}
                aria-invalid={!!errors.name}
                onChange={(event) => setName(event.target.value)}
              />
              {errors.name && <span className="field-error">{errors.name}</span>}
            </div>

            <div>
              <label htmlFor="product-code" className="label">
                Código interno
              </label>
              <input
                id="product-code"
                className="input font-mono"
                value={internalCode}
                maxLength={100}
                placeholder="Ex.: CHU-001"
                aria-invalid={!!errors.internal_code}
                onChange={(event) => setInternalCode(event.target.value)}
              />
              {errors.internal_code && (
                <span className="field-error">{errors.internal_code}</span>
              )}
            </div>

            <div>
              <span className="label">Imagem</span>
              <input
                id="product-image"
                ref={fileRef}
                type="file"
                accept={IMAGE_TYPES.join(',')}
                hidden
                onChange={handleFileChange}
              />
              <div
                onDragOver={(event) => {
                  event.preventDefault()
                  setDragging(true)
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={`flex items-center gap-4 rounded-xl border-2 border-dashed p-4 transition ${
                  dragging
                    ? 'border-pitch-500 bg-pitch-50'
                    : 'border-slate-300 bg-slate-50'
                }`}
              >
                {currentImage ? (
                  <img
                    src={currentImage}
                    alt="Imagem do produto"
                    className="size-24 shrink-0 rounded-lg border border-slate-200 bg-white object-cover"
                  />
                ) : (
                  <div className="flex size-24 shrink-0 items-center justify-center rounded-lg bg-white text-slate-400 ring-1 ring-slate-200">
                    <ImagePlus className="size-8" />
                  </div>
                )}
                <div className="min-w-0">
                  <p className="text-sm text-slate-600">
                    Arraste uma imagem aqui ou escolha um arquivo.
                  </p>
                  <p className="mt-0.5 text-xs text-slate-400">
                    JPG, PNG, WebP ou GIF, até 2 MB.
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      disabled={submitting}
                      onClick={() => fileRef.current?.click()}
                    >
                      {currentImage ? 'Trocar imagem' : 'Escolher imagem'}
                    </button>
                    {currentImage && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm text-red-600"
                        disabled={submitting}
                        onClick={clearImage}
                      >
                        <Trash2 className="size-3.5" />
                        Remover
                      </button>
                    )}
                  </div>
                </div>
              </div>
              {errors.image_url && (
                <span className="field-error">{errors.image_url}</span>
              )}
            </div>
          </div>

          <div className="flex justify-end gap-3 rounded-b-2xl border-t border-slate-200 bg-slate-50 px-6 py-4">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => onClose(changed)}
              disabled={submitting}
            >
              Cancelar
            </button>
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              {submitting && <Loader2 className="size-4 animate-spin" />}
              {submitting ? 'Salvando...' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default ProductFormModal

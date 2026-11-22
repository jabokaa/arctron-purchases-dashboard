import { CircleAlert } from 'lucide-react'

type Props = {
  message: string
  onRetry?: () => void
}

function ErrorAlert({ message, onRetry }: Props) {
  return (
    <div className="alert alert-error" role="alert">
      <CircleAlert className="mt-0.5 size-4 shrink-0" />
      <span className="flex-1">{message}</span>
      {onRetry && (
        <button type="button" className="font-semibold underline" onClick={onRetry}>
          Tentar novamente
        </button>
      )}
    </div>
  )
}

export default ErrorAlert

const LABELS: Record<string, string> = {
  API: 'API',
  WOOCOMMERCE: 'WooCommerce',
  SHOPIFY: 'Shopify',
}

const STYLES: Record<string, string> = {
  WOOCOMMERCE: 'bg-violet-50 text-violet-700 ring-violet-100',
  SHOPIFY: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
}

function ChannelBadge({ channel }: { channel: string }) {
  return (
    <span
      className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
        STYLES[channel] ?? 'bg-navy-50 text-navy-700 ring-navy-100'
      }`}
    >
      {LABELS[channel] ?? channel}
    </span>
  )
}

export default ChannelBadge

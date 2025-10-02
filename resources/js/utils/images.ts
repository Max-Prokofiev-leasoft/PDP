let _leaLogoTried = false
let _leaLogoDataUrl: string | null = null

export async function getLeaSoftLogo(): Promise<string | null> {
  if (_leaLogoTried) return _leaLogoDataUrl
  _leaLogoTried = true
  try {
    const res = await fetch('/images/lea-soft.png', { cache: 'force-cache' })
    if (!res.ok) return null
    const blob = await res.blob()
    const dataUrl: string = await new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result || ''))
      reader.onerror = () => reject(new Error('Failed to read logo blob'))
      reader.readAsDataURL(blob)
    })
    _leaLogoDataUrl = dataUrl || null
  } catch {
    _leaLogoDataUrl = null
  }
  return _leaLogoDataUrl
}

const _leaLogoCircularCache: Record<number, string> = {}
export async function getLeaSoftLogoCircular(size = 40): Promise<string | null> {
  const cacheKey = size && size > 0 ? size : 0
  if (_leaLogoCircularCache[cacheKey]) return _leaLogoCircularCache[cacheKey]
  const base = await getLeaSoftLogo()
  if (!base) return null
  try {
    const img: HTMLImageElement = await new Promise((resolve, reject) => {
      const im = new Image()
      im.onload = () => resolve(im)
      im.onerror = () => reject(new Error('Failed to load logo image'))
      im.src = base
    })

    const useNative = !(size && size > 0)
    const canW = useNative ? (img.width || size || 40) : size
    const canH = useNative ? (img.height || size || 40) : size

    const canvas = document.createElement('canvas')
    canvas.width = canW
    canvas.height = canH
    const ctx = canvas.getContext('2d')
    if (!ctx) return base

    const diam = Math.min(canW, canH)
    const cx = canW / 2
    const cy = canH / 2
    ctx.clearRect(0, 0, canW, canH)
    ctx.save()
    ctx.beginPath()
    ctx.arc(cx, cy, diam / 2, 0, Math.PI * 2)
    ctx.closePath()
    ctx.clip()

    const scale = Math.max(diam / img.width, diam / img.height)
    const drawW = img.width * scale
    const drawH = img.height * scale
    const dx = cx - drawW / 2
    const dy = cy - drawH / 2
    ctx.drawImage(img, dx, dy, drawW, drawH)
    ctx.restore()

    const out = canvas.toDataURL('image/png')
    _leaLogoCircularCache[cacheKey] = out
    return out
  } catch {
    return base
  }
}

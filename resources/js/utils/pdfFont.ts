let _pdfFontBase64Regular: string | null = null
let _pdfFontBase64Bold: string | null = null
const _pdfFontFileRegular = 'DejaVuSans.ttf'
const _pdfFontFileBold = 'DejaVuSans-Bold.ttf'
const _pdfFontName = 'DejaVuSans'

export async function ensurePdfUnicodeFont(doc: any): Promise<void> {
  if ((doc as any).__pdpFontLoaded) return

  const fetchAsBase64 = async (url: string) => {
    const res = await fetch(url, { cache: 'force-cache' })
    if (!res.ok) throw new Error(`Failed to fetch font: ${res.status}`)
    const buf = await res.arrayBuffer()
    let binary = ''
    const bytes = new Uint8Array(buf)
    for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i])
    return btoa(binary)
  }

  try {
    if (!_pdfFontBase64Regular) {
      _pdfFontBase64Regular = await fetchAsBase64('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/fonts/DejaVuSans.ttf')
    }
    if (!_pdfFontBase64Bold) {
      _pdfFontBase64Bold = await fetchAsBase64('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/fonts/DejaVuSans-Bold.ttf')
    }

    doc.addFileToVFS(_pdfFontFileRegular, _pdfFontBase64Regular)
    doc.addFileToVFS(_pdfFontFileBold, _pdfFontBase64Bold)
    doc.addFont(_pdfFontFileRegular, _pdfFontName, 'normal')
    doc.addFont(_pdfFontFileBold, _pdfFontName, 'bold')
    doc.setFont(_pdfFontName, 'normal')
    ;(doc as any).__pdpFontLoaded = true
  } catch {
    try { doc.setFont('helvetica', 'normal') } catch {}
  }
}

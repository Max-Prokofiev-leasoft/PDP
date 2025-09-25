// Lightweight composable around jsPDF dynamic loader. Reuse utils for fonts and images.
import { ensurePdfUnicodeFont } from '@/utils/pdfFont'

let _jsPdfCtor: any = null

export async function getJsPdfCtor(): Promise<any> {
  if (typeof window !== 'undefined' && (window as any).jspdf?.jsPDF) {
    return (window as any).jspdf.jsPDF
  }
  if (_jsPdfCtor) return _jsPdfCtor
  await new Promise<void>((resolve, reject) => {
    const id = 'jspdf-cdn-script'
    if (document.getElementById(id)) {
      ;(document.getElementById(id) as HTMLScriptElement).addEventListener('load', () => resolve())
      return
    }
    const s = document.createElement('script')
    s.id = id
    s.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js'
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('Failed to load jsPDF'))
    document.head.appendChild(s)
  })
  _jsPdfCtor = (window as any).jspdf?.jsPDF
  if (!_jsPdfCtor) throw new Error('jsPDF not available after loading')
  return _jsPdfCtor
}

export async function ensurePdfFont(doc: any) {
  await ensurePdfUnicodeFont(doc)
}

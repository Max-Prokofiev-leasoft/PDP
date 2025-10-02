type NotifyType = 'success' | 'error' | 'info' | 'warning'

export interface NotifyPayload {
  id?: number
  title?: string
  message: string
  type?: NotifyType
  durationMs?: number
}

const EVENT_NAME = 'app:notify'

export function notify(payload: NotifyPayload) {
  const detail: Required<NotifyPayload> = {
    id: Date.now() + Math.floor(Math.random() * 1000),
    title: payload.title || '',
    message: payload.message,
    type: payload.type || 'info',
    durationMs: payload.durationMs ?? 3500,
  }
  window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail }))
}

export const notifySuccess = (message: string, title = '') => notify({ message, title, type: 'success' })
export const notifyError = (message: string, title = '') => notify({ message, title, type: 'error', durationMs: 5000 })
export const notifyInfo = (message: string, title = '') => notify({ message, title, type: 'info' })
export const notifyWarning = (message: string, title = '') => notify({ message, title, type: 'warning' })

export function onNotify(handler: (p: Required<NotifyPayload>) => void) {
  const listener = (e: Event) => handler((e as CustomEvent).detail)
  window.addEventListener(EVENT_NAME, listener as EventListener)
  return () => window.removeEventListener(EVENT_NAME, listener as EventListener)
}

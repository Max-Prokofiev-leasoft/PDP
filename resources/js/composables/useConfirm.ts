export function confirmDialog(message: string): Promise<boolean> {
  return new Promise(resolve => {
    const event = new CustomEvent('app:confirm:open', { detail: { message, resolve } })
    window.dispatchEvent(event)
  })
}

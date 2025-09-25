export interface ParsedCriterion { text: string; comment?: string; done?: boolean }

export function parseCriteriaItems(text?: string): ParsedCriterion[] {
  if (!text) return []
  try {
    const parsed = JSON.parse(text)
    if (Array.isArray(parsed)) {
      return parsed
        .map((x: any) =>
          typeof x === 'string'
            ? { text: x, done: false }
            : {
                text: String(x?.text ?? '').trim(),
                comment:
                  x?.comment != null && String(x.comment).trim() !== ''
                    ? String(x.comment)
                    : undefined,
                done: Boolean(x?.done),
              }
        )
        .filter((i: ParsedCriterion) => i.text)
    }
  } catch {
    // ignore
  }
  return text
    .split(/[\n,;]+/)
    .map(s => s.trim())
    .filter(Boolean)
    .map(t => ({ text: t }))
}

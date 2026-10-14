const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
const num = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 })

export const reais = (v) => (v === null || v === undefined ? '—' : moeda.format(v))
export const numero = (v) => (v === null || v === undefined ? '—' : num.format(v))
export const pct = (v) => (v === null || v === undefined ? '—' : num.format(v) + '%')
export const data = (s) => {
  if (!s) return '—'
  const [a, m, d] = s.slice(0, 10).split('-')
  return `${d}/${m}/${a}`
}
export const dataHora = (s) => s
  ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' }).format(new Date(s.replace(' ', 'T') + 'Z'))
  : '—'
/** "3746.07" (amostra da planilha) -> "3.746,07" quando for número */
export const amostra = (s) => (/^-?\d+\.\d+$/.test(s) ? num.format(Number(s)) : s)

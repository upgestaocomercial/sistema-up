// Cliente da API. Sessão em cookie http-only; o header X-Requested-With protege contra CSRF.
export class ErroApi extends Error {
  constructor(status, mensagem, detalhes) {
    super(mensagem)
    this.status = status
    this.detalhes = detalhes || {}
  }
}

let aoPerderSessao = () => {}
export function quandoPerderSessao(fn) { aoPerderSessao = fn }

export async function api(metodo, caminho, corpo) {
  let resp
  try {
    resp = await fetch('/api' + caminho, {
      method: metodo,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'sistema-up',
      },
      body: corpo === undefined ? undefined : JSON.stringify(corpo),
    })
  } catch {
    throw new ErroApi(0, 'Sem conexão com o servidor. Confira a internet e tente de novo.')
  }
  let dados = null
  try { dados = await resp.json() } catch { /* resposta sem JSON */ }
  if (!resp.ok) {
    if (resp.status === 401 && caminho !== '/auth/login') aoPerderSessao()
    throw new ErroApi(resp.status, dados?.erro || 'Não foi possível concluir.', dados?.detalhes)
  }
  return dados
}

export const get = (c) => api('GET', c)
export const post = (c, b = {}) => api('POST', c, b)
export const put = (c, b = {}) => api('PUT', c, b)

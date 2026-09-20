/**
 * Zak WhatsApp Web spike worker (whatsapp-web.js).
 *
 * DEV / hackathon ONLY. Unofficial browser automation of web.whatsapp.com.
 * ToS / ban risk — do not run in production.
 *
 * Flow: QR login → inbound private text → POST Laravel spike inbound → send reply.
 */

const { Client, LocalAuth } = require('whatsapp-web.js')
const qrcode = require('qrcode-terminal')
const { readFileSync, existsSync } = require('node:fs')
const { join } = require('node:path')

const root = join(__dirname, '..')

function loadEnv() {
  const envPath = join(root, '.env')
  if (!existsSync(envPath)) return
  for (const line of readFileSync(envPath, 'utf8').split('\n')) {
    const t = line.trim()
    if (!t || t.startsWith('#')) continue
    const i = t.indexOf('=')
    if (i === -1) continue
    const k = t.slice(0, i).trim()
    const v = t.slice(i + 1).trim()
    if (!process.env[k]) process.env[k] = v
  }
}

loadEnv()

const LARAVEL_BASE_URL = (process.env.LARAVEL_BASE_URL || 'http://localhost').replace(/\/$/, '')
const SPIKE_SECRET = process.env.SPIKE_SECRET || ''
const PRIVATE_CHATS_ONLY = (process.env.PRIVATE_CHATS_ONLY || 'true') === 'true'
const AUTH_DIR = join(root, '.wwebjs_auth')
const CHROME_PATH =
  process.env.CHROME_PATH ||
  (process.platform === 'win32'
    ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    : process.platform === 'darwin'
      ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
      : '')

if (!SPIKE_SECRET) {
  console.error('[spike] SPIKE_SECRET is required (must match WHATSAPP_WEB_SPIKE_SECRET in backend/.env)')
  process.exit(1)
}

async function callLaravelInbound({ from, text, message_id }) {
  const res = await fetch(`${LARAVEL_BASE_URL}/api/v1/internal/whatsapp-web-spike/inbound`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Spike-Secret': SPIKE_SECRET,
    },
    body: JSON.stringify({ from, text, message_id }),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(`Laravel inbound ${res.status}: ${JSON.stringify(body)}`)
  }
  return body?.data?.reply ?? null
}

console.log('═══════════════════════════════════════════════════')
console.log(' Zak WhatsApp Web SPIKE — NOT FOR PRODUCTION')
console.log(' whatsapp-web.js (Chromium + web.whatsapp.com)')
console.log(' Unofficial. Ban / ToS risk.')
console.log('═══════════════════════════════════════════════════')
console.log(`[spike] Laravel → ${LARAVEL_BASE_URL}`)
console.log(`[spike] Session → ${AUTH_DIR}`)
if (CHROME_PATH) console.log(`[spike] Chrome → ${CHROME_PATH}`)

const puppeteerOpts = {
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
}
if (CHROME_PATH && existsSync(CHROME_PATH)) {
  puppeteerOpts.executablePath = CHROME_PATH
}

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: AUTH_DIR }),
  puppeteer: puppeteerOpts,
})

client.on('qr', (qr) => {
  console.log('[spike] Scan this QR with WhatsApp → Linked Devices:')
  qrcode.generate(qr, { small: true })
})

client.on('authenticated', () => {
  console.log('[spike] Authenticated — session saved.')
})

client.on('ready', () => {
  console.log('[spike] Connected. Waiting for private messages…')
})

client.on('auth_failure', (msg) => {
  console.error('[spike] Auth failure:', msg)
  console.error('[spike] Delete .wwebjs_auth/ and run again for a new QR.')
})

client.on('disconnected', (reason) => {
  console.log(`[spike] Disconnected (${reason}). Re-initializing…`)
  client.initialize().catch((err) => {
    console.error('[spike] re-init failed:', err.message || err)
  })
})

client.on('message', async (msg) => {
  try {
    if (msg.fromMe) return
    if (msg.isStatus) return
    if (PRIVATE_CHATS_ONLY && msg.from.endsWith('@g.us')) return

    const text = (msg.body || '').trim()
    if (!text) return

    const from = msg.from.replace(/@.*/, '')
    console.log(`[spike] inbound from=${from}: ${text.slice(0, 80)}`)

    const reply = await callLaravelInbound({
      from,
      text,
      message_id: msg.id?._serialized || msg.id?.id || null,
    })

    if (reply) {
      await msg.reply(reply)
      console.log(`[spike] replied (${reply.length} chars)`)
    }
  } catch (err) {
    console.error('[spike] message handler error:', err.message || err)
  }
})

client.initialize().catch((err) => {
  console.error('[spike] fatal:', err)
  process.exit(1)
})

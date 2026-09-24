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
const http = require('node:http')

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
// Temporary: verbose quote/settle tracing while debugging swipe-reply.
const SPIKE_DEBUG = (process.env.SPIKE_DEBUG || 'false') === 'true'
// Headless often connects but never emits inbound DMs on Windows; default visible Chrome.
const HEADLESS = (process.env.HEADLESS || 'false') === 'true'
const OUTBOUND_PORT = Number(process.env.OUTBOUND_PORT || 3101)
// Seed bot identity early so first @LID / @username mention is detected (don't wait for fromMe).
const ENV_BOT_LID = String(process.env.BOT_LID || process.env.WHATSAPP_WEB_SPIKE_BOT_LID || '').replace(/\D+/g, '')
const ENV_BOT_NUMBER = String(process.env.BOT_NUMBER || process.env.WHATSAPP_WEB_SPIKE_BOT_NUMBER || '').replace(/\D+/g, '')
const ENV_BOT_USERNAME = String(process.env.BOT_USERNAME || process.env.WHATSAPP_WEB_SPIKE_BOT_USERNAME || '')
  .trim()
  .replace(/^@/, '')
  .toLowerCase()
const ENV_BOT_ALIASES = String(process.env.BOT_ALIASES || process.env.WHATSAPP_WEB_SPIKE_BOT_ALIASES || 'zak_bot')
  .split(',')
  .map((s) => s.trim().toLowerCase())
  .filter(Boolean)
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

// Same wording as Telegram spike when Laravel / the bot snags (no em dashes).
const TRANSIENT_ERROR_REPLY =
  "Thanks — I've got your message 🙂\n\n"
  + "I'll reply as soon as I can. No need to send it again."

/**
 * @returns {Promise<{ queued: boolean, reply: string|null }>}
 */
async function callLaravelInbound(payload) {
  const started = Date.now()
  console.log('[spike] → Laravel inbound…')
  try {
    const res = await fetch(`${LARAVEL_BASE_URL}/api/v1/internal/whatsapp-web-spike/inbound`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Spike-Secret': SPIKE_SECRET,
      },
      body: JSON.stringify(payload),
      // Sync mode: AI can take a while. Async/queued mode returns ~1s with accepted.
      signal: AbortSignal.timeout(120_000),
    })
    const body = await res.json().catch(() => ({}))
    const ms = Date.now() - started
    if (!res.ok && res.status !== 202) {
      console.error(`[spike] Laravel inbound ${res.status} after ${ms}ms:`, JSON.stringify(body).slice(0, 500))
      // Same wording as Telegram spike when Laravel returns an error.
      return { queued: false, reply: body?.data?.reply || TRANSIENT_ERROR_REPLY }
    }
    const data = body?.data || {}
    if (data.queued === true) {
      // Keep composing on the client — worker will deliver via /send later.
      console.log(`[spike] ← Laravel ${ms}ms queued=yes (keep typing until outbound)`)
      return { queued: true, reply: null }
    }
    const reply = data.reply ?? null
    const len = reply ? String(reply).length : 0
    console.log(`[spike] ← Laravel ${ms}ms reply_chars=${len}`)
    return { queued: false, reply }
  } catch (err) {
    console.error(`[spike] Laravel request failed after ${Date.now() - started}ms:`, err.message || err)
    return { queued: false, reply: TRANSIENT_ERROR_REPLY }
  }
}

console.log('═══════════════════════════════════════════════════')
console.log(' Zak WhatsApp Web SPIKE — NOT FOR PRODUCTION')
console.log(' whatsapp-web.js (Chromium + web.whatsapp.com)')
console.log(' Unofficial. Ban / ToS risk.')
console.log('═══════════════════════════════════════════════════')
console.log(`[spike] Laravel → ${LARAVEL_BASE_URL}`)
console.log(`[spike] Session → ${AUTH_DIR}`)
console.log(`[spike] Headless → ${HEADLESS} (set HEADLESS=true to hide Chrome)`)
if (CHROME_PATH) console.log(`[spike] Chrome → ${CHROME_PATH}`)

const puppeteerOpts = {
  headless: HEADLESS,
  args: [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-extensions',
    '--disable-component-update',
    '--no-first-run',
    '--no-default-browser-check',
  ],
}
if (CHROME_PATH && existsSync(CHROME_PATH)) {
  puppeteerOpts.executablePath = CHROME_PATH
}

/**
 * Stale Chromium SingletonLock left after a crash → next start hangs at ready=false.
 * Safe to delete locks when no chrome is holding the profile.
 */
function clearStaleSessionLocks() {
  const sessionRoot = join(AUTH_DIR, 'session')
  if (!existsSync(sessionRoot)) return
  const lockNames = ['SingletonLock', 'SingletonCookie', 'SingletonSocket', 'lockfile']
  const walk = (dir, depth = 0) => {
    if (depth > 3 || !existsSync(dir)) return
    let entries = []
    try {
      entries = require('node:fs').readdirSync(dir, { withFileTypes: true })
    } catch {
      return
    }
    for (const ent of entries) {
      const full = join(dir, ent.name)
      if (ent.isDirectory()) {
        walk(full, depth + 1)
        continue
      }
      if (lockNames.includes(ent.name)) {
        try {
          require('node:fs').unlinkSync(full)
          console.log(`[spike] Removed stale Chrome lock → ${full}`)
        } catch (err) {
          console.warn(`[spike] Could not remove lock ${full}:`, err.message || err)
        }
      }
    }
  }
  walk(sessionRoot)
}

clearStaleSessionLocks()

// Only pin WA Web HTML when WEB_VERSION is set explicitly.
// A stale default pin is the #1 cause of ready=false forever on Windows.
const WEB_VERSION = String(process.env.WEB_VERSION || '').trim()
const WEB_CACHE_DIR = join(root, '.wwebjs_cache')
const webVersionOpts = (() => {
  const remote = String(process.env.WEB_VERSION_HTML || '').trim()
  if (remote) {
    console.log('[spike] WA Web cache → remote URL from WEB_VERSION_HTML')
    return { webVersionCache: { type: 'remote', remotePath: remote } }
  }
  if (!WEB_VERSION) {
    console.log('[spike] WA Web cache → default (no WEB_VERSION pin — recommended)')
    return {}
  }
  const localHtml = join(WEB_CACHE_DIR, `${WEB_VERSION}.html`)
  if (existsSync(localHtml)) {
    console.log(`[spike] WA Web cache → local pin ${WEB_VERSION}`)
    return {
      webVersion: WEB_VERSION,
      webVersionCache: { type: 'local', path: WEB_CACHE_DIR },
    }
  }
  console.warn(`[spike] WEB_VERSION=${WEB_VERSION} set but ${localHtml} missing — using default`)
  return {}
})()

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: AUTH_DIR }),
  puppeteer: puppeteerOpts,
  ...webVersionOpts,
})

const readyAt = { t: 0 }
setTimeout(() => {
  if (readyAt.t) return
  console.error('[spike] Still not ready after 90s (health reports ready=false).')
  console.error('[spike] Usually: leftover spike Chrome lock, or a bad WEB_VERSION pin.')
  console.error('[spike] Fix:')
  console.error('  1) Ctrl+C')
  console.error('  2) npm run kill-chrome   (only closes spike Chromium, not your normal Chrome)')
  console.error('  3) npm start')
  console.error('  If still stuck: remove WEB_VERSION from .env, or npm run fresh (re-scan QR)')
}, 90_000)

client.on('qr', (qr) => {
  console.log('[spike] Scan this QR with WhatsApp → Linked Devices:')
  qrcode.generate(qr, { small: true })
})

client.on('loading_screen', (percent, message) => {
  console.log(`[spike] Loading WhatsApp Web… ${percent}% ${message || ''}`.trim())
})

client.on('authenticated', () => {
  console.log('[spike] Authenticated — session saved.')
})

client.on('change_state', (state) => {
  console.log(`[spike] State → ${state}`)
})

let linkedBotNumber = ENV_BOT_NUMBER
let linkedBotLid = ENV_BOT_LID // WhatsApp often @-mentions the LID, not the phone

function idUserPart(value) {
  return String(value || '')
    .replace(/@.*/, '')
    .trim()
}

function rememberBotIdentityFromId(rawId) {
  const s = String(rawId?._serialized || rawId || '')
  const user = idUserPart(s)
  if (!user) return
  if (s.includes('@lid') && user !== linkedBotLid) {
    linkedBotLid = user
    console.log(`[spike] Bot LID for group mentions → ${linkedBotLid}`)
  }
  if (s.includes('@c.us') && user && user !== linkedBotNumber) {
    linkedBotNumber = user
  }
}

function collectMentionedUsers(msg) {
  const out = new Set()
  const raw = msg.mentionedIds || msg._data?.mentionedJidList || []
  for (const item of raw) {
    const user = idUserPart(item?._serialized || item)
    if (user) out.add(user)
  }
  // Visible @digits in body (WA often renders LID this way)
  const text = normalizeMentionText(msg.body || '')
  for (const m of text.matchAll(/@(\d{8,})\b/g)) {
    out.add(m[1])
  }
  return out
}

/** Strip WA directionality / zero-width junk so "@zak_bot" matches cleanly. */
function normalizeMentionText(text) {
  return String(text || '')
    .replace(/[\u200e\u200f\u200b\u200c\u200d\ufeff]/g, '')
    .trim()
}

function isBotMentioned(msg) {
  const mentioned = collectMentionedUsers(msg)
  if (linkedBotLid && mentioned.has(linkedBotLid)) return true
  if (linkedBotNumber && mentioned.has(linkedBotNumber)) return true
  for (const id of mentioned) {
    if (isBotUserPart(id)) return true
  }

  const text = normalizeMentionText(msg.body || '')
  const lower = text.toLowerCase()

  if (linkedBotLid && lower.includes('@' + linkedBotLid)) return true
  if (linkedBotNumber && lower.includes('@' + linkedBotNumber)) return true

  // Real @username (e.g. @unipod_bot) — require the @
  if (ENV_BOT_USERNAME) {
    const re = new RegExp(`(^|[^\\w])@${ENV_BOT_USERNAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i')
    if (re.test(text)) return true
  }

  // Aliases: @zak_bot / zak_bot / @zak (never bare substring inside other words)
  for (const alias of ENV_BOT_ALIASES) {
    if (!alias) continue
    const bare = alias.replace(/^@/, '')
    if (!bare) continue
    const escaped = bare.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const re = new RegExp(`(^|[^\\w])@?${escaped}\\b`, 'i')
    if (re.test(text)) return true
  }

  // Common short forms people type even when the WA username is different.
  if (/(^|[^\w])@?zak(?:[_\s-]?bot)?\b/i.test(text)) return true

  return false
}

/** True when the body is only a bot ping (@zak / @lid / username), no real ask. */
function isBareBotPingText(text) {
  const raw = normalizeMentionText(text)
  if (!raw) return false
  let body = raw
  if (ENV_BOT_USERNAME) {
    body = body.replace(
      new RegExp(`(^|\\s)@?${ENV_BOT_USERNAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'ig'),
      ' ',
    )
  }
  for (const alias of ENV_BOT_ALIASES) {
    const bare = String(alias || '').replace(/^@/, '')
    if (!bare) continue
    body = body.replace(
      new RegExp(`(^|\\s)@?${bare.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'ig'),
      ' ',
    )
  }
  body = body.replace(/(^|\s)@?zak(?:[_\s-]?bot)?\b/gi, ' ')
  if (linkedBotLid) {
    body = body.replace(new RegExp(`(^|\\s)@?${linkedBotLid}\\b`, 'g'), ' ')
  }
  if (linkedBotNumber) {
    body = body.replace(new RegExp(`(^|\\s)@?${linkedBotNumber}\\b`, 'g'), ' ')
  }
  body = body.replace(/@\d{8,}\b/g, ' ').replace(/\s+/g, ' ').trim()
  return body === ''
}

/** Prefer chat.isGroup — @g.us alone misses some WA community / linked chats. */
async function detectIsGroup(msg, fromRaw) {
  if (String(fromRaw || '').endsWith('@g.us')) return true
  if (msg.author) return true
  try {
    const chat = await msg.getChat()
    if (chat?.isGroup) return true
  } catch {
    /* ignore */
  }
  return false
}

function contactDisplayName(contact, fromName) {
  const candidates = [
    contact?.name, // address-book full name first
    contact?.verifiedName,
    contact?.pushname,
    contact?.shortName,
    fromName,
  ]
  let best = null
  for (const name of candidates) {
    const cleaned = String(name || '').trim().replace(/^~/, '')
    if (!cleaned || /^\d{8,}$/.test(cleaned)) continue
    if (!best || cleaned.length > best.length) best = cleaned
  }
  return best
}

/**
 * Resolve non-bot @mentions to { id, name, phone } so Laravel can answer
 * "Who's @Joy?" using the display name, not a LID digit string.
 */
async function resolveMentionedPeople(msg) {
  const people = []
  const seen = new Set()

  const pushPerson = (id, name, phone) => {
    const user = idUserPart(id)
    if (!user || seen.has(user)) return
    if (linkedBotLid && user === linkedBotLid) return
    if (linkedBotNumber && user === linkedBotNumber) return
    seen.add(user)
    const cleanName = String(name || '').trim().replace(/^~/, '')
    people.push({
      id: user,
      name: cleanName && !/^\d{8,}$/.test(cleanName) ? cleanName : null,
      phone: phone || null,
    })
  }

  try {
    const mentioned = await msg.getMentions()
    for (const contact of mentioned || []) {
      if (!contact || isBotContact(contact)) continue
      const id = contact.id?._serialized || contact.id?.user
      pushPerson(
        id,
        contactDisplayName(contact, null),
        contactPhoneDigits(contact, id),
      )
    }
  } catch {
    /* ignore */
  }

  // Fallback: JIDs from mentionedIds without a resolved Contact yet
  for (const item of msg.mentionedIds || msg._data?.mentionedJidList || []) {
    const serialized = item?._serialized || item
    const user = idUserPart(serialized)
    if (!user || seen.has(user)) continue
    if (linkedBotLid && user === linkedBotLid) continue
    if (linkedBotNumber && user === linkedBotNumber) continue
    try {
      const contact = await client.getContactById(
        typeof serialized === 'string' && serialized.includes('@')
          ? serialized
          : `${user}@lid`,
      )
      if (contact && !isBotContact(contact)) {
        pushPerson(
          contact.id?._serialized || user,
          contactDisplayName(contact, null),
          contactPhoneDigits(contact, serialized),
        )
        continue
      }
    } catch {
      /* ignore */
    }
    pushPerson(user, null, null)
  }

  return people
}

/** Best-effort phone digits for admin matching (never treat LID as phone). */
function contactPhoneDigits(contact, senderRaw = '') {
  const serialized = String(contact?.id?._serialized || '')
  const server = String(contact?.id?.server || '')
  const sender = String(senderRaw || '')
  const senderUser = idUserPart(sender)
  // LID-only contacts: contact.number is often the LID echoed back — not MSISDN.
  if (serialized.endsWith('@lid') || server === 'lid') {
    return null
  }
  // Inbound author is @lid: WA sometimes fabricates a @c.us id with the same digits.
  if (sender.includes('@lid') || (senderUser && senderUser.length >= 14)) {
    return null
  }
  const fromContact = String(contact?.number || '').replace(/\D+/g, '')
  const user = idUserPart(serialized || contact?.id?.user)
  if (!fromContact) {
    return null
  }
  // WA often echoes the LID into contact.number even on odd id shapes.
  if (user && fromContact === user) {
    return null
  }
  if (senderUser && fromContact === senderUser) {
    return null
  }
  // Real MSISDNs with country code are typically 10–13 digits for our markets;
  // many WhatsApp LIDs are 14–15+.
  if (!serialized.endsWith('@c.us') && server !== 'c.us') {
    return null
  }
  if (fromContact.length >= 10 && fromContact.length <= 13) {
    return fromContact
  }
  return null
}

/**
 * MSISDN for Laravel admin checks when DMs arrive as @lid (no contact.number).
 * Reuses WA Web LID↔phone APIs before falling back to null.
 */
async function resolveSenderPhoneDigits(contact, senderRaw = '') {
  const direct = contactPhoneDigits(contact, senderRaw)
  if (direct) {
    return direct
  }

  const sender = String(senderRaw || '')
  const candidates = []
  if (sender.includes('@')) {
    candidates.push(sender)
  }
  if (contact?.id?._serialized) {
    candidates.push(String(contact.id._serialized))
  }

  let pn = null
  if (client?.getContactLidAndPhone && candidates.length) {
    try {
      const rows = await client.getContactLidAndPhone(candidates)
      for (const row of rows || []) {
        if (row?.pn) {
          pn = String(row.pn)
        }
      }
    } catch (err) {
      debugLog('resolveSenderPhoneDigits getContactLidAndPhone failed', formatErr(err))
    }
  }

  if (!pn && client?.pupPage && candidates[0]) {
    try {
      const looked = await client.pupPage.evaluate(async (userId) => {
        try {
          const { phone } = await window.WWebJS.enforceLidAndPnRetrieval(userId)
          return phone?._serialized || null
        } catch {
          return null
        }
      }, candidates[0])
      if (looked) {
        pn = looked
      }
    } catch (_) {
      /* ignore */
    }
  }

  if (!pn) {
    return null
  }

  const userDigits = idUserPart(pn).replace(/\D+/g, '')
  const serializedDigits = String(pn).replace(/\D+/g, '')
  const candidate = userDigits || serializedDigits
  if (candidate.length >= 10 && candidate.length <= 13) {
    return candidate
  }

  return null
}

/**
 * Remove plain-text "@Display Name," prefixes (not real WA mentions).
 * Real mentions are @<digits> only.
 */
function stripFakeAtDisplayNames(text) {
  let out = String(text || '').trim()
  // Leading "@Abdulsamad Balogun, …" / "@~Nedi, …"
  out = out.replace(/^@~?(?!\d)[\p{L}\p{N}._ -]{1,60},\s*/u, '')
  return out.trim()
}

/**
 * Collect @digit tags from body text → JIDs for options.mentions (green paint).
 * Phone-length (10–13) → @c.us; longer IDs → @lid (group Linked IDs).
 *
 * @returns {string[]}
 */
function mentionJidsFromText(text) {
  const jids = []
  const re = /@(\d{6,})\b/g
  let m
  while ((m = re.exec(String(text || ''))) !== null) {
    const digits = m[1]
    const jid = digits.length >= 14 ? `${digits}@lid` : `${digits}@c.us`
    if (!jids.includes(jid)) jids.push(jid)
  }
  return jids
}

/** @deprecated use mentionJidsFromText — kept for private mid-body phone tags */
function phoneMentionJidsFromText(text) {
  return mentionJidsFromText(text).filter((jid) => jid.endsWith('@c.us'))
}

/**
 * Official wwebjs mention format: @<phone digits> in the body PLUS that JID in
 * options.mentions. WhatsApp then paints the green name.
 *
 * Group authors often arrive as @lid. Mentions with raw LID digits look like a
 * "strange id" and may not highlight — resolve LID → @c.us phone when possible.
 */
function mentionAtUserId(contact, senderRaw) {
  const phone = contactPhoneDigits(contact, senderRaw)
  if (phone) return `@${phone}`

  const serialized = String(contact?.id?._serialized || '')
  if (serialized.endsWith('@c.us')) {
    const user = idUserPart(serialized)
    if (user && /^\d{6,15}$/.test(user)) return `@${user}`
  }

  const sender = String(senderRaw || '')
  if (sender.endsWith('@c.us')) {
    const user = idUserPart(sender)
    if (user && /^\d{6,15}$/.test(user)) return `@${user}`
  }

  // Last resort: LID digits (may not render as a green name).
  const lidUser =
    (sender.includes('@lid') && idUserPart(sender))
    || (serialized.endsWith('@lid') && idUserPart(serialized))
    || null
  if (lidUser && /^\d{6,}$/.test(lidUser)) return `@${lidUser}`
  return null
}

function mentionSerializedId(contact, senderRaw) {
  const phone = contactPhoneDigits(contact, senderRaw)
  if (phone) return `${phone}@c.us`

  const serialized = String(contact?.id?._serialized || '')
  if (serialized.includes('@')) return serialized

  const sender = String(senderRaw || '')
  if (sender.includes('@')) return sender

  const user = idUserPart(sender)
  return user ? `${user}@lid` : null
}

/**
 * Resolve asker mention for a green/clickable WhatsApp @tag.
 * Groups are often LID-addressed: mentioning only @c.us phone fails to highlight.
 * Prefer the inbound LID for the body tag + mentions list; also include phone when known.
 * When the mention is attached correctly, WhatsApp paints the contact name in green
 * (not the raw digits) — same as before.
 */
async function resolveAskerMention(contact, senderRaw) {
  const sender = String(senderRaw || '')
  const candidates = []
  if (sender.includes('@')) candidates.push(sender)
  if (contact?.id?._serialized) candidates.push(String(contact.id._serialized))

  let pn = null
  let lid = null
  if (client?.getContactLidAndPhone && candidates.length) {
    try {
      const rows = await client.getContactLidAndPhone(candidates)
      for (const row of rows || []) {
        if (row?.pn) pn = String(row.pn)
        if (row?.lid) lid = String(row.lid)
      }
    } catch (err) {
      debugLog('getContactLidAndPhone failed', formatErr(err))
    }
  }

  if ((!pn || !lid) && client?.pupPage && candidates[0]) {
    try {
      const looked = await client.pupPage.evaluate(async (userId) => {
        try {
          const { phone, lid } = await window.WWebJS.enforceLidAndPnRetrieval(userId)
          return {
            pn: phone?._serialized || null,
            lid: lid?._serialized || null,
          }
        } catch (e) {
          return { pn: null, lid: null, err: String(e?.message || e) }
        }
      }, candidates[0])
      if (looked?.pn && !pn) pn = looked.pn
      if (looked?.lid && !lid) lid = looked.lid
    } catch (_) {
      /* ignore */
    }
  }

  // Prefer LID for group author mentions (LID addressing). Phone alone often
  // prints as plain @234… with no green highlight.
  if (!lid && sender.includes('@lid')) lid = sender
  if (!lid && String(contact?.id?._serialized || '').endsWith('@lid')) {
    lid = String(contact.id._serialized)
  }
  if (!pn) {
    const phone = contactPhoneDigits(contact, senderRaw)
    if (phone) pn = `${phone}@c.us`
  }

  const mentionJids = []
  if (lid) mentionJids.push(lid)
  if (pn && !mentionJids.includes(pn)) mentionJids.push(pn)

  // Visible @tag: prefer phone digits (familiar). Mentions list still includes LID so
  // LID-addressed groups can attach a real green/clickable chip. WhatsApp UI then
  // paints the contact name (e.g. Abdulsamad) when the mention binds.
  const tagJid = pn || lid || mentionSerializedId(contact, senderRaw)
  const primary = lid || pn || tagJid
  const user = idUserPart(tagJid)
  if (!primary || !user) {
    return { tag: null, jid: null, mentionJids: [] }
  }

  debugLog('mention resolved', { from: sender || candidates[0], lid, pn, tag: `@${user}` })
  return {
    tag: `@${user}`,
    jid: primary,
    mentionJids,
  }
}

/**
 * Address the asker with a real @id mention tag (WA renders the green name).
 * Language-neutral: "@id, …" — never inject English "Hi" (reply body may be any language).
 */
function blendMentionTag(mentionTag, body) {
  let text = stripFakeAtDisplayNames(body)
  const tag = String(mentionTag || '').trim()
  if (!tag) return text
  if (!text) return `${tag},`

  // Normalize inverted "@digits, Hi," from older sends / plain-name prefixes.
  text = text.replace(/^@\+?\d{6,}\s*,\s*Hi\b[,]?/i, '')
  text = text.replace(/^@\d{6,}(?!\d)\s*,\s*Hi\b[,]?/i, '')
  text = stripFakeAtDisplayNames(text)

  if (text.includes(tag)) {
    // Already addressed — keep order, drop duplicate leading tags.
    return text.replace(/^@\d{6,}(?!\d)\s*,\s*/u, '').trim()
  }

  // Drop a previous LID/phone @digits tag if we now have a better tag.
  let cleaned = text.replace(/^@\+?\d{6,}\b[,\s]*/u, '').trim()
  cleaned = cleaned.replace(/^@\d{6,}(?!\d)[,\s]*/u, '').trim()

  // Strip legacy English-only channel opener (not a greeting catalog — just remove old inject).
  cleaned = cleaned.replace(/^(hey|hi|hello|howdy|yo)\b[ \t]*,?[ \t]*/i, '').trim()
  // Drop fake "@~Joy" / "@Abdulsamad Balogun," openers (not real WA digit mentions).
  cleaned = cleaned.replace(/^@~?(?!\d)[\p{L}\p{N}._❤️💕🙏\s-]{1,80},?[ \t]*/u, '').trim()
  // Drop a leftover plain display name after a stripped Hi (e.g. "Abdulsamad,\n\n…").
  cleaned = cleaned.replace(/^[^@\n,]{1,60},\s*(?=\S)/, '').trim()

  if (!cleaned) return `${tag},`
  const gap = cleaned.startsWith('\n') ? '' : '\n\n'
  return `${tag},${gap}${cleaned}`.replace(/\n{3,}/g, '\n\n')
}

function isBotContact(contact) {
  const user = idUserPart(contact?.id?._serialized || contact?.id?.user)
  if (!user) return false
  if (linkedBotLid && user === linkedBotLid) return true
  if (linkedBotNumber && user === linkedBotNumber) return true
  return false
}

function isBotUserPart(userPart) {
  const user = idUserPart(userPart)
  if (!user) return false
  return (linkedBotLid && user === linkedBotLid) || (linkedBotNumber && user === linkedBotNumber)
}

/** Recent outbound message ids so reply-to-bot works even when fromMe flags are flaky. */
const recentBotMsgIds = new Set()

function rememberBotOutboundId(messageOrId) {
  const id =
    typeof messageOrId === 'string'
      ? messageOrId
      : messageOrId?.id?._serialized || messageOrId?.id?.id || null
  if (!id) return
  recentBotMsgIds.add(String(id))
  if (recentBotMsgIds.size > 200) {
    const first = recentBotMsgIds.values().next().value
    recentBotMsgIds.delete(first)
  }
}

async function resolveQuoteContext(msg) {
  const result = {
    reply_to_bot: false,
    quoted_text: null,
    quoted_from: null,
    quoted_message_id: null,
    quoted_msg: null,
    quoted_voice_kind: null,
    quoted_image_kind: null,
  }

  const raw = msg._data || {}
  const hasQuote = Boolean(
    msg.hasQuotedMsg
    || raw.quotedMsg
    || raw.quotedStanzaID
    || raw.quotedMsgId
    || raw.quotedParticipant,
  )
  if (!hasQuote) {
    return result
  }

  try {
    let quoted = null
    try {
      quoted = await msg.getQuotedMessage()
    } catch {
      quoted = null
    }

    if (quoted) {
      result.quoted_msg = quoted
      result.quoted_voice_kind = voiceMediaKind(quoted)
      result.quoted_image_kind = imageMediaKind(quoted)
      // Some WA builds omit type on the wrapper — check raw payload too.
      if (!result.quoted_voice_kind) {
        const rawType = String(quoted._data?.type || quoted.type || '').toLowerCase()
        if (rawType === 'ptt' || rawType === 'voice') result.quoted_voice_kind = 'voice'
        else if (rawType === 'audio') result.quoted_voice_kind = 'audio'
      }
      if (!result.quoted_image_kind) {
        const rawType = String(quoted._data?.type || quoted.type || '').toLowerCase()
        if (rawType === 'image') result.quoted_image_kind = 'image'
      }
      const body = String(quoted.body || quoted.caption || '').trim()
      // Voice notes / photos often have empty body; keep a hint for Laravel context.
      if (body) {
        result.quoted_text = body.slice(0, 1500)
      } else if (result.quoted_voice_kind) {
        result.quoted_text = '[voice note]'
      } else if (result.quoted_image_kind) {
        result.quoted_text = '[photo]'
      }
      result.quoted_from = idUserPart(quoted.author || quoted.from) || null
      result.quoted_message_id = quoted.id?._serialized || quoted.id?.id || null

      if (quoted.fromMe) {
        result.reply_to_bot = true
        rememberBotIdentityFromId(quoted.from)
        rememberBotIdentityFromId(quoted.id?.participant)
        rememberBotOutboundId(result.quoted_message_id)
        return result
      }

      const qFrom = idUserPart(quoted.from)
      const qAuthor = idUserPart(quoted.author)
      if (isBotUserPart(qFrom) || isBotUserPart(qAuthor)) {
        result.reply_to_bot = true
      }
    } else {
      // Raw fallback when getQuotedMessage fails on some WA Web builds.
      const rawQuoted = raw.quotedMsg || {}
      const body = String(rawQuoted.body || rawQuoted.caption || '').trim()
      const rawType = String(rawQuoted.type || '').toLowerCase()
      if (rawType === 'ptt' || rawType === 'voice') {
        result.quoted_voice_kind = 'voice'
      } else if (rawType === 'audio') {
        result.quoted_voice_kind = 'audio'
      } else if (rawType === 'image') {
        result.quoted_image_kind = 'image'
      }
      if (body) {
        result.quoted_text = body.slice(0, 1500)
      } else if (result.quoted_voice_kind) {
        result.quoted_text = '[voice note]'
      } else if (result.quoted_image_kind) {
        result.quoted_text = '[photo]'
      }
      result.quoted_from = idUserPart(
        raw.quotedParticipant || rawQuoted.participant || rawQuoted.author || rawQuoted.from,
      ) || null
      result.quoted_message_id =
        raw.quotedStanzaID
        || rawQuoted.id?._serialized
        || rawQuoted.id?.id
        || null

      if (
        rawQuoted.fromMe === true
        || isBotUserPart(result.quoted_from)
        || isBotUserPart(raw.quotedParticipant)
      ) {
        result.reply_to_bot = true
      }
    }

    if (
      result.quoted_message_id
      && recentBotMsgIds.has(String(result.quoted_message_id))
    ) {
      result.reply_to_bot = true
    }
  } catch (err) {
    const detail = err && (err.message || err.toString?.() || String(err))
    if (detail && detail !== 'r') {
      console.log('[spike] quoted msg read failed:', detail)
    }
  }

  return result
}

client.on('ready', async () => {
  readyAt.t = Date.now()
  const wid = client.info?.wid?._serialized || client.info?.wid?.user || '?'
  const widUser = String(client.info?.wid?.user || String(wid).replace(/@.*/, '') || '')
  if (widUser) linkedBotNumber = widUser
  // Some wweb builds expose lid on info
  const lidRaw = client.info?.lid?._serialized || client.info?.lid?.user || ''
  if (lidRaw) {
    linkedBotLid = idUserPart(lidRaw)
  }
  console.log(`[spike] Connected as ${wid}. Waiting for messages…`)
  if (linkedBotNumber) {
    console.log(`[spike] Bot number for group mentions → ${linkedBotNumber}`)
  }
  if (linkedBotLid) {
    console.log(`[spike] Bot LID for group mentions → ${linkedBotLid}`)
  } else {
    console.log('[spike] Bot LID not known yet — set BOT_LID in spike .env or wait for first outbound.')
  }
  if (ENV_BOT_USERNAME) {
    console.log(`[spike] Bot username mention → @${ENV_BOT_USERNAME}`)
  }
  console.log('[spike] Tip: from a *different* phone, DM this account (the one that scanned QR).')
  if (PRIVATE_CHATS_ONLY) {
    console.log('[spike] PRIVATE_CHATS_ONLY=true — groups skipped in sidecar (set false for group listen).')
  } else {
    console.log('[spike] Listen: @mention, swipe-reply to bot, or /commands only (no bare chatter).')
  }
})

client.on('auth_failure', (msg) => {
  console.error('[spike] Auth failure:', msg)
  console.error('[spike] Delete .wwebjs_auth/ and run again for a new QR.')
})

let reinitInFlight = false

client.on('disconnected', async (reason) => {
  const why = String(reason || 'unknown')
  console.log(`[spike] Disconnected (${why}).`)
  readyAt.t = 0

  // LOGOUT = phone unlinked this session. Do not auto re-init (Chrome lock / QR loop).
  if (why.toUpperCase() === 'LOGOUT') {
    console.error('[spike] Session was logged out (Linked Devices / phone).')
    console.error('[spike] Run: npm run fresh   then scan QR once.')
    try {
      await client.destroy()
    } catch (_) {
      /* ignore */
    }
    process.exit(1)
  }

  if (reinitInFlight) {
    console.log('[spike] Re-init already in progress; skipping.')
    return
  }
  reinitInFlight = true
  console.log('[spike] Waiting a few seconds, then re-initializing…')
  setTimeout(async () => {
    try {
      try {
        await client.destroy()
      } catch (_) {
        /* ignore */
      }
      clearStaleSessionLocks()
      await sleep(1500)
      await client.initialize()
    } catch (err) {
      console.error('[spike] re-init failed:', err.message || err)
      console.error('[spike] Run: npm run kill-chrome && npm start')
    } finally {
      reinitInFlight = false
    }
  }, 4000)
})

const seenIds = new Set()

function isRecognizedCommand(text) {
  const t = String(text || '').trim()
  if (t === '') return false
  if (/^JOIN-/i.test(t)) return true
  return /^\/?(join|help|start|ask|share|feature|import|export|asset|approve|decline|reject|reply|blacklist|unblacklist)\b/i.test(
    t,
  )
}

/**
 * WhatsApp voice note / audio attachment (ptt = push-to-talk).
 * @returns {'voice'|'audio'|null}
 */
function voiceMediaKind(msg) {
  const type = String(msg?.type || msg?._data?.type || '').toLowerCase()
  if (type === 'ptt' || type === 'voice') return 'voice'
  if (type === 'audio') return 'audio'
  return null
}

const ALLOWED_IMAGE_MIMES = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/webp',
  'image/gif',
])

/**
 * WhatsApp photo / image document (not stickers — those are noisy).
 * @returns {'image'|null}
 */
function imageMediaKind(msg) {
  const type = String(msg?.type || msg?._data?.type || '').toLowerCase()
  if (type === 'image') return 'image'
  if (type === 'document') {
    const mime = String(msg?.mimetype || msg?._data?.mimetype || '').toLowerCase().split(';')[0]
    if (ALLOWED_IMAGE_MIMES.has(mime)) return 'image'
  }
  return null
}

/**
 * Download voice/audio media as base64 for Laravel STT.
 * Prefers a Store/DownloadManager path: wwebjs downloadMedia() often throws
 * Puppeteer EvaluationFailed ("r") on @lid chats even when the msg is settled.
 * @returns {Promise<{kind: string, mime_type: string, filename: string, data_base64: string}|null>}
 */
async function downloadVoiceMedia(msg, kind) {
  if (!kind || !msg) return null

  // WA Web 2.3000+: id._serialized renamed to id.$1 → wwebjs passes undefined → "r".
  normalizeMsgSerialized(msg)
  const hint = messageKeyHint(msg) || {}
  hint.type = kind === 'audio' ? 'audio' : 'ptt'
  const tryWwebjs = async (target) => {
    if (!target || typeof target.downloadMedia !== 'function') return null
    normalizeMsgSerialized(target)
    // Last-chance: force the serialized string wwebjs will pass into Msg.get.
    const sid = messageSerializedId(target)
    if (sid && target.id && typeof target.id === 'object' && !target.id._serialized) {
      target.id._serialized = sid
    }
    const media = await target.downloadMedia()
    if (!media?.data) return null
    const mime = String(media.mimetype || media.mimeType || 'audio/ogg; codecs=opus')
    const filename = String(media.filename || (mime.includes('ogg') ? 'voice.ogg' : 'voice.m4a'))
    return {
      kind,
      mime_type: mime,
      filename,
      data_base64: String(media.data),
    }
  }

  const tryStoreDownload = async () => {
    if (!client?.pupPage || !hint) return { ok: false, reason: 'no_page_or_hint' }
    try {
      return await client.pupPage.evaluate(async (hintObj, finderSrc) => {
        // eslint-disable-next-line no-new-func
        eval(finderSrc)
        const found = await findMsgInStore(hintObj)
        const msg = found?.msg
        if (!msg) {
          return { ok: false, reason: 'not_in_store', via: found?.via || null }
        }

        // Force media resolve when WA still has a spinner / deferred stage.
        const waitResolved = async () => {
          const start = Date.now()
          while (Date.now() - start < 8000) {
            const stage = String(msg.mediaData?.mediaStage || '')
            if (stage === 'RESOLVED') return stage
            if (stage.includes('ERROR') || stage === 'REUPLOADING') return stage
            try {
              if (typeof msg.downloadMedia === 'function') {
                await msg.downloadMedia({
                  downloadEvenIfExpensive: true,
                  rmrReason: 1,
                })
              }
            } catch (_) { /* keep polling */ }
            await new Promise((r) => setTimeout(r, 350))
          }
          return String(msg.mediaData?.mediaStage || '')
        }

        const stage = await waitResolved()
        if (stage.includes('ERROR') || stage === 'REUPLOADING') {
          return { ok: false, reason: 'media_stage_' + stage }
        }

        const type = String(msg.type || hintObj.type || 'ptt')
        const mime =
          String(msg.mimetype || '')
          || (type === 'ptt' || type === 'voice'
            ? 'audio/ogg; codecs=opus'
            : 'audio/mpeg')

        // Prefer media keys from the live Store model (not the Node proxy).
        const directPath = msg.directPath || msg.mediaObject?.directPath
        const encFilehash = msg.encFilehash || msg.mediaObject?.encFilehash
        const filehash = msg.filehash || msg.mediaObject?.filehash
        const mediaKey = msg.mediaKey || msg.mediaObject?.mediaKey
        const mediaKeyTimestamp = msg.mediaKeyTimestamp || msg.mediaObject?.mediaKeyTimestamp

        if (!mediaKey || !directPath) {
          return {
            ok: false,
            reason: 'missing_media_keys',
            stage,
            hasKey: Boolean(mediaKey),
            hasPath: Boolean(directPath),
            via: found?.via || null,
          }
        }

        try {
          const mockQpl = {
            addAnnotations() { return this },
            addPoint() { return this },
          }
          const DownloadManager = window.require('WAWebDownloadManager')
          const decrypted = await DownloadManager.downloadManager.downloadAndMaybeDecrypt({
            directPath,
            encFilehash,
            filehash,
            mediaKey,
            mediaKeyTimestamp,
            type,
            signal: (new AbortController()).signal,
            downloadQpl: mockQpl,
          })
          if (!decrypted) {
            return { ok: false, reason: 'decrypt_empty', stage }
          }
          const data = await window.WWebJS.arrayBufferToBase64Async(decrypted)
          if (!data) {
            return { ok: false, reason: 'b64_empty', stage }
          }
          return {
            ok: true,
            data,
            mimetype: mime,
            filename: String(msg.filename || (mime.includes('ogg') ? 'voice.ogg' : 'voice.m4a')),
            stage,
            via: found?.via || 'store',
          }
        } catch (e) {
          return {
            ok: false,
            reason: 'decrypt_throw',
            err: String(e && (e.message || e)) || 'unknown',
            stage,
            hasKey: Boolean(mediaKey),
            hasPath: Boolean(directPath),
          }
        }
      }, hint, FIND_MSG_IN_STORE_JS)
    } catch (err) {
      return { ok: false, reason: 'evaluate_throw', err: formatErr(err) }
    }
  }

  let lastErr = null
  for (let attempt = 1; attempt <= 5; attempt++) {
    try {
      let target = msg
      if (attempt > 1) {
        await settleInboundMessage(msg)
        await sleep(400 * attempt)
        target = (await reloadMessage(msg)) || msg
      } else {
        await settleInboundMessage(msg)
      }

      // 1) Official wwebjs helper (fast when it works).
      try {
        const viaWweb = await tryWwebjs(target)
        if (viaWweb) {
          if (attempt > 1) console.log(`[spike] voice download ok wwebjs try=${attempt}`)
          return viaWweb
        }
      } catch (err) {
        lastErr = err
        console.error(`[spike] voice wwebjs failed try=${attempt}:`, formatErr(err))
      }

      // 2) Direct Store + DownloadManager (survives many LID / EvaluationFailed "r" cases).
      const viaStore = await tryStoreDownload()
      if (viaStore?.ok && viaStore.data) {
        console.log(
          `[spike] voice download ok store try=${attempt} via=${viaStore.via} stage=${viaStore.stage || '?'}`,
        )
        return {
          kind,
          mime_type: String(viaStore.mimetype || 'audio/ogg; codecs=opus'),
          filename: String(viaStore.filename || 'voice.ogg'),
          data_base64: String(viaStore.data),
        }
      }
      console.log(
        `[spike] voice store miss try=${attempt}:`,
        viaStore?.reason || 'unknown',
        viaStore?.err ? `err=${viaStore.err}` : '',
        viaStore?.hasKey === false ? 'no_mediaKey' : '',
      )
    } catch (err) {
      lastErr = err
      console.error(`[spike] voice download failed try=${attempt}:`, formatErr(err))
    }
    await sleep(350 * attempt)
  }

  if (lastErr) {
    console.error('[spike] voice download gave up:', formatErr(lastErr))
  }
  return null
}

const MAX_IMAGE_B64_CHARS = 3_500_000

/**
 * Download a WhatsApp image as base64 for Laravel vision.
 * @returns {Promise<{kind: string, mime_type: string, filename: string, data_base64: string}|null>}
 */
async function downloadImageMedia(msg) {
  if (!msg) return null
  normalizeMsgSerialized(msg)
  let lastErr = null
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      if (typeof msg.downloadMedia !== 'function') return null
      const media = await msg.downloadMedia()
      if (!media?.data) {
        lastErr = new Error('empty_image_data')
        await sleep(300 * attempt)
        continue
      }
      const mime = String(media.mimetype || media.mimeType || 'image/jpeg')
        .toLowerCase()
        .split(';')[0]
      const normalizedMime = mime === 'image/jpg' ? 'image/jpeg' : mime
      if (!ALLOWED_IMAGE_MIMES.has(normalizedMime)) {
        console.log(`[spike] skip image mime=${normalizedMime}`)
        return null
      }
      const data = String(media.data)
      if (data.length > MAX_IMAGE_B64_CHARS) {
        console.log(`[spike] image too large b64=${data.length}; skip`)
        return null
      }
      const filename = String(media.filename || (normalizedMime.includes('png') ? 'photo.png' : 'photo.jpg'))
      if (attempt > 1) console.log(`[spike] image download ok try=${attempt}`)
      return {
        kind: 'image',
        mime_type: normalizedMime,
        filename,
        data_base64: data,
      }
    } catch (err) {
      lastErr = err
      console.error(`[spike] image download failed try=${attempt}:`, formatErr(err))
      await sleep(350 * attempt)
    }
  }
  if (lastErr) {
    console.error('[spike] image download gave up:', formatErr(lastErr))
  }
  return null
}


async function resolveSenderContact(msg, senderRaw) {
  let contact = null
  try {
    contact = await msg.getContact()
  } catch {
    /* ignore */
  }
  if (!contact && senderRaw) {
    try {
      contact = await client.getContactById(senderRaw)
    } catch {
      /* ignore */
    }
  }
  return contact
}

/**
 * Key parts for finding an inbound message in WA Store when _serialized
 * lookup fails (common for group @lid messages).
 */
function messageKeyHint(msg) {
  if (!msg) return null
  const id = msg.id || {}
  const data = msg._data || {}
  const dataId = data.id || {}
  const stanza =
    id.id
    || dataId.id
    || (typeof id === 'string' ? id.split('_').pop() : null)
    || null
  const remote =
    id.remote
    || dataId.remote
    || msg.from
    || data.from
    || null
  const participant =
    id.participant
    || dataId.participant
    || msg.author
    || data.author
    || data.participant
    || null
  const fromMe = Boolean(id.fromMe ?? dataId.fromMe ?? msg.fromMe)
  const serialized = messageSerializedId(msg)
  return {
    serialized,
    stanza: stanza ? String(stanza) : null,
    remote: remote ? String(remote?._serialized || remote?.$1 || remote) : null,
    participant: participant ? String(participant?._serialized || participant?.$1 || participant) : null,
    fromMe,
    t: Number(msg.timestamp || data.t || 0) || 0,
    body: String(msg.body || data.body || '').slice(0, 80),
  }
}

/**
 * Browser-side: find a Msg model even when Msg.get(serialized) misses.
 * Injected as a string so settle / quote-ready / store-send share one finder.
 */
const FIND_MSG_IN_STORE_JS = `function serializeStoreMsgId(m) {
  if (!m || !m.id) return null
  if (m.id._serialized) return String(m.id._serialized)
  if (m.id.$1) return String(m.id.$1)
  try {
    if (typeof m.id.toString === 'function') {
      const s = String(m.id.toString())
      if (s && s !== '[object Object]' && s.includes('_')) return s
    }
  } catch (_) {}
  const fromMe = m.id.fromMe ? 'true' : 'false'
  let remote = m.id.remote
  if (remote && typeof remote === 'object') remote = remote._serialized || remote.$1 || ''
  remote = String(remote || '')
  const stanza = m.id.id || ''
  let participant = m.id.participant
  if (participant && typeof participant === 'object') {
    participant = participant._serialized || participant.$1 || ''
  }
  participant = participant ? String(participant) : ''
  if (!remote || !stanza) return null
  return participant
    ? (fromMe + '_' + remote + '_' + stanza + '_' + participant)
    : (fromMe + '_' + remote + '_' + stanza)
}

async function findMsgInStore(hint) {
  const Collections = window.require('WAWebCollections')
  const Msg = Collections.Msg
  const Chat = Collections.Chat
  const hintObj = hint || {}
  const serialized = hintObj.serialized || null
  const stanza = hintObj.stanza || null
  const remote = hintObj.remote || null
  const participant = hintObj.participant || null
  const fromMe = Boolean(hintObj.fromMe)
  const body = String(hintObj.body || '')
  const t = Number(hintObj.t || 0)

  const tryGet = async (key) => {
    if (!key) return null
    let m = Msg.get(key)
    if (m) return m
    try {
      const res = await Msg.getMessagesById([key])
      m = res?.messages?.[0]
      if (m) return m
    } catch (_) {}
    return null
  }

  let found = await tryGet(serialized)
  if (found) return { msg: found, via: 'serialized' }

  if (serialized && participant && !String(serialized).endsWith(String(participant))) {
    found = await tryGet(serialized + '_' + participant)
    if (found) return { msg: found, via: 'serialized_participant' }
  }
  if (serialized && String(serialized).includes('@lid')) {
    const stripped = String(serialized).replace(/_[^_]+@lid$/, '')
    if (stripped !== serialized) {
      found = await tryGet(stripped)
      if (found) return { msg: found, via: 'serialized_stripped' }
    }
  }

  if (remote && stanza) {
    const alts = [
      (fromMe ? 'true' : 'false') + '_' + remote + '_' + stanza,
      (fromMe ? 'true' : 'false') + '_' + remote + '_' + stanza + (participant ? '_' + participant : ''),
    ]
    for (const alt of alts) {
      found = await tryGet(alt)
      if (found) return { msg: found, via: 'alt_serialized' }
    }
  }

  const listModels = (collection) => {
    if (!collection) return []
    if (typeof collection.getModelsArray === 'function') return collection.getModelsArray()
    if (Array.isArray(collection._models)) return collection._models
    if (Array.isArray(collection.models)) return collection.models
    try { return Array.from(collection) } catch (_) { return [] }
  }

  const matchModel = (m) => {
    if (!m || !m.id) return false
    const sid = serializeStoreMsgId(m) || ''
    const mid = m.id.id || ''
    if (serialized && (sid === serialized || sid.startsWith(serialized) || serialized.startsWith(sid))) return true
    if (stanza && mid === stanza) return true
    if (stanza && sid.includes('_' + stanza)) return true
    return false
  }

  if (remote) {
    let chat = Chat.get(remote)
    if (!chat && typeof Chat.find === 'function') {
      try { chat = await Chat.find(remote) } catch (_) {}
    }
    if (chat) {
      const models = listModels(chat.msgs)
      found = models.find(matchModel) || null
      if (found) return { msg: found, via: 'chat.msgs' }

      if (body && t) {
        found = models.find((m) => {
          if (!m) return false
          const mt = Number(m.t || m.timestamp || 0)
          if (Math.abs(mt - t) > 5) return false
          const mb = String(m.body || m.caption || '')
          return mb && (mb === body || mb.includes(body) || body.includes(mb.slice(0, 40)))
        }) || null
        if (found) return { msg: found, via: 'chat.msgs_soft' }
      }
    }
  }

  const all = listModels(Msg)
  found = all.find(matchModel) || null
  if (found) return { msg: found, via: 'Msg.global' }

  return { msg: null, via: 'not_found' }
}`

async function findInboundInStore(hint) {
  if (!client?.pupPage || !hint) {
    return { ok: false, reason: 'missing_page_or_hint', via: null, serialized: null }
  }
  try {
    return await client.pupPage.evaluate(async (hintObj, finderSrc) => {
      // eslint-disable-next-line no-new-func
      eval(finderSrc)
      const result = await findMsgInStore(hintObj)
      if (!result?.msg) {
        return { ok: false, reason: 'not_in_store', via: result?.via || 'not_found', serialized: null }
      }
      const m = result.msg
      let serialized = serializeStoreMsgId(m)
      if (!serialized && hintObj.remote && hintObj.stanza) {
        const fromMe = hintObj.fromMe ? 'true' : 'false'
        serialized = hintObj.participant
          ? (fromMe + '_' + hintObj.remote + '_' + hintObj.stanza + '_' + hintObj.participant)
          : (fromMe + '_' + hintObj.remote + '_' + hintObj.stanza)
      }
      let canReply = true
      try {
        const ReplyUtils = window.require('WAWebMsgReply')
        canReply = ReplyUtils
          ? ReplyUtils.canReplyMsg(m.unsafe ? m.unsafe() : m)
          : (typeof m.canReply === 'function' ? m.canReply() : true)
      } catch (_) {
        canReply = true
      }
      // Found in Store counts as ready even if id serialization is quirky.
      return {
        ok: Boolean(canReply),
        reason: canReply ? 'ready' : 'can_reply_false',
        via: result.via,
        serialized: serialized || null,
      }
    }, hint, FIND_MSG_IN_STORE_JS)
  } catch (err) {
    return { ok: false, reason: formatErr(err), via: 'eval_error', serialized: null }
  }
}

/**
 * Private DMs: natural conversation — no forced asker @greeting.
 * Mid-body @phone tags (e.g. admin ack "I notified @234…") still need a
 * mentions[] JID list or WhatsApp leaves them as plain digits (not green).
 * Groups: always swipe-quote the asker's message + real @id mention.
 *
 * Quote strategy (wwebjs + WA Web):
 * 1. Settle inbound in Msg store (message_create races indexing).
 * 2. Reload via client.getMessageById so reply() has a live Store object.
 * 3. Verify Store canReply before send. Critical: ignoreQuoteErrors defaults to
 *    TRUE in wwebjs and silently drops the swipe-quote when Store misses the msg.
 * 4. Prefer msg.reply with ignoreQuoteErrors:false; fall back to Store send.
 * Never treat a plain delivery as a quoted success.
 */
async function replyInContext(msg, text, { isGroup, senderRaw, fromName, contact: presetContact }) {
  const contact = presetContact || (await resolveSenderContact(msg, senderRaw))
  let liveMsg = await reloadMessage(msg)

  if (!isGroup) {
    let body = stripFakeAtDisplayNames(text)
    // Greeting-style leading @digits only (not mid-body "I notified @234…").
    body = body.replace(/^@\d{6,}\b[,\s]*/u, '').trim()
    body = body.replace(/^@~?(?!\d)[\p{L}\p{N}._ -]{1,60},\s*/u, '').trim()

    const mentionJids = phoneMentionJidsFromText(body)
    const sendOpts = { ignoreQuoteErrors: false }
    if (mentionJids.length > 0) {
      sendOpts.mentions = mentionJids
    }

    await settleInboundMessage(liveMsg || msg)
    liveMsg = (await reloadMessage(liveMsg || msg)) || liveMsg || msg
    await sendTyping(liveMsg)

    try {
      const sent = await liveMsg.reply(body, undefined, sendOpts)
      rememberBotOutboundId(sent)
      const quoted = await messageLooksQuoted(sent)
      console.log(
        `[spike] send ok private quoted=${quoted ? 'msg.reply' : 'NONE'}`
          + ` mentions=${mentionJids.length}`,
      )
      return { mentioned: mentionJids.length > 0, to: mentionJids[0] || null, quoted }
    } catch (err) {
      console.error('[spike] private reply failed, plain send:', formatErr(err))
    }
    try {
      const chat = await resolveChat(liveMsg)
      const sent = await chat.sendMessage(body, mentionJids.length ? { mentions: mentionJids } : {})
      rememberBotOutboundId(sent)
      return { mentioned: mentionJids.length > 0, to: mentionJids[0] || null, quoted: false }
    } catch (err2) {
      console.error('[spike] private plain send failed:', formatErr(err2))
      return { mentioned: false, quoted: false }
    }
  }

  const asker = await resolveAskerMention(contact, senderRaw)
  const askerTag = asker.tag
  const askerId = asker.jid
  const askerMentionJids = Array.isArray(asker.mentionJids) && asker.mentionJids.length
    ? asker.mentionJids
    : (askerId ? [askerId] : [])
  let body = stripFakeAtDisplayNames(text)
  body = body.replace(/^@~?(?!\d)[\p{L}\p{N}._ -]{1,80},?\s+/u, '').trim()
  // Drop a prior phone/LID @digits prefix if we're about to blend the real tag.
  body = body.replace(/^@\d{6,}\b[,\s]*/u, '').trim()
  if (askerTag) {
    body = blendMentionTag(askerTag, body)
  }

  const uniqueMentions = [...new Set([
    ...askerMentionJids.filter(Boolean),
    ...mentionJidsFromText(body),
  ])]
  const mentionOpts = uniqueMentions.length > 0 ? { mentions: uniqueMentions } : {}
  debugLog('group mention plan', { tag: askerTag, mentions: uniqueMentions })

  // Quote needs a real Store-backed id. message_create often races — wait longer here.
  await ensureMessageId(liveMsg || msg, 2500)
  let hint = await settleInboundMessage(liveMsg || msg)
  liveMsg = (await reloadMessage(liveMsg || msg)) || liveMsg || msg
  hint = { ...messageKeyHint(liveMsg || msg), ...hint }
  // Keep participant on the serialized key when Store indexes group msgs that way.
  if (hint?.participant && hint?.serialized
    && !String(hint.serialized).includes(String(hint.participant))) {
    hint.serialized = `${hint.serialized}_${hint.participant}`
  }
  let quoteId = hint?.serialized || messageSerializedId(liveMsg) || messageSerializedId(msg)
  void sendTyping(liveMsg)

  let quoteReady = await findInboundInStore(hint)
  debugLog('quote ready', { quoteId, ...quoteReady })
  if (!quoteReady.ok) {
    await sleep(500)
    hint = await settleInboundMessage(liveMsg || msg)
    liveMsg = (await reloadMessage(liveMsg || msg)) || liveMsg || msg
    hint = { ...messageKeyHint(liveMsg || msg), ...hint }
    quoteReady = await findInboundInStore(hint)
    debugLog('quote ready retry', { quoteId: hint?.serialized, ...quoteReady })
  }
  // Prefer the Store's real serialized id (may include participant / differ from event id).
  if (quoteReady?.serialized) {
    quoteId = quoteReady.serialized
    try {
      const fresh = await client.getMessageById(quoteId)
      if (fresh) liveMsg = fresh
    } catch (_) {
      /* keep liveMsg */
    }
  }

  const quoteOpts = { ...mentionOpts, ignoreQuoteErrors: false }

  const tryQuoted = async (label, run) => {
    const sent = await run()
    rememberBotOutboundId(sent)
    const reallyQuoted = await messageLooksQuoted(sent)
    const usedMentions = label.includes('mentions') && uniqueMentions.length > 0
    debugLog('group send result', {
      via: label,
      hasQuotedMsg: reallyQuoted,
      sentId: sent?.id?._serialized || null,
      mentions: usedMentions ? uniqueMentions.length : 0,
    })
    console.log(
      `[spike] send ok group via=${label} quoted=${reallyQuoted}`
        + ` mentions=${usedMentions ? uniqueMentions.length : 0}`
        + ` tag=${askerTag || 'none'}`,
    )
    return {
      mentioned: usedMentions,
      to: askerTag || null,
      quoted: reallyQuoted,
      sent,
      delivered: true,
    }
  }

  // 1) Store quoted send first (getChat often throws "r"; direct quotedMsg is reliable).
  if (client?.pupPage) {
    try {
      const storeResult = await sendQuotedViaStore({
        chatId: String(liveMsg.from || msg.from || ''),
        text: body,
        quoteMsgId: quoteId,
        mentionIds: uniqueMentions,
        hint,
      })
      if (storeResult?.ok) {
        console.log(
          `[spike] send ok group via=store.quoted quoted=${storeResult.quoted ? 'true' : 'false'}`
            + ` mentions=${uniqueMentions.length}`
            + (storeResult.via ? ` find=${storeResult.via}` : '')
            + (storeResult.method ? ` method=${storeResult.method}` : '')
            + (storeResult.reason ? ` note=${storeResult.reason}` : ''),
        )
        return {
          mentioned: uniqueMentions.length > 0,
          to: askerTag || null,
          quoted: Boolean(storeResult.quoted),
        }
      }
      console.warn('[spike] store.quoted failed:', storeResult?.reason || 'unknown')
    } catch (err) {
      console.error('[spike] store.quoted error:', formatErr(err))
    }
  }

  // 2) msg.reply with ignoreQuoteErrors=false (default true drops quotes).
  if (quoteId && (quoteReady.ok || quoteReady.via)) {
    const attempts = [
      {
        label: 'msg.reply+mentions',
        run: () => liveMsg.reply(body, undefined, { ...quoteOpts }),
      },
      {
        label: 'msg.reply',
        run: () => liveMsg.reply(body, undefined, { ...mentionOpts, ignoreQuoteErrors: false }),
      },
    ]
    for (const attempt of attempts) {
      try {
        const result = await tryQuoted(attempt.label, attempt.run)
        if (result.quoted) {
          return {
            mentioned: result.mentioned,
            to: result.to,
            quoted: true,
          }
        }
        // Body already sent without quote — do not double-post.
        console.warn(`[spike] ${attempt.label} delivered but quoted=false (WA dropped swipe-reply)`)
        return {
          mentioned: result.mentioned,
          to: result.to,
          quoted: false,
        }
      } catch (err) {
        console.error(`[spike] group ${attempt.label} failed:`, formatErr(err))
      }
    }
  } else {
    console.warn(
      '[spike] quote not ready in Store:',
      quoteReady.reason || 'unknown',
      'quoteId=',
      quoteId,
    )
  }

  // 3) chat.sendMessage with quotedMessageId (one try).
  liveMsg = (await reloadMessage(liveMsg)) || liveMsg
  let chat = await resolveChat(liveMsg)
  if (chat && quoteId) {
    try {
      const result = await tryQuoted(
        'sendMessage+quote+mentions',
        () => chat.sendMessage(body, { ...quoteOpts, quotedMessageId: quoteId }),
      )
      if (result.quoted) {
        return {
          mentioned: result.mentioned,
          to: result.to,
          quoted: true,
        }
      }
      console.warn('[spike] sendMessage+quote delivered but quoted=false')
      return {
        mentioned: result.mentioned,
        to: result.to,
        quoted: false,
      }
    } catch (err) {
      console.error('[spike] group sendMessage+quote failed:', formatErr(err))
    }
  }

  console.error('[spike] GROUP QUOTE FAILED — sending plain (no swipe-reply). quoteId=', quoteId)
  try {
    chat = chat || (await resolveChat(liveMsg))
    // Always keep mentions — without them @digits stays plain (not green/clickable).
    const sent = chat
      ? await chat.sendMessage(body, mentionOpts)
      : await liveMsg.reply(body, undefined, { ...mentionOpts, ignoreQuoteErrors: true })
    rememberBotOutboundId(sent)
    return {
      mentioned: uniqueMentions.length > 0,
      to: askerTag || null,
      quoted: await messageLooksQuoted(sent),
    }
  } catch (err) {
    console.error('[spike] group plain send failed:', formatErr(err))
    return { mentioned: false, quoted: false }
  }
}

/** True when the outbound message actually attached a swipe-quote. */
async function messageLooksQuoted(sent) {
  if (!sent) return false
  if (sent.hasQuotedMsg) return true
  if (sent._data?.quotedMsg || sent._data?.quotedStanzaID) return true
  const id = sent.id?._serialized
  if (!id || !client?.pupPage) return false
  try {
    const flag = await client.pupPage.evaluate(async (msgId) => {
      try {
        const Msg = window.require('WAWebCollections').Msg
        let m = Msg.get(msgId)
        if (!m) {
          const res = await Msg.getMessagesById([msgId])
          m = res?.messages?.[0]
        }
        if (!m) return false
        return Boolean(m.quotedMsgId || m.quotedStanzaID || m.quotedMsg)
      } catch (_) {
        return false
      }
    }, id)
    return Boolean(flag)
  } catch {
    return false
  }
}

/** @deprecated use findInboundInStore(messageKeyHint(msg)) */
async function ensureQuoteReadyInStore(quoteMsgId) {
  return findInboundInStore({ serialized: quoteMsgId })
}

/** Reload inbound message from Store so quote/reply APIs see a live object. */
async function reloadMessage(msg) {
  const id = messageSerializedId(msg)
  if (!id || !client?.getMessageById) return msg
  try {
    const fresh = await client.getMessageById(id)
    if (fresh) {
      debugLog('reloaded message for quote', { id })
      return fresh
    }
  } catch (err) {
    debugLog('getMessageById failed', formatErr(err))
  }
  return msg
}

/**
 * Quoted send via WA Web Store. Finds the inbound Msg by serialized id, stanza id,
 * or chat.msgs scan — then quotes with the live Store model (quotedMsg), not a
 * second id lookup that often fails for group @lid keys.
 */
async function sendQuotedViaStore({ chatId, text, quoteMsgId, mentionIds, hint }) {
  if (!client?.pupPage || !chatId) {
    return { ok: false, reason: 'missing_pup_or_ids' }
  }
  const keyHint = {
    ...(hint || {}),
    serialized: (hint && hint.serialized) || quoteMsgId || null,
    remote: (hint && hint.remote) || chatId || null,
  }
  // Prefer the longest known id (with participant) when hint is incomplete.
  if (keyHint.participant && keyHint.serialized
    && !String(keyHint.serialized).endsWith(String(keyHint.participant))) {
    keyHint.serialized = `${keyHint.serialized}_${keyHint.participant}`
  }
  return client.pupPage.evaluate(
    async (chatId, text, mentionIds, hintObj, finderSrc) => {
      try {
        // eslint-disable-next-line no-new-func
        eval(finderSrc)
        const Collections = window.require('WAWebCollections')
        const Chat = Collections.Chat
        let chat = Chat.get(chatId)
        if (!chat && Chat.find) {
          chat = await Chat.find(chatId)
        }
        if (!chat) return { ok: false, reason: 'chat_not_found' }

        const found = await findMsgInStore({ ...hintObj, remote: hintObj.remote || chatId })
        const quoted = found?.msg
        if (!quoted) return { ok: false, reason: 'quoted_msg_not_found', via: found?.via || null }

        const realId = serializeStoreMsgId(quoted) || hintObj.serialized
        try {
          const ReplyUtils = window.require('WAWebMsgReply')
          const canReply = ReplyUtils
            ? ReplyUtils.canReplyMsg(quoted.unsafe ? quoted.unsafe() : quoted)
            : (typeof quoted.canReply === 'function' ? quoted.canReply() : true)
          if (!canReply) return { ok: false, reason: 'can_reply_false', via: found.via }
        } catch (_) {
          /* continue */
        }

        const quotedModel = quoted.unsafe ? quoted.unsafe() : quoted

        // Prefer direct quotedMsg (avoids a second Msg.get that fails on LID keys).
        try {
          const ctx = typeof quoted.msgContextInfo === 'function'
            ? quoted.msgContextInfo(chat)
            : null
          const sendText = window.require('WAWebSendTextMsgChatAction')
          if (sendText?.sendTextMsgToChat && ctx) {
            const opts = { ...ctx, quotedMsg: quotedModel }
            if (mentionIds && mentionIds.length) {
              try {
                opts.mentionedJidList = mentionIds.map((id) =>
                  window.require('WAWebWidFactory').createWid(id),
                )
              } catch (_) {
                /* optional */
              }
            }
            await sendText.sendTextMsgToChat(chat, text, opts)
            return { ok: true, quoted: true, via: found.via, method: 'sendTextMsgToChat' }
          }
        } catch (e) {
          /* fall through to WWebJS */
        }

        if (window.WWebJS?.sendMessage && realId) {
          await window.WWebJS.sendMessage(chat, text, {
            quotedMessageId: realId,
            mentions: mentionIds || [],
            ignoreQuoteErrors: false,
          })
          return { ok: true, quoted: true, via: found.via, method: 'WWebJS.sendMessage' }
        }

        return { ok: false, reason: 'no_send_helper', via: found.via }
      } catch (e) {
        return { ok: false, reason: String(e?.message || e || 'store_error') }
      }
    },
    chatId,
    text,
    mentionIds || [],
    keyHint,
    FIND_MSG_IN_STORE_JS,
  )
}



function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms))
}

function debugLog(...args) {
  if (SPIKE_DEBUG) console.log('[spike:debug]', ...args)
}

/**
 * WA Web renamed id._serialized → id.$1 on some builds. Prefer either.
 * @param {any} id
 */
function idSerialized(id) {
  if (!id) return null
  if (typeof id === 'string') return id.includes('@') || id.includes('_') ? id : null
  const s = id._serialized || id.$1 || null
  return s ? String(s) : null
}

/** Ensure Node-side Message.id has _serialized so wwebjs downloadMedia/getMessageById work. */
function normalizeMsgSerialized(msg) {
  if (!msg) return null
  const fill = (obj) => {
    if (!obj || typeof obj !== 'object') return
    if (!obj._serialized && obj.$1) obj._serialized = obj.$1
  }
  fill(msg.id)
  fill(msg._data?.id)
  // Also fill WID-like fields that Store helpers may read.
  fill(typeof msg.from === 'object' ? msg.from : null)
  fill(typeof msg.author === 'object' ? msg.author : null)
  return idSerialized(msg.id) || messageSerializedId(msg)
}

/** Stable serialized id — message_create often lacks id._serialized until later. */
function messageSerializedId(msg) {
  if (!msg) return null
  const direct = idSerialized(msg.id)
  if (direct) return direct
  if (typeof msg.id === 'string' && msg.id.includes('@')) return msg.id
  const dataId = msg._data?.id
  const fromData = idSerialized(dataId)
  if (fromData) return fromData
  if (typeof dataId === 'string' && dataId) return dataId
  const stanza = msg.id?.id || dataId?.id
  const remote = String(msg.from || dataId?.remote || '')
  if (stanza && remote) {
    const fromMe = msg.fromMe || dataId?.fromMe ? 'true' : 'false'
    const participant = String(
      msg.id?.participant
      || dataId?.participant
      || msg.author
      || msg._data?.author
      || '',
    )
    // Group messages are often keyed with participant suffix in Store.
    if (participant && participant.includes('@')) {
      return `${fromMe}_${remote}_${stanza}_${participant}`
    }
    return `${fromMe}_${remote}_${stanza}`
  }
  return null
}

/** Wait briefly for WA to attach a real message id (needed for swipe-quote). */
async function ensureMessageId(msg, maxMs = 1200) {
  let id = messageSerializedId(msg)
  if (id) return id
  const start = Date.now()
  while (Date.now() - start < maxMs) {
    await sleep(150)
    id = messageSerializedId(msg)
    if (id) return id
  }
  return messageSerializedId(msg)
}

/**
 * Typing via Store / WWebJS only. NEVER call resolveChat — getChat "r" burns 5–15s.
 * Prefer window.WWebJS.sendChatstate (same path as Chat.sendStateTyping in wwebjs).
 */
async function sendTypingToChat(chatId) {
  const id = String(chatId || '').trim()
  if (!client?.pupPage || !id) return
  try {
    const ok = await client.pupPage.evaluate(async (chatKey) => {
      try {
        // Official wwebjs inject path — works when chat.presence APIs are gone.
        if (typeof window.WWebJS?.sendChatstate === 'function') {
          await window.WWebJS.sendChatstate('typing', chatKey)
          return { ok: true, via: 'WWebJS.sendChatstate' }
        }
        try {
          const wid = window.require('WAWebWidFactory').createWid(chatKey)
          const ChatState = window.require('WAWebChatStateBridge')
          if (ChatState?.sendChatStateComposing) {
            await ChatState.sendChatStateComposing(wid)
            return { ok: true, via: 'WAWebChatStateBridge' }
          }
        } catch (_) {
          /* fall through */
        }

        const Chat = window.require('WAWebCollections').Chat
        let chat = Chat.get(chatKey)
        if (!chat && typeof Chat.find === 'function') {
          chat = await Chat.find(chatKey)
        }
        if (!chat) return { ok: false, reason: 'no_chat' }

        if (chat.presence?.subscribe) {
          try {
            await chat.presence.subscribe()
          } catch (_) {
            /* groups need subscribe first */
          }
        }
        if (typeof chat.presence?.sendComposing === 'function') {
          await chat.presence.sendComposing()
          return { ok: true, via: 'presence.sendComposing' }
        }
        if (typeof chat.sendStateTyping === 'function') {
          await chat.sendStateTyping()
          return { ok: true, via: 'sendStateTyping' }
        }
        try {
          const Mark = window.require('WAWebChatPresenceMutation')
          if (Mark?.setChatComposition) {
            await Mark.setChatComposition(chat, true)
            return { ok: true, via: 'setChatComposition' }
          }
        } catch (_) {
          /* optional */
        }
        return { ok: false, reason: 'no_typing_api' }
      } catch (e) {
        return { ok: false, reason: String(e?.message || e || 'eval_error') }
      }
    }, id)
    if (ok?.ok) debugLog('typing ok', ok.via)
    else debugLog('typing skip', ok?.reason || 'failed')
  } catch (err) {
    debugLog('typing evaluate failed', formatErr(err))
  }
}

async function sendTyping(msg) {
  const chatId = String(msg?.from || msg?._data?.from || '')
  await sendTypingToChat(chatId)
}

/** chatId → stop fn (queued mode keeps composing until outbound /send). */
const typingByChat = new Map()

function stopTypingForChat(chatId) {
  const id = String(chatId || '').trim()
  if (!id) return
  const stop = typingByChat.get(id)
  if (!stop) return
  try {
    stop()
  } catch (_) {
    /* ignore */
  }
  typingByChat.delete(id)
}

/** Refresh composing while Laravel is slow (OpenClaw active-turn pattern). */
function startTypingHeartbeat(msg) {
  const chatId = String(msg?.from || msg?._data?.from || '').trim()
  stopTypingForChat(chatId)
  void sendTyping(msg)
  const timer = setInterval(() => {
    void sendTyping(msg)
  }, 4000)
  // Queued jobs can take ~2m; stop so we never leak intervals.
  const maxTimer = setTimeout(() => stopTypingForChat(chatId), 110_000)
  const stop = () => {
    clearInterval(timer)
    clearTimeout(maxTimer)
    if (chatId && typingByChat.get(chatId) === stop) {
      typingByChat.delete(chatId)
    }
  }
  if (chatId) typingByChat.set(chatId, stop)
  return stop
}

/** One quick try — never multi-second backoff. */
async function resolveChat(msg) {
  const chatId = String(msg?.from || '')
  try {
    const chat = await msg.getChat()
    if (chat) return chat
  } catch (err) {
    debugLog('getChat once', formatErr(err))
  }
  if (chatId && client?.getChatById) {
    try {
      const chat = await client.getChatById(chatId)
      if (chat) return chat
    } catch (err) {
      debugLog('getChatById once', formatErr(err))
    }
  }
  return null
}

/**
 * Short settle for quote — max ~1.5s, not the old 14-try multi-second loop.
 */
async function settleInboundMessage(msg) {
  const hint = messageKeyHint(msg)
  const msgId = hint?.serialized || messageSerializedId(msg)
  debugLog('settle start', {
    msgId: msgId || '(none)',
    stanza: hint?.stanza || null,
    participant: hint?.participant || null,
    hasQuotedMsg: Boolean(msg?.hasQuotedMsg),
  })
  if (!msgId || !client?.pupPage) {
    await sleep(200)
    return hint
  }

  for (let i = 0; i < 10; i++) {
    const found = await findInboundInStore(hint)
    // ok=true means found + replyable. Also accept via when Store found the model.
    if (found?.ok || (found?.via && found.via !== 'not_found' && found.via !== 'not_in_store')) {
      let serialized = found.serialized || hint?.serialized || null
      if (!serialized && hint?.remote && hint?.stanza) {
        const fromMe = hint.fromMe ? 'true' : 'false'
        serialized = hint.participant
          ? `${fromMe}_${hint.remote}_${hint.stanza}_${hint.participant}`
          : `${fromMe}_${hint.remote}_${hint.stanza}`
      }
      debugLog('settle ok', { try: i + 1, via: found.via, serialized })
      return { ...hint, serialized, storeVia: found.via }
    }
    debugLog(`settle miss try=${i + 1}`, found?.reason || found?.via || 'unknown')
    await sleep(200 + i * 100)
  }
  debugLog('settle gave up', { msgId, stanza: hint?.stanza })
  return hint
}

/** WA Web / Puppeteer often throws EvaluationFailed with message "r" (minified). */
function formatErr(err) {
  if (err == null) return String(err)
  const msg = String(err.message || err.msg || '').trim()
  const name = String(err.name || err.constructor?.name || '').trim()
  const stackLine = String(err.stack || '')
    .split('\n')
    .map((l) => l.trim())
    .find((l) => l && !l.startsWith(name) && l !== msg)
  const parts = []
  if (name && name !== 'Error') parts.push(name)
  if (msg) parts.push(msg)
  else parts.push(String(err))
  if (stackLine && stackLine.length < 160) parts.push(stackLine)
  return parts.join(' | ')
}

async function handleInboundMessage(msg, source) {
  try {
    // Backfill id._serialized from id.$1 (WA Web rename) before any Store lookup.
    normalizeMsgSerialized(msg)
    const sid = messageSerializedId(msg)
    const fingerprint = `t:${msg.timestamp}|f:${msg.from}|a:${msg.author || ''}|b:${String(msg.body || '').slice(0, 120)}`
    const keys = [sid, fingerprint].filter(Boolean)
    if (keys.some((k) => seenIds.has(k))) return
    for (const k of keys) seenIds.add(k)
    if (seenIds.size > 800) {
      const first = seenIds.values().next().value
      seenIds.delete(first)
    }

    const fromRaw = String(msg.from || '')
    const text = normalizeMentionText(msg.body || '')
    const preview = text.slice(0, 80) || '(no text)'
    const isGroup = await detectIsGroup(msg, fromRaw)

    if (msg.fromMe) {
      // Learn LID from our own outbound identity (WA often uses @lid here).
      rememberBotIdentityFromId(msg.from)
      rememberBotIdentityFromId(msg.id?.participant)
      rememberBotOutboundId(msg)
      console.log(`[spike] skip fromMe (${source}) from=${fromRaw}: ${preview}`)
      return
    }
    if (msg.isStatus) {
      console.log(`[spike] skip status (${source})`)
      return
    }
    if (PRIVATE_CHATS_ONLY && isGroup) {
      console.log(`[spike] skip group (${source}) from=${fromRaw}: ${preview}`)
      return
    }
    if (
      fromRaw.endsWith('@newsletter')
      || fromRaw.endsWith('@broadcast')
      || fromRaw.includes('status@broadcast')
    ) {
      console.log(`[spike] skip non-chat (${source}) from=${fromRaw}`)
      return
    }

    const voiceKind = voiceMediaKind(msg)
    const imageKind = imageMediaKind(msg)
    if (!text && !voiceKind && !imageKind) {
      console.log(`[spike] skip empty body (${source}) from=${fromRaw}`)
      return
    }

    // In groups, author is the participant; from is the group JID.
    // Keep full JID (…@lid / …@c.us) so Laravel can mention correctly — digit-only
    // LIDs were mis-tagged as phones ("@+805 995…").
    const senderRaw = isGroup ? String(msg.author || msg.from || '') : fromRaw
    const from = senderRaw.includes('@')
      ? senderRaw
      : senderRaw.replace(/@.*/, '')
    const chatType = isGroup ? 'group' : 'private'
    const chatId = isGroup ? fromRaw : String(msg.from || fromRaw)
    const contact = await resolveSenderContact(msg, senderRaw)
    const fromName =
      contactDisplayName(contact, null)
      || msg._data?.notifyName
      || msg._data?.pushname
      || null
    const fromPhone = await resolveSenderPhoneDigits(contact, senderRaw)
    const quote = await resolveQuoteContext(msg)
    const mentioned = isBotMentioned(msg)
    const replyToBot = quote.reply_to_bot
    const command = isRecognizedCommand(text)
    const hasQuote = Boolean(quote.quoted_message_id || quote.quoted_text)
    const barePing = isBareBotPingText(text)
    // Private DMs: always answer.
    // Groups: @mention, swipe-reply to bot, /command, or bare @zak on a quote ("answer this").
    const shouldHandle = !isGroup
      || mentioned
      || replyToBot
      || command
      || (hasQuote && barePing)

    console.log(
      `[spike] inbound (${source}) chat=${chatType} from=${from}`
        + (fromPhone ? ` phone=${fromPhone}` : '')
        + ` mentioned=${mentioned} reply_to_bot=${replyToBot} command=${command}`
        + ` bare_ping=${barePing} has_quote=${hasQuote}`
        + ` raw=${fromRaw}: ${preview}`,
    )

    if (!shouldHandle) {
      console.log('[spike] silent (group needs @mention, reply-to-bot, quote+@zak, or /command)')
      return
    }

    const mentions = await resolveMentionedPeople(msg)

    // Do NOT skip typing for directed turns — members need composing feedback
    // during the Laravel/RAG wait. Undirected group chatter never reaches here.
    await ensureMessageId(msg, 600)
    // Settle BEFORE media download — message_create often races Store media keys
    // (Puppeteer EvaluationFailed "r" / empty download).
    await settleInboundMessage(msg)

    let mediaPayload = null
    let inboundText = text
    if (voiceKind) {
      mediaPayload = await downloadVoiceMedia(msg, voiceKind)
      if (!mediaPayload && !text) {
        console.log('[spike] voice download empty; telling member to resend / type')
        const stopTyping = startTypingHeartbeat(msg)
        try {
          await replyInContext(
            msg,
            "I couldn't download that voice note clearly.\n\n"
              + 'Could you send it once more, or type the question?',
            { isGroup, senderRaw, fromName, contact },
          )
        } finally {
          stopTyping()
        }
        return
      }
    } else if (imageKind) {
      mediaPayload = await downloadImageMedia(msg)
      if (!mediaPayload && !text) {
        console.log('[spike] image download empty; telling member to resend / type')
        const stopTyping = startTypingHeartbeat(msg)
        try {
          await replyInContext(
            msg,
            "I couldn't download that photo clearly.\n\n"
              + 'Could you send it once more, or type the question?',
            { isGroup, senderRaw, fromName, contact },
          )
        } finally {
          stopTyping()
        }
        return
      }
    } else if (
      quote.quoted_voice_kind
      && (barePing || mentioned || replyToBot)
    ) {
      // "@zak" (or reply) on a quoted voice note → transcribe THAT note, not ping-intro.
      let quotedTarget = quote.quoted_msg
      if (!quotedTarget && quote.quoted_message_id && typeof client?.getMessageById === 'function') {
        try {
          quotedTarget = await client.getMessageById(String(quote.quoted_message_id))
          console.log(
            `[spike] quoted voice reloaded via id=${quote.quoted_message_id} `
              + `ok=${Boolean(quotedTarget)}`,
          )
        } catch (err) {
          console.error('[spike] quoted voice reload failed:', formatErr(err))
        }
      }
      if (quotedTarget) {
        const kind = quote.quoted_voice_kind || voiceMediaKind(quotedTarget)
        console.log(`[spike] downloading quoted ${kind} for directed ask`)
        mediaPayload = await downloadVoiceMedia(quotedTarget, kind)
      } else {
        console.log(
          `[spike] quoted voice detected (kind=${quote.quoted_voice_kind}) `
            + 'but message object missing — cannot download',
        )
      }
      if (mediaPayload && barePing) {
        // Drop bare @zak so STT transcript becomes the real ask.
        inboundText = ''
      }
      if (!mediaPayload) {
        // Never send the "[voice note]" placeholder to Laravel as the question —
        // that RAG'd into "I don't have a solid answer" on WhatsApp.
        console.log('[spike] quoted voice download empty; clearing voice placeholder')
        quote.quoted_text = null
        if (barePing && !inboundText) {
          inboundText = ''
        }
      }
    } else if (
      quote.quoted_image_kind
      && (barePing || mentioned || replyToBot || Boolean(String(text || '').trim()))
    ) {
      let quotedTarget = quote.quoted_msg
      if (!quotedTarget && quote.quoted_message_id && typeof client?.getMessageById === 'function') {
        try {
          quotedTarget = await client.getMessageById(String(quote.quoted_message_id))
          console.log(
            `[spike] quoted image reloaded via id=${quote.quoted_message_id} `
              + `ok=${Boolean(quotedTarget)}`,
          )
        } catch (err) {
          console.error('[spike] quoted image reload failed:', formatErr(err))
        }
      }
      if (quotedTarget) {
        console.log('[spike] downloading quoted image for directed ask')
        mediaPayload = await downloadImageMedia(quotedTarget)
      } else {
        console.log(
          `[spike] quoted image detected (kind=${quote.quoted_image_kind}) `
            + 'but message object missing — cannot download',
        )
      }
      if (mediaPayload && barePing) {
        inboundText = ''
      }
      if (!mediaPayload) {
        console.log('[spike] quoted image download empty; clearing photo placeholder')
        quote.quoted_text = null
        if (barePing && !inboundText) {
          inboundText = ''
        }
      }
    }

    // Quoted voice directed at Zak but audio never arrived → honest failure, not fake ask.
    if (
      !mediaPayload
      && !voiceKind
      && quote.quoted_voice_kind
      && (barePing || mentioned || replyToBot)
      && !String(inboundText || '').trim()
    ) {
      console.log('[spike] quoted voice unavailable; telling member to resend')
      const stopTyping = startTypingHeartbeat(msg)
      try {
        await replyInContext(
          msg,
          "I couldn't download that voice note clearly.\n\n"
            + 'Could you send it once more as a voice reply to me, or type the question?',
          { isGroup, senderRaw, fromName, contact },
        )
      } finally {
        stopTyping()
      }
      return
    }

    if (
      !mediaPayload
      && !imageKind
      && quote.quoted_image_kind
      && (barePing || mentioned || replyToBot)
      && !String(inboundText || '').trim()
    ) {
      console.log('[spike] quoted image unavailable; telling member to resend')
      const stopTyping = startTypingHeartbeat(msg)
      try {
        await replyInContext(
          msg,
          "I couldn't download that photo clearly.\n\n"
            + 'Could you send it once more as a photo reply to me, or type the question?',
          { isGroup, senderRaw, fromName, contact },
        )
      } finally {
        stopTyping()
      }
      return
    }

    let inboundResult = { queued: false, reply: null }
    // Typing during the whole Laravel/RAG wait (not only after the reply arrives).
    // Queued mode: keep heartbeat until outbound /send (or 110s cap) — do not stop on 202.
    const stopTyping = startTypingHeartbeat(msg)
    try {
      const inbound = {
        from,
        text: inboundText,
        message_id: messageSerializedId(msg),
        chat_type: chatType,
        chat_id: chatId,
        is_group: isGroup,
        from_name: fromName,
        from_phone: fromPhone,
        bot_number: linkedBotNumber || null,
        bot_lid: linkedBotLid || null,
        // Quote + bare @zak counts as directed even when WA omitted mentionedIds.
        bot_mentioned: mentioned || replyToBot || (hasQuote && barePing) || barePing,
        reply_to_bot: replyToBot,
        quoted_text: quote.quoted_text,
        quoted_from: quote.quoted_from,
        quoted_message_id: quote.quoted_message_id,
        mentions,
      }
      if (mediaPayload) {
        inbound.media = mediaPayload
        if (quote.quoted_voice_kind && !voiceKind) {
          inbound.quoted_voice = true
        }
        if (quote.quoted_image_kind && !imageKind) {
          inbound.quoted_image = true
        }
      }
      inboundResult = await callLaravelInbound(inbound)
    } catch (err) {
      console.error('[spike] laravel inbound failed:', formatErr(err))
      stopTyping()
    }

    if (inboundResult.queued) {
      console.log('[spike] queued — typing stays on until worker outbound /send')
      return
    }

    const reply = inboundResult.reply
    if (reply) {
      let meta
      try {
        meta = await replyInContext(msg, reply, {
          isGroup,
          senderRaw,
          fromName,
          contact,
        })
      } finally {
        stopTyping()
      }
      console.log(
        `[spike] replied (${reply.length} chars)`
          + (meta.quoted ? ' quoted=yes' : ' quoted=no')
          + (isGroup
            ? (meta.mentioned ? ` mentioned=${meta.to}` : ' (no mention)')
            : ' private'),
      )
    } else {
      stopTyping()
      console.log('[spike] silent (no reply from Laravel - not for Zak / nothing to say)')
    }

  } catch (err) {
    console.error('[spike] message handler error:', err.message || err)
  }
}

client.on('message', (msg) => {
  void handleInboundMessage(msg, 'message')
})

// message_create is what many WA Web builds emit first (often without id).
// Keep it for inbound; ensureMessageId + seenIds fingerprint avoid double-send.
client.on('message_create', (msg) => {
  void handleInboundMessage(msg, 'message_create')
})

/**
 * Laravel → spike outbound (admin escalation DMs).
 * POST /send { secret, to, text }
 */
function startOutboundServer() {
  const server = http.createServer(async (req, res) => {
    const sendJson = (code, body) => {
      res.writeHead(code, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify(body))
    }

    if (req.method === 'GET' && (req.url === '/' || req.url === '/health')) {
      sendJson(200, { ok: true, ready: Boolean(client?.info) })
      return
    }

    if (req.method !== 'POST' || req.url !== '/send') {
      sendJson(404, { ok: false, error: 'not_found' })
      return
    }

    let raw = ''
    try {
      for await (const chunk of req) raw += chunk
      const body = JSON.parse(raw || '{}')
      if (!SPIKE_SECRET || body.secret !== SPIKE_SECRET) {
        sendJson(401, { ok: false, error: 'unauthorized' })
        return
      }
      const toRaw = String(body.to || '').trim()
      const textRaw = String(body.text || '').trim()
      if (!toRaw || !textRaw) {
        sendJson(422, { ok: false, error: 'to_and_text_required' })
        return
      }
      if (!client?.info) {
        sendJson(503, { ok: false, error: 'whatsapp_not_ready' })
        return
      }

      // Phone → @c.us; full JID (…@lid / …@c.us / …@g.us) used as-is.
      // Never turn a bare LID into @c.us (causes "No LID for user").
      let chatId
      if (toRaw.includes('@')) {
        chatId = toRaw
      } else {
        const digits = toRaw.replace(/\D+/g, '')
        if (!digits) {
          sendJson(422, { ok: false, error: 'invalid_to' })
          return
        }
        chatId = digits.length >= 14 ? `${digits}@lid` : `${digits}@c.us`
      }

      const mentionRaw = String(body.mention || '').trim()
      const mentionListRaw = Array.isArray(body.mentions) ? body.mentions : []
      let text = textRaw
      const opts = {}
      const mentionJids = []

      const pushMention = (raw) => {
        let jid = String(raw || '').trim()
        if (!jid) return null
        if (!jid.includes('@')) {
          const md = jid.replace(/\D+/g, '')
          if (!md) return null
          jid = md.length >= 14 ? `${md}@lid` : `${md}@c.us`
        }
        if (!mentionJids.includes(jid)) mentionJids.push(jid)
        return jid
      }

      const askerJid = mentionRaw ? pushMention(mentionRaw) : null
      for (const m of mentionListRaw) pushMention(m)

      // Structured admin cards already embed @tags (Name / Cc:). Do not prepend
      // another leading @asker — only ensure mentions[] includes every body tag.
      const isAdminCard = /Request ID:|Member ID:|\*Member requested/i.test(text)
      if (askerJid && !isAdminCard) {
        const mentionUser = idUserPart(askerJid)
        const tag = `@${(mentionUser || '').replace(/\D+/g, '') || mentionUser}`
        // Fix inverted "@id, Hi," from older clients / failed blends.
        text = text.replace(new RegExp(`^${tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*,\\s*Hi\\b[,]?`, 'i'), '')
        text = text.replace(/^(hey|hi|hello|howdy|yo)\b[ \t]*,?[ \t]*/i, '').trim()
        if (!text.includes(tag)) {
          const gap = text.startsWith('\n') ? '' : '\n\n'
          text = `${tag},${gap}${text}`.replace(/\n{3,}/g, '\n\n')
        }
      }
      // Also harvest any @digits already in the body (Cc:, Name:, etc.).
      for (const jid of mentionJidsFromText(text)) {
        pushMention(jid)
      }
      if (mentionJids.length > 0) {
        opts.mentions = mentionJids
      }
      const quotedId = String(body.quoted_message_id || '').trim()
      if (quotedId) {
        opts.quotedMessageId = quotedId
        // Do not silently drop swipe-quotes (wwebjs default ignoreQuoteErrors=true).
        opts.ignoreQuoteErrors = false
      }

      const sendOutbound = async (bodyText, sendOpts = {}) => {
        try {
          return await client.sendMessage(chatId, bodyText, sendOpts)
        } catch (err) {
          const chat = client?.getChatById ? await client.getChatById(chatId) : null
          if (!chat?.sendMessage) throw err
          return chat.sendMessage(bodyText, sendOpts)
        }
      }

      // Pulse composing once more, then clear the queued-mode heartbeat for this chat.
      await sendTypingToChat(chatId)

      let sent
      try {
        sent = await sendOutbound(text, opts)
      } catch (err) {
        // Group quote/mention can fail; retry same body without rich opts (keep @tag order).
        if (opts.quotedMessageId || opts.mentions) {
          console.error('[spike] outbound rich send failed, plain retry:', formatErr(err))
          sent = await sendOutbound(text)
        } else {
          throw err
        }
      } finally {
        stopTypingForChat(chatId)
      }
      rememberBotOutboundId(sent)
      const messageId = messageSerializedId(sent)
      console.log(`[spike] outbound ok to=${chatId} chars=${text.length} id=${messageId || 'none'}`)
      sendJson(200, { ok: true, to: chatId, message_id: messageId })
    } catch (err) {
      console.error('[spike] outbound failed:', err.message || err)
      sendJson(500, { ok: false, error: String(err.message || err) })
    }
  })

  server.listen(OUTBOUND_PORT, '0.0.0.0', () => {
    console.log(`[spike] Outbound HTTP on 0.0.0.0:${OUTBOUND_PORT} (POST /send for admin DMs)`)
  })
  server.on('error', (err) => {
    console.error('[spike] outbound server error:', err.message || err)
  })
}

startOutboundServer()

client.initialize().catch((err) => {
  console.error('[spike] fatal:', err)
  process.exit(1)
})

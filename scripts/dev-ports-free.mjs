// `npm run dev` guard: refuse to start when the API or public dev port is already taken, so a second Laravel or Vite
// server is never started (php artisan serve would otherwise move to another port). Usage: node this.mjs 8010 5180
import net from 'node:net'

const ports = process.argv.slice(2).map(Number)
const taken = await Promise.all(ports.map((port) => new Promise((resolve) => {
  const s = net.createServer().once('error', () => resolve(port)).once('listening', () => s.close(() => resolve(null)))
  s.listen(port, '127.0.0.1')
})))
const busy = taken.filter(Boolean)
if (busy.length) {
  console.error(`${busy.length > 1 ? 'Ports' : 'Port'} ${busy.join(' and ')} ${busy.length > 1 ? 'are' : 'is'} already in use: a dev server is probably running. Stop it first, or use other ports (API_PORT=8020 WEB_PORT=5190 npm run dev).`)
  process.exit(1)
}

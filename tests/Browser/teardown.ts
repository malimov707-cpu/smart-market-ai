import { rmSync, readFileSync, existsSync } from 'node:fs';
import { dirname } from 'node:path';
export default function teardown() {
    const path = process.env.SMART_MARKET_BROWSER_DB;
    if (path && existsSync(path+'.worker')) { try { process.kill(Number(readFileSync(path+'.worker','utf8')), 'SIGTERM'); } catch {} }
    if (path && dirname(path).split('/').at(-1)?.startsWith('smart-market-browser-')) rmSync(dirname(path), { recursive: true, force: true });
}

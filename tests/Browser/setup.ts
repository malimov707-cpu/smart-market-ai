import { writeFileSync } from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import { browserEnvironment } from '../../playwright.config';

export default function setup() {
    if (!browserEnvironment.DB_DATABASE.includes('smart-market-browser-')) throw new Error('Refusing a non-isolated browser database');
    writeFileSync(browserEnvironment.DB_DATABASE, '', { flag: 'wx' });
    for (const args of [['artisan', 'migrate', '--force', '--no-interaction'], ['artisan', 'db:seed', '--class=Tests\\Browser\\BrowserSeeder', '--force', '--no-interaction']]) {
        const result = spawnSync('php', args, { env: { ...process.env, ...browserEnvironment }, encoding: 'utf8' });
        if (result.status !== 0) throw new Error(`Browser fixture setup failed: ${result.stdout} ${result.stderr}`);
    }
    const worker = spawn('php', ['artisan','queue:work','database','--queue=imports','--sleep=1','--timeout=60','--tries=2','--max-time=300','--no-interaction'], {env:{...process.env,...browserEnvironment}, detached:true, stdio:'ignore'});
    writeFileSync(browserEnvironment.DB_DATABASE+'.worker', String(worker.pid));
    worker.unref();
}

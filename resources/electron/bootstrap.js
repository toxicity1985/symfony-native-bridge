'use strict';

/**
 * Bootstrap — démarre le serveur PHP avant de lancer l'app Electron.
 *
 * Ordre de résolution du binaire PHP :
 *  1. php-bin/php-{platform}  (binaire statique embarqué — prioritaire)
 *  2. SYMFONY_PHP_BINARY env var
 *  3. php dans le PATH système  (fallback dev)
 */

const { app, dialog } = require('electron');
const { spawn }       = require('child_process');
const net             = require('net');
const path            = require('path');
const fs              = require('fs');

const PHP_HOST = '127.0.0.1';
const PHP_PORT = parseInt(process.env.SYMFONY_PHP_PORT || '8765', 10);
const IPC_PORT = parseInt(process.env.SYMFONY_IPC_PORT || '9000', 10);

// Racine de l'app Symfony
// - En dev          : dossier parent de electron/ (la racine du projet)
// - En .AppImage    : process.resourcesPath/app  (extraResources)
const APP_ROOT    = app.isPackaged
    ? path.join(process.resourcesPath, 'app')
    : path.join(__dirname, '..');

const PUBLIC_DIR  = path.join(APP_ROOT, 'public');
const ROUTER_FILE = path.join(PUBLIC_DIR, 'index.php');
const PHP_BIN_DIR = path.join(APP_ROOT, 'php-bin');

// ---------------------------------------------------------------------------
// Résolution du binaire PHP embarqué
// ---------------------------------------------------------------------------

function getEmbeddedPhpName() {
    const arch  = process.arch;   // 'x64' | 'arm64'
    const plat  = process.platform; // 'linux' | 'darwin' | 'win32'

    const map = {
        'linux-x64':    'php-linux-x64',
        'linux-arm64':  'php-linux-arm64',
        'darwin-x64':   'php-macos-x64',
        'darwin-arm64': 'php-macos-arm64',
        'win32-x64':    'php-win-x64.exe',
    };

    return map[`${plat}-${arch}`] || null;
}

function findPhpBinary() {
    // 1. Binaire embarqué dans php-bin/
    const embeddedName = getEmbeddedPhpName();
    if (embeddedName) {
        const embeddedPath = path.join(PHP_BIN_DIR, embeddedName);
        if (fs.existsSync(embeddedPath)) {
            console.log(`[Bootstrap] Using embedded PHP: ${embeddedPath}`);
            return embeddedPath;
        }
    }

    // 2. Variable d'environnement explicite
    if (process.env.SYMFONY_PHP_BINARY && fs.existsSync(process.env.SYMFONY_PHP_BINARY)) {
        console.log(`[Bootstrap] Using SYMFONY_PHP_BINARY: ${process.env.SYMFONY_PHP_BINARY}`);
        return process.env.SYMFONY_PHP_BINARY;
    }

    // 3. PHP système (fallback dev)
    const candidates = ['php', '/usr/bin/php', '/usr/local/bin/php'];
    const { execSync } = require('child_process');

    for (const bin of candidates) {
        try {
            execSync(`"${bin}" -r "echo 1;"`, { stdio: 'ignore' });
            console.log(`[Bootstrap] Using system PHP: ${bin}`);
            return bin;
        } catch {}
    }

    return null;
}

// ---------------------------------------------------------------------------
// Attente TCP
// ---------------------------------------------------------------------------

function waitForPort(host, port, timeoutMs = 12000) {
    return new Promise((resolve, reject) => {
        const deadline = Date.now() + timeoutMs;

        function probe() {
            const sock = new net.Socket();
            sock.setTimeout(300);

            sock.connect(port, host, () => {
                sock.destroy();
                resolve();
            });

            sock.on('error', () => {
                sock.destroy();
                Date.now() < deadline ? setTimeout(probe, 200) : reject(new Error(`Port ${port} unreachable after ${timeoutMs}ms`));
            });

            sock.on('timeout', () => {
                sock.destroy();
                Date.now() < deadline ? setTimeout(probe, 200) : reject(new Error(`Timeout waiting for port ${port}`));
            });
        }

        probe();
    });
}

// ---------------------------------------------------------------------------
// Bootstrap principal
// ---------------------------------------------------------------------------

async function bootstrap() {
    const phpBin = findPhpBinary();

    if (!phpBin) {
        await app.whenReady();
        dialog.showErrorBox(
            'PHP not found',
            'PHP 8.2+ is required.\n\n' +
            'Either install PHP on your system, or run:\n' +
            '  php bin/console native:php-embed\n' +
            'to embed a static PHP binary in the package.'
        );
        app.exit(1);
        return;
    }

    // Vérifier que public/index.php existe
    if (!fs.existsSync(ROUTER_FILE)) {
        await app.whenReady();
        dialog.showErrorBox(
            'App not found',
            `Cannot find ${ROUTER_FILE}\n\nMake sure the Symfony public/ directory is included in the package.`
        );
        app.exit(1);
        return;
    }

    // Injecter les variables pour main.js et IpcBridge
    process.env.SYMFONY_SERVER_URL      = `http://${PHP_HOST}:${PHP_PORT}`;
    process.env.SYMFONY_IPC_PORT        = String(IPC_PORT);
    process.env.APP_ENV                 = process.env.APP_ENV || 'prod';
    process.env.APP_DEBUG               = '0';
    // Permet au Kernel PHP de détecter qu'il tourne dans une app packagée
    // → redirige cache/ et log/ vers un dossier accessible en écriture
    process.env.ELECTRON_APP_PACKAGED   = app.isPackaged ? '1' : '0';

    console.log(`[Bootstrap] PHP       : ${phpBin}`);
    console.log(`[Bootstrap] App root  : ${APP_ROOT}`);
    console.log(`[Bootstrap] Public dir: ${PUBLIC_DIR}`);
    console.log(`[Bootstrap] PHP port  : ${PHP_PORT}`);
    console.log(`[Bootstrap] IPC port  : ${IPC_PORT}`);

    // Démarrer PHP built-in server
    const phpServer = spawn(phpBin, [
        '-S', `${PHP_HOST}:${PHP_PORT}`,
        '-t', PUBLIC_DIR,
        ROUTER_FILE,
    ], {
        env: { ...process.env },
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    phpServer.stdout.on('data', d => console.log(`[PHP] ${d.toString().trim()}`));
    phpServer.stderr.on('data', d => console.log(`[PHP] ${d.toString().trim()}`));

    phpServer.on('error', err => console.error(`[Bootstrap] PHP error: ${err.message}`));

    phpServer.on('exit', code => {
        console.log(`[Bootstrap] PHP exited (code ${code})`);
        if (code !== 0 && code !== null) app.quit();
    });

    // Tuer PHP proprement quand Electron quitte
    app.on('will-quit', () => {
        console.log('[Bootstrap] Stopping PHP…');
        phpServer.kill('SIGTERM');
    });

    // Attendre que PHP réponde
    console.log(`[Bootstrap] Waiting for PHP on :${PHP_PORT}…`);
    try {
        await waitForPort(PHP_HOST, PHP_PORT, 12000);
        console.log(`[Bootstrap] PHP ready ✓`);
    } catch (err) {
        console.error(`[Bootstrap] ${err.message}`);
        await app.whenReady();
        dialog.showErrorBox('PHP failed to start', err.message + '\n\nCheck that the PHP binary is valid for your system.');
        phpServer.kill();
        app.exit(1);
        return;
    }

    // PHP est prêt — charger l'app Electron
    require('./main.js');
}

bootstrap().catch(err => {
    console.error('[Bootstrap] Fatal:', err);
    app.exit(1);
});
'use strict';

const { app, BrowserWindow, Tray, Menu, Notification, dialog, shell, nativeImage } = require('electron');
const { WebSocketServer, WebSocket } = require('ws');
const path = require('path');
const Store = require('electron-store').default;

// ---------------------------------------------------------------------------
// Configuration (injectée par PHP)
const SERVER_URL  = process.env.SYMFONY_SERVER_URL || 'http://127.0.0.1:8765';
const IPC_PORT    = parseInt(process.env.SYMFONY_IPC_PORT || '9000', 10);
const IPC_TOKEN   = process.env.SYMFONY_IPC_TOKEN || null; // null = auth disabled
const APP_NAME    = process.env.SYMFONY_APP_NAME || 'Symfony Native App';

app.setName(APP_NAME);

// ---------------------------------------------------------------------------
// State
const windows = new Map();
const trays   = new Map();
const store   = new Store();

let phpSocket    = null; // connexion principale PHP ↔ Electron
const allClients = new Set(); // tous les clients connectés
let wss          = null;

// ---------------------------------------------------------------------------
// IPC Server
function startIpcServer() {
  wss = new WebSocketServer({ port: IPC_PORT, host: '127.0.0.1' });

  wss.on('error', (err) => {
    console.error(`[IPC] WebSocket server error: ${err.message}`);
    if (err.code === 'EADDRINUSE') {
      console.error(`[IPC] Port ${IPC_PORT} already in use.`);
      app.exit(1);
    }
  });

  wss.on('connection', (ws, req) => {
    // Token validation — reject connections that don't present the correct secret.
    // IPC_TOKEN is null when native:serve is run without token injection (dev fallback).
    if (IPC_TOKEN !== null) {
      const params = new URLSearchParams(req.url.replace(/^[^?]*/, ''));
      const clientToken = params.get('token');
      if (clientToken !== IPC_TOKEN) {
        console.warn('[IPC] Rejected connection: invalid or missing token');
        ws.close(4401, 'Unauthorized');
        return;
      }
    }

    allClients.add(ws);
    console.log(`[IPC] Client connected (total: ${allClients.size})`);

    // Le premier client est le PHP principal
    if (phpSocket === null) {
      phpSocket = ws;
      ws.send(JSON.stringify({ event: 'ipc.ready' }));
      console.log(`[IPC] PHP main client on port ${IPC_PORT}`);
    } else {
      console.log(`[IPC] Hot-reload client connected`);
    }

    ws.on('message', (raw) => {
      let msg;
      try { msg = JSON.parse(raw); } catch { return; }
      handleAction(msg, ws);
    });

    ws.on('close', () => {
      allClients.delete(ws);
      if (ws === phpSocket) {
        phpSocket = null;
        console.log('[IPC] PHP main client disconnected');
      } else {
        console.log('[IPC] Hot-reload client disconnected');
      }
    });
  });

  console.log(`[IPC] Listening on ws://127.0.0.1:${IPC_PORT}/ipc`);
}

// ---------------------------------------------------------------------------
// Helpers
function pushEvent(eventName, payload = {}) {
  if (!phpSocket || phpSocket.readyState !== WebSocket.OPEN) return;
  phpSocket.send(JSON.stringify({ event: eventName, payload }));
}

function reply(ws, id, result) {
  ws.send(JSON.stringify({ id, ok: true, result }));
}

function replyError(ws, id, error) {
  ws.send(JSON.stringify({ id, ok: false, error: String(error) }));
}

// ---------------------------------------------------------------------------
// Menu builder (recursif pour les sous-menus)
function buildMenu(items, trayId) {
  return Menu.buildFromTemplate(items.map(item => {
    if (item.type === 'separator') return { type: 'separator' };

    const entry = {
      label:       item.label,
      enabled:     item.enabled  ?? true,
      visible:     item.visible  ?? true,
      type:        item.type     || 'normal',
      checked:     item.checked  ?? false,
      accelerator: item.accelerator,
    };

    if (item.submenu?.length) {
      entry.submenu = buildMenu(item.submenu, trayId);
    }

    if (item.id) {
      entry.click = () => pushEvent('tray.menu_item_clicked', { trayId, menuItemId: item.id });
    }

    if (item.role) {
      entry.role = item.role;
    }

    return entry;
  }));
}

// ---------------------------------------------------------------------------
// Action dispatcher
async function handleAction(msg, ws) {
  const { id, action, payload = {} } = msg;

  try {
    switch (action) {

        // ── Window ────────────────────────────────────────────────────────────

      case 'window.open': {
        const windowId = `win_${Date.now()}`;
        const win = new BrowserWindow({
          width:           payload.width          ?? 1200,
          height:          payload.height         ?? 800,
          minWidth:        payload.minWidth        ?? 400,
          minHeight:       payload.minHeight       ?? 300,
          title:           payload.title          || APP_NAME,
          resizable:       payload.resizable      ?? true,
          fullscreen:      payload.fullscreen     ?? false,
          frame:           payload.frame          ?? true,
          transparent:     payload.transparent    ?? false,
          backgroundColor: payload.backgroundColor ?? '#ffffff',
          alwaysOnTop:     payload.alwaysOnTop    ?? false,
          autoHideMenuBar: payload.autoHideMenuBar ?? false,
          webPreferences:  { nodeIntegration: false, contextIsolation: true },
        });

        win.loadURL(payload.url || SERVER_URL);
        windows.set(windowId, win);

        win.on('focus',  () => pushEvent('window.focused',  { windowId }));
        win.on('blur',   () => pushEvent('window.blurred',  { windowId }));
        win.on('closed', () => { windows.delete(windowId); pushEvent('window.closed', { windowId }); });
        win.on('resize', () => { const [w, h] = win.getSize(); pushEvent('window.resized', { windowId, width: w, height: h }); });
        win.on('move',   () => { const [x, y] = win.getPosition(); pushEvent('window.moved', { windowId, x, y }); });

        reply(ws, id, windowId);
        break;
      }

      case 'window.close':
        windows.get(payload.windowId)?.close();
        reply(ws, id, null);
        break;

      case 'window.setTitle':
        windows.get(payload.windowId)?.setTitle(payload.title);
        reply(ws, id, null);
        break;

      case 'window.resize':
        windows.get(payload.windowId)?.setSize(payload.width, payload.height);
        reply(ws, id, null);
        break;

      case 'window.minimize':
        windows.get(payload.windowId)?.minimize();
        reply(ws, id, null);
        break;

      case 'window.maximize':
        windows.get(payload.windowId)?.maximize();
        reply(ws, id, null);
        break;

      case 'window.focus':
        windows.get(payload.windowId)?.focus();
        reply(ws, id, null);
        break;

      case 'window.loadUrl':
        windows.get(payload.windowId)?.loadURL(payload.url);
        reply(ws, id, null);
        break;

      case 'window.setFullscreen':
        windows.get(payload.windowId)?.setFullScreen(payload.fullscreen);
        reply(ws, id, null);
        break;

      case 'window.list':
        reply(ws, id, Array.from(windows.keys()));
        break;

      case 'window.reloadAll':
        // Hot-reload: attendre 150ms que PHP ait fini de traiter
        // avant de recharger pour éviter un flash de page blanche
        setTimeout(() => {
          BrowserWindow.getAllWindows().forEach(win => {
            if (!win.isDestroyed()) win.webContents.reload();
          });
        }, 150);
        reply(ws, id, null);
        break;

        // ── Tray ──────────────────────────────────────────────────────────────

      case 'tray.create': {
        const trayId = `tray_${Date.now()}`;

        const icon = payload.icon && require('fs').existsSync(payload.icon)
            ? nativeImage.createFromPath(payload.icon)
            : nativeImage.createEmpty();

        const tray = new Tray(icon);
        tray.setToolTip(payload.tooltip || APP_NAME);

        tray.on('click',        () => pushEvent('tray.clicked', { trayId, button: 'left' }));
        tray.on('right-click',  () => pushEvent('tray.clicked', { trayId, button: 'right' }));
        tray.on('double-click', () => pushEvent('tray.clicked', { trayId, button: 'double' }));

        trays.set(trayId, tray);
        reply(ws, id, trayId);
        break;
      }

      case 'tray.setMenu': {
        const tray = trays.get(payload.trayId);
        if (!tray) { replyError(ws, id, `Tray not found: ${payload.trayId}`); break; }
        tray.setContextMenu(buildMenu(payload.items || [], payload.trayId));
        reply(ws, id, null);
        break;
      }

      case 'tray.setTooltip':
        trays.get(payload.trayId)?.setToolTip(payload.tooltip);
        reply(ws, id, null);
        break;

      case 'tray.destroy':
        trays.get(payload.trayId)?.destroy();
        trays.delete(payload.trayId);
        reply(ws, id, null);
        break;

      case 'tray.list':
        reply(ws, id, Array.from(trays.keys()));
        break;

        // ── Notifications ─────────────────────────────────────────────────────

      case 'notification.send': {
        const n = new Notification({
          title:  payload.title || '',
          body:   payload.body  || '',
          silent: payload.sound === false,
          ...(payload.icon ? { icon: payload.icon } : {}),
        });
        n.on('click', () => pushEvent('notification.clicked', { title: payload.title }));
        n.show();
        reply(ws, id, null);
        break;
      }

        // ── Dialogs ───────────────────────────────────────────────────────────

      case 'dialog.showOpenDialog': {
        const r = await dialog.showOpenDialog({
          title:       payload.title,
          defaultPath: payload.defaultPath,
          filters:     payload.filters     || [],
          properties:  payload.properties  || ['openFile'],
          buttonLabel: payload.buttonLabel,
        });
        reply(ws, id, r);
        break;
      }

      case 'dialog.showSaveDialog': {
        const r = await dialog.showSaveDialog({
          title:       payload.title,
          defaultPath: payload.defaultPath,
          filters:     payload.filters  || [],
          buttonLabel: payload.buttonLabel,
        });
        reply(ws, id, r);
        break;
      }

      case 'dialog.showMessageBox': {
        const r = await dialog.showMessageBox({
          type:    payload.type    || 'info',
          title:   payload.title   || '',
          message: payload.message || '',
          buttons: payload.buttons || ['OK'],
        });
        reply(ws, id, r);
        break;
      }

        // ── App & Shell ───────────────────────────────────────────────────────

      case 'app.quit':
        reply(ws, id, null);
        app.quit();
        break;

      case 'app.relaunch':
        reply(ws, id, null);
        app.relaunch();
        app.quit();
        break;

      case 'app.cancelQuit':
        // no-op — quit was prevented by PHP
        break;

      case 'app.getPath':
        reply(ws, id, app.getPath(payload.name));
        break;

      case 'shell.openExternal':
        await shell.openExternal(payload.url);
        reply(ws, id, null);
        break;

      case 'shell.openPath':
        await shell.openPath(payload.path);
        reply(ws, id, null);
        break;

      case 'shell.trashItem':
        await shell.trashItem(payload.path);
        reply(ws, id, null);
        break;

      case 'shell.showItemInFolder':
        shell.showItemInFolder(payload.path);
        reply(ws, id, null);
        break;

        // ── Store ─────────────────────────────────────────────────────────────

        // ── Protocol Handler ──────────────────────────────────────────────────

      case 'protocol.register':
        app.setAsDefaultProtocolClient(payload.scheme);
        reply(ws, id, null);
        break;

      case 'protocol.unregister':
        app.removeAsDefaultProtocolClient(payload.scheme);
        reply(ws, id, null);
        break;

        // ── Store ─────────────────────────────────────────────────────────────

      case 'store.get':
        reply(ws, id, store.get(payload.key, null));
        break;

      case 'store.set':
        store.set(payload.key, payload.value);
        reply(ws, id, null);
        break;

      case 'store.delete':
        store.delete(payload.key);
        reply(ws, id, null);
        break;

        // ── Default ───────────────────────────────────────────────────────────

      default:
        replyError(ws, id, `Unknown action: ${action}`);
    }
  } catch (err) {
    replyError(ws, id, err.message);
  }
}

// ---------------------------------------------------------------------------
// Deep-link / Protocol handler
//
// On Windows & Linux a second instance is launched with the URL in argv.
// requestSingleInstanceLock() ensures only one instance runs; the second
// instance passes its argv to the first via the 'second-instance' event.
//
// On macOS the OS sends 'open-url' directly to the running instance.

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  // Another instance is already running — it will receive our argv via
  // 'second-instance'. Exit this extra instance immediately.
  app.quit();
}

app.on('second-instance', (_event, argv) => {
  // Windows / Linux: the deep-link URL is the last argument that contains '://'
  const url = argv.find(arg => arg.includes('://'));
  if (url) pushEvent('protocol.deep_link', { url });

  // Bring the main window to the front
  const win = windows.get('main') || BrowserWindow.getAllWindows()[0];
  if (win) { if (win.isMinimized()) win.restore(); win.focus(); }
});

app.on('open-url', (event, url) => {
  // macOS: fired when the OS routes a custom-scheme URL to this app
  event.preventDefault();
  pushEvent('protocol.deep_link', { url });
});

// ---------------------------------------------------------------------------
// App lifecycle
app.whenReady().then(() => {
  console.log('Electron ready');
  startIpcServer();

  const mainWin = new BrowserWindow({
    width:  1200,
    height: 800,
    title:  APP_NAME,
    webPreferences: { nodeIntegration: false, contextIsolation: true },
  });

  mainWin.loadURL(SERVER_URL);
  windows.set('main', mainWin);

  setTimeout(() => pushEvent('app.ready'), 100);

  mainWin.on('focus',  () => pushEvent('window.focused',  { windowId: 'main' }));
  mainWin.on('blur',   () => pushEvent('window.blurred',  { windowId: 'main' }));
  mainWin.on('closed', () => { windows.delete('main'); pushEvent('window.closed', { windowId: 'main' }); });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

app.on('activate', () => {
  pushEvent('app.activated', { hasVisibleWindows: BrowserWindow.getAllWindows().length > 0 });
  if (BrowserWindow.getAllWindows().length === 0) {
    const win = new BrowserWindow({ width: 1200, height: 800, title: APP_NAME });
    win.loadURL(SERVER_URL);
    windows.set('main', win);
  }
});

app.on('before-quit', (e) => {
  if (!phpSocket) return;
  e.preventDefault();
  pushEvent('app.before_quit');
  setTimeout(() => app.exit(0), 3000);
});
<?php

/**
 * Stock (unpatched) copies of the NativePHP files the patch set rewrites.
 *
 * They live here rather than in one of the test files because Pest runs the
 * suite in parallel workers: a fixture defined in another test file is not
 * loaded in the worker that needs it.
 */
function stockPreload(): string
{
    return <<<'JS'
import remote from "@electron/remote";
import { ipcRenderer, contextBridge } from "electron";
const Native = {
    on: (event, callback) => {
        ipcRenderer.on('native-event', (_, data) => {
            event = event.replace(/^(\\)+/, '');
            data.event = data.event.replace(/^(\\)+/, '');
            if (event === data.event) {
                return callback(data.payload, event);
            }
        });
    },
    contextMenu: (template) => {
        let menu = remote.Menu.buildFromTemplate(template);
        menu.popup({ window: remote.getCurrentWindow() });
    }
};
contextBridge.exposeInMainWorld('Native', Native);
JS;
}

function stockWindowApi(): string
{
    return <<<'JS'
import express from 'express';
import { BrowserWindow } from 'electron';
import state from '../state.js';
const router = express.Router();
router.post('/open', (req, res) => {
    const window = new BrowserWindow({ show: false,
        backgroundColor, transparent: transparency, alwaysOnTop,
    });
    window.loadURL(url);
    window.webContents.on('dom-ready', () => {
        window.webContents.setZoomFactor(parseFloat(zoomFactor));
    });
    window.webContents.on('did-finish-load', () => {
        if (state.noFocusOnRestart && window.isVisible()) {
            return;
        }
        window.show();
    });
    window.webContents.on('did-fail-load', (event) => {
        console.error('failed to open window...', event);
    });
    state.windows[id] = window;
    res.sendStatus(200);
});
export default router;
JS;
}

function stockServer(): string
{
    return <<<'JS'
mkdirpSync(join(storagePath, 'framework', 'sessions'));
mkdirpSync(join(storagePath, 'framework', 'views'));
mkdirpSync(join(storagePath, 'framework', 'testing'));
function retrievePhpIniSettings() {
    return __awaiter(this, void 0, void 0, function* () {
        let command = ['artisan', 'native:php-ini'];
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        return yield promisify(execFile)(state.php, command, phpOptions);
    });
}
function retrieveNativePHPConfig() {
    return __awaiter(this, void 0, void 0, function* () {
        let command = ['artisan', 'native:config'];
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        return yield promisify(execFile)(state.php, command, phpOptions);
    });
}
        if (env.NIGHTWATCH_INGEST_URI && phpNightWatchPort) {
            console.log('Starting Nightwatch server...');
        }
        if (shouldOptimize(store)) {
            console.log('Caching view and routes...');
            let result = callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings);
            if (result.status !== 0) {
                console.error('Failed to cache view and routes:', result.stderr.toString());
            }
            else {
                store.set('optimized_version', app.getVersion());
            }
        }
        if (shouldMigrateDatabase(store)) {
            console.log('Migrating database...');
        }
JS;
}

function stockIndex(): string
{
    return <<<'JS'
import electronUpdater from 'electron-updater';
class App {
    loadConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            let config = {};
            try {
                const result = yield retrieveNativePHPConfig();
                config = JSON.parse(result.stdout);
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
    loadPhpIni() {
        return __awaiter(this, void 0, void 0, function* () {
            let config = {};
            try {
                const result = yield retrievePhpIniSettings();
                config = JSON.parse(result.stdout);
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
}
JS;
}

function stockIndexForSplash(): string
{
    return <<<'JS'
import { app, session, powerMonitor } from "electron";
import electronUpdater from 'electron-updater';
const { autoUpdater } = electronUpdater;
class NativePHP {
    registerListeners(app) {
        app.on("browser-window-created", (_, window) => {
            optimizer.watchWindowShortcuts(window);
        });
    }
    bootstrapApp(app) {
        return __awaiter(this, void 0, void 0, function* () {
            yield app.whenReady();
            const config = yield this.loadConfig();
            yield this.startPhpApp();
            yield notifyLaravel("booted");
        });
    }
}
JS;
}

// main.js (Electron main process)
const { app, BrowserWindow, Menu } = require('electron');
const { spawn } = require('child_process');
const path = require('path');

let phpServer;

function startPhpServer() {
    const phpPath = 'C:\\xampp\\php\\php.exe'; // XAMPP's actual PHP location
    const docRoot = path.join(__dirname, '..', 'hopeline'); // adjust to wherever your hopeline folder actually is relative to this Electron project
    phpServer = spawn(phpPath, ['-S', 'localhost:8000', '-t', docRoot]);
}

function createWindow() {
    const win = new BrowserWindow({ width: 1280, height: 800 });
    win.loadURL('http://localhost:8000/index.php');
}

app.whenReady().then(() => {
    Menu.setApplicationMenu(null); 
    startPhpServer();
    createWindow();
});

app.on('window-all-closed', () => {
    if (phpServer) phpServer.kill();
    app.quit();
});
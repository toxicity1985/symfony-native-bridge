// src-tauri/src/main.rs
//
// Symfony Native Bridge — Tauri sidecar
//
// This Rust application:
//  1. Reads SYMFONY_SERVER_URL and SYMFONY_IPC_PIPE from the environment
//  2. Opens a WebView window pointing at the Symfony server
//  3. Exposes a named-pipe IPC server that speaks the same JSON protocol
//     as the Electron main.js, so PHP can drive windows, tray, dialogs, etc.

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::io::{BufRead, BufReader, Write};
use std::sync::{Arc, Mutex};
use tauri::{
    AppHandle, Manager, SystemTray, SystemTrayEvent, SystemTrayMenu,
    SystemTrayMenuItem,
};

mod ipc_server;
mod actions;

fn main() {
    let server_url = std::env::var("SYMFONY_SERVER_URL")
        .unwrap_or_else(|_| "http://127.0.0.1:8765".to_string());

    let pipe_path = std::env::var("SYMFONY_IPC_PIPE")
        .unwrap_or_else(|_| {
            #[cfg(windows)]
            { r"\\.\pipe\symfony-native-bridge".to_string() }
            #[cfg(not(windows))]
            { format!("{}/symfony-native-bridge.sock", std::env::temp_dir().display()) }
        });

    // ── System tray ────────────────────────────────────────────────────────
    let tray_menu = SystemTrayMenu::new()
        .add_item(tauri::CustomMenuItem::new("open".to_string(), "Open"))
        .add_native_item(SystemTrayMenuItem::Separator)
        .add_item(tauri::CustomMenuItem::new("quit".to_string(), "Quit"));

    let tray = SystemTray::new().with_menu(tray_menu);

    // ── App ────────────────────────────────────────────────────────────────
    tauri::Builder::default()
        .system_tray(tray)
        .on_system_tray_event(move |app, event| match event {
            SystemTrayEvent::LeftClick { .. } => {
                ipc_server::push_event("tray.clicked", serde_json::json!({
                    "trayId": "default",
                    "button": "left"
                }));
            }
            SystemTrayEvent::MenuItemClick { id, .. } => {
                ipc_server::push_event("tray.menu_item_clicked", serde_json::json!({
                    "trayId": "default",
                    "menuItemId": id
                }));
            }
            _ => {}
        })
        .setup(move |app| {
            // Start the IPC pipe server in a background thread
            let pipe_path_clone = pipe_path.clone();
            let app_handle = app.handle();
            std::thread::spawn(move || {
                ipc_server::run(pipe_path_clone, app_handle);
            });

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}

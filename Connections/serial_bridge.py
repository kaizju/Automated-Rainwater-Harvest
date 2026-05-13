#!/usr/bin/env python3
"""
=============================================================
  serial_bridge.py — HC-SR04 Arduino → EcoRain MySQL Bridge
  Reads JSON from Arduino Serial, POSTs to EcoRain API
=============================================================
  Requirements:
    pip install pyserial requests

  Usage:
    python serial_bridge.py
    python serial_bridge.py --port COM3
    python serial_bridge.py --port COM3 --key your_api_key

  Config:
    Edit the CONFIG block below OR pass CLI arguments.
=============================================================
"""

import serial
import serial.tools.list_ports
import json
import requests
import time
import sys
import argparse
from datetime import datetime

# ── CONFIG ─────────────────────────────────────────────────
# Edit these to match your EcoRain installation:
CONFIG = {
    "port":       "AUTO",       # Serial port: AUTO, COM3, /dev/ttyUSB0, etc.
    "baud":       9600,
    "api_url":    "http://localhost/Automated-RainWater-Harvest/api/store.php",
    "api_key":    "ecorain_iot_secret_2025",  # Must match sensor's api_key in DB
    "retry_secs": 5,
    "verbose":    True,
    "timeout":    5,            # HTTP request timeout seconds
}
# ───────────────────────────────────────────────────────────


def parse_args():
    parser = argparse.ArgumentParser(description="EcoRain HC-SR04 Serial Bridge")
    parser.add_argument("--port", help="Serial port (overrides CONFIG)")
    parser.add_argument("--key",  help="API key (overrides CONFIG)")
    parser.add_argument("--url",  help="API URL (overrides CONFIG)")
    parser.add_argument("--baud", type=int, help="Baud rate (overrides CONFIG)")
    parser.add_argument("--quiet", action="store_true", help="Suppress verbose output")
    return parser.parse_args()


def find_arduino_port():
    """Auto-detect the Arduino / CH340 / FTDI serial port."""
    ports = serial.tools.list_ports.comports()
    keywords = ["arduino", "ch340", "ch341", "cp210", "cp2102", "ftdi", "usb serial", "usb-serial"]
    for p in ports:
        desc = (p.description or "").lower()
        if any(k in desc for k in keywords):
            log(f"Auto-detected: {p.device} — {p.description}")
            return p.device
    if ports:
        log(f"No Arduino keyword matched; using first available port: {ports[0].device}", "WARN")
        return ports[0].device
    return None


def log(msg, level="INFO"):
    if not CONFIG.get("verbose") and level == "INFO":
        return
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] [{level}] {msg}", flush=True)


def send_to_api(payload: dict):
    """POST sensor reading to EcoRain API."""
    payload["api_key"] = CONFIG["api_key"]
    try:
        resp = requests.post(
            CONFIG["api_url"],
            json=payload,
            timeout=CONFIG["timeout"],
        )
        if resp.status_code == 200:
            result = resp.json()
            if CONFIG["verbose"]:
                tank  = result.get("tank_id", "—")
                pct   = result.get("pct",     "—")
                alert = result.get("alert",   "NONE")
                log(f"✓ Saved → Tank #{tank} | {pct}% | Alert: {alert}")
        else:
            log(f"API HTTP {resp.status_code}: {resp.text[:120]}", "WARN")
    except requests.exceptions.ConnectionError:
        log("API unreachable — is XAMPP/Apache running?", "ERROR")
    except requests.exceptions.Timeout:
        log("API request timed out", "WARN")
    except Exception as e:
        log(f"API error: {e}", "ERROR")


def run():
    # Apply CLI overrides
    args = parse_args()
    if args.port:  CONFIG["port"]    = args.port
    if args.key:   CONFIG["api_key"] = args.key
    if args.url:   CONFIG["api_url"] = args.url
    if args.baud:  CONFIG["baud"]    = args.baud
    if args.quiet: CONFIG["verbose"] = False

    port = CONFIG["port"]
    if port == "AUTO":
        port = find_arduino_port()
        if not port:
            log("No Arduino found. Connect your device and retry.", "ERROR")
            log("Available ports:")
            for p in serial.tools.list_ports.comports():
                log(f"  {p.device} — {p.description}")
            sys.exit(1)

    log(f"EcoRain IoT Bridge starting…")
    log(f"  Port    : {port} @ {CONFIG['baud']} baud")
    log(f"  API     : {CONFIG['api_url']}")
    log(f"  API key : {CONFIG['api_key'][:8]}…")

    while True:
        try:
            log(f"Connecting to {port}…")
            with serial.Serial(port, CONFIG["baud"], timeout=3) as ser:
                log("Serial connected. Waiting for sensor data…")
                while True:
                    raw = ser.readline().decode("utf-8", errors="ignore").strip()
                    if not raw:
                        continue

                    if CONFIG["verbose"]:
                        log(f"← {raw}")

                    # Skip non-JSON lines (startup messages etc.)
                    if not raw.startswith("{"):
                        continue

                    try:
                        data = json.loads(raw)
                    except json.JSONDecodeError:
                        log(f"Non-JSON skipped: {raw[:60]}", "WARN")
                        continue

                    msg_type = data.get("type", "reading")

                    if msg_type == "boot":
                        log(f"Arduino booted: {data.get('msg', '')}")
                        # Notify API of boot
                        try:
                            requests.post(
                                CONFIG["api_url"],
                                json={"type": "boot", "api_key": CONFIG["api_key"]},
                                timeout=CONFIG["timeout"],
                            )
                        except Exception:
                            pass

                    elif msg_type == "reading":
                        send_to_api(data)

                    elif msg_type == "error":
                        log(f"Arduino error: {data.get('msg', '')}", "WARN")
                        send_to_api({"type": "error", "msg": data.get("msg", ""),
                                     "api_key": CONFIG["api_key"]})

        except serial.SerialException as e:
            log(f"Serial error: {e}. Retrying in {CONFIG['retry_secs']}s…", "ERROR")
            time.sleep(CONFIG["retry_secs"])
        except KeyboardInterrupt:
            log("Stopped by user.")
            break


if __name__ == "__main__":
    run()
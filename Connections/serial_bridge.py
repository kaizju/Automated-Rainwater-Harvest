import serial, requests, time, json, sys

CONFIG = {
    "port":    "COM3",        # change if your port is different
    "baud":    9600,
    "api_url": "http://localhost/Automated-RainWater-Harvest/others/store.php",
}

def connect():
    while True:
        try:
            ser = serial.Serial(CONFIG["port"], CONFIG["baud"], timeout=2)
            print(f"[OK] Connected to {CONFIG['port']}")
            time.sleep(2)
            return ser
        except Exception as e:
            print(f"[ERR] Cannot open port: {e}. Retrying in 5s...")
            time.sleep(5)

def send(payload):
    try:
        r    = requests.post(CONFIG["api_url"], json=payload, timeout=5)
        data = r.json()

        if r.status_code == 200:
            print(f"  [OK] {payload['api_key'][:15]}... | "
                  f"pct:{payload['pct']}% | "
                  f"tank_id:{data.get('tank_id')} | "
                  f"HTTP 200")
        else:
            print(f"  [ERR {r.status_code}] {data.get('error','unknown')} "
                  f"| key:{payload['api_key']}")

    except requests.exceptions.JSONDecodeError:
        print(f"  [ERR] Non-JSON response: {r.text[:200]}")
    except Exception as e:
        print(f"  [ERR] {e}")

def main():
    print("EcoRain 3-Sensor Bridge starting...")
    ser = connect()

    while True:
        try:
            line = ser.readline().decode("utf-8", errors="ignore").strip()
            if not line:
                continue

            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                print(f"  [SKIP] Not JSON: {line}")
                continue

            # Skip boot/info messages
            if obj.get("type") != "reading":
                print(f"  [INFO] {obj.get('msg', line)}")
                continue

            # Must have api_key and pct
            if "api_key" not in obj or "pct" not in obj:
                print(f"  [SKIP] Missing fields: {line}")
                continue

            send(obj)

        except serial.SerialException:
            print("[ERR] Serial disconnected. Reconnecting...")
            try: ser.close()
            except: pass
            ser = connect()

        except KeyboardInterrupt:
            print("\n[STOP] Bridge stopped.")
            try: ser.close()
            except: pass
            sys.exit(0)

if __name__ == "__main__":
    main()
# connections/serial_bridge.py
import serial, requests, time, json, sys

CONFIG = {
    "port":    "COM3",
    "baud":    9600,
    "api_url": "http://localhost/Automated-RainWater-Harvest/others/store.php",
    "api_key": "ecr_libona_sensor_001",  # ← must match DB exactly
}

def connect():
    while True:
        try:
            ser = serial.Serial(CONFIG["port"], CONFIG["baud"], timeout=2)
            print(f"[OK] Connected to {CONFIG['port']}")
            time.sleep(2)  # wait for Arduino to reset
            return ser
        except Exception as e:
            print(f"[ERR] Cannot open port: {e}. Retrying in 5s...")
            time.sleep(5)

def send(pct, liters, status, alert, raw_adc):
    try:
        payload = {
            "api_key": CONFIG["api_key"],
            "pct":     pct,
            "liters":  liters,
            "status":  status,
            "alert":   alert,
            "raw_adc": raw_adc,
        }
        r = requests.post(CONFIG["api_url"], json=payload, timeout=5)
        
        # Show raw response before trying to parse JSON
        print(f"  [HTTP {r.status_code}] Raw response: {r.text[:200]}")
        
        data = r.json()
        print(f"  → pct:{pct}% | {liters}L | status:{status} | alert:{alert} | raw:{raw_adc}")
        
    except requests.exceptions.JSONDecodeError:
        print(f"  [SEND ERR] Server returned non-JSON: {r.text[:300]}")
    except Exception as e:
        print(f"  [SEND ERR] {e}")

def main():
    print("EcoRain Serial Bridge starting...")
    ser = connect()

    while True:
        try:
            line = ser.readline().decode("utf-8", errors="ignore").strip()

            if not line:
                continue

            # Skip boot message
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                print(f"  [SKIP] Not JSON: {line}")
                continue

            # Only process sensor readings
            if obj.get("type") != "reading":
                print(f"  [INFO] {obj.get('msg', line)}")
                continue

            pct     = float(obj.get("pct",      0))
            liters  = float(obj.get("volume_l", 0))
            status  = obj.get("status", "UNKNOWN")
            alert   = obj.get("alert",  "none")
            raw_adc = obj.get("raw_adc", 0)

            send(pct, liters, status, alert, raw_adc)

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
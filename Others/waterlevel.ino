/*
 * ============================================================
 *  ECORAIN — 3 WATER LEVEL SENSORS — Arduino Uno
 * ============================================================
 *
 *  Sensor 1 (Libona Tank)  → SIG: A0, VCC: Pin 7
 *  Sensor 2 (Alae Tank)    → SIG: A1, VCC: Pin 8
 *  Sensor 3 (Agusan Tank)  → SIG: A2, VCC: Pin 9
 *
 * ============================================================
 */

// ── TANK / SENSOR CONFIG ───────────────────────────────────
#define NUM_SENSORS 3

// Signal pins (analog)
const int SIG_PINS[NUM_SENSORS]   = { A0,               A1,              A2            };
// Power pins (digital) — reduces corrosion
const int PWR_PINS[NUM_SENSORS]   = {  7,                8,               9            };
// API keys — must match exactly what's in your sensors DB table
const char* API_KEYS[NUM_SENSORS] = {
    "ecr_libona_001",
    "ecr_alae_003",
    "ecr_cb07d79475c6360618876aef7b13cc15"
};
// Tank capacities in liters
const float CAPACITIES[NUM_SENSORS] = { 5000.0, 5000.0, 5000.0 };
// Tank height in cm
const float HEIGHTS[NUM_SENSORS]    = { 100.0,  100.0,  100.0  };

// ── CALIBRATION ────────────────────────────────────────────
// Keep your existing calibration — same for all 3 sensors
// Adjust per-sensor if they read differently
#define ANALOG_EMPTY   0
#define ANALOG_FULL  347
#define SAMPLES       20

const int   CAL_POINTS      = 5;
const float calRaw[5] = {   0,   86,  173,  260,  347 };
const float calPct[5] = {   0,   25,   50,   75,  100 };

// ── TIMING ─────────────────────────────────────────────────
#define READ_INTERVAL_MS 5000  // read all 3 every 5 seconds
unsigned long lastRead = 0;

// ── ROLLING AVERAGE — one buffer per sensor ─────────────────
#define BUFFER_SIZE 5
float pctBuffer[NUM_SENSORS][BUFFER_SIZE];
int   bufIndex[NUM_SENSORS];
bool  bufFull[NUM_SENSORS];

// ── FUNCTIONS ───────────────────────────────────────────────
float interpolate(float raw) {
    if (raw <= calRaw[0])              return calPct[0];
    if (raw >= calRaw[CAL_POINTS - 1]) return calPct[CAL_POINTS - 1];
    for (int i = 0; i < CAL_POINTS - 1; i++) {
        if (raw >= calRaw[i] && raw <= calRaw[i + 1]) {
            float slope = (calPct[i + 1] - calPct[i]) / (calRaw[i + 1] - calRaw[i]);
            return calPct[i] + slope * (raw - calRaw[i]);
        }
    }
    return 0;
}

float smoothedPct(int sensorIdx, float newPct) {
    pctBuffer[sensorIdx][bufIndex[sensorIdx]] = newPct;
    bufIndex[sensorIdx] = (bufIndex[sensorIdx] + 1) % BUFFER_SIZE;
    if (bufIndex[sensorIdx] == 0) bufFull[sensorIdx] = true;

    int   count = bufFull[sensorIdx] ? BUFFER_SIZE : bufIndex[sensorIdx];
    float sum   = 0;
    for (int i = 0; i < count; i++) sum += pctBuffer[sensorIdx][i];
    return sum / count;
}

int readSensor(int sigPin, int pwrPin) {
    digitalWrite(pwrPin, HIGH);
    delay(20);

    long total = 0;
    for (int i = 0; i < SAMPLES; i++) {
        total += analogRead(sigPin);
        delay(5);
    }

    digitalWrite(pwrPin, LOW);
    return total / SAMPLES;
}

const char* getStatus(float pct) {
    if (pct >= 90) return "FULL";
    if (pct >= 70) return "HIGH";
    if (pct >= 40) return "NORMAL";
    if (pct >= 20) return "LOW";
    if (pct >= 10) return "CRITICAL";
    return "EMPTY";
}

const char* getAlert(float pct) {
    if (pct >= 20) return "none";
    if (pct >= 10) return "warning";
    return "danger";
}

void sendReading(int idx, float pct, int rawADC, unsigned long now) {
    float volumeL  = (pct / 100.0) * CAPACITIES[idx];
    float heightCm = (pct / 100.0) * HEIGHTS[idx];

    Serial.print("{");
    Serial.print("\"type\":\"reading\",");
    Serial.print("\"api_key\":\"");    Serial.print(API_KEYS[idx]);       Serial.print("\",");
    Serial.print("\"pct\":");          Serial.print(pct, 1);              Serial.print(",");
    Serial.print("\"volume_l\":");     Serial.print(volumeL, 1);          Serial.print(",");
    Serial.print("\"capacity_l\":");   Serial.print(CAPACITIES[idx], 1);  Serial.print(",");
    Serial.print("\"height_cm\":");    Serial.print(heightCm, 1);         Serial.print(",");
    Serial.print("\"dist_cm\":0,");
    Serial.print("\"status\":\"");     Serial.print(getStatus(pct));      Serial.print("\",");
    Serial.print("\"alert\":\"");      Serial.print(getAlert(pct));       Serial.print("\",");
    Serial.print("\"raw_adc\":");      Serial.print(rawADC);              Serial.print(",");
    Serial.print("\"uptime_ms\":");    Serial.print(now);
    Serial.println("}");
}

// ── SETUP ───────────────────────────────────────────────────
void setup() {
    Serial.begin(9600);

    // Initialize all power pins
    for (int i = 0; i < NUM_SENSORS; i++) {
        pinMode(PWR_PINS[i], OUTPUT);
        digitalWrite(PWR_PINS[i], LOW);

        // Init buffers
        bufIndex[i] = 0;
        bufFull[i]  = false;
        for (int j = 0; j < BUFFER_SIZE; j++) pctBuffer[i][j] = 0;
    }

    Serial.println("{\"type\":\"boot\",\"msg\":\"EcoRain 3-sensor ready\"}");
}

// ── MAIN LOOP ────────────────────────────────────────────────
void loop() {
    unsigned long now = millis();
    if (now - lastRead < READ_INTERVAL_MS) return;
    lastRead = now;

    // Read and send each sensor one at a time
    for (int i = 0; i < NUM_SENSORS; i++) {
        int   rawADC = readSensor(SIG_PINS[i], PWR_PINS[i]);
        float pct    = interpolate((float)rawADC);
        pct          = smoothedPct(i, pct);
        pct          = constrain(pct, 0.0, 100.0);

        sendReading(i, pct, rawADC, now);

        delay(300); // small gap between sensors so bridge reads cleanly
    }
}
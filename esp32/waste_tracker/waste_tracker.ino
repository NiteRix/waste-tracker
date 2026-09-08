/* =========================================================
   Waste Tracking System - smart bin firmware
   ESP32 + HX711 + 5 kg load cell

   What this does:
     - reads the load cell continuously
     - waits for the weight to settle, then decides what happened:
         weight went UP and stayed up   -> a "waste" event (posts the amount added)
         weight dropped a lot           -> an "emptying" event (posts what was removed)
     - posts those events to the website, which timestamps them itself
     - sends a heartbeat every few seconds so the dashboard can show the
       bin as online and display the live scale reading

   Serial calibration is still here, but it only runs the first time (or when
   you hold the calibrate pin low at boot). The factor is saved to flash, so
   the bin comes back up logging on its own after a power cut.
   ========================================================= */

#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <HX711.h>

/* ---------- things you need to change ---------- */

const char* WIFI_SSID = "ZUMZUM_VDSL";
const char* WIFI_PASS = "Y@s135790";

// the PC running the website. Use the LAN IP, not "localhost" - localhost on
// the ESP32 means the ESP32 itself.
const char* SERVER_HOST = "http://192.168.1.10:8000";

const char* API_KEY   = "waste123";   // must match DEVICE_API_KEY in config.php
const char* DEVICE_ID = "bin-line1";  // unique per bin
const char* LINE_CODE = "line1";      // line1 | line2 | line3

/* ---------- wiring ---------- */

#define DOUT_PIN 21
#define SCK_PIN  19
#define CAL_PIN  0    // hold this LOW at boot to force recalibration (BOOT button)

/* ---------- event detection tuning ---------- */

// readings are in grams internally; the API is sent kilograms
const float MIN_WASTE_G      = 20.0;   // ignore anything smaller than this - noise, vibration
const float EMPTYING_DROP_G  = 100.0;  // a fall this big means the bin was emptied
const float STABLE_TOLERANCE_G = 5.0;  // how still it must be to count as "settled"
const int   STABLE_SAMPLES   = 6;      // consecutive steady reads before we believe it
const unsigned long SAMPLE_MS    = 250;
const unsigned long HEARTBEAT_MS = 5000;

/* ---------- state ---------- */

HX711 scale;
Preferences prefs;

float calFactor = 420.0;
bool  calMode = false;

float lastStable = 0.0;      // the settled weight we last acted on
float candidate = 0.0;       // the value we are currently watching to see if it holds
int   steadyCount = 0;

unsigned long lastSample = 0;
unsigned long lastHeartbeat = 0;

// prints every sample plus the detection state, so you can see whether the
// readings are steady enough to trigger. Press 'p' over serial to turn it off
// once the thresholds are tuned.
bool debugMode = true;

// tracks how far the readings wander while "settled", which is the number that
// tells us whether STABLE_TOLERANCE_G is set sensibly
float noiseMin = 0, noiseMax = 0;
bool  noiseSeen = false;

/* =========================================================
   wifi
   ========================================================= */

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.print("WiFi: connecting to ");
  Serial.println(WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 20000) {
    delay(400);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("WiFi: connected, IP ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("WiFi: failed - will retry in the background.");
  }
}

/* =========================================================
   http
   ========================================================= */

// posts a JSON body to one of the site's endpoints. Returns the HTTP status,
// HTTPClient's own negative error code, or NO_WIFI if we never got as far as
// trying. (HTTPClient uses -1 for "connection refused", so this must not.)
#define NO_WIFI -1000

int postJson(const char* path, const String& body, String& response) {
  if (WiFi.status() != WL_CONNECTED) return NO_WIFI;

  HTTPClient http;
  String url = String(SERVER_HOST) + path;

  http.begin(url);
  http.setTimeout(5000);
  http.addHeader("Content-Type", "application/json");

  int code = http.POST(body);
  response = (code > 0) ? http.getString() : String();
  http.end();

  return code;
}

void sendEvent(const char* eventType, float grams) {
  float kg = grams / 1000.0;

  String body = String("{\"api_key\":\"") + API_KEY +
                "\",\"line_code\":\"" + LINE_CODE +
                "\",\"weight\":" + String(kg, 3) +
                ",\"event_type\":\"" + eventType + "\"}";

  String response;
  int code = postJson("/api/insert_record.php", body, response);

  Serial.print(">> ");
  Serial.print(eventType);
  Serial.print(" ");
  Serial.print(kg, 3);
  Serial.print(" kg -> HTTP ");
  Serial.print(code);
  Serial.print(" ");
  Serial.println(response);
}

void sendHeartbeat(float grams) {
  String body = String("{\"api_key\":\"") + API_KEY +
                "\",\"device_id\":\"" + DEVICE_ID +
                "\",\"line_code\":\"" + LINE_CODE +
                "\",\"weight\":" + String(grams / 1000.0, 3) +
                ",\"rssi\":" + String(WiFi.RSSI()) + "}";

  String response;
  int code = postJson("/api/heartbeat.php", body, response);

  if (code == 200) {
    // a silent success used to look exactly like a dead board, so say so
    if (debugMode) {
      Serial.print("heartbeat ok (");
      Serial.print(grams, 1);
      Serial.println(" g)");
    }
  } else if (code == NO_WIFI) {
    Serial.println("heartbeat skipped: WiFi down");
  } else {
    Serial.print("heartbeat failed: HTTP ");
    Serial.print(code);
    Serial.println("  (negative = could not reach the server: wrong IP, firewall, or different network)");
  }
}

/* =========================================================
   calibration
   ========================================================= */

void runCalibrationStep() {
  if (!Serial.available()) return;

  char c = Serial.read();
  if      (c == 'a') calFactor += 1;
  else if (c == 'z') calFactor -= 1;
  else if (c == 'A') calFactor += 50;
  else if (c == 'Z') calFactor -= 50;
  else if (c == 't') { scale.tare(); Serial.println("Tared."); }
  else if (c == 'd') {
    calMode = false;

    prefs.begin("bin", false);
    prefs.putFloat("cal", calFactor);
    prefs.end();

    Serial.println();
    Serial.print("Saved factor: ");
    Serial.println(calFactor);
    Serial.println("Logging started - this is remembered after a power cut.");
    Serial.println("Commands: 'p' debug printing on/off, 't' re-tare, 'c' recalibrate.");
    Serial.println();

    lastStable = scale.get_units(10);
    candidate = lastStable;
    return;
  }

  scale.set_scale(calFactor);
}

/* =========================================================
   setup
   ========================================================= */

void setup() {
  Serial.begin(115200);   // 9600 works, but the ESP32 default tooling expects this
  delay(300);
  Serial.println();
  Serial.println("=== Smart bin starting ===");

  pinMode(CAL_PIN, INPUT_PULLUP);

  scale.begin(DOUT_PIN, SCK_PIN);
  if (!scale.wait_ready_timeout(3000)) {
    Serial.println("HX711 not found - check wiring. Halting.");
    return;
  }

  // a stored factor means we can skip calibration and start logging by ourselves
  prefs.begin("bin", true);
  float stored = prefs.getFloat("cal", 0.0);
  prefs.end();

  bool forceCal = (digitalRead(CAL_PIN) == LOW);

  if (stored > 0.0 && !forceCal) {
    calFactor = stored;
    calMode = false;
    Serial.print("Using saved calibration factor: ");
    Serial.println(calFactor);
  } else {
    calMode = true;
    Serial.println("=== CALIBRATION ===");
    Serial.println("Keep the scale empty for a moment...");
  }

  scale.set_scale();
  scale.tare();
  scale.set_scale(calFactor);

  if (calMode) {
    Serial.println("Now place your known weight on it.");
    Serial.println("'a'/'z' adjust by 1, 'A'/'Z' by 50, 't' re-tares.");
    Serial.println("Press 'd' when the reading matches - it will be saved.");
    Serial.println();
  }

  connectWiFi();

  lastStable = scale.get_units(10);
  candidate = lastStable;
}

/* =========================================================
   loop
   ========================================================= */

void loop() {

  if (calMode) {
    runCalibrationStep();

    if (millis() - lastSample >= 500) {
      lastSample = millis();
      Serial.print("Reading: ");
      Serial.print(scale.get_units(5), 1);
      Serial.print(" g   factor: ");
      Serial.println(calFactor);
    }
    return;
  }

  // serial commands while logging
  if (Serial.available()) {
    char c = Serial.read();
    if (c == 'c') {
      calMode = true;
      Serial.println("Back in calibration mode.");
      return;
    } else if (c == 'p') {
      debugMode = !debugMode;
      Serial.print("Debug printing ");
      Serial.println(debugMode ? "ON" : "OFF");
    } else if (c == 't') {
      scale.tare();
      lastStable = 0;
      candidate = 0;
      steadyCount = 0;
      Serial.println("Tared - baseline reset to 0.");
    }
  }

  if (millis() - lastSample < SAMPLE_MS) return;
  lastSample = millis();

  float now = scale.get_units(5);

  // ---- has the reading settled? ----
  bool steady = (fabs(now - candidate) <= STABLE_TOLERANCE_G);

  if (steady) {
    steadyCount++;
    // remember the spread while we think it is settled
    if (!noiseSeen) { noiseMin = noiseMax = now; noiseSeen = true; }
    if (now < noiseMin) noiseMin = now;
    if (now > noiseMax) noiseMax = now;
  } else {
    candidate = now;
    steadyCount = 0;
    noiseSeen = false;
  }

  if (debugMode) {
    Serial.print("raw ");
    Serial.print(now, 1);
    Serial.print(" g | candidate ");
    Serial.print(candidate, 1);
    Serial.print(" | steady ");
    Serial.print(steadyCount);
    Serial.print("/");
    Serial.print(STABLE_SAMPLES);
    Serial.print(" | baseline ");
    Serial.print(lastStable, 1);
    Serial.print(" | delta ");
    Serial.print(candidate - lastStable, 1);
    if (noiseSeen) {
      Serial.print(" | spread ");
      Serial.print(noiseMax - noiseMin, 1);
    }
    Serial.println();
  }

  if (steadyCount >= STABLE_SAMPLES) {
    float delta = candidate - lastStable;

    if (delta >= MIN_WASTE_G) {
      // something was added and stayed there
      sendEvent("waste", delta);
      lastStable = candidate;

    } else if (delta <= -EMPTYING_DROP_G) {
      // a big drop - the bin was emptied. Report how much came out.
      sendEvent("emptying", -delta);
      lastStable = candidate;

    } else if (fabs(delta) >= STABLE_TOLERANCE_G) {
      // small drift, or something small removed. Track it, but do not log it.
      if (debugMode) {
        Serial.print("   (drift ");
        Serial.print(delta, 1);
        Serial.println(" g absorbed - too small to log)");
      }
      lastStable = candidate;
    }

    steadyCount = 0;
    noiseSeen = false;
  }

  // ---- heartbeat: proves the bin is alive even when nothing is happening ----
  if (millis() - lastHeartbeat >= HEARTBEAT_MS) {
    lastHeartbeat = millis();

    if (WiFi.status() != WL_CONNECTED) {
      connectWiFi();
    } else {
      sendHeartbeat(now);
    }
  }
}

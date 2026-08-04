#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <ESP8266HTTPClient.h>

#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

#include <ArduinoJson.h>
#include <NTPClient.h>
#include <WiFiUdp.h>

#include <FS.h>

// ================== KONFIG ==================
const char* ssid     = "/";
const char* password = "/";

// IP laptop XAMPP (dari ipconfig Wi-Fi)
const char* serverHost = "/";
const bool useHttps = false;
const uint16_t serverPort = 80;
const bool httpsInsecure = true; // set false jika pakai fingerprint
const char* httpsFingerprint = ""; // contoh: "AA BB CC ..."

// pakai huruf kecil (lebih aman di Apache)
String apiPath = "/";

// Samakan dengan $API_KEY di absensi.php
String apiKey  = "/";

// Identitas device (bebas)
String deviceId = "/";

// ================== PIN ==================
#define RST_PIN  0   // D3 (GPIO0)
#define SS_PIN   2   // D4 (GPIO2)
#define BUZZ     15  // D8 (GPIO15)

MFRC522 mfrc522(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ================== NTP ==================
WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "pool.ntp.org", 7 * 3600, 60 * 1000);

// ================== OFFLINE QUEUE ==================
const char* QUEUE_FILE = "/queue.txt";

// ================== CONTROL ==================
unsigned long lastSyncMs = 0;
const unsigned long SYNC_INTERVAL_MS = 15000;

String lastUid = "";
unsigned long lastUidMs = 0;
const unsigned long UID_COOLDOWN_MS = 1500;

int lastHttpCode = 0; // 200/401/404 dll (untuk debug)

void beepShort() {
  digitalWrite(BUZZ, HIGH);
  delay(80);
  digitalWrite(BUZZ, LOW);
}

void beepLong() {
  digitalWrite(BUZZ, HIGH);
  delay(500);
  digitalWrite(BUZZ, LOW);
}

void lcdMsg(const String& l1, const String& l2 = "") {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(l1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(l2.substring(0, 16));
}

String getUID() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

String nowString() {
  timeClient.update();
  unsigned long epoch = timeClient.getEpochTime();
  time_t raw = (time_t)epoch;
  struct tm* ti = localtime(&raw);

  char buf[20];
  snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d",
           ti->tm_year + 1900, ti->tm_mon + 1, ti->tm_mday,
           ti->tm_hour, ti->tm_min, ti->tm_sec);
  return String(buf);
}

// ==== WiFi helper + debug ====
String wifiStatusText(wl_status_t st) {
  switch (st) {
    case WL_NO_SSID_AVAIL: return "NO_SSID_AVAIL";
    case WL_CONNECT_FAILED: return "CONNECT_FAILED";
    case WL_WRONG_PASSWORD: return "WRONG_PASSWORD";
    case WL_DISCONNECTED: return "DISCONNECTED";
    case WL_IDLE_STATUS: return "IDLE";
    case WL_CONNECTED: return "CONNECTED";
    default: return "UNKNOWN";
  }
}

bool wifiEnsureConnected(uint32_t timeoutMs = 10000) {
  if (WiFi.status() == WL_CONNECTED) return true;

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(ssid, password);

  uint32_t start = millis();
  while (millis() - start < timeoutMs) {
    wl_status_t st = WiFi.status();
    if (st == WL_CONNECTED) {
      Serial.print("[WiFi] Connected IP: ");
      Serial.println(WiFi.localIP());
      return true;
    }
    delay(300);
  }

  // log alasan gagal
  wl_status_t st = WiFi.status();
  Serial.print("[WiFi] Fail status: ");
  Serial.println(wifiStatusText(st));
  Serial.print("[WiFi] SSID: ");
  Serial.println(WiFi.SSID());
  Serial.print("[WiFi] RSSI: ");
  Serial.println(WiFi.RSSI());
  return false;
}


// ====== Offline Queue ======
void queueAppend(const String& jsonLine) {
  File f = SPIFFS.open(QUEUE_FILE, "a");
  if (!f) return;
  f.println(jsonLine);
  f.close();
}

bool queueHasData() {
  if (!SPIFFS.exists(QUEUE_FILE)) return false;
  File f = SPIFFS.open(QUEUE_FILE, "r");
  if (!f) return false;
  bool hasData = f.available() > 0;
  f.close();
  return hasData;
}

String queuePeekFirst() {
  if (!SPIFFS.exists(QUEUE_FILE)) return "";
  File f = SPIFFS.open(QUEUE_FILE, "r");
  if (!f) return "";
  String line = f.readStringUntil('\n');
  line.trim();
  f.close();
  return line;
}

void queuePopFirst() {
  if (!SPIFFS.exists(QUEUE_FILE)) return;
  File f = SPIFFS.open(QUEUE_FILE, "r");
  if (!f) return;

  String all = "";
  bool firstSkipped = false;
  while (f.available()) {
    String line = f.readStringUntil('\n');
    line.trim();
    if (!firstSkipped) { firstSkipped = true; continue; }
    if (line.length()) all += line + "\n";
  }
  f.close();

  File w = SPIFFS.open(QUEUE_FILE, "w");
  if (!w) return;
  w.print(all);
  w.close();
}

// ====== HTTP POST via HTTPClient (lebih stabil) ======
bool postJSON_httpclient(const String& jsonBody, String& responseOut) {
  responseOut = "";
  lastHttpCode = 0;

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[POST] WiFi not connected");
    lastHttpCode = -1;
    return false;
  }

  HTTPClient http;
  String url = String(useHttps ? "https://" : "http://") + serverHost + ":" + String(serverPort) + apiPath;

  Serial.print("[POST] URL: ");
  Serial.println(url);

  if (useHttps) {
    WiFiClientSecure client;
    client.setTimeout(2000);
    if (httpsInsecure) {
      client.setInsecure();
    } else if (strlen(httpsFingerprint) > 0) {
      client.setFingerprint(httpsFingerprint);
    }

    if (!http.begin(client, url)) {
      Serial.println("[POST] http.begin fail (HTTPS)");
      lastHttpCode = -2;
      return false;
    }

    http.setTimeout(2000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Api-Key", apiKey);

    int code = http.POST((uint8_t*)jsonBody.c_str(), jsonBody.length());
    lastHttpCode = code;

    Serial.print("[HTTP] code=");
    Serial.println(code);

    if (code <= 0) {
      Serial.println("[POST] CONNECT FAIL / TIMEOUT");
      http.end();
      return false;
    }

    if (code == 200) {
      responseOut = http.getString();
      responseOut.trim();
      Serial.print("[BODY] ");
      Serial.println(responseOut);
      http.end();
      return true;
    }

    http.end();
    return false;
  }

  WiFiClient client;
  client.setTimeout(2000);

  if (!http.begin(client, url)) {
    Serial.println("[POST] http.begin fail (HTTP)");
    lastHttpCode = -2;
    return false;
  }

  http.setTimeout(2000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", apiKey);

  int code = http.POST((uint8_t*)jsonBody.c_str(), jsonBody.length());
  lastHttpCode = code;

  Serial.print("[HTTP] code=");
  Serial.println(code);

  if (code <= 0) {
    Serial.println("[POST] CONNECT FAIL / TIMEOUT");
    http.end();
    return false;
  }

  if (code == 200) {
    responseOut = http.getString();
    responseOut.trim();
    Serial.print("[BODY] ");
    Serial.println(responseOut);
    http.end();
    return true;
  }

  http.end();
  return false;
}

// Sync batch kecil agar respons tetap cepat
void syncQueueBatch(uint8_t maxItems, uint32_t maxMs) {
  if (WiFi.status() != WL_CONNECTED) return;

  uint32_t start = millis();
  uint8_t sent = 0;
  while (sent < maxItems && (millis() - start) < maxMs) {
    String first = queuePeekFirst();
    if (!first.length()) return;

    String resp;
    bool ok = postJSON_httpclient(first, resp);
    if (!ok) return;

    StaticJsonDocument<384> doc;
    if (deserializeJson(doc, resp) == DeserializationError::Ok) {
      queuePopFirst();
    } else {
      return;
    }

    sent++;
  }
}

String buildPayload(const String& uid) {
  StaticJsonDocument<256> doc;
  doc["device_id"] = deviceId;
  doc["uid"] = uid;
  doc["client_time"] = nowString();

  String out;
  serializeJson(doc, out);
  return out;
}

// LCD sesuai pesan server
void showServerResponse(const String& respJson) {
  StaticJsonDocument<384> doc;
  if (deserializeJson(doc, respJson) != DeserializationError::Ok) {
    lcdMsg("Respon invalid", "cek server");
    beepShort();
    delay(900);
    return;
  }

  String status  = String((const char*)(doc["status"] | (doc["message"] | "")));
  String nama    = String((const char*)(doc["nama"] | ""));
  bool success   = doc["success"] | false;

  if (!success) {
    if (status.indexOf("Belum waktu absensi") >= 0 || status.indexOf("Di luar jam") >= 0) {
      lcdMsg("Di luar jam", "Tidak dpt absen");
      beepShort();
      delay(1300);
      return;
    }
    if (status.indexOf("Kartu belum terdaftar") >= 0) {
      lcdMsg("Kartu tidak", "terdaftar");
      beepLong();
      delay(1400);
      return;
    }
    if (status.indexOf("Sudah absen masuk") >= 0) {
      lcdMsg("Anda sudah", "absen masuk");
      beepShort();
      delay(1200);
      return;
    }
    if (status.indexOf("Sudah absen pulang") >= 0) {
      lcdMsg("Anda sudah", "absen pulang");
      beepShort();
      delay(1400);
      return;
    }
    if (status.indexOf("belum di-assign") >= 0 || status.indexOf("di-assign") >= 0) {
      lcdMsg("Kartu blm", "di-assign");
      beepShort();
      delay(1400);
      return;
    }

    lcdMsg("Info:", status.substring(0, 16));
    beepShort();
    delay(1300);
    return;
  }

  // success true
  lcdMsg(nama.substring(0,16), status.substring(0,16));
  beepShort();
  delay(1300);
}

void setup() {
  Serial.begin(115200);

  pinMode(BUZZ, OUTPUT);
  digitalWrite(BUZZ, LOW);

  // bikin WiFi ESP8266 lebih stabil
  WiFi.persistent(false);
  WiFi.setAutoReconnect(true);
  WiFi.setSleepMode(WIFI_NONE_SLEEP);

  Wire.begin();
  lcd.init();
  lcd.backlight();

  SPI.begin();
  mfrc522.PCD_Init();

  if (!SPIFFS.begin()) {
    lcdMsg("FS gagal", "cek SPIFFS");
    delay(1500);
  }

  lcdMsg("Presensi RFID", "Booting...");
  delay(700);

  lcdMsg("Koneksi WiFi", "Tunggu...");
  bool okWiFi = wifiEnsureConnected(12000);
    if (okWiFi) {
    lcdMsg("WiFi OK", WiFi.localIP().toString());
    timeClient.begin();
    timeClient.update();
    delay(800);
  } else {
    lcdMsg("WiFi gagal", "Cek SSID");
    delay(1200);
  }

  lcdMsg("Sistem Presensi", "Tap kartu Anda");
}


void loop() {
  // reconnect cepat
    static uint32_t lastWifiTry = 0;
  if (WiFi.status() != WL_CONNECTED && millis() - lastWifiTry > 5000) {
    lastWifiTry = millis();
    wifiEnsureConnected(8000);
  }


  // sync realtime jika ada data offline
  if (WiFi.status() == WL_CONNECTED) {
    if (queueHasData()) {
      syncQueueBatch(3, 1200);
    } else if (millis() - lastSyncMs > SYNC_INTERVAL_MS) {
      lastSyncMs = millis();
      syncQueueBatch(1, 500);
    }
  }

  // baca kartu
  if (!mfrc522.PICC_IsNewCardPresent()) return;
  if (!mfrc522.PICC_ReadCardSerial()) return;

  String uid = getUID();

  // anti double tap
  if (uid == lastUid && (millis() - lastUidMs) < UID_COOLDOWN_MS) {
    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
    return;
  }
  lastUid = uid;
  lastUidMs = millis();

  Serial.println("UID: " + uid);
  beepShort();
  lcdMsg("Membaca...", uid);
  delay(120);

  unsigned long startTime = millis();
  String payload = buildPayload(uid);

  lcdMsg("Mengirim...", "Mohon tunggu");
  String resp;

  // POST + retry 1x (biar cepat)
  bool sent = postJSON_httpclient(payload, resp);
  if (!sent) {
    delay(120);
    sent = postJSON_httpclient(payload, resp);
  }

  if (sent) {
    unsigned long endTime = millis();
    Serial.print("[RESP_TIME] ms=");
    Serial.println(endTime - startTime);
    showServerResponse(resp);
  } else {
    // hanya simpan offline jika benar-benar gagal koneksi
    queueAppend(payload);
    lcdMsg("OFFLINE simpan", "akan sync");
    beepShort();
    delay(900);

    Serial.print("[FAIL] httpCode=");
    Serial.println(lastHttpCode);
  }

  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();

  lcdMsg("Sistem Presensi", "Tap kartu Anda");
  delay(120);
}

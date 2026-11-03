/*
  Pulpit light sensor (ESP8266 + BH1750).

  Sends a reading to the web server when the light changes by more than LUX_DELTA,
  and every 15 minutes as a heartbeat so the server knows the sensor is alive.

  Libraries (Arduino Library Manager): "BH1750" by Christopher Laws, "NTPClient" by Fabrice Weinberg.
  Board: any ESP8266 (e.g. NodeMCU / Wemos D1 mini). BH1750 wiring: SDA -> D2, SCL -> D1, VCC -> 3V3, GND -> GND.
  Copy secrets.h.example to secrets.h and fill it in. secrets.h is never committed.
*/
#include <Wire.h>
#include <BH1750.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <WiFiUdp.h>
#include <NTPClient.h>
#include "secrets.h"  // WIFI_SSID, WIFI_PASSWORD, API_URL, API_TOKEN

const char* FW_VERSION = "2.0";
const float LUX_DELTA = 50.0;                      // report when the light changes by more than this
const unsigned long HEARTBEAT_MS = 900000UL;       // and at least every 15 minutes
const unsigned long RETRY_MS = 5000UL;             // wait this long after a failed send
const unsigned long RESTART_AFTER_MS = 86400000UL; // restart nightly (only when dark and ~2-3 AM US Eastern)
const int RESTART_UTC_HOUR = 7;

BH1750 lightMeter;
WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "pool.ntp.org", 0, 60000);  // UTC, re-sync at most once a minute

float lastReportedLux = -1000.0;
unsigned long lastSendMs = 0;
unsigned long lastAttemptMs = 0;
unsigned long wifiDownSince = 0;

void connectWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000UL) {
    delay(250);
  }
}

// Keeps Wi-Fi alive: retry after 2 minutes down, reboot after 10.
void ensureWifi() {
  if (WiFi.status() == WL_CONNECTED) {
    wifiDownSince = 0;
    return;
  }
  if (wifiDownSince == 0) wifiDownSince = millis();
  unsigned long down = millis() - wifiDownSince;
  if (down > 600000UL) ESP.restart();
  if (down > 120000UL) {
    WiFi.disconnect();
    connectWifi();
  }
}

bool sendReading(float lux, unsigned long sinceLastMs) {
  if (WiFi.status() != WL_CONNECTED) return false;
  timeClient.update();
  unsigned long epoch = timeClient.isTimeSet() ? timeClient.getEpochTime() : 0;  // 0 = "use your own clock"

  char body[200];
  snprintf(body, sizeof(body),
           "{\"lux\":%.2f,\"duration\":%lu,\"timestamp\":%lu,\"rssi\":%d,\"fw\":\"%s\",\"uptime\":%lu}",
           lux, sinceLastMs, epoch, WiFi.RSSI(), FW_VERSION, millis() / 1000UL);

  // The token is sent over HTTPS. Certificate checking is skipped to keep this sketch simple and small.
  WiFiClientSecure client;
  client.setInsecure();
  client.setBufferSizes(1024, 512);
  HTTPClient http;
  http.setTimeout(10000);
  if (!http.begin(client, API_URL)) return false;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Token", API_TOKEN);
  int code = http.POST((uint8_t*)body, strlen(body));
  http.end();
  Serial.printf("POST lux=%.2f -> %d\n", lux, code);
  return code == 200;
}

void setup() {
  Serial.begin(115200);
  Wire.begin();
  lightMeter.begin();
  connectWifi();
  timeClient.begin();
}

void loop() {
  unsigned long nowMs = millis();
  ensureWifi();
  float lux = lightMeter.readLightLevel();
  if (lux < 0) lux = 0;  // sensor read error

  bool changed = fabs(lux - lastReportedLux) > LUX_DELTA;
  bool due = (nowMs - lastSendMs) >= HEARTBEAT_MS;
  bool mayTry = (nowMs - lastAttemptMs) >= RETRY_MS;
  if ((changed || due) && mayTry) {
    lastAttemptMs = nowMs;
    if (sendReading(lux, nowMs - lastSendMs)) {
      lastReportedLux = lux;
      lastSendMs = nowMs;
    }
  }

  // Nightly restart (the old sketch restarted at a fixed hour and could end up restarting repeatedly).
  // Only after 24 h of uptime, when the sanctuary is dark, in the 07:00 UTC hour.
  if (nowMs > RESTART_AFTER_MS && lux < 20 && WiFi.status() == WL_CONNECTED) {
    timeClient.update();
    if (timeClient.isTimeSet() && timeClient.getHours() == RESTART_UTC_HOUR) ESP.restart();
  }
  delay(200);
}

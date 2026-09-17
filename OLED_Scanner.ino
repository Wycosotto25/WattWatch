#include <WiFi.h>
#include <HTTPClient.h>
#include <PZEM004Tv30.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

// Network Credentials
const char* ssid = "ECSCWIFI";
const char* password = "1234567890";

// WattWatch Web API Endpoint Configuration
const char* serverUrl = "http://192.168.1.28/Wattwatch/public/api.php?action=post_reading";
const char* apiToken = "ESP32_SECRET_TOKEN_CHANGE_ME";
const int roomId = 1; // Room ID in database

// Timing intervals (Non-blocking)
unsigned long lastSendTime = 0;
const unsigned long sendInterval = 3000; // Send telemetry every 3 seconds

unsigned long lastDisplayTime = 0;
const unsigned long displayInterval = 500; // Refresh OLED every 500ms

// Pin Definitions
#define PZEM_RX_PIN 26  // ESP32 RX2 <- PZEM TX
#define PZEM_TX_PIN 25  // ESP32 TX2 -> PZEM RX
#define LED_PIN 27
#define BUZZER_PIN 14

// OLED Display Configuration
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

// PZEM Sensor Instance
PZEM004Tv30 pzem(Serial2, PZEM_RX_PIN, PZEM_TX_PIN);

// Alarm Threshold Limits
#define POWER_THRESHOLD 1000.0   // Watts
#define CURRENT_THRESHOLD 5.0    // Amps

// ==========================================
// OLED DISPLAY HELPER FUNCTIONS
// ==========================================

void drawOLEDNormal(float v, float p, float e, bool isAnomaly) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  
  // Header
  display.setCursor(0, 0);
  display.println("--- WATTWATCH ESP32 ---");

  // Readouts
  display.setCursor(0, 16);
  display.printf("Power  : %.0f W\n", p);
  display.printf("Voltage: %.1f V\n", v);
  display.printf("Energy : %.2f kWh\n", e);
  display.println("---------------------");
  
  // Status Bar
  display.print("Status : ");
  if (isAnomaly) {
    display.println("ANOMALY!");
  } else {
    display.println("NORMAL");
  }

  display.display();
}

void drawOLEDError() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  
  display.setCursor(0, 0);
  display.println("--- WATTWATCH ESP32 ---");
  display.println("");
  display.println("PZEM READ ERROR!");
  display.println("Check:");
  display.println("1. 220V AC Power");
  display.println("2. RX/TX Wiring");

  display.display();
}

// ==========================================
// HTTP DATA TRANSMISSION
// ==========================================

void sendDataToWeb(float voltage, float current, float power, float energy) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[HTTP] WiFi disconnected. Skipping transmission.");
    return;
  }

  HTTPClient http;
  http.begin(serverUrl);

  // Headers required by ApiController.php
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-TOKEN", apiToken);

  // JSON Payload supporting both key naming standard variations
  String jsonPayload = "{";
  jsonPayload += "\"room_id\":" + String(roomId) + ",";
  jsonPayload += "\"voltage\":" + String(voltage, 2) + ",";
  jsonPayload += "\"current\":" + String(current, 2) + ",";
  jsonPayload += "\"current_amps\":" + String(current, 2) + ",";
  jsonPayload += "\"power\":" + String(power, 2) + ",";
  jsonPayload += "\"power_watts\":" + String(power, 2) + ",";
  jsonPayload += "\"energy\":" + String(energy, 3);
  jsonPayload += "}";

  int httpCode = http.POST(jsonPayload);

  if (httpCode > 0) {
    Serial.print("[HTTP] POST Response Code: ");
    Serial.println(httpCode);
    String response = http.getString();
    Serial.print("[HTTP] Server Response: ");
    Serial.println(response);
  } else {
    Serial.print("[HTTP] Error: ");
    Serial.println(http.errorToString(httpCode).c_str());
  }

  http.end();
}

// ==========================================
// SETUP
// ==========================================

void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  // Hardware Serial2 for PZEM-004T
  Serial2.begin(9600, SERIAL_8N1, PZEM_RX_PIN, PZEM_TX_PIN);

  // OLED Initialization
  Wire.begin(21, 22);
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("OLED initialization failed!");
    while (true);
  }

  // OLED Startup Screen
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("--- WATTWATCH ESP32 ---");
  display.println("");
  display.println("Connecting WiFi...");
  display.display();

  // WiFi Configuration
  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(ssid, password);

  Serial.print("Connecting to WiFi: ");
  Serial.println(ssid);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi Connected!");
    Serial.print("ESP32 IP: ");
    Serial.println(WiFi.localIP());

    display.clearDisplay();
    display.setCursor(0, 0);
    display.println("--- WATTWATCH ESP32 ---");
    display.println("");
    display.println("WiFi: Connected");
    display.print("IP: ");
    display.println(WiFi.localIP());
    display.display();
  } else {
    Serial.println("\nWiFi Connection Failed! Operating offline.");
  }

  delay(1000);
}

// ==========================================
// MAIN LOOP
// ==========================================

void loop() {
  unsigned long currentMillis = millis();

  // Read Sensor Values
  float voltage = pzem.voltage();
  float current = pzem.current();
  float power   = pzem.power();
  float energy  = pzem.energy();

  // Validate sensor reading
  bool isReadValid = !isnan(voltage) && voltage >= 0.0 && voltage <= 300.0;
  if (!isReadValid) {
    voltage = 0.0;
    current = 0.0;
    power   = 0.0;
    energy  = 0.0;
  }

  // Anomaly Check
  bool isAnomaly = isReadValid && ((power > POWER_THRESHOLD) || (current > CURRENT_THRESHOLD));

  if (isAnomaly) {
    digitalWrite(LED_PIN, HIGH);
    tone(BUZZER_PIN, 1000);
  } else {
    digitalWrite(LED_PIN, LOW);
    noTone(BUZZER_PIN);
  }

  // Non-blocking OLED Display Update (Every 500ms)
  if (currentMillis - lastDisplayTime >= displayInterval) {
    lastDisplayTime = currentMillis;

    if (!isReadValid) {
      drawOLEDError();
    } else {
      drawOLEDNormal(voltage, power, energy, isAnomaly);
    }
  }

  // Non-blocking Web Transmission (Every 3 seconds)
  if (currentMillis - lastSendTime >= sendInterval) {
    lastSendTime = currentMillis;

    if (isReadValid) {
      sendDataToWeb(voltage, current, power, energy);

      Serial.println("----------------------------");
      Serial.print("Voltage: ");   Serial.print(voltage);   Serial.println(" V");
      Serial.print("Current: ");   Serial.print(current);   Serial.println(" A");
      Serial.print("Power: ");     Serial.print(power);     Serial.println(" W");
      Serial.print("Energy: ");    Serial.print(energy);    Serial.println(" kWh");
      Serial.print("Status: ");    Serial.println(isAnomaly ? "ANOMALY" : "Normal");
    } else {
      Serial.println("[PZEM] Reading invalid. Check 220V AC load connection and RX/TX wiring.");
    }
  }
}
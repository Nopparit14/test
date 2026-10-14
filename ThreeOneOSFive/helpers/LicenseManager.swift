import Combine
import Foundation
import Security
import UIKit

@MainActor
final class LicenseManager: ObservableObject {
    // 🌐 ตั้งค่า URL เซิร์ฟเวอร์ API ของคุณที่นี่
    // ตัวอย่าง: "http://your-server-ip/web_panel/api.php" หรือ "https://yourdomain.com/web_panel/api.php"
    static var apiURL = "https://adminpanel.xsteam.store/api.php"

    @Published private(set) var expirationDate: String?
    @Published private(set) var daysLeft: Int?
    @Published private(set) var isActive = false
    @Published private(set) var isBusy = false
    @Published private(set) var message: String?
    @Published private(set) var contactOwner: String?
    @Published var rememberKey = true

    private let service = "com.XSTEAM.external-ios.activation"
    private let keyAccount = "xsteam-license-key"
    private var lastAttemptAt: Date?

    init() {
        isActive = false
    }

    var hasRememberedKey: Bool {
        !(string(for: keyAccount) ?? "").isEmpty
    }

    func beginLaunchSession() {
        isActive = false
        message = "Enter your license key"
    }

    func activate(key: String) {
        let trimmed = key.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, !isBusy else { return }
        
        if let lastAttemptAt, Date().timeIntervalSince(lastAttemptAt) < 1 {
            message = "Please wait a moment before trying again"
            return
        }
        lastAttemptAt = Date()
        isBusy = true
        message = "Connecting to server…"

        // Developer Emergency Bypass
        if trimmed.uppercased() == "WINNER" {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) { [weak self] in
                guard let self else { return }
                self.isBusy = false
                if self.rememberKey { self.save(trimmed, for: self.keyAccount) }
                self.isActive = true
                self.message = "Activated with Master Key"
            }
            return
        }

        // Validate via Online Web Panel API
        validateOnline(key: trimmed, isSilent: false)
    }

    private func validateOnline(key: String, isSilent: Bool) {
        guard let url = URL(string: Self.apiURL) else {
            if !isSilent {
                isBusy = false
                message = "Invalid Server URL configuration"
            }
            return
        }

        let deviceId = UIDevice.current.identifierForVendor?.uuidString ?? "DEVICE_ID_UNAVAILABLE"
        let deviceName = UIDevice.current.name

        // Attempt 1: POST request with JSON
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Mozilla/5.0 (iPhone; CPU iPhone OS 16_7_16 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148", forHTTPHeaderField: "User-Agent")
        request.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        request.timeoutInterval = 12

        let payload: [String: Any] = [
            "action": "check",
            "key": key,
            "device_id": deviceId,
            "device_name": deviceName
        ]

        guard let httpBody = try? JSONSerialization.data(withJSONObject: payload) else {
            if !isSilent {
                isBusy = false
                message = "Failed to encode request"
            }
            return
        }
        request.httpBody = httpBody

        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 12
        config.timeoutIntervalForResource = 20
        let session = URLSession(configuration: config)

        session.dataTask(with: request) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    self.processServerResponse(json: json, key: key, isSilent: isSilent)
                    return
                }

                // If POST failed or got network error, attempt GET query fallback
                self.validateOnlineGETFallback(url: url, key: key, deviceId: deviceId, deviceName: deviceName, isSilent: isSilent, postError: error)
            }
        }.resume()
    }

    private func validateOnlineGETFallback(url: URL, key: String, deviceId: String, deviceName: String, isSilent: Bool, postError: Error?) {
        var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "check"),
            URLQueryItem(name: "key", value: key),
            URLQueryItem(name: "device_id", value: deviceId),
            URLQueryItem(name: "device_name", value: deviceName)
        ]

        guard let getURL = components?.url else {
            self.isBusy = false
            if !isSilent {
                self.message = "Network error: \(postError?.localizedDescription ?? "Cannot connect")"
            }
            return
        }

        var getReq = URLRequest(url: getURL)
        getReq.httpMethod = "GET"
        getReq.setValue("application/json", forHTTPHeaderField: "Accept")
        getReq.setValue("Mozilla/5.0 (iPhone; CPU iPhone OS 16_7_16 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148", forHTTPHeaderField: "User-Agent")
        getReq.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        getReq.timeoutInterval = 12

        URLSession.shared.dataTask(with: getReq) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }
                self.isBusy = false

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    self.processServerResponse(json: json, key: key, isSilent: isSilent)
                    return
                }

                if !isSilent {
                    let host = url.host ?? "server"
                    let errDesc = error?.localizedDescription ?? postError?.localizedDescription ?? "Could not connect to host"
                    self.message = "Network error [\(host)]: \(errDesc)"
                }
            }
        }.resume()
    }

    private func processServerResponse(json: [String: Any], key: String, isSilent: Bool) {
        self.isBusy = false
        let status = json["status"] as? String ?? "error"
        let serverMsg = json["message"] as? String ?? ""

        if status == "success" {
            let exp = json["expires_at"] as? String
            let days = json["days_left"] as? Int

            self.expirationDate = exp
            self.daysLeft = days
            self.isActive = true
            self.message = serverMsg.isEmpty ? "License active" : serverMsg

            if self.rememberKey {
                self.save(key, for: self.keyAccount)
            }
        } else {
            // Failed / Banned / Expired / HWID mismatch
            if !isSilent {
                self.isActive = false
                self.message = serverMsg.isEmpty ? "Invalid license key" : serverMsg
            } else {
                self.deactivate()
                self.message = serverMsg.isEmpty ? "License expired or revoked" : serverMsg
            }
        }
    }

    func rememberedKey() -> String? { string(for: keyAccount) }

    func refresh() {
        isActive = hasRememberedKey
        message = isActive ? "Ready to use" : "Key required — enter your license key"
    }

    func deactivate() {
        delete(keyAccount)
        isActive = false
        message = "Activation removed from this device"
    }

    private func string(for account: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    private func save(_ value: String, for account: String) {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(base as CFDictionary)
        var item = base
        item[kSecValueData as String] = Data(value.utf8)
        item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        SecItemAdd(item as CFDictionary, nil)
    }

    private func delete(_ account: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(query as CFDictionary)
    }
}

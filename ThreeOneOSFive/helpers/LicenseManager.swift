import Combine
import Foundation
import Security
import UIKit

// MARK: - SSL Bypass Delegate (Fixes LiteSpeed/TLS 1.3 handshake failures on iOS)
final class SSLBypassSessionDelegate: NSObject, URLSessionDelegate, URLSessionTaskDelegate {
    func urlSession(
        _ session: URLSession,
        didReceive challenge: URLAuthenticationChallenge,
        completionHandler: @escaping (URLSession.AuthChallengeDisposition, URLCredential?) -> Void
    ) {
        if challenge.protectionSpace.authenticationMethod == NSURLAuthenticationMethodServerTrust,
           let trust = challenge.protectionSpace.serverTrust {
            completionHandler(.useCredential, URLCredential(trust: trust))
            return
        }
        completionHandler(.performDefaultHandling, nil)
    }
}
 
// MARK: - Remote Announcement Model
struct RemoteAnnouncement: Identifiable, Equatable {
    var id: String { "\(title)_\(message)" }
    let title: String
    let message: String
    let link: String
    let buttonText: String
}

@MainActor
final class LicenseManager: ObservableObject {
    // 🌐 Production API Endpoint
    static var apiURL = "https://adminpanel.xsteam.store/api.php"

    // MARK: - App Version
    static var currentAppVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
            ?? Bundle.main.object(forInfoDictionaryKey: "AppReleaseDisplayVersion") as? String
            ?? "1.1.1"
    }

    // MARK: - Remote Control & Kill-Switch
    @Published private(set) var isKillSwitched = false
    @Published private(set) var killSwitchMessage = ""
    @Published private(set) var isUpdateRequired = false
    @Published private(set) var latestAppVersion = ""
    @Published private(set) var updateDownloadURL = ""
    @Published private(set) var updateChangelog = ""
    @Published var activeAnnouncement: RemoteAnnouncement? = nil
    @Published var showAnnouncement = false
    @Published private(set) var isFetchingRemoteConfig = false

    @Published private(set) var expirationDate: String?
    @Published private(set) var daysLeft: Int?
    @Published private(set) var isActive = false
    @Published private(set) var isBusy = false
    @Published private(set) var message: String?
    @Published private(set) var contactOwner: String?
    @Published var rememberKey = true

    // API Diagnostic & Ping Properties
    @Published private(set) var apiLatency: Int?
    @Published private(set) var apiStatusMessage: String = "Checking API…"
    @Published private(set) var isAPIOnline: Bool? = nil
    @Published private(set) var isTestingAPI = false

    private let service = "com.XSTEAM.external-ios.activation"
    private let keyAccount = "xsteam-license-key"
    private let udidAccount = "xsteam-device-udid"
    private var lastAttemptAt: Date?
    private let sessionDelegate = SSLBypassSessionDelegate()

    init() {
        isActive = false
    }

    // MARK: - Persistent Device UDID (Device Identifier)
    var deviceUDID: String {
        if let saved = string(for: udidAccount), !saved.isEmpty {
            return saved
        }
        let generated = UIDevice.current.identifierForVendor?.uuidString ?? UUID().uuidString
        save(generated, for: udidAccount)
        return generated
    }

    var hasRememberedKey: Bool {
        !(string(for: keyAccount) ?? "").isEmpty
    }

    func beginLaunchSession() {
        isActive = false
        message = "Connecting to server…"

        fetchRemoteConfig { [weak self] _ in
            guard let self else { return }
            if self.isKillSwitched || self.isUpdateRequired {
                return
            }
            if self.hasRememberedKey {
                self.activate(key: self.rememberedKey() ?? "")
            } else {
                self.message = "Enter your license key"
            }
        }
        testAPIConnection()
    }

    // MARK: - Remote Control Parser
    func parseRemoteConfig(from json: [String: Any], topStatus: String?, topMessage: String?) {
        if topStatus == "maintenance" {
            self.isKillSwitched = true
            if let msg = topMessage, !msg.isEmpty {
                self.killSwitchMessage = msg
            }
        }
        if topStatus == "update_required" {
            self.isUpdateRequired = true
        }

        guard let rc = json["remote_config"] as? [String: Any] else { return }

        // 1. Kill-Switch
        if let ks = rc["kill_switch"] as? [String: Any] {
            let ksEnabled = ks["enabled"] as? Bool ?? false
            let ksMsg = ks["message"] as? String ?? ""
            if ksEnabled {
                self.isKillSwitched = true
                self.killSwitchMessage = ksMsg.isEmpty ? "⚠️ ระบบกำลังอัปเดตความปลอดภัย " : ksMsg
            } else if topStatus != "maintenance" {
                self.isKillSwitched = false
            }
        }

        // 2. Version Control / Force Update
        if let vc = rc["version_control"] as? [String: Any] {
            let req = vc["update_required"] as? Bool ?? false
            let latest = vc["latest_version"] as? String ?? ""
            let dlUrl = vc["download_url"] as? String ?? ""
            let changelog = vc["changelog"] as? String ?? ""

            self.latestAppVersion = latest
            self.updateDownloadURL = dlUrl
            self.updateChangelog = changelog

            if req || topStatus == "update_required" {
                self.isUpdateRequired = true
            } else {
                self.isUpdateRequired = false
            }
        }

        // 3. Remote Announcement
        if let ann = rc["announcement"] as? [String: Any] {
            let annEnabled = ann["enabled"] as? Bool ?? false
            let annTitle = ann["title"] as? String ?? ""
            let annMsg = ann["message"] as? String ?? ""
            let annLink = ann["link"] as? String ?? ""
            let annBtn = ann["button_text"] as? String ?? "ตกลง"

            if annEnabled && (!annTitle.isEmpty || !annMsg.isEmpty) {
                let announcement = RemoteAnnouncement(
                    title: annTitle,
                    message: annMsg,
                    link: annLink,
                    buttonText: annBtn.isEmpty ? "ตกลง" : annBtn
                )
                let dismissedId = UserDefaults.standard.string(forKey: "rc_dismissed_announcement_id")
                if dismissedId != announcement.id {
                    self.activeAnnouncement = announcement
                    self.showAnnouncement = true
                }
            }
        }
    }

    func dismissAnnouncement() {
        if let active = activeAnnouncement {
            UserDefaults.standard.set(active.id, forKey: "rc_dismissed_announcement_id")
        }
        self.showAnnouncement = false
        self.activeAnnouncement = nil
    }

    func fetchRemoteConfig(completion: ((Bool) -> Void)? = nil) {
        guard let url = URL(string: Self.apiURL) else {
            completion?(false)
            return
        }

        isFetchingRemoteConfig = true
        var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "remote_config"),
            URLQueryItem(name: "app_version", value: Self.currentAppVersion),
            URLQueryItem(name: "device_id", value: deviceUDID),
            URLQueryItem(name: "device_name", value: UIDevice.current.name)
        ]

        guard let targetURL = components?.url else {
            isFetchingRemoteConfig = false
            completion?(false)
            return
        }

        var req = URLRequest(url: targetURL)
        req.httpMethod = "GET"
        req.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        req.timeoutInterval = 8
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("close", forHTTPHeaderField: "Connection")

        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 8
        config.timeoutIntervalForResource = 12
        let session = URLSession(configuration: config, delegate: sessionDelegate, delegateQueue: nil)

        session.dataTask(with: req) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }
                self.isFetchingRemoteConfig = false
                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    let status = json["status"] as? String
                    let msg = json["message"] as? String
                    self.parseRemoteConfig(from: json, topStatus: status, topMessage: msg)
                    completion?(true)
                } else {
                    completion?(false)
                }
            }
        }.resume()
    }

    // MARK: - Test API Connection (Ping Server)
    func testAPIConnection(completion: ((Bool, String) -> Void)? = nil) {
        guard let url = URL(string: Self.apiURL) else {
            self.isAPIOnline = false
            self.apiStatusMessage = "Invalid API URL"
            completion?(false, "Invalid API URL")
            return
        }

        isTestingAPI = true
        var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "ping"),
            URLQueryItem(name: "app_version", value: Self.currentAppVersion),
            URLQueryItem(name: "device_id", value: deviceUDID)
        ]

        guard let pingURL = components?.url else {
            isTestingAPI = false
            return
        }

        var req = URLRequest(url: pingURL)
        req.httpMethod = "GET"
        req.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        req.timeoutInterval = 8
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("close", forHTTPHeaderField: "Connection")

        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 8
        config.timeoutIntervalForResource = 12
        let session = URLSession(configuration: config, delegate: sessionDelegate, delegateQueue: nil)
        let startTime = Date()

        session.dataTask(with: req) { [weak self] data, response, error in
            let elapsed = Int(Date().timeIntervalSince(startTime) * 1000)
            DispatchQueue.main.async {
                guard let self else { return }
                self.isTestingAPI = false

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    let status = json["status"] as? String ?? "error"
                    let serverMsg = json["message"] as? String ?? ""
                    self.parseRemoteConfig(from: json, topStatus: status, topMessage: serverMsg)

                    if status == "success" {
                        self.isAPIOnline = true
                        self.apiLatency = elapsed
                        self.apiStatusMessage = "API Online (\(elapsed)ms)"
                        completion?(true, "API Online (\(elapsed)ms)")
                    } else if status == "maintenance" {
                        self.isAPIOnline = true
                        self.apiLatency = elapsed
                        self.apiStatusMessage = "Maintenance Mode (\(elapsed)ms)"
                        completion?(true, "Maintenance Mode")
                    } else {
                        self.isAPIOnline = true
                        self.apiLatency = elapsed
                        self.apiStatusMessage = "\(serverMsg) (\(elapsed)ms)"
                        completion?(true, serverMsg)
                    }
                } else {
                    self.isAPIOnline = false
                    let errDesc = error?.localizedDescription ?? "Server unresponsive"
                    self.apiStatusMessage = "API Offline (\(errDesc))"
                    completion?(false, errDesc)
                }
            }
        }.resume()
    }

    // MARK: - Main Activate Action
    func activate(key: String) {
        let trimmed = key.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, !isBusy else { return }

        if isKillSwitched {
            message = killSwitchMessage.isEmpty ? "⚠️ ระบบกำลังปิดปรับปรุงฉุกเฉิน (Kill-Switch Active)" : killSwitchMessage
            return
        }

        if isUpdateRequired {
            message = "กรุณาอัปเดตแอปเป็นเวอร์ชั่น \(latestAppVersion) ก่อนใช้งาน"
            return
        }

        if let lastAttemptAt, Date().timeIntervalSince(lastAttemptAt) < 1 {
            message = "Please wait a moment before trying again"
            return
        }
        lastAttemptAt = Date()
        isBusy = true
        message = "Connecting to server…"

        // Developer / Admin Master Bypass Keys (Immediate 100% Unlock)
        let upper = trimmed.uppercased()
        if upper == "WINNER" || upper == "XSTEAM-VIP" || upper == "ADMIN-PASS" || upper == "XSTEAM-2026" {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) { [weak self] in
                guard let self else { return }
                self.isBusy = false
                if self.rememberKey { self.save(trimmed, for: self.keyAccount) }
                self.expirationDate = "Lifetime (Master)"
                self.daysLeft = 9999
                self.isActive = true
                self.message = "Activated with Master Key"
            }
            return
        }

        // Validate via Online Web Panel API (GET first, then POST fallback)
        validateOnlineGET(key: trimmed)
    }

    // MARK: - Check License by Device UDID
    func checkUDIDLicense() {
        guard !isBusy else { return }

        if isKillSwitched {
            message = killSwitchMessage.isEmpty ? "⚠️ ระบบกำลังปิดปรับปรุงฉุกเฉิน (Kill-Switch Active)" : killSwitchMessage
            return
        }

        if isUpdateRequired {
            message = "กรุณาอัปเดตแอปเป็นเวอร์ชั่น \(latestAppVersion) ก่อนใช้งาน"
            return
        }

        isBusy = true
        message = "Checking UDID registration…"

        guard let url = URL(string: Self.apiURL) else {
            isBusy = false
            message = "Invalid API URL"
            return
        }

        var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "check"),
            URLQueryItem(name: "device_id", value: deviceUDID),
            URLQueryItem(name: "device_name", value: UIDevice.current.name),
            URLQueryItem(name: "app_version", value: Self.currentAppVersion)
        ]

        guard let requestURL = components?.url else {
            isBusy = false
            message = "Failed to construct request"
            return
        }

        var req = URLRequest(url: requestURL)
        req.httpMethod = "GET"
        req.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        req.timeoutInterval = 10
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("close", forHTTPHeaderField: "Connection")

        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 10
        config.timeoutIntervalForResource = 15
        let session = URLSession(configuration: config, delegate: sessionDelegate, delegateQueue: nil)

        session.dataTask(with: req) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }
                self.isBusy = false

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    self.processServerResponse(json: json, key: self.rememberedKey() ?? "UDID-ACTIVATED", isSilent: false)
                } else {
                    let errDesc = error?.localizedDescription ?? "Could not connect to server"
                    self.message = "Network error: \(errDesc)"
                }
            }
        }.resume()
    }

    // MARK: - Online Validation (GET Primary)
    private func validateOnlineGET(key: String) {
        guard let url = URL(string: Self.apiURL) else {
            isBusy = false
            message = "Invalid Server URL"
            return
        }

        let deviceId = deviceUDID
        let deviceName = UIDevice.current.name

        var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "check"),
            URLQueryItem(name: "key", value: key),
            URLQueryItem(name: "device_id", value: deviceId),
            URLQueryItem(name: "device_name", value: deviceName),
            URLQueryItem(name: "app_version", value: Self.currentAppVersion)
        ]

        guard let getURL = components?.url else {
            validateOnlinePOST(key: key, getError: nil)
            return
        }

        var req = URLRequest(url: getURL)
        req.httpMethod = "GET"
        req.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        req.timeoutInterval = 10
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("close", forHTTPHeaderField: "Connection")

        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 10
        config.timeoutIntervalForResource = 15
        let session = URLSession(configuration: config, delegate: sessionDelegate, delegateQueue: nil)

        session.dataTask(with: req) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    self.processServerResponse(json: json, key: key, isSilent: false)
                    return
                }

                // If GET failed, try POST fallback
                self.validateOnlinePOST(key: key, getError: error)
            }
        }.resume()
    }

    // MARK: - Online Validation (POST Fallback)
    private func validateOnlinePOST(key: String, getError: Error?) {
        guard let url = URL(string: Self.apiURL) else {
            isBusy = false
            message = "Invalid Server URL"
            return
        }

        let deviceId = deviceUDID
        let deviceName = UIDevice.current.name

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("close", forHTTPHeaderField: "Connection")
        request.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        request.timeoutInterval = 10

        let payload: [String: Any] = [
            "action": "check",
            "key": key,
            "device_id": deviceId,
            "device_name": deviceName,
            "app_version": Self.currentAppVersion
        ]

        request.httpBody = try? JSONSerialization.data(withJSONObject: payload)

        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 10
        config.timeoutIntervalForResource = 15
        let session = URLSession(configuration: config, delegate: sessionDelegate, delegateQueue: nil)

        session.dataTask(with: request) { [weak self] data, response, error in
            DispatchQueue.main.async {
                guard let self else { return }
                self.isBusy = false

                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    self.processServerResponse(json: json, key: key, isSilent: false)
                    return
                }

                // Both GET and POST failed: Handle network diagnostic
                let host = url.host ?? "server"
                let finalError = error?.localizedDescription ?? getError?.localizedDescription ?? "Could not connect to server"
                self.handleNetworkFailure(key: key, host: host, errorDesc: finalError)
            }
        }.resume()
    }

    // MARK: - Handle Network Failure with 100% Usability Fallback
    private func handleNetworkFailure(key: String, host: String, errorDesc: String) {
        // If the key has a valid XSTEAM / OGIOS format, allow offline fallback activation
        let parts = key.split(separator: "-")
        let isStandardFormattedKey = parts.count >= 3 && key.count >= 16

        if isStandardFormattedKey {
            // Activate offline protected mode so user is NEVER blocked by server connection issues!
            self.expirationDate = "30 Days (Offline Verified)"
            self.daysLeft = 30
            self.isActive = true
            self.message = "Activated in Offline Mode (Server was unreachable)"
            if self.rememberKey {
                self.save(key, for: self.keyAccount)
            }
        } else {
            self.message = "Network error [\(host)]: \(errorDesc)"
        }
    }

    // MARK: - Process Server JSON
    private func processServerResponse(json: [String: Any], key: String, isSilent: Bool) {
        self.isBusy = false
        let status = json["status"] as? String ?? "error"
        let serverMsg = json["message"] as? String ?? ""

        // Process Remote Configuration
        self.parseRemoteConfig(from: json, topStatus: status, topMessage: serverMsg)

        if status == "maintenance" {
            self.isActive = false
            self.message = serverMsg.isEmpty ? "⚠️ Emergency maintenance in progress" : serverMsg
            return
        }

        if status == "update_required" {
            self.isActive = false
            self.message = serverMsg.isEmpty ? "Update required" : serverMsg
            return
        }

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
            // Server returned explicit error: Invalid Key, Banned, Expired, HWID Mismatch
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

    // MARK: - Keychain Helper
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

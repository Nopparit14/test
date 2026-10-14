import SwiftUI
import UIKit
import AVFoundation

struct ContentView: View {
    @Environment(\.scenePhase) private var scenePhase
    @EnvironmentObject private var appState: AppState
    @EnvironmentObject private var licenseManager: LicenseManager
    @State private var showSettings = false
    @State private var showCleaner = false
    @StateObject private var patchStore = PatchProjectStore()
    @State private var patchOperationBusy = false
    @State private var patchMessage = "SYSTEM READY — TOGGLE TO ACTIVATE"
    
    // Patch States
    @State private var aimDragEnabled = false
    @State private var aimNeckEnabled = false
    @State private var hspeitoffEnabled = false
    @State private var hyperBalamagicaEnabled = false
    @State private var aimBodyPackageEnabled = false
    @State private var aimChestPackageEnabled = false
    @State private var magicEnabled = false

    var body: some View {
        ZStack {
            // Background: OLED Black with subtle Cyber Aura
            Color.black.ignoresSafeArea()

            RadialGradient(
                gradient: Gradient(colors: [
                    Color(red: 0.75, green: 0.05, blue: 0.15).opacity(0.16),
                    Color(red: 0.05, green: 0.05, blue: 0.08).opacity(0.95),
                    Color.black
                ]),
                center: .top,
                startRadius: 40,
                endRadius: 550
            )
            .ignoresSafeArea()

            ScrollView(showsIndicators: false) {
                VStack(spacing: 20) {
                    brandHeader
                    deviceHUDPanel
                    patchOptionsSection
                    gameLaunchPanel
                    footerStatus
                    developerCredits
                }
                .padding(.horizontal, 16)
                .padding(.top, 14)
                .padding(.bottom, 36)
            }
        }
        .preferredColorScheme(.dark)
        .sheet(isPresented: $showSettings) {
            SettingsView()
                .environmentObject(licenseManager)
                .environmentObject(appState)
        }
        .sheet(isPresented: $showCleaner) {
            CleanerView()
        }
        .sheet(item: $patchStore.passwordRequest, onDismiss: patchStore.cancelUnlock) { _ in
            PatchUnlockPrompt(store: patchStore)
        }
        .onAppear { syncPatchStates() }
        .onChange(of: scenePhase) { phase in
            guard phase == .active, !patchOperationBusy else { return }
            syncPatchStates()
            patchMessage = "SYSTEM READY — TOGGLE TO ACTIVATE"
        }
    }

    // MARK: - Header
    private var brandHeader: some View {
        HStack(spacing: 14) {
            ZStack {
                Circle()
                    .fill(Color(red: 0.85, green: 0.1, blue: 0.2).opacity(0.25))
                    .frame(width: 48, height: 48)
                    .blur(radius: 6)

                Circle()
                    .fill(Color(red: 0.08, green: 0.09, blue: 0.12))
                    .frame(width: 44, height: 44)
                    .overlay(Circle().stroke(Color(red: 0.85, green: 0.1, blue: 0.2).opacity(0.6), lineWidth: 1.5))

                Image(systemName: "shield.checkered")
                    .font(.system(size: 20, weight: .black))
                    .foregroundStyle(
                        LinearGradient(
                            colors: [.white, Color(red: 0.95, green: 0.25, blue: 0.35)],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )
            }

            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text("OGIOS")
                        .font(.system(size: 24, weight: .black, design: .rounded))
                        .tracking(2.5)
                        .foregroundStyle(.white)

                    Text("PRO")
                        .font(.system(size: 9, weight: .heavy, design: .rounded))
                        .foregroundStyle(Color(red: 0.9, green: 0.2, blue: 0.3))
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(Color(red: 0.9, green: 0.2, blue: 0.3).opacity(0.18), in: Capsule())
                }

                Text("CONTROL & INJECTION MENU")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .tracking(1.4)
                    .foregroundStyle(Color.white.opacity(0.45))
            }

            Spacer()

            // Settings Button
            Button {
                showSettings = true
            } label: {
                ZStack {
                    Circle()
                        .fill(Color(red: 0.10, green: 0.11, blue: 0.15))
                        .frame(width: 44, height: 44)
                        .overlay(Circle().stroke(Color.white.opacity(0.12), lineWidth: 1))

                    Image(systemName: "gearshape.fill")
                        .font(.system(size: 18, weight: .bold))
                        .foregroundStyle(.white.opacity(0.85))
                }
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Settings")
        }
        .padding(.horizontal, 2)
    }

    // MARK: - Device HUD Panel (Modern 3-Column Gauges)
    private var deviceHUDPanel: some View {
        HStack(spacing: 10) {
            hudItem(icon: "apple.logo", title: "iOS VERSION", value: AppInfo.osVersion, color: .white)
            hudItem(icon: "iphone", title: "DEVICE", value: AppInfo.displayMachineName, color: Color(red: 0.3, green: 0.7, blue: 1.0))
            hudItem(icon: "bolt.shield.fill", title: "ENGINE", value: "READY", color: Color(red: 0.2, green: 0.85, blue: 0.45))
        }
    }

    private func hudItem(icon: String, title: String, value: String, color: Color) -> some View {
        VStack(spacing: 6) {
            Image(systemName: icon)
                .font(.system(size: 16, weight: .bold))
                .foregroundStyle(color)

            Text(value)
                .font(.system(size: 13, weight: .black, design: .rounded))
                .foregroundStyle(.white)
                .lineLimit(1)
                .minimumScaleFactor(0.75)

            Text(title)
                .font(.system(size: 8, weight: .heavy, design: .rounded))
                .tracking(0.8)
                .foregroundStyle(Color.white.opacity(0.4))
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 14)
        .padding(.horizontal, 8)
        .background(
            RoundedRectangle(cornerRadius: 18, style: .continuous)
                .fill(Color(red: 0.06, green: 0.07, blue: 0.09).opacity(0.85))
        )
        .overlay(
            RoundedRectangle(cornerRadius: 18, style: .continuous)
                .stroke(Color.white.opacity(0.08), lineWidth: 1)
        )
    }

    // MARK: - Patch Options Section (List of Toggle Switches)
    private var patchOptionsSection: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Label("PATCH OPTIONS", systemImage: "slider.horizontal.3")
                    .font(.system(size: 13, weight: .black, design: .rounded))
                    .tracking(1.4)
                    .foregroundStyle(Color(red: 0.95, green: 0.2, blue: 0.3))

                Spacer()

                HStack(spacing: 5) {
                    Circle()
                        .fill(patchOperationBusy ? Color.yellow : (patchMessage.localizedCaseInsensitiveContains("successful") ? Color.green : Color.white.opacity(0.4)))
                        .frame(width: 6, height: 6)

                    Text(patchOperationBusy ? "BUSY" : "LIVE")
                        .font(.system(size: 9, weight: .heavy, design: .rounded))
                        .foregroundStyle(Color.white.opacity(0.6))
                }
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.white.opacity(0.06), in: Capsule())
            }
            .padding(.horizontal, 2)

            // Modern Toggle List
            VStack(spacing: 10) {
                modernPatchToggleRow(
                    name: "Aim Drag",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (6).3105",
                    icon: "scope",
                    accentColor: Color(red: 0.95, green: 0.22, blue: 0.32),
                    isEnabled: $aimDragEnabled
                )

                modernPatchToggleRow(
                    name: "Aim Neck",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (7).3105",
                    icon: "person.crop.circle.badge.checkmark",
                    accentColor: Color(red: 0.95, green: 0.22, blue: 0.32),
                    isEnabled: $aimNeckEnabled
                )

                modernPatchToggleRow(
                    name: "Antenna Position",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (8).3105",
                    icon: "antenna.radiowaves.left.and.right",
                    accentColor: Color(red: 0.25, green: 0.72, blue: 1.0),
                    isEnabled: $hspeitoffEnabled
                )

                modernPatchToggleRow(
                    name: "144 FPS Unlock",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (10).3105",
                    icon: "speedometer",
                    accentColor: Color(red: 0.2, green: 0.88, blue: 0.5),
                    isEnabled: $hyperBalamagicaEnabled
                )

                modernPatchToggleRow(
                    name: "Aim Body",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (12).3105",
                    icon: "figure.stand",
                    accentColor: Color(red: 0.95, green: 0.22, blue: 0.32),
                    isEnabled: $aimBodyPackageEnabled
                )

                modernPatchToggleRow(
                    name: "Aim Chest",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (2).3105",
                    icon: "shield.lefthalf.filled",
                    accentColor: Color(red: 0.95, green: 0.22, blue: 0.32),
                    isEnabled: $aimChestPackageEnabled
                )

                modernPatchToggleRow(
                    name: "Magic Bullet",
                    target: "FREE FIRE • NORMAL",
                    package: "OGIOS File (14).3105",
                    icon: "wand.and.stars",
                    accentColor: Color(red: 0.85, green: 0.35, blue: 1.0),
                    isEnabled: $magicEnabled
                )
            }

            // Realtime Status Bar
            HStack(spacing: 8) {
                if patchOperationBusy {
                    ProgressView().tint(.white).scaleEffect(0.7)
                } else {
                    Circle()
                        .fill(patchMessage.localizedCaseInsensitiveContains("successful") ? Color.green : Color(red: 0.9, green: 0.2, blue: 0.3))
                        .frame(width: 8, height: 8)
                }

                Text(patchOperationBusy ? "APPLYING PATCH MODIFICATIONS…" : patchMessage)
                    .font(.system(size: 11, weight: .bold, design: .rounded))
                    .foregroundStyle(.white.opacity(0.85))
                    .lineLimit(1)
                Spacer()
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 11)
            .background(Color(red: 0.05, green: 0.06, blue: 0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(Color.white.opacity(0.08), lineWidth: 1))
        }
    }

    // MARK: - Modern Toggle Switch Row Component
    private func modernPatchToggleRow(
        name: String,
        target: String,
        package: String,
        icon: String,
        accentColor: Color,
        isEnabled: Binding<Bool>
    ) -> some View {
        Button {
            togglePatch(packageFilename: package, state: isEnabled)
        } label: {
            HStack(spacing: 14) {
                // Icon Box
                ZStack {
                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                        .fill(isEnabled.wrappedValue ? accentColor.opacity(0.22) : Color.white.opacity(0.05))
                        .frame(width: 44, height: 44)

                    Image(systemName: icon)
                        .font(.system(size: 18, weight: .bold))
                        .foregroundStyle(isEnabled.wrappedValue ? accentColor : Color.white.opacity(0.4))
                }

                // Name & Target Info
                VStack(alignment: .leading, spacing: 3) {
                    Text(name)
                        .font(.system(size: 15, weight: .black, design: .rounded))
                        .foregroundStyle(.white)

                    HStack(spacing: 6) {
                        Text(target)
                            .font(.system(size: 9, weight: .bold, design: .rounded))
                            .tracking(1.0)
                            .foregroundStyle(isEnabled.wrappedValue ? accentColor : Color.white.opacity(0.35))

                        Circle().fill(Color.white.opacity(0.2)).frame(width: 3, height: 3)

                        Text(isEnabled.wrappedValue ? "ACTIVE" : "OFF")
                            .font(.system(size: 9, weight: .heavy, design: .rounded))
                            .foregroundStyle(isEnabled.wrappedValue ? Color.green : Color.white.opacity(0.35))
                    }
                }

                Spacer()

                // Real iOS Style Toggle Switch (สวิตช์เปิด-ปิด ชัดเจน 100%)
                ZStack(alignment: isEnabled.wrappedValue ? .trailing : .leading) {
                    Capsule()
                        .fill(
                            isEnabled.wrappedValue ?
                            LinearGradient(colors: [Color(red: 0.15, green: 0.85, blue: 0.45), Color(red: 0.1, green: 0.7, blue: 0.35)], startPoint: .topLeading, endPoint: .bottomTrailing)
                            :
                            LinearGradient(colors: [Color.white.opacity(0.12), Color.white.opacity(0.08)], startPoint: .topLeading, endPoint: .bottomTrailing)
                        )
                        .frame(width: 52, height: 30)

                    Circle()
                        .fill(.white)
                        .frame(width: 24, height: 24)
                        .padding(.horizontal, 3)
                        .shadow(color: Color.black.opacity(0.4), radius: 3, x: 0, y: 1)
                }
                .animation(.spring(response: 0.25, dampingFraction: 0.7), value: isEnabled.wrappedValue)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .fill(Color(red: 0.06, green: 0.07, blue: 0.09).opacity(0.85))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .stroke(
                        isEnabled.wrappedValue ? accentColor.opacity(0.6) : Color.white.opacity(0.08),
                        lineWidth: isEnabled.wrappedValue ? 1.5 : 1
                    )
            )
            .shadow(color: isEnabled.wrappedValue ? accentColor.opacity(0.2) : Color.clear, radius: 10, y: 3)
        }
        .buttonStyle(.plain)
        .disabled(patchOperationBusy)
        .opacity(patchOperationBusy ? 0.6 : 1)
    }

    // MARK: - Game Launch Panel
    private var gameLaunchPanel: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label("LAUNCH GAME", systemImage: "gamecontroller.fill")
                .font(.system(size: 13, weight: .black, design: .rounded))
                .tracking(1.4)
                .foregroundStyle(Color(red: 0.95, green: 0.2, blue: 0.3))
                .padding(.horizontal, 2)

            HStack(spacing: 12) {
                // Free Fire Normal Button
                Button {
                    openGame(scheme: "freefireth")
                } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "arrow.up.right.square.fill")
                            .font(.system(size: 20, weight: .bold))
                            .foregroundStyle(Color(red: 0.95, green: 0.2, blue: 0.3))

                        VStack(alignment: .leading, spacing: 2) {
                            Text("FREE FIRE")
                                .font(.system(size: 13, weight: .black, design: .rounded))
                                .foregroundStyle(.white)

                            Text("Launch Normal")
                                .font(.system(size: 10, weight: .bold, design: .rounded))
                                .foregroundStyle(Color.white.opacity(0.45))
                        }
                        Spacer()
                    }
                    .padding(14)
                    .background(Color(red: 0.06, green: 0.07, blue: 0.09), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color(red: 0.95, green: 0.2, blue: 0.3).opacity(0.4), lineWidth: 1))
                }
                .buttonStyle(.plain)

                // Cleaner Button
                Button {
                    showCleaner = true
                } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "trash.slash.fill")
                            .font(.system(size: 18, weight: .bold))
                            .foregroundStyle(Color(red: 0.3, green: 0.7, blue: 1.0))

                        VStack(alignment: .leading, spacing: 2) {
                            Text("CLEAN CACHE")
                                .font(.system(size: 13, weight: .black, design: .rounded))
                                .foregroundStyle(.white)

                            Text("Purge Temporary")
                                .font(.system(size: 10, weight: .bold, design: .rounded))
                                .foregroundStyle(Color.white.opacity(0.45))
                        }
                        Spacer()
                    }
                    .padding(14)
                    .background(Color(red: 0.06, green: 0.07, blue: 0.09), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.12), lineWidth: 1))
                }
                .buttonStyle(.plain)
            }
        }
    }

    // MARK: - Footer Status
    private var footerStatus: some View {
        HStack(spacing: 8) {
            Circle().fill(Color.green).frame(width: 8, height: 8).shadow(color: .green, radius: 4)
            Text("ENGINE SECURE • ANTI-BAN PROTECTED")
                .font(.system(size: 10, weight: .black, design: .rounded))
                .tracking(1.2)
                .foregroundStyle(.white.opacity(0.75))
            Spacer()
            Text("v1.2.0")
                .font(.system(size: 9, weight: .heavy, design: .rounded))
                .foregroundStyle(Color(red: 0.9, green: 0.2, blue: 0.3))
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(Color(red: 0.05, green: 0.06, blue: 0.08), in: Capsule())
        .overlay(Capsule().stroke(Color.white.opacity(0.08), lineWidth: 1))
    }

    // MARK: - Developer Credits
    private var developerCredits: some View {
        VStack(spacing: 8) {
            Text("Developed by OGIOS TEAM")
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(Color.white.opacity(0.55))

            Button {
                if let url = URL(string: "https://t.me/ogios1") {
                    UIApplication.shared.open(url)
                }
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "paperplane.fill")
                    Text("Official Telegram @ogios1")
                }
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(.white)
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(Color(red: 0.9, green: 0.2, blue: 0.3).opacity(0.2), in: Capsule())
                .overlay(Capsule().stroke(Color(red: 0.9, green: 0.2, blue: 0.3).opacity(0.4), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
        .padding(.top, 4)
    }

    // MARK: - Logic
    private func syncPatchStates() {
        aimDragEnabled = isPatchActive("OGIOS File (6).3105")
        aimNeckEnabled = isPatchActive("OGIOS File (7).3105")
        hspeitoffEnabled = isPatchActive("OGIOS File (8).3105")
        hyperBalamagicaEnabled = isPatchActive("OGIOS File (10).3105")
        aimBodyPackageEnabled = isPatchActive("OGIOS File (12).3105")
        aimChestPackageEnabled = isPatchActive("OGIOS File (2).3105")
        magicEnabled = isPatchActive("OGIOS File (14).3105")
    }

    private func isPatchActive(_ packageFilename: String) -> Bool {
        patchStore.items.first(where: { $0.packageURL.lastPathComponent.caseInsensitiveCompare(packageFilename) == .orderedSame })
            .flatMap { DevicePatchService.latestReceipt(projectID: $0.id) } != nil
    }

    private enum PatchActionResult {
        case applied
        case restored
        case unavailable(String)
    }

    private func setPatchState(for packageFilename: String, enabled: Bool) {
        switch packageFilename {
        case "OGIOS File (6).3105": aimDragEnabled = enabled
        case "OGIOS File (7).3105": aimNeckEnabled = enabled
        case "OGIOS File (8).3105": hspeitoffEnabled = enabled
        case "OGIOS File (10).3105": hyperBalamagicaEnabled = enabled
        case "OGIOS File (12).3105": aimBodyPackageEnabled = enabled
        case "OGIOS File (2).3105": aimChestPackageEnabled = enabled
        case "OGIOS File (14).3105": magicEnabled = enabled
        default: break
        }
    }

    private func togglePatch(packageFilename: String, state: Binding<Bool>) {
        guard !patchOperationBusy else { return }
        guard let item = patchStore.items.first(where: { $0.packageURL.lastPathComponent.caseInsensitiveCompare(packageFilename) == .orderedSame }) else {
            patchMessage = "ERROR — PACKAGE NOT FOUND"
            return
        }

        let wasEnabled = state.wrappedValue
        patchOperationBusy = true
        patchMessage = "PROCESSING — \(packageFilename)"
        let project = item.project
        let projectID = item.id

        DispatchQueue.global(qos: .userInitiated).async {
            let result: PatchActionResult
            do {
                if wasEnabled {
                    guard let receipt = DevicePatchService.latestReceipt(projectID: projectID) else {
                        result = .unavailable("NO ACTIVE RECEIPT FOUND")
                        DispatchQueue.main.async {
                            self.setPatchState(for: packageFilename, enabled: false)
                            self.patchMessage = "OFF — NO ACTIVE PATCH"
                            self.patchOperationBusy = false
                        }
                        return
                    }
                    try DevicePatchService.restore(receipt: receipt)
                    result = .restored
                } else {
                    guard let project else {
                        result = .unavailable("PASSWORD REQUIRED — UNLOCK PACKAGE")
                        DispatchQueue.main.async {
                            self.patchStore.requestUnlock(for: item)
                            self.patchMessage = "ENTER PACKAGE PASSWORD"
                            self.patchOperationBusy = false
                        }
                        return
                    }
                    _ = try DevicePatchService.apply(project: project)
                    result = .applied
                }
            } catch {
                result = .unavailable("FAILED — \(String(describing: error))")
            }

            DispatchQueue.main.async {
                switch result {
                case .applied:
                    self.setPatchState(for: packageFilename, enabled: true)
                    self.patchMessage = "INJECT SUCCESSFUL: \(packageFilename)"
                    PatchAudioFeedback.bypassActivated()
                case .restored:
                    self.setPatchState(for: packageFilename, enabled: false)
                    self.patchMessage = "RESTORE SUCCESSFUL: \(packageFilename)"
                    PatchAudioFeedback.originalRestored()
                case .unavailable(let message):
                    self.patchMessage = message
                }
                self.patchOperationBusy = false
            }
        }
    }

    private func openGame(scheme: String) {
        guard let url = URL(string: "\(scheme)://") else { return }
        UIApplication.shared.open(url, options: [:]) { _ in }
    }
}

private enum PatchAudioFeedback {
    private static let synthesizer = AVSpeechSynthesizer()
    static func bypassActivated() { speak("Bypass ativado") }
    static func originalRestored() { speak("Bypass desativado") }
    private static func speak(_ message: String) {
        let session = AVAudioSession.sharedInstance()
        try? session.setCategory(.playback, mode: .spokenAudio, options: [.duckOthers])
        try? session.setActive(true, options: [])
        synthesizer.stopSpeaking(at: .immediate)
        let utterance = AVSpeechUtterance(string: message)
        let voices = AVSpeechSynthesisVoice.speechVoices()
        utterance.voice = voices.first(where: {
            ($0.language.hasPrefix("pt-BR") || $0.language.hasPrefix("pt-PT") || $0.language.hasPrefix("pt")) && $0.gender == .female && $0.quality == .enhanced
        }) ?? voices.first(where: {
            $0.language.hasPrefix("pt-BR") || $0.language.hasPrefix("pt-PT") || $0.language.hasPrefix("pt")
        }) ?? AVSpeechSynthesisVoice(language: "pt-BR")
        utterance.rate = 0.43
        utterance.pitchMultiplier = 1.10
        utterance.volume = 0.90
        synthesizer.speak(utterance)
    }
}

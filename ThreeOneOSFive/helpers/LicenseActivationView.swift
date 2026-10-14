import SwiftUI
import UIKit

struct LicenseActivationView: View {
    @ObservedObject var manager: LicenseManager
    @State private var key = ""
    @FocusState private var keyFocused: Bool
    @State private var autoConnect = true
    @State private var isPulsing = false
    @State private var showCopiedAlert = false
    @State private var copiedMessage = ""

    // Electric Blue & Cyan Color Palette
    private let neonCyan = Color(red: 0.0, green: 0.85, blue: 1.0)
    private let electricBlue = Color(red: 0.0, green: 0.48, blue: 1.0)
    private let deepBlue = Color(red: 0.02, green: 0.22, blue: 0.65)
    private let cardBackground = Color(red: 0.04, green: 0.06, blue: 0.10).opacity(0.88)
    private let strokeColor = Color(red: 0.12, green: 0.35, blue: 0.70).opacity(0.4)

    var body: some View {
        NavigationStack {
            ZStack {
                // 1. OLED Pitch Black Background
                Color.black.ignoresSafeArea()

                // Cyber Electric Blue Radial Ambient Glow
                RadialGradient(
                    gradient: Gradient(colors: [
                        electricBlue.opacity(0.25),
                        Color(red: 0.01, green: 0.04, blue: 0.12).opacity(0.95),
                        Color.black
                    ]),
                    center: .top,
                    startRadius: 40,
                    endRadius: 550
                )
                .ignoresSafeArea()

                // Animated Pulsing Ambient Orb
                Circle()
                    .fill(neonCyan.opacity(0.12))
                    .frame(width: 320, height: 320)
                    .blur(radius: 90)
                    .offset(y: isPulsing ? -140 : -100)
                    .animation(.easeInOut(duration: 3.5).repeatForever(autoreverses: true), value: isPulsing)

                ScrollViewReader { proxy in
                    ScrollView(showsIndicators: false) {
                        VStack(spacing: 20) {
                            Spacer(minLength: 16)

                            // 2. High-Tech Logo Header
                            headerSection

                            // 3. Device UDID Card (เอาเป็นแบบ getudid)
                            deviceUDIDCard

                            // 4. Main Glassmorphic Activation Card (ใส่คีย์ + เช็ค API)
                            VStack(spacing: 20) {
                                cardHeader

                                // Key Input Field with Paste & Clear Buttons
                                keyInputField

                                // Toggles Section
                                togglesSection

                                // Primary Action Button (VERIFY & LOGIN)
                                verifyButton

                                // Status / Error Banner
                                if let message = manager.message {
                                    statusBanner(message: message)
                                }

                                // Interactive API Check Section (เช็ค API ด้วย)
                                apiHealthDiagnosticRow

                                // Support Links (Telegram & Get License)
                                supportLinks
                            }
                            .padding(22)
                            .background(
                                RoundedRectangle(cornerRadius: 28, style: .continuous)
                                    .fill(cardBackground)
                                    .background(.ultraThinMaterial.opacity(0.6))
                            )
                            .overlay(
                                RoundedRectangle(cornerRadius: 28, style: .continuous)
                                    .stroke(
                                        LinearGradient(
                                            colors: [
                                                Color.white.opacity(0.20),
                                                neonCyan.opacity(0.45),
                                                electricBlue.opacity(0.3),
                                                Color.white.opacity(0.05)
                                            ],
                                            startPoint: .topLeading,
                                            endPoint: .bottomTrailing
                                        ),
                                        lineWidth: 1.2
                                    )
                            )
                            .shadow(color: electricBlue.opacity(0.25), radius: 30, y: 15)
                            .padding(.horizontal, 20)
                            .id("activation-card")

                            // 5. Footer Information
                            footerSection

                            Spacer(minLength: 30)
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .scrollDismissesKeyboard(.interactively)
                    .onChange(of: keyFocused) { focused in
                        guard focused else { return }
                        withAnimation(.easeOut(duration: 0.25)) {
                            proxy.scrollTo("activation-card", anchor: .center)
                        }
                    }
                }

                // Toast Alert for Copy Actions
                if showCopiedAlert {
                    VStack {
                        Spacer()
                        HStack(spacing: 8) {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundStyle(neonCyan)
                            Text(copiedMessage)
                                .font(.system(size: 13, weight: .bold, design: .rounded))
                                .foregroundStyle(.white)
                        }
                        .padding(.horizontal, 18)
                        .padding(.vertical, 12)
                        .background(Color(red: 0.05, green: 0.12, blue: 0.25).opacity(0.95), in: Capsule())
                        .overlay(Capsule().stroke(neonCyan.opacity(0.5), lineWidth: 1))
                        .shadow(color: neonCyan.opacity(0.3), radius: 15)
                        .padding(.bottom, 40)
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                    }
                    .animation(.spring(response: 0.35, dampingFraction: 0.8), value: showCopiedAlert)
                }
            }
        }
        .preferredColorScheme(.dark)
        .onAppear {
            isPulsing = true
            if let saved = manager.rememberedKey(), !saved.isEmpty {
                key = saved
            }
            manager.testAPIConnection()
        }
    }

    // MARK: - 1. Header Section (Electric Blue Cyber Shield)
    private var headerSection: some View {
        VStack(spacing: 8) {
            ZStack {
                // Outer Cyan Glow
                Circle()
                    .fill(electricBlue.opacity(0.35))
                    .frame(width: 86, height: 86)
                    .blur(radius: 14)

                if let uiImage = UIImage(named: "AppLogo") {
                    Image(uiImage: uiImage)
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                        .frame(width: 76, height: 76)
                        .clipShape(Circle())
                        .overlay(Circle().stroke(neonCyan.opacity(0.85), lineWidth: 2))
                        .shadow(color: neonCyan.opacity(0.4), radius: 10)
                } else {
                    // High-Tech Gradient Disc
                    Circle()
                        .fill(
                            LinearGradient(
                                colors: [Color(red: 0.08, green: 0.16, blue: 0.32), Color(red: 0.02, green: 0.05, blue: 0.12)],
                                startPoint: .topLeading,
                                endPoint: .bottomTrailing
                            )
                        )
                        .frame(width: 76, height: 76)
                        .overlay(Circle().stroke(neonCyan.opacity(0.7), lineWidth: 1.5))

                    // Shield Icon with Neon Gradient
                    Image(systemName: "checkerboard.shield")
                        .font(.system(size: 34, weight: .black))
                        .foregroundStyle(
                            LinearGradient(
                                colors: [.white, neonCyan],
                                startPoint: .top,
                                endPoint: .bottom
                            )
                        )
                }
            }
            .padding(.bottom, 2)

            Text("XSTEAM")
                .font(.system(size: 32, weight: .black, design: .rounded))
                .tracking(3)
                .foregroundStyle(
                    LinearGradient(
                        colors: [.white, Color(white: 0.88)],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )

            HStack(spacing: 6) {
                Circle().fill(neonCyan).frame(width: 6, height: 6)
                Text("SECURE ACTIVATION SYSTEM")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .tracking(1.8)
                    .foregroundStyle(neonCyan)
            }
        }
    }

    // MARK: - 2. Device UDID Card (Get UDID Feature)
    private var deviceUDIDCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                HStack(spacing: 6) {
                    Image(systemName: "iphone.gen3")
                        .font(.system(size: 12, weight: .bold))
                        .foregroundStyle(neonCyan)
                    Text("DEVICE UDID / HARDWARE ID")
                        .font(.system(size: 10, weight: .bold, design: .rounded))
                        .foregroundStyle(Color.white.opacity(0.7))
                        .tracking(1.2)
                }

                Spacer()

                // Copy UDID Button
                Button {
                    copyToClipboard(text: manager.deviceUDID, label: "UDID copied to clipboard!")
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: "doc.on.doc.fill")
                        Text("COPY UDID")
                    }
                    .font(.system(size: 10, weight: .heavy, design: .rounded))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 5)
                    .background(
                        LinearGradient(colors: [electricBlue, deepBlue], startPoint: .leading, endPoint: .trailing),
                        in: Capsule()
                    )
                    .overlay(Capsule().stroke(neonCyan.opacity(0.4), lineWidth: 1))
                }
                .buttonStyle(.plain)
            }

            // UDID Text display box
            HStack {
                Text(manager.deviceUDID)
                    .font(.system(size: 11, weight: .medium, design: .monospaced))
                    .foregroundStyle(neonCyan.opacity(0.95))
                    .lineLimit(1)
                    .truncationMode(.middle)

                Spacer()

                // Fast Check UDID Registration button
                Button {
                    manager.checkUDIDLicense()
                } label: {
                    HStack(spacing: 3) {
                        Image(systemName: "arrow.triangle.2.circlepath")
                        Text("CHECK")
                    }
                    .font(.system(size: 9, weight: .bold, design: .rounded))
                    .foregroundStyle(neonCyan)
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 9)
            .background(Color(red: 0.02, green: 0.04, blue: 0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .stroke(neonCyan.opacity(0.2), lineWidth: 1)
            )
        }
        .padding(16)
        .background(
            RoundedRectangle(cornerRadius: 22, style: .continuous)
                .fill(cardBackground)
                .background(.ultraThinMaterial.opacity(0.5))
        )
        .overlay(
            RoundedRectangle(cornerRadius: 22, style: .continuous)
                .stroke(strokeColor, lineWidth: 1)
        )
        .padding(.horizontal, 20)
    }

    // MARK: - 3. Card Header
    private var cardHeader: some View {
        HStack {
            VStack(alignment: .leading, spacing: 3) {
                Text("LICENSE LOGIN")
                    .font(.system(size: 16, weight: .black, design: .rounded))
                    .foregroundStyle(.white)
                    .tracking(1)

                Text("Enter your authentication key to unlock")
                    .font(.system(size: 11, weight: .medium, design: .rounded))
                    .foregroundStyle(Color.white.opacity(0.55))
            }
            Spacer()

            // Server Online Status Pill
            HStack(spacing: 5) {
                Circle()
                    .fill(manager.isAPIOnline == true ? Color.green : (manager.isAPIOnline == false ? Color.red : Color.orange))
                    .frame(width: 7, height: 7)

                Text(manager.isAPIOnline == true ? "ONLINE" : (manager.isAPIOnline == false ? "OFFLINE" : "CHECKING"))
                    .font(.system(size: 9, weight: .heavy, design: .rounded))
                    .foregroundStyle(manager.isAPIOnline == true ? Color.green : (manager.isAPIOnline == false ? Color.red : Color.orange))
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(
                (manager.isAPIOnline == true ? Color.green : (manager.isAPIOnline == false ? Color.red : Color.orange)).opacity(0.12),
                in: Capsule()
            )
            .overlay(
                Capsule().stroke(
                    (manager.isAPIOnline == true ? Color.green : (manager.isAPIOnline == false ? Color.red : Color.orange)).opacity(0.35),
                    lineWidth: 1
                )
            )
        }
    }

    // MARK: - 4. Key Input Field
    private var keyInputField: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("LICENSE KEY")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .foregroundStyle(Color.white.opacity(0.65))
                    .tracking(1.2)
                Spacer()

                // Paste from Clipboard Button
                Button {
                    if let clip = UIPasteboard.general.string {
                        key = clip.trimmingCharacters(in: .whitespacesAndNewlines)
                    }
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: "doc.on.clipboard")
                        Text("PASTE")
                    }
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .foregroundStyle(neonCyan)
                }
                .buttonStyle(.plain)
            }

            HStack(spacing: 12) {
                Image(systemName: "key.horizontal.fill")
                    .font(.system(size: 16, weight: .bold))
                    .foregroundStyle(key.isEmpty ? Color.white.opacity(0.3) : neonCyan)

                TextField("XSTEAM-XXXX-XXXX-XXXX", text: $key)
                    .focused($keyFocused)
                    .textInputAutocapitalization(.characters)
                    .autocorrectionDisabled()
                    .submitLabel(.go)
                    .onSubmit { activate() }
                    .font(.system(size: 15, weight: .bold, design: .monospaced))
                    .foregroundStyle(.white)

                if !key.isEmpty {
                    Button {
                        key = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.system(size: 16))
                            .foregroundStyle(Color.white.opacity(0.4))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16)
            .frame(height: 56)
            .background(Color(red: 0.02, green: 0.03, blue: 0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .stroke(
                        keyFocused ? neonCyan : Color.white.opacity(0.12),
                        lineWidth: keyFocused ? 1.5 : 1
                    )
            )
        }
    }

    // MARK: - 5. Toggles Section (Electric Blue Switches)
    private var togglesSection: some View {
        VStack(spacing: 10) {
            // Toggle 1: Remember Key
            modernToggleRow(
                icon: "lock.shield.fill",
                title: "Remember License Key",
                subtitle: "Save key securely on this device",
                isOn: $manager.rememberKey
            )

            // Toggle 2: Auto Connect
            modernToggleRow(
                icon: "network",
                title: "Auto Server Connect",
                subtitle: "Sync active license automatically",
                isOn: $autoConnect
            )
        }
        .padding(.vertical, 4)
    }

    private func modernToggleRow(icon: String, title: String, subtitle: String, isOn: Binding<Bool>) -> some View {
        HStack(spacing: 12) {
            ZStack {
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(isOn.wrappedValue ? electricBlue.opacity(0.25) : Color.white.opacity(0.06))
                    .frame(width: 36, height: 36)

                Image(systemName: icon)
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(isOn.wrappedValue ? neonCyan : Color.white.opacity(0.4))
            }

            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.system(size: 12, weight: .bold, design: .rounded))
                    .foregroundStyle(.white)

                Text(subtitle)
                    .font(.system(size: 10, weight: .medium, design: .rounded))
                    .foregroundStyle(Color.white.opacity(0.45))
            }

            Spacer()

            Toggle("", isOn: isOn)
                .labelsHidden()
                .tint(electricBlue)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
        .background(Color(red: 0.03, green: 0.04, blue: 0.08).opacity(0.7), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.08), lineWidth: 1))
    }

    // MARK: - 6. Verify Button (Electric Blue Glowing Gradient)
    private var verifyButton: some View {
        Button(action: activate) {
            HStack(spacing: 10) {
                if manager.isBusy {
                    ProgressView()
                        .tint(.white)
                } else {
                    Image(systemName: "arrow.right.circle.fill")
                        .font(.system(size: 17, weight: .bold))
                }

                Text(manager.isBusy ? "AUTHENTICATING…" : "VERIFY & LOGIN")
                    .font(.system(size: 14, weight: .heavy, design: .rounded))
                    .tracking(1.2)
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, minHeight: 56)
            .background(
                LinearGradient(
                    colors: [
                        Color(red: 0.0, green: 0.55, blue: 1.0),
                        Color(red: 0.0, green: 0.28, blue: 0.85)
                    ],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                ),
                in: RoundedRectangle(cornerRadius: 18, style: .continuous)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .stroke(neonCyan.opacity(0.5), lineWidth: 1)
            )
            .shadow(color: electricBlue.opacity(0.45), radius: 18, y: 6)
        }
        .buttonStyle(.plain)
        .disabled(key.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || manager.isBusy)
        .opacity(key.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? 0.45 : 1)
    }

    // MARK: - 7. Status Banner
    private func statusBanner(message: String) -> some View {
        let isSuccess = message.localizedCaseInsensitiveContains("success") || message.localizedCaseInsensitiveContains("active")
        let isError = message.localizedCaseInsensitiveContains("error") || message.localizedCaseInsensitiveContains("invalid") || message.localizedCaseInsensitiveContains("banned") || message.localizedCaseInsensitiveContains("expired")

        return HStack(spacing: 8) {
            Image(systemName: isSuccess ? "checkmark.circle.fill" : (isError ? "exclamationmark.triangle.fill" : "info.circle.fill"))
                .foregroundStyle(isSuccess ? Color.green : (isError ? Color.orange : neonCyan))

            Text(message)
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(.white.opacity(0.92))
                .multilineTextAlignment(.leading)
            Spacer()
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 11)
        .background(
            isSuccess ? Color.green.opacity(0.12) : (isError ? Color.orange.opacity(0.12) : electricBlue.opacity(0.12)),
            in: RoundedRectangle(cornerRadius: 14, style: .continuous)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 14, style: .continuous)
                .stroke(isSuccess ? Color.green.opacity(0.3) : (isError ? Color.orange.opacity(0.35) : electricBlue.opacity(0.3)), lineWidth: 1)
        )
    }

    // MARK: - 8. API Health Diagnostic Row (เช็ค API ด้วย)
    private var apiHealthDiagnosticRow: some View {
        HStack(spacing: 10) {
            HStack(spacing: 6) {
                if manager.isTestingAPI {
                    ProgressView()
                        .scaleEffect(0.7)
                        .tint(neonCyan)
                } else {
                    Circle()
                        .fill(manager.isAPIOnline == true ? Color.green : (manager.isAPIOnline == false ? Color.red : Color.orange))
                        .frame(width: 8, height: 8)
                }

                Text(manager.apiStatusMessage)
                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                    .foregroundStyle(Color.white.opacity(0.75))
                    .lineLimit(1)
            }

            Spacer()

            // Ping API Button
            Button {
                manager.testAPIConnection()
            } label: {
                HStack(spacing: 3) {
                    Image(systemName: "bolt.horizontal.fill")
                    Text("PING API")
                }
                .font(.system(size: 9, weight: .heavy, design: .rounded))
                .foregroundStyle(neonCyan)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(neonCyan.opacity(0.12), in: Capsule())
                .overlay(Capsule().stroke(neonCyan.opacity(0.3), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color(red: 0.02, green: 0.04, blue: 0.07), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(Color.white.opacity(0.06), lineWidth: 1))
    }

    // MARK: - 9. Support Links (Electric Blue Style)
    private var supportLinks: some View {
        HStack(spacing: 12) {
            Button {
                if let url = URL(string: "https://t.me/ogios1") {
                    UIApplication.shared.open(url)
                }
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "paperplane.fill")
                    Text("Telegram Channel")
                }
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(Color.white.opacity(0.75))
                .frame(maxWidth: .infinity, minHeight: 38)
                .background(Color.white.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)

            Button {
                if let url = URL(string: "https://t.me/ogios1") {
                    UIApplication.shared.open(url)
                }
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "cart.fill")
                    Text("Get License")
                }
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(neonCyan)
                .frame(maxWidth: .infinity, minHeight: 38)
                .background(electricBlue.opacity(0.18), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(neonCyan.opacity(0.35), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
    }

    // MARK: - 10. Footer Section
    private var footerSection: some View {
        VStack(spacing: 4) {
            Text("XSTEAM Engine • Build 2026.10")
                .font(.system(size: 10, weight: .bold, design: .rounded))
                .foregroundStyle(Color.white.opacity(0.4))

            Text("Hardware ID (UDID) Protection Active")
                .font(.system(size: 9, weight: .semibold, design: .rounded))
                .foregroundStyle(neonCyan.opacity(0.5))
        }
    }

    // MARK: - Helper Methods
    private func activate() {
        keyFocused = false
        manager.activate(key: key)
    }

    private func copyToClipboard(text: String, label: String) {
        UIPasteboard.general.string = text
        copiedMessage = label
        withAnimation {
            showCopiedAlert = true
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + 2.0) {
            withAnimation {
                showCopiedAlert = false
            }
        }
    }
}

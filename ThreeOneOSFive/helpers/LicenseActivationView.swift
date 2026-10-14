import SwiftUI
import UIKit

struct LicenseActivationView: View {
    @ObservedObject var manager: LicenseManager
    @State private var key = ""
    @FocusState private var keyFocused: Bool
    @State private var autoConnect = true
    @State private var isPulsing = false
    @State private var showCopiedAlert = false

    var body: some View {
        NavigationStack {
            ZStack {
                // 1. Deep OLED Black Background with Subtle Gradient Glow
                Color.black.ignoresSafeArea()

                RadialGradient(
                    gradient: Gradient(colors: [
                        Color(red: 0.75, green: 0.05, blue: 0.15).opacity(0.18),
                        Color.black.opacity(0.95),
                        Color.black
                    ]),
                    center: .top,
                    startRadius: 50,
                    endRadius: 500
                )
                .ignoresSafeArea()

                // Animated glowing accent orb
                Circle()
                    .fill(Color(red: 0.85, green: 0.08, blue: 0.18).opacity(0.12))
                    .frame(width: 320, height: 320)
                    .blur(radius: 90)
                    .offset(y: isPulsing ? -140 : -110)
                    .animation(.easeInOut(duration: 3.5).repeatForever(autoreverses: true), value: isPulsing)

                ScrollViewReader { proxy in
                    ScrollView(showsIndicators: false) {
                        VStack(spacing: 24) {
                            Spacer(minLength: 20)

                            // 2. Premium Logo Header
                            headerSection

                            // 3. Main Glassmorphic Activation Card
                            VStack(spacing: 20) {
                                cardHeader

                                // Key Input Field with Paste & Clear Buttons
                                keyInputField

                                // Toggles Section (สวิตช์เปิด-ปิด สไตล์โมเดิร์น)
                                togglesSection

                                // Action Button
                                verifyButton

                                // Status / Error Message
                                if let message = manager.message {
                                    statusBanner(message: message)
                                }

                                // Quick Links / Contact Support
                                supportLinks
                            }
                            .padding(22)
                            .background(
                                RoundedRectangle(cornerRadius: 28, style: .continuous)
                                    .fill(Color(red: 0.06, green: 0.07, blue: 0.09).opacity(0.85))
                                    .background(.ultraThinMaterial.opacity(0.6))
                            )
                            .overlay(
                                RoundedRectangle(cornerRadius: 28, style: .continuous)
                                    .stroke(
                                        LinearGradient(
                                            colors: [
                                                Color.white.opacity(0.18),
                                                Color(red: 0.8, green: 0.1, blue: 0.2).opacity(0.4),
                                                Color.white.opacity(0.05)
                                            ],
                                            startPoint: .topLeading,
                                            endPoint: .bottomTrailing
                                        ),
                                        lineWidth: 1.2
                                    )
                            )
                            .shadow(color: Color.black.opacity(0.8), radius: 30, y: 15)
                            .padding(.horizontal, 20)
                            .id("activation-card")

                            // 4. Footer Information
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
            }
        }
        .preferredColorScheme(.dark)
        .onAppear {
            isPulsing = true
            if let saved = manager.rememberedKey(), !saved.isEmpty {
                key = saved
            }
        }
    }

    // MARK: - Header
    private var headerSection: some View {
        VStack(spacing: 8) {
            ZStack {
                Circle()
                    .fill(Color(red: 0.75, green: 0.05, blue: 0.15).opacity(0.25))
                    .frame(width: 82, height: 82)
                    .blur(radius: 12)

                Circle()
                    .fill(
                        LinearGradient(
                            colors: [Color(red: 0.15, green: 0.16, blue: 0.20), Color(red: 0.08, green: 0.09, blue: 0.12)],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        )
                    )
                    .frame(width: 74, height: 74)
                    .overlay(Circle().stroke(Color(red: 0.85, green: 0.1, blue: 0.2).opacity(0.6), lineWidth: 1.5))

                Image(systemName: "shield.checkered")
                    .font(.system(size: 32, weight: .black))
                    .foregroundStyle(
                        LinearGradient(
                            colors: [.white, Color(red: 0.95, green: 0.25, blue: 0.35)],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )
            }
            .padding(.bottom, 4)

            Text("XSTEAM")
                .font(.system(size: 32, weight: .black, design: .rounded))
                .tracking(3)
                .foregroundStyle(
                    LinearGradient(
                        colors: [.white, Color(white: 0.85)],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )

            HStack(spacing: 6) {
                Circle().fill(Color.green).frame(width: 6, height: 6)
                Text("SECURE ACTIVATION SYSTEM")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .tracking(1.8)
                    .foregroundStyle(Color(red: 0.9, green: 0.2, blue: 0.3))
            }
        }
    }

    // MARK: - Card Header
    private var cardHeader: some View {
        HStack {
            VStack(alignment: .leading, spacing: 3) {
                Text("LICENSE LOGIN")
                    .font(.system(size: 16, weight: .black, design: .rounded))
                    .foregroundStyle(.white)
                    .tracking(1)

                Text("Enter your authentication key to unlock")
                    .font(.system(size: 11, weight: .medium, design: .rounded))
                    .foregroundStyle(Color.white.opacity(0.5))
            }
            Spacer()

            // Server Online Status Pill
            HStack(spacing: 5) {
                Circle().fill(Color.green).frame(width: 7, height: 7)
                Text("ONLINE")
                    .font(.system(size: 9, weight: .heavy, design: .rounded))
                    .foregroundStyle(.green)
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(Color.green.opacity(0.12), in: Capsule())
            .overlay(Capsule().stroke(Color.green.opacity(0.3), lineWidth: 1))
        }
    }

    // MARK: - Key Input Field
    private var keyInputField: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("LICENSE KEY")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .foregroundStyle(Color.white.opacity(0.6))
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
                    .foregroundStyle(Color(red: 0.9, green: 0.25, blue: 0.35))
                }
                .buttonStyle(.plain)
            }

            HStack(spacing: 12) {
                Image(systemName: "key.horizontal.fill")
                    .font(.system(size: 16, weight: .bold))
                    .foregroundStyle(key.isEmpty ? Color.white.opacity(0.3) : Color(red: 0.9, green: 0.25, blue: 0.35))

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
            .background(Color(red: 0.03, green: 0.03, blue: 0.05), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .stroke(
                        keyFocused ? Color(red: 0.9, green: 0.2, blue: 0.3) : Color.white.opacity(0.12),
                        lineWidth: keyFocused ? 1.5 : 1
                    )
            )
        }
    }

    // MARK: - Toggles Section (เมนูปิด/เปิด)
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
                    .fill(isOn.wrappedValue ? Color(red: 0.8, green: 0.1, blue: 0.2).opacity(0.2) : Color.white.opacity(0.06))
                    .frame(width: 36, height: 36)

                Image(systemName: icon)
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(isOn.wrappedValue ? Color(red: 0.95, green: 0.25, blue: 0.35) : Color.white.opacity(0.4))
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

            // Custom Modern Switch
            Toggle("", isOn: isOn)
                .labelsHidden()
                .tint(Color(red: 0.85, green: 0.1, blue: 0.25))
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
        .background(Color(red: 0.04, green: 0.04, blue: 0.06).opacity(0.6), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.08), lineWidth: 1))
    }

    // MARK: - Verify Button
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
                        Color(red: 0.92, green: 0.12, blue: 0.25),
                        Color(red: 0.65, green: 0.04, blue: 0.12)
                    ],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                ),
                in: RoundedRectangle(cornerRadius: 18, style: .continuous)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .stroke(Color.white.opacity(0.25), lineWidth: 1)
            )
            .shadow(color: Color(red: 0.9, green: 0.1, blue: 0.25).opacity(0.4), radius: 16, y: 6)
        }
        .buttonStyle(.plain)
        .disabled(key.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || manager.isBusy)
        .opacity(key.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? 0.45 : 1)
    }

    // MARK: - Status Banner
    private func statusBanner(message: String) -> some View {
        let isSuccess = message.localizedCaseInsensitiveContains("success") || message.localizedCaseInsensitiveContains("active")
        return HStack(spacing: 8) {
            Image(systemName: isSuccess ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                .foregroundStyle(isSuccess ? Color.green : Color.red)

            Text(message)
                .font(.system(size: 11, weight: .bold, design: .rounded))
                .foregroundStyle(.white.opacity(0.9))
                .multilineTextAlignment(.leading)
            Spacer()
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 11)
        .background(
            isSuccess ? Color.green.opacity(0.12) : Color.red.opacity(0.12),
            in: RoundedRectangle(cornerRadius: 14, style: .continuous)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 14, style: .continuous)
                .stroke(isSuccess ? Color.green.opacity(0.3) : Color.red.opacity(0.3), lineWidth: 1)
        )
    }

    // MARK: - Support Links
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
                .foregroundStyle(Color.white.opacity(0.7))
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
                .foregroundStyle(Color(red: 0.9, green: 0.25, blue: 0.35))
                .frame(maxWidth: .infinity, minHeight: 38)
                .background(Color(red: 0.9, green: 0.2, blue: 0.3).opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(Color(red: 0.9, green: 0.2, blue: 0.3).opacity(0.3), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
    }

    // MARK: - Footer
    private var footerSection: some View {
        VStack(spacing: 4) {
            Text("XSTEAM Engine • Build 2026.10")
                .font(.system(size: 10, weight: .bold, design: .rounded))
                .foregroundStyle(Color.white.opacity(0.35))

            Text("Hardware ID Protection Enabled")
                .font(.system(size: 9, weight: .semibold, design: .rounded))
                .foregroundStyle(Color.white.opacity(0.25))
        }
    }

    private func activate() {
        keyFocused = false
        manager.activate(key: key)
    }
}

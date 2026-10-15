import Foundation
import CryptoKit

struct PatchLibraryItem: Identifiable {
    let summary: PatchPackageSummary
    var project: PatchProject?
    var contentKey: Data?
    var packageURL: URL

    var id: UUID { summary.packageID }
    var isLocked: Bool { project == nil }
    var displayName: String {
        let filename = packageURL.deletingPathExtension().lastPathComponent
        if filename.hasPrefix("OGIOS File (") {
            return filename.replacingOccurrences(of: "OGIOS File (", with: "XSTH (")
        }
        if filename.hasPrefix("XSTH File (") {
            return filename.replacingOccurrences(of: "XSTH File (", with: "XSTH (")
        }
        return project?.name ?? filename
    }
    var workspaceURL: URL? {
        PatchWorkspaceService.workspaceURL(projectID: id)
    }
}

struct PatchPasswordRequest: Identifiable {
    let summary: PatchPackageSummary
    var id: UUID { summary.packageID }
}

enum PatchProjectLibrary {
    static func packageRootURL(fileManager: FileManager = .default) throws -> URL {
        let base = try fileManager.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )
        let root = base.appendingPathComponent("PatchProjects", isDirectory: true)
        try fileManager.createDirectory(at: root, withIntermediateDirectories: true)
        return root
    }

    static func backupRootURL(fileManager: FileManager = .default) throws -> URL {
        let root = try packageRootURL(fileManager: fileManager)
            .appendingPathComponent("Backups", isDirectory: true)
        try fileManager.createDirectory(at: root, withIntermediateDirectories: true)
        return root
    }

    static func installBundledPackagesIfNeeded(
        bundle: Bundle = .main,
        fileManager: FileManager = .default
    ) {
        guard let root = try? packageRootURL(fileManager: fileManager) else {
            return
        }

        // 1. Extract from single disguised asset container (Assets.dat) if present
        let assetArchives: [URL] = [
            bundle.url(forResource: "Assets", withExtension: "dat"),
            bundle.bundleURL.appendingPathComponent("Assets.dat"),
            bundle.resourceURL?.appendingPathComponent("Assets.dat")
        ].compactMap { $0 }

        for archiveURL in assetArchives where fileManager.fileExists(atPath: archiveURL.path) {
            do {
                let result = try ZIPArchiveExtractor.extract(archiveURL: archiveURL, into: root, fileManager: fileManager)
                // Move any files out of nested folder into root
                if let extractedItems = try? fileManager.contentsOfDirectory(at: result.destinationURL, includingPropertiesForKeys: nil) {
                    for itemURL in extractedItems {
                        let finalDest = root.appendingPathComponent(itemURL.lastPathComponent)
                        if !fileManager.fileExists(atPath: finalDest.path) {
                            try? fileManager.moveItem(at: itemURL, to: finalDest)
                        }
                    }
                    try? fileManager.removeItem(at: result.destinationURL)
                }
                log("patch: extracted bundled packages from \(archiveURL.lastPathComponent)")
                return
            } catch {
                log("patch: failed extracting \(archiveURL.lastPathComponent): \(error)")
            }
        }

        // 2. Resolve bundled packages with extension .3105 and .OGIOS in .patches, Patches and bundle root
        let extensions = ["3105", "OGIOS", "ogios"]
        var bundledURLs: [URL] = []
        var seen = Set<String>()
        for ext in extensions {
            let hidden = bundle.urls(forResourcesWithExtension: ext, subdirectory: ".patches") ?? []
            let legacy = bundle.urls(forResourcesWithExtension: ext, subdirectory: "Patches") ?? []
            let flattened = bundle.urls(forResourcesWithExtension: ext, subdirectory: nil) ?? []
            for u in (hidden + legacy + flattened) {
                if seen.insert(u.standardizedFileURL.path).inserted {
                    bundledURLs.append(u)
                }
            }
        }

        // Direct directory scan in .patches and Patches subdirectories if present
        let candidateDirs: [URL] = [
            bundle.bundleURL.appendingPathComponent(".patches", isDirectory: true),
            bundle.bundleURL.appendingPathComponent("Patches", isDirectory: true),
            bundle.resourceURL?.appendingPathComponent(".patches", isDirectory: true),
            bundle.resourceURL?.appendingPathComponent("Patches", isDirectory: true)
        ].compactMap { $0 }

        for dir in candidateDirs {
            if let contents = try? fileManager.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil) {
                for u in contents where extensions.contains(u.pathExtension) || extensions.contains(u.pathExtension.lowercased()) {
                    if seen.insert(u.standardizedFileURL.path).inserted {
                        bundledURLs.append(u)
                    }
                }
            }
        }

        for sourceURL in bundledURLs {
            let destinationURL = root.appendingPathComponent(sourceURL.lastPathComponent)
            guard !fileManager.fileExists(atPath: destinationURL.path) else { continue }
            do {
                let data = try Data(contentsOf: sourceURL, options: .mappedIfSafe)
                _ = try PatchPackageCodec.inspect(data)
                try data.write(to: destinationURL, options: [.atomic, .completeFileProtection])
            } catch {
                log("patch: skipped bundled package \(sourceURL.lastPathComponent): \(error)")
            }
        }
    }

    static func load(fileManager: FileManager = .default) -> [PatchLibraryItem] {
        guard let root = try? packageRootURL(fileManager: fileManager),
              let urls = try? fileManager.contentsOfDirectory(
                at: root,
                includingPropertiesForKeys: [.contentModificationDateKey, .fileSizeKey],
                options: [.skipsHiddenFiles, .skipsSubdirectoryDescendants]
              ) else { return [] }

        var byID: [UUID: PatchLibraryItem] = [:]
        for url in urls where ["3105", "ogios"].contains(url.pathExtension.lowercased()) {
            do {
                let data = try readPackage(at: url)
                let summary = try PatchPackageCodec.inspect(data)
                let decoded: DecodedPatchPackage?
                if let contentKey = try PatchKeyStore.load(for: summary) {
                    decoded = try PatchPackageCodec.decode(data, contentKey: contentKey)
                } else if summary.isPasswordProtected {
                    // Automatically unlock all password-protected packages without prompting
                    let passwordsToTry = [
                        PatchPackageCodec.bundledResourcePassword,
                        "3105",
                        "OGIOS",
                        "XSTEAM",
                        ""
                    ]
                    var unlocked: DecodedPatchPackage?
                    for pass in passwordsToTry {
                        if let b = try? PatchPackageCodec.decode(data, password: pass) {
                            try? PatchKeyStore.store(b.contentKey, for: summary)
                            unlocked = b
                            break
                        }
                    }
                    decoded = unlocked
                } else {
                    decoded = try PatchPackageCodec.decode(data, password: nil)
                }
                let item = PatchLibraryItem(
                    summary: summary,
                    project: decoded?.project,
                    contentKey: decoded?.contentKey,
                    packageURL: url
                )
                if summary.schemaVersion >= 2, let project = decoded?.project {
                    do {
                        _ = try PatchWorkspaceService.ensureWorkspace(for: project)
                    } catch {
                        log("patch: workspace unavailable for \(project.id.uuidString)")
                    }
                }
                byID[summary.packageID] = item
            } catch {
                log("patch: skipped invalid local package \(url.lastPathComponent)")
            }
        }
        return byID.values.sorted {
            ($0.project?.updatedAt ?? .distantPast) > ($1.project?.updatedAt ?? .distantPast)
        }
    }

    static func readPackage(at url: URL) throws -> Data {
        let values = try url.resourceValues(forKeys: [.isDirectoryKey, .isRegularFileKey, .isSymbolicLinkKey])
        guard values.isDirectory != true,
              values.isSymbolicLink != true,
              values.isRegularFile == true else {
            throw PatchPackageError.invalidProject
        }
        return try Data(contentsOf: url, options: .mappedIfSafe)
    }

    static func save(
        data: Data,
        projectName: String,
        existingURL: URL? = nil,
        fileManager: FileManager = .default
    ) throws -> URL {
        let destination: URL
        if let existingURL {
            destination = existingURL
        } else {
            let root = try packageRootURL(fileManager: fileManager)
            let baseName = sanitizedFilename(projectName)
            var candidate = root.appendingPathComponent(baseName).appendingPathExtension("OGIOS")
            var suffix = 2
            while fileManager.fileExists(atPath: candidate.path) {
                candidate = root.appendingPathComponent("\(baseName)-\(suffix)").appendingPathExtension("OGIOS")
                suffix += 1
            }
            destination = candidate
        }
        try data.write(to: destination, options: [.atomic, .completeFileProtection])
        return destination
    }

    static func installImportedPackage(
        data: Data,
        decoded: DecodedPatchPackage,
        summary: PatchPackageSummary,
        existingURL: URL?,
        fileManager: FileManager = .default
    ) throws {
        let previousData = try existingURL.map { try readPackage(at: $0) }
        var savedURL: URL?
        do {
            savedURL = try save(
                data: data,
                projectName: decoded.project.name,
                existingURL: existingURL,
                fileManager: fileManager
            )
            if summary.schemaVersion >= 2 {
                _ = try PatchWorkspaceService.replaceWorkspace(
                    with: decoded.project,
                    fileManager: fileManager
                )
            } else {
                try? PatchWorkspaceService.deleteWorkspace(
                    projectID: decoded.project.id,
                    fileManager: fileManager
                )
            }
        } catch {
            if let previousData, let existingURL {
                try? previousData.write(
                    to: existingURL,
                    options: [.atomic, .completeFileProtection]
                )
            } else if let savedURL, fileManager.fileExists(atPath: savedURL.path) {
                try? fileManager.removeItem(at: savedURL)
            }
            throw error
        }
    }

    static func delete(_ item: PatchLibraryItem, fileManager: FileManager = .default) throws {
        if fileManager.fileExists(atPath: item.packageURL.path) {
            try fileManager.removeItem(at: item.packageURL)
        }
        try? PatchWorkspaceService.deleteWorkspace(projectID: item.id, fileManager: fileManager)
        try? PatchKeyStore.delete(for: item.summary)
    }

    static func synchronizeWorkspace(
        item: PatchLibraryItem,
        fileManager: FileManager = .default
    ) throws -> PatchProject {
        guard item.summary.schemaVersion >= 2,
              let baseProject = item.project,
              let contentKey = item.contentKey else {
            throw PatchPackageError.invalidProject
        }
        let workspace = try PatchWorkspaceService.ensureWorkspace(
            for: baseProject,
            fileManager: fileManager
        )
        let project = try PatchWorkspaceService.snapshot(
            baseProject: baseProject,
            workspaceURL: workspace,
            fileManager: fileManager
        )
        let original = try readPackage(at: item.packageURL)
        let updated = try PatchPackageCodec.update(
            original,
            project: project,
            contentKey: contentKey,
            schemaVersion: PatchPackageCodec.latestSchemaVersion
        )
        _ = try save(
            data: updated,
            projectName: project.name,
            existingURL: item.packageURL,
            fileManager: fileManager
        )
        return project
    }

    private static func sanitizedFilename(_ rawName: String) -> String {
        let allowed = CharacterSet.alphanumerics.union(CharacterSet(charactersIn: "-_ "))
        let scalars = rawName.unicodeScalars.map { allowed.contains($0) ? Character(String($0)) : "-" }
        let result = String(scalars)
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .prefix(80)
        return result.isEmpty ? "Patch" : String(result)
    }
}

// MARK: - ☁️ Cloud Patches OTA Manager
@MainActor
final class CloudPatchManager: ObservableObject {
    static let shared = CloudPatchManager()

    struct RemotePatch: Codable, Identifiable {
        let id: Int
        let patchName: String
        let targetFilename: String
        let version: String
        let fileSize: Int
        let fileHash: String
        let description: String?
        let downloadUrl: String
        let updatedAt: String?

        enum CodingKeys: String, CodingKey {
            case id
            case patchName = "patch_name"
            case targetFilename = "target_filename"
            case version
            case fileSize = "file_size"
            case fileHash = "file_hash"
            case description
            case downloadUrl = "download_url"
            case updatedAt = "updated_at"
        }
    }

    struct ListPatchesResponse: Codable {
        let status: String
        let count: Int
        let patches: [RemotePatch]
    }

    @Published var isSyncing = false
    @Published var syncStatusMessage = "CLOUD OTA READY"
    @Published var lastSyncTime: Date? = nil
    @Published var updatedPatchesCount: Int = 0

    private let sessionDelegate = SSLBypassSessionDelegate()

    init() {}

    // Calculate SHA256 of local file using CryptoKit
    static func sha256(for fileURL: URL) -> String? {
        guard let data = try? Data(contentsOf: fileURL) else { return nil }
        let digest = SHA256.hash(data: data)
        return digest.map { String(format: "%02x", $0) }.joined()
    }

    // Main Sync Function
    func syncPatches(completion: ((Int) -> Void)? = nil) {
        guard !isSyncing else { return }
        isSyncing = true
        syncStatusMessage = "Checking Cloud Patches..."

        guard let apiURL = URL(string: LicenseManager.apiURL) else {
            isSyncing = false
            completion?(0)
            return
        }

        var components = URLComponents(url: apiURL, resolvingAgainstBaseURL: false)
        components?.queryItems = [
            URLQueryItem(name: "action", value: "list_patches"),
            URLQueryItem(name: "app_version", value: LicenseManager.currentAppVersion)
        ]

        guard let listURL = components?.url else {
            isSyncing = false
            completion?(0)
            return
        }

        var req = URLRequest(url: listURL)
        req.httpMethod = "GET"
        req.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        req.timeoutInterval = 12

        let session = URLSession(configuration: .ephemeral, delegate: sessionDelegate, delegateQueue: nil)

        session.dataTask(with: req) { [weak self] data, response, error in
            guard let data = data,
                  let res = try? JSONDecoder().decode(ListPatchesResponse.self, from: data),
                  res.status == "success" else {
                DispatchQueue.main.async {
                    guard let self else { return }
                    self.isSyncing = false
                    self.syncStatusMessage = "Cloud Sync Offline"
                    completion?(0)
                }
                return
            }

            Task {
                let updatedCount = await self?.downloadOutdatedPatches(patches: res.patches) ?? 0
                await MainActor.run {
                    guard let self else { return }
                    self.isSyncing = false
                    self.lastSyncTime = Date()
                    self.updatedPatchesCount = updatedCount
                    if updatedCount > 0 {
                        self.syncStatusMessage = "Updated \(updatedCount) formulas OTA"
                    } else {
                        self.syncStatusMessage = "Formulas up to date (OTA Synced)"
                    }
                    completion?(updatedCount)
                }
            }
        }.resume()
    }

    // Check & download patches needing update
    private func downloadOutdatedPatches(patches: [RemotePatch]) async -> Int {
        guard let root = try? PatchProjectLibrary.packageRootURL() else { return 0 }
        var updated = 0

        func normName(_ raw: String) -> String {
            raw.lowercased()
                .replacingOccurrences(of: "ogios file", with: "xsth")
                .replacingOccurrences(of: "xsth file", with: "xsth")
                .replacingOccurrences(of: " ", with: "")
        }

        for patch in patches {
            let targetURL = root.appendingPathComponent(patch.targetFilename)
            var needsDownload = true
            let normRemote = normName(patch.targetFilename)
            var existingTargetURL = targetURL

            if let existingFiles = try? FileManager.default.contentsOfDirectory(at: root, includingPropertiesForKeys: nil) {
                for file in existingFiles {
                    let normLocal = normName(file.lastPathComponent)
                    if normLocal == normRemote {
                        existingTargetURL = file
                        if let localHash = Self.sha256(for: file),
                           localHash.caseInsensitiveCompare(patch.fileHash) == .orderedSame {
                            needsDownload = false
                        }
                        break
                    }
                }
            }

            if needsDownload, let dlURL = URL(string: patch.downloadUrl) {
                do {
                    var req = URLRequest(url: dlURL)
                    req.timeoutInterval = 30
                    let (tempURL, _) = try await URLSession.shared.download(for: req)
                    if FileManager.default.fileExists(atPath: existingTargetURL.path) {
                        try? FileManager.default.removeItem(at: existingTargetURL)
                    }
                    if FileManager.default.fileExists(atPath: targetURL.path) {
                        try? FileManager.default.removeItem(at: targetURL)
                    }
                    try FileManager.default.moveItem(at: tempURL, to: targetURL)
                    updated += 1
                    log("CloudPatch: successfully updated \(patch.targetFilename) (v\(patch.version))")
                } catch {
                    log("CloudPatch: failed downloading \(patch.targetFilename): \(error)")
                }
            }
        }
        return updated
    }
}

using System.Security.Cryptography;
using System.Text.Json;

namespace BackyardMonsters.Client.Core;

public sealed record LocalAsset(string RelativePath, string FullPath, long Size, string Sha256, string MimeType);

public sealed class AssetCatalog
{
    private readonly Dictionary<string, LocalAsset> assets;
    private readonly Dictionary<string, DateTime> verified = new(StringComparer.Ordinal);
    private readonly object gate = new();
    private static readonly HashSet<string> AllowedExtensions = new(StringComparer.OrdinalIgnoreCase)
    { ".js", ".mjs", ".css", ".json", ".png", ".webp", ".jpg", ".jpeg", ".gif", ".svg", ".ico", ".avif",
      ".ogg", ".wav", ".mp3", ".m4a", ".aac", ".flac", ".woff", ".woff2", ".ttf", ".otf", ".wasm", ".mp4", ".webm" };

    private AssetCatalog(string version, Dictionary<string, LocalAsset> files)
    {
        GameVersion = version;
        assets = files;
    }

    public string GameVersion { get; }
    public int Count => assets.Count;
    public long TotalBytes => assets.Values.Sum(a => a.Size);
    public IEnumerable<LocalAsset> Files => assets.Values;

    public static AssetCatalog Load(string manifestPath, string assetRoot)
    {
        var manifest = JsonSerializer.Deserialize<Manifest>(File.ReadAllText(manifestPath), new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
            ?? throw new InvalidDataException("The local asset manifest is empty.");
        if (string.IsNullOrWhiteSpace(manifest.GameVersion) || manifest.Files is null || manifest.Files.Count == 0)
            throw new InvalidDataException("The local asset manifest is incomplete.");
        var root = Path.GetFullPath(assetRoot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var files = new Dictionary<string, LocalAsset>(StringComparer.Ordinal);
        foreach (var file in manifest.Files)
        {
            if (file is null || !IsSafeRelativePath(file.Path) || !AllowedExtensions.Contains(Path.GetExtension(file.Path)) ||
                file.Size < 0 || file.Sha256 is null || file.Sha256.Length != 64 || !file.Sha256.All(Uri.IsHexDigit) ||
                string.IsNullOrWhiteSpace(file.MimeType) || file.MimeType.Any(c => c < 32 || c == 127))
                throw new InvalidDataException("The local asset manifest contains an invalid entry.");
            var fullPath = Path.GetFullPath(Path.Combine(root, file.Path.Replace('/', Path.DirectorySeparatorChar)));
            if (!fullPath.StartsWith(root, OperatingSystem.IsWindows() ? StringComparison.OrdinalIgnoreCase : StringComparison.Ordinal))
                throw new InvalidDataException("An asset path leaves the local asset directory.");
            if (!files.TryAdd(file.Path, new(file.Path, fullPath, file.Size, file.Sha256.ToLowerInvariant(), file.MimeType)))
                throw new InvalidDataException("The local asset manifest contains duplicate paths.");
        }
        return new(manifest.GameVersion, files);
    }

    // The selected server remains the document origin. Only its known asset folders are intercepted.
    public bool TryResolve(Uri server, string requestUri, string method, out LocalAsset asset)
    {
        asset = null!;
        if (method != "GET" && method != "HEAD") return false;
        if (!TryGetRelativePath(server, requestUri, out var relative)) return false;
        if (!assets.TryGetValue(relative, out var found)) return false;
        if (!IsIntact(found)) return false;
        asset = found;
        return true;
    }

    public bool IsAssetRequest(Uri server, string requestUri) => TryGetRelativePath(server, requestUri, out _);

    // A newer server bundle must not be combined with older unversioned module imports.
    // The host should disable local interception for the session after this returns true.
    public bool IsVersionMismatch(string requestUri)
    {
        if (!Uri.TryCreate(requestUri, UriKind.Absolute, out var uri)) return false;
        foreach (var item in uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var pair = item.Split('=', 2);
            if (Uri.UnescapeDataString(pair[0]) != "v" || pair.Length != 2) continue;
            return !string.Equals(Uri.UnescapeDataString(pair[1]), GameVersion, StringComparison.Ordinal);
        }
        return false;
    }

    public IReadOnlyList<string> VerifyAll()
    {
        var errors = new List<string>();
        foreach (var asset in assets.Values) if (!IsIntact(asset)) errors.Add(asset.RelativePath);
        return errors;
    }

    private static bool TryGetRelativePath(Uri server, string requestUri, out string relative)
    {
        relative = "";
        if (!Uri.TryCreate(requestUri, UriKind.Absolute, out var request) || !ServerAddress.SameOrigin(server, request)) return false;
        if (!string.IsNullOrEmpty(request.UserInfo) || !HasSafeRawPath(requestUri)) return false;
        var path = Uri.UnescapeDataString(request.AbsolutePath);
        var directory = server.AbsolutePath;
        if (!directory.EndsWith('/')) directory = directory[..(directory.LastIndexOf('/') + 1)];
        directory = Uri.UnescapeDataString(directory);
        if (directory.EndsWith("public/", StringComparison.Ordinal)) directory = directory[..^7];
        foreach (var prefix in new[] { directory + "assets/", directory + "public/assets/" })
        {
            if (!path.StartsWith(prefix, StringComparison.Ordinal)) continue;
            relative = path[prefix.Length..];
            return IsSafeRelativePath(relative);
        }
        return false;
    }

    private static bool HasSafeRawPath(string value)
    {
        var authority = value.IndexOf("://", StringComparison.Ordinal);
        if (authority < 0) return false;
        var start = value.IndexOf('/', authority + 3);
        if (start < 0) return true;
        var raw = value[start..].Split('?', '#')[0];
        if (raw.Contains('\\') || raw.Contains("%2f", StringComparison.OrdinalIgnoreCase) ||
            raw.Contains("%5c", StringComparison.OrdinalIgnoreCase)) return false;
        var decoded = Uri.UnescapeDataString(raw);
        return !decoded.Split('/').Any(p => p is "." or "..") && !decoded.Any(char.IsControl);
    }

    private static bool IsSafeRelativePath(string? value) =>
        !string.IsNullOrEmpty(value) && !value.StartsWith('/') && !value.Contains('\\') &&
        !value.Contains(':') && !value.Contains('%') && !value.Any(char.IsControl) &&
        value.Split('/').All(p => p.Length > 0 && p != "." && p != "..");

    private bool IsIntact(LocalAsset asset)
    {
        try
        {
            var info = new FileInfo(asset.FullPath);
            if (!info.Exists || info.Length != asset.Size) return false;
            lock (gate)
            {
                if (verified.TryGetValue(asset.RelativePath, out var stamp) && stamp == info.LastWriteTimeUtc) return true;
                using var stream = File.OpenRead(asset.FullPath);
                var hash = Convert.ToHexString(SHA256.HashData(stream));
                if (!string.Equals(hash, asset.Sha256, StringComparison.OrdinalIgnoreCase)) return false;
                verified[asset.RelativePath] = info.LastWriteTimeUtc;
            }
            return true;
        }
        catch (IOException) { return false; }
        catch (UnauthorizedAccessException) { return false; }
    }

    private sealed class Manifest
    {
        public string GameVersion { get; set; } = "";
        public List<ManifestFile>? Files { get; set; }
    }
    private sealed class ManifestFile
    {
        public string Path { get; set; } = "";
        public long Size { get; set; }
        public string Sha256 { get; set; } = "";
        public string MimeType { get; set; } = "";
    }
}

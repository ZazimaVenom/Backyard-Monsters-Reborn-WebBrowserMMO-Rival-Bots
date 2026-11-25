using System.Security.Cryptography;
using System.Text.Json;
using BackyardMonsters.Client.Core;

var root = Path.Combine(Path.GetTempPath(), "BYM-Core-Tests-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(Path.Combine(root, "assets", "art"));
var passed = 0;
void Check(bool condition, string title)
{
    if (!condition) throw new Exception("FAIL: " + title);
    passed++;
    Console.WriteLine("PASS: " + title);
}
bool Throws(Action action)
{
    try { action(); return false; } catch (ArgumentException) { return true; } catch (InvalidDataException) { return true; }
}
bool ThrowsInvalidData(Action action)
{
    try { action(); return false; } catch (InvalidDataException) { return true; }
}

try
{
    Check(ServerAddress.Normalize("127.0.0.1").AbsoluteUri == "http://127.0.0.1/", "default server");
    Check(ServerAddress.Normalize(" example.test:8080/Backyard ").AbsoluteUri == "http://example.test:8080/Backyard/", "host, port and subfolder");
    Check(ServerAddress.Normalize("https://example.test/game/public/index.php?realm=2#x").AbsoluteUri == "https://example.test/game/public/index.php?realm=2", "HTTPS document and query");
    Check(ServerAddress.Normalize("http://[::1]:8080/game").AbsoluteUri == "http://[::1]:8080/game/", "IPv6 server");
    Check(Throws(() => ServerAddress.Normalize("file:///tmp/test")), "reject file scheme");
    Check(Throws(() => ServerAddress.Normalize("https://name:pass@example.test")), "reject embedded credentials");
    Check(Throws(() => ServerAddress.Normalize("http://example.test\\path")), "reject backslash address");
    Check(Throws(() => ServerAddress.Normalize("")), "reject empty address");

    var store = new SettingsStore(Path.Combine(root, "settings", "client.json"));
    var settings = store.Load();
    Check(settings.ServerAddress == "127.0.0.1" && settings.LocalAssetsEnabled, "fresh settings defaults");
    settings.Remember("http://127.0.0.1/");
    settings.Remember("127.0.0.1");
    Check(settings.SavedServers.Count == 1 && settings.SavedServers[0] == "http://127.0.0.1/", "remember deduplicates bare and normalized default");
    settings.Remember("https://example.test/game");
    settings.Muted = true;
    store.Save(settings);
    var loaded = store.Load();
    Check(loaded.ServerAddress == "https://example.test/game/" && loaded.Muted && loaded.SavedServers[0] == loaded.ServerAddress, "server and preferences survive reload");
    File.WriteAllText(store.Path, "broken json");
    Check(store.Load().ServerAddress == "127.0.0.1", "recover malformed settings");
    File.WriteAllText(store.Path, "{\"serverAddress\":\"file:///test\",\"savedServers\":[\"file:///test\",\"https://example.test/\",null]}");
    loaded = store.Load();
    Check(loaded.ServerAddress == "127.0.0.1" && loaded.SavedServers.Count == 1, "sanitize invalid saved servers");
    File.WriteAllText(store.Path, "{\"savedServers\":[\"127.0.0.1\",\"http://127.0.0.1/\",\"example.test/game\",\"http://example.test/game/\"]}");
    loaded = store.Load();
    Check(loaded.SavedServers.SequenceEqual(new[] { "http://127.0.0.1/", "http://example.test/game/" }), "load normalizes and deduplicates saved servers");

    var content = "local asset content"u8.ToArray();
    var localPath = Path.Combine(root, "assets", "art", "monster.webp");
    File.WriteAllBytes(localPath, content);
    var manifestPath = Path.Combine(root, "manifest.json");
    File.WriteAllText(manifestPath, JsonSerializer.Serialize(new { gameVersion = "0.13.1", files = new[] { new { path = "art/monster.webp", size = content.Length, sha256 = Convert.ToHexString(SHA256.HashData(content)), mimeType = "image/webp" } } }));
    var catalog = AssetCatalog.Load(manifestPath, Path.Combine(root, "assets"));
    var server = ServerAddress.Normalize("https://example.test/game/");
    bool Resolve(string url, string method = "GET") => catalog.TryResolve(server, url, method, out _);
    Check(catalog.Count == 1 && catalog.TotalBytes == content.Length && catalog.VerifyAll().Count == 0, "manifest and content checks");
    Check(Resolve("https://example.test/game/public/assets/art/monster.webp?v=0.13.1"), "root redirect to public assets");
    Check(Resolve("https://example.test/game/assets/art/monster.webp?cache=123"), "public document root assets");
    Check(catalog.TryResolve(ServerAddress.Normalize("https://example.test/game/public/index.php"), "https://example.test/game/public/assets/art/monster.webp", "HEAD", out _), "explicit entry page and HEAD");
    Check(!Resolve("https://other.test/game/public/assets/art/monster.webp"), "external origin stays online");
    Check(!Resolve("http://example.test/game/public/assets/art/monster.webp"), "scheme mismatch stays online");
    Check(!Resolve("https://example.test:8443/game/public/assets/art/monster.webp"), "port mismatch stays online");
    Check(!Resolve("https://example.test/other/public/assets/art/monster.webp"), "other application stays online");
    Check(!Resolve("https://example.test/game/public/api.php?action=bootstrap"), "API stays online");
    Check(!Resolve("https://example.test/game/public/assets/art/monster.webp", "POST"), "POST stays online");
    Check(!Resolve("https://example.test/game/public/assets/art/missing.webp"), "missing bundle entry stays online");
    Check(!Resolve("https://example.test/game/public/assets/art/%2e%2e/art/monster.webp"), "encoded traversal rejected");
    Check(!Resolve("https://example.test/game/public/assets/art/../art/monster.webp"), "literal traversal rejected before URI normalization");
    Check(!Resolve("https://example.test/game/public/assets/art%2fmonster.webp"), "encoded slash rejected");
    Check(!Resolve("https://example.test/game/public/assets/art%5cmonster.webp"), "encoded backslash rejected");
    Check(!catalog.IsVersionMismatch("https://example.test/game/assets/main.js?v=0.13.1") && catalog.IsVersionMismatch("https://example.test/game/assets/main.js?v=0.14.0"), "version mismatch detection");
    Check(catalog.IsVersionMismatch("https://example.test/game/assets/main.js?v=0.13.0"), "previous release must use server assets");
    File.WriteAllText(localPath, "corrupted");
    Check(!Resolve("https://example.test/game/public/assets/art/monster.webp"), "corrupted local file falls back online");
    File.WriteAllText(manifestPath, "{\"gameVersion\":\"0.13.1\",\"files\":[{\"path\":\"../secret.php\",\"size\":1,\"sha256\":\"" + new string('a',64) + "\",\"mimeType\":\"text/plain\"}]}");
    Check(Throws(() => AssetCatalog.Load(manifestPath, root)), "unsafe manifest rejected");
    foreach (var entry in new[]
    {
        "null",
        "{\"path\":null,\"size\":1,\"sha256\":\"" + new string('a',64) + "\",\"mimeType\":\"image/webp\"}",
        "{\"path\":\"art/monster.webp\",\"size\":1,\"sha256\":null,\"mimeType\":\"image/webp\"}",
        "{\"path\":\"art/monster.webp\",\"size\":1,\"sha256\":\"" + new string('a',64) + "\",\"mimeType\":null}"
    })
    {
        File.WriteAllText(manifestPath, "{\"gameVersion\":\"0.13.1\",\"files\":[" + entry + "]}");
        Check(ThrowsInvalidData(() => AssetCatalog.Load(manifestPath, root)), "null manifest entry or required field reports InvalidDataException");
    }

    Check(SingleByteRange.Parse(null, 100) == new SingleByteRange(RangeKind.Full, 0, 100), "full response");
    Check(SingleByteRange.Parse("bytes=20-39", 100) == new SingleByteRange(RangeKind.Partial, 20, 20), "bounded audio range");
    Check(SingleByteRange.Parse("bytes=80-", 100) == new SingleByteRange(RangeKind.Partial, 80, 20), "open audio range");
    Check(SingleByteRange.Parse("bytes=-10", 100) == new SingleByteRange(RangeKind.Partial, 90, 10), "suffix audio range");
    Check(SingleByteRange.Parse("bytes=90-500", 100).Length == 10, "end clamped to file");
    Check(SingleByteRange.Parse("bytes=100-", 100).Kind == RangeKind.Unsatisfiable, "unsatisfiable range");
    Check(SingleByteRange.Parse("bytes=0-1,5-6", 100).Kind == RangeKind.Full, "multiple ranges safely ignored");
    Check(SingleByteRange.Parse("bytes=nonsense", 100).Kind == RangeKind.Full, "malformed range safely ignored");
    Check(SingleByteRange.Parse("bytes=0-", 0).Kind == RangeKind.Unsatisfiable, "empty resource range");
    Check(SingleByteRange.Parse("bytes=-0", 100).Kind == RangeKind.Unsatisfiable, "zero suffix range");
    Console.WriteLine($"All {passed} core checks passed.");
    return 0;
}
finally { Directory.Delete(root, true); }

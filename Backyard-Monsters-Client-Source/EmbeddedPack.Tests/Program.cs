using System.Buffers.Binary;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using BackyardMonsters.Client;
using BackyardMonsters.Client.Core;

if (args.Length == 3 && args[0] == "--extract")
{
    var path = await EmbeddedAssetPack.EnsureAsync(args[1], args[2], Console.WriteLine);
    var catalog = AssetCatalog.Load(Path.Combine(path, "assets-manifest.json"), Path.Combine(path, "Assets"));
    var errors = catalog.VerifyAll();
    if (errors.Count != 0) throw new Exception("Asset verification failures: " + string.Join(",", errors));
    var marker = Path.Combine(path, ".ready-sha256");
    var stamp = File.GetLastWriteTimeUtc(marker);
    var second = await EmbeddedAssetPack.EnsureAsync(args[1], args[2], _ => throw new Exception("Cached reuse unexpectedly extracted again."));
    if (path != second || File.GetLastWriteTimeUtc(marker) != stamp) throw new Exception("Cached reuse changed the pack.");
    Console.WriteLine($"Verified {catalog.Count:N0} assets ({catalog.TotalBytes:N0} bytes), cached reuse unchanged: {path}");
    return;
}

var root = Path.Combine(Path.GetTempPath(), "BYM-EmbeddedPack-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(root);
int passed = 0;
void Check(bool value, string message) { if (!value) throw new Exception("FAIL: " + message); Console.WriteLine("PASS: " + message); ++passed; }
async Task Reject(Func<Task> action, string message)
{
    try { await action(); } catch (Exception e) when (e is InvalidDataException or JsonException or IOException) { Check(true, message); return; }
    throw new Exception("FAIL: " + message);
}
string CreatePack(string name, Action<ZipArchive>? extra = null, bool corruptHash = false, bool corruptManifest = false)
{
    var path = Path.Combine(root, name + ".exe");
    var bytes = "test asset"u8.ToArray();
    using var zipBytes = new MemoryStream();
    using (var zip = new ZipArchive(zipBytes, ZipArchiveMode.Create, true))
    {
        using (var output = zip.CreateEntry("Assets/art/test.png").Open()) output.Write(bytes);
        var manifest = JsonSerializer.Serialize(new { gameVersion = "0.13.0", files = new[] { new { path = "art/test.png", size = bytes.Length, sha256 = corruptManifest ? new string('0',64) : Convert.ToHexString(SHA256.HashData(bytes)), mimeType = "image/png" } } });
        using (var output = new StreamWriter(zip.CreateEntry("assets-manifest.json").Open())) output.Write(manifest);
        extra?.Invoke(zip);
    }
    var zipData = zipBytes.ToArray();
    using var exe = File.Create(path);
    exe.Write(new byte[1024]);
    exe.Write(zipData);
    Span<byte> len = stackalloc byte[8];
    BinaryPrimitives.WriteUInt64LittleEndian(len, (ulong)zipData.Length);
    exe.Write(len);
    var hash = SHA256.HashData(zipData); if (corruptHash) hash[0] ^= 0xFF;
    exe.Write(hash);
    exe.Write(Encoding.ASCII.GetBytes(EmbeddedAssetPack.FooterMagic));
    return path;
}
try
{
    Check(Encoding.ASCII.GetByteCount(EmbeddedAssetPack.FooterMagic) == 16 && EmbeddedAssetPack.FooterSize == 56, "footer schema");
    var dataRoot = Path.Combine(root, "userdata");
    var exe = CreatePack("client");
    var progress = new List<string>();
    var pack = await EmbeddedAssetPack.EnsureAsync(exe, dataRoot, progress.Add);
    Check(File.ReadAllText(Path.Combine(pack, "Assets/art/test.png")) == "test asset", "extract appended ZIP without executable prefix");
    Check(AssetCatalog.Load(Path.Combine(pack,"assets-manifest.json"), Path.Combine(pack,"Assets")).VerifyAll().Count == 0, "extracted manifest integrity");
    Check(progress.Count > 0 && Path.GetFileName(pack).StartsWith("0.13.0-"), "progress and versioned cache path");
    var marker = Path.Combine(pack, ".ready-sha256");
    var stamp = File.GetLastWriteTimeUtc(marker);
    Check(await EmbeddedAssetPack.EnsureAsync(exe, dataRoot, _ => throw new Exception("Unexpected extraction")) == pack && File.GetLastWriteTimeUtc(marker) == stamp, "cached second launch does not rewrite");
    var parallelRoot = Path.Combine(root,"parallel");
    var both = await Task.WhenAll(EmbeddedAssetPack.EnsureAsync(exe,parallelRoot), EmbeddedAssetPack.EnsureAsync(exe,parallelRoot));
    Check(both[0] == both[1], "concurrent extraction shares complete pack");
    await Reject(() => EmbeddedAssetPack.EnsureAsync(CreatePack("bad-hash",corruptHash:true),dataRoot), "corrupt archive checksum rejected");
    await Reject(() => EmbeddedAssetPack.EnsureAsync(CreatePack("bad-manifest",corruptManifest:true),dataRoot), "corrupt manifest hash rejected before publish");
    await Reject(() => EmbeddedAssetPack.EnsureAsync(CreatePack("traversal",z=> { using var s=z.CreateEntry("Assets/../../escape.txt").Open(); s.WriteByte(1); }),dataRoot), "ZIP traversal rejected");
    Check(!File.Exists(Path.Combine(root,"escape.txt")), "traversal writes nothing outside staging");
    await Reject(() => EmbeddedAssetPack.EnsureAsync(CreatePack("duplicate",z=> { using var s=z.CreateEntry("Assets/ART/TEST.PNG").Open(); s.WriteByte(1); }),dataRoot), "case-insensitive duplicate rejected");
    await Reject(() => EmbeddedAssetPack.EnsureAsync(CreatePack("unexpected",z=> { using var s=z.CreateEntry("launch.exe").Open(); s.WriteByte(1); }),dataRoot), "unexpected payload rejected");
    var noticeExe=CreatePack("notice",z=>{ using var s=z.CreateEntry("THIRD-PARTY/LICENSE.txt").Open(); s.WriteByte(1); });
    var noticePack=await EmbeddedAssetPack.EnsureAsync(noticeExe,dataRoot);
    Check(File.Exists(Path.Combine(noticePack,"THIRD-PARTY/LICENSE.txt")),"third-party notices retained");
    var badLength=CreatePack("bad-length");
    using(var s=new FileStream(badLength,FileMode.Open,FileAccess.Write)){s.Position=s.Length-EmbeddedAssetPack.FooterSize;s.Write(new byte[]{255,255,255,255,255,255,255,255});}
    await Reject(()=>EmbeddedAssetPack.EnsureAsync(badLength,dataRoot),"invalid footer length rejected");
    Check(!Directory.EnumerateDirectories(Path.Combine(dataRoot,"AssetPacks"),"*.preparing-*").Any(),"failed extraction staging cleaned");
    Check(File.ReadAllText(Path.Combine(pack,"Assets/art/test.png"))=="test asset","failed other packages preserve previous working pack");
    using var cancellation=new CancellationTokenSource();cancellation.Cancel();
    try{await EmbeddedAssetPack.EnsureAsync(exe,dataRoot,null,cancellation.Token);throw new Exception("Cancellation ignored");}catch(OperationCanceledException){Check(true,"cancelled preparation exits cleanly");}
    Console.WriteLine($"All {passed} embedded-pack checks passed.");
}
finally {Directory.Delete(root,true);}

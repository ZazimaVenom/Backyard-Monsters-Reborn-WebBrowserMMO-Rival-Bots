using System.Buffers.Binary;
using System.IO;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using BackyardMonsters.Client.Core;

namespace BackyardMonsters.Client;

/// <summary>Reads the asset ZIP appended to the published EXE without loading it into memory.</summary>
public static class EmbeddedAssetPack
{
    // File layout: executable | ZIP | uint64 ZIP length (LE) | SHA256 (32 bytes) | magic (16 bytes).
    public const string FooterMagic = "BYMR_ASSETPACKV1";
    public const int FooterSize = 56;
    private const long MaximumUnpackedBytes = 8L * 1024 * 1024 * 1024;
    private const long MaximumEntryBytes = 512L * 1024 * 1024;
    private const string ReadyFile = ".ready-sha256";

    /// <remarks>The progress callback is invoked on a background thread.</remarks>
    public static Task<string> EnsureAsync(string executablePath, string dataRoot, Action<string>? progress = null,
        CancellationToken cancellationToken = default) =>
        Task.Run(() => Ensure(executablePath, dataRoot, progress, cancellationToken), cancellationToken);

    private static string Ensure(string executablePath, string dataRoot, Action<string>? progress, CancellationToken token)
    {
        token.ThrowIfCancellationRequested();
        using var executable = new FileStream(executablePath, FileMode.Open, FileAccess.Read, FileShare.Read, 128 * 1024, FileOptions.RandomAccess);
        var footer = new byte[FooterSize];
        if (executable.Length < FooterSize) return FindDevelopmentPack(executablePath);
        executable.Position = executable.Length - FooterSize;
        executable.ReadExactly(footer);
        if (!footer.AsSpan(40, 16).SequenceEqual(Encoding.ASCII.GetBytes(FooterMagic)))
            return FindDevelopmentPack(executablePath);
        var unsignedLength = BinaryPrimitives.ReadUInt64LittleEndian(footer.AsSpan(0, 8));
        if (unsignedLength < 22 || unsignedLength > (ulong)(executable.Length - FooterSize))
            throw new InvalidDataException("The asset package embedded in this client is incomplete. Download the client again.");
        var length = checked((long)unsignedLength);
        var offset = executable.Length - FooterSize - length;
        var expectedHash = footer.AsSpan(8, 32).ToArray();
        var hashText = Convert.ToHexString(expectedHash).ToLowerInvariant();
        using var bounded = new BoundedReadStream(executable, offset, length);
        using var archive = new ZipArchive(bounded, ZipArchiveMode.Read, leaveOpen: true);
        var manifestEntry = archive.GetEntry("assets-manifest.json")
            ?? throw new InvalidDataException("The embedded asset package has no manifest.");
        if (manifestEntry.Length <= 0 || manifestEntry.Length > 4 * 1024 * 1024)
            throw new InvalidDataException("The embedded asset manifest is invalid.");
        string version;
        using (var manifestStream = manifestEntry.Open())
        using (var document = JsonDocument.Parse(manifestStream))
        {
            if (!document.RootElement.TryGetProperty("gameVersion", out var value) || value.ValueKind != JsonValueKind.String)
                throw new InvalidDataException("The asset package has no game version.");
            version = value.GetString() ?? "";
        }
        if (version.Length is < 1 or > 48 || version.Any(c => !(char.IsAsciiLetterOrDigit(c) || c is '.' or '-' or '_')) || version is "." or "..")
            throw new InvalidDataException("The embedded asset package has an invalid version.");
        var packStore = Path.Combine(Path.GetFullPath(dataRoot), "AssetPacks");
        Directory.CreateDirectory(packStore);
        var packName = version + "-" + hashText[..16];
        var destination = Path.Combine(packStore, packName);
        if (IsReady(destination, hashText)) return destination;
        progress?.Invoke("Preparing your local game files for the first launch…");
        using var extractionLock = AcquireLock(Path.Combine(packStore, packName + ".lock"), token);
        if (IsReady(destination, hashText)) return destination;
        token.ThrowIfCancellationRequested();
        bounded.Position = 0;
        var actualHash = HashStream(bounded, token);
        if (!CryptographicOperations.FixedTimeEquals(expectedHash, actualHash))
            throw new InvalidDataException("The client asset package failed its integrity check. Download the client again.");
        var staging = Path.Combine(packStore, packName + ".preparing-" + Guid.NewGuid().ToString("N"));
        var previous = Path.Combine(packStore, packName + ".previous-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(staging);
        try
        {
            var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            long total = 0;
            var count = 0;
            if (archive.Entries.Count > 200_000) throw new InvalidDataException("The embedded asset package has too many entries.");
            foreach (var entry in archive.Entries)
            {
                token.ThrowIfCancellationRequested();
                var relative = ValidateEntry(entry);
                var isDirectory = relative.EndsWith('/');
                if (!seen.Add(relative)) throw new InvalidDataException("The embedded asset package contains duplicate paths.");
                var target = Path.GetFullPath(Path.Combine(staging, relative.Replace('/', Path.DirectorySeparatorChar)));
                if (!target.StartsWith(staging + Path.DirectorySeparatorChar, StringComparison.Ordinal))
                    throw new InvalidDataException("An embedded asset path leaves the asset folder.");
                if (isDirectory) { Directory.CreateDirectory(target); continue; }
                total = checked(total + entry.Length);
                if (entry.Length < 0 || entry.Length > MaximumEntryBytes || total > MaximumUnpackedBytes)
                    throw new InvalidDataException("The embedded asset package is too large.");
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                using (var input = entry.Open())
                using (var output = new FileStream(target, FileMode.CreateNew, FileAccess.Write, FileShare.None, 128 * 1024))
                {
                    CopyEntry(input, output, entry.Length, token);
                }
                ++count;
                if (count % 25 == 0 || count == archive.Entries.Count)
                    progress?.Invoke($"Preparing your local game files… {count:N0} of {archive.Entries.Count:N0}");
            }
            token.ThrowIfCancellationRequested();
            progress?.Invoke("Checking your local game files…");
            var catalog = AssetCatalog.Load(Path.Combine(staging, "assets-manifest.json"), Path.Combine(staging, "Assets"));
            var failures = catalog.VerifyAll();
            if (failures.Count > 0) throw new InvalidDataException($"{failures.Count} embedded game files failed verification. Download the client again.");
            token.ThrowIfCancellationRequested();
            File.WriteAllText(Path.Combine(staging, ReadyFile), hashText, Encoding.ASCII);
            if (Directory.Exists(destination)) Directory.Move(destination, previous);
            try { Directory.Move(staging, destination); }
            catch
            {
                if (Directory.Exists(previous) && !Directory.Exists(destination)) Directory.Move(previous, destination);
                throw;
            }
            TryDeleteDirectory(previous);
            progress?.Invoke($"Your {catalog.Count:N0} local game files are ready.");
            return destination;
        }
        finally { TryDeleteDirectory(staging); }
    }

    private static string FindDevelopmentPack(string executablePath)
    {
        foreach (var candidate in new[] { Path.GetDirectoryName(Path.GetFullPath(executablePath))!, AppContext.BaseDirectory })
            if (File.Exists(Path.Combine(candidate, "assets-manifest.json")) && Directory.Exists(Path.Combine(candidate, "Assets")))
                return candidate;
        throw new InvalidDataException("This client has no bundled game assets. Download the complete precompiled client.");
    }

    private static bool IsReady(string path, string hash)
    {
        try
        {
            return Directory.Exists(Path.Combine(path, "Assets")) && File.Exists(Path.Combine(path, "assets-manifest.json")) &&
                new FileInfo(Path.Combine(path, ReadyFile)) is { Exists: true, Length: 64 } &&
                File.ReadAllText(Path.Combine(path, ReadyFile), Encoding.ASCII) == hash;
        }
        catch (IOException) { return false; }
        catch (UnauthorizedAccessException) { return false; }
    }

    private static FileStream AcquireLock(string path, CancellationToken token)
    {
        var deadline = DateTime.UtcNow.AddMinutes(5);
        while (true)
        {
            token.ThrowIfCancellationRequested();
            try { return new FileStream(path, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None); }
            catch (IOException) when (DateTime.UtcNow < deadline) { token.WaitHandle.WaitOne(100); }
        }
    }

    private static byte[] HashStream(Stream stream, CancellationToken token)
    {
        using var hash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        var buffer = new byte[128 * 1024];
        int read;
        while ((read = stream.Read(buffer, 0, buffer.Length)) > 0) { token.ThrowIfCancellationRequested(); hash.AppendData(buffer, 0, read); }
        return hash.GetHashAndReset();
    }

    private static string ValidateEntry(ZipArchiveEntry entry)
    {
        var path = entry.FullName;
        var withoutTrailingSlash = path.TrimEnd('/');
        if (path.Length == 0 || path.StartsWith('/') || path.Contains('\\') || path.Contains(':') || path.Any(char.IsControl) ||
            withoutTrailingSlash.Split('/').Any(p => p.Length == 0 || p is "." or ".." || p.EndsWith(' ') || p.EndsWith('.')) ||
            ((entry.ExternalAttributes >> 16) & 0xF000) == 0xA000)
            throw new InvalidDataException("The embedded asset package contains an unsafe path.");
        if (!(path == "assets-manifest.json" || path == "README.txt" || path.StartsWith("Assets/", StringComparison.Ordinal) ||
              path.StartsWith("THIRD-PARTY/", StringComparison.Ordinal)))
            throw new InvalidDataException("The embedded asset package contains an unexpected file.");
        return path;
    }

    private static void CopyEntry(Stream input, Stream output, long expectedLength, CancellationToken token)
    {
        var buffer = new byte[128 * 1024];
        long copied = 0;
        int read;
        while ((read = input.Read(buffer, 0, buffer.Length)) > 0)
        {
            token.ThrowIfCancellationRequested();
            copied += read;
            if (copied > expectedLength) throw new InvalidDataException("An embedded asset exceeds its declared size.");
            output.Write(buffer, 0, read);
        }
        if (copied != expectedLength) throw new InvalidDataException("An embedded asset is incomplete.");
    }

    private static void TryDeleteDirectory(string path)
    {
        try { if (Directory.Exists(path)) Directory.Delete(path, recursive: true); }
        catch (IOException) { }
        catch (UnauthorizedAccessException) { }
    }

    private sealed class BoundedReadStream(Stream source, long offset, long length) : Stream
    {
        private long position;
        public override bool CanRead => true;
        public override bool CanSeek => true;
        public override bool CanWrite => false;
        public override long Length => length;
        public override long Position { get => position; set => Seek(value, SeekOrigin.Begin); }
        public override int Read(byte[] buffer, int bufferOffset, int count) => Read(buffer.AsSpan(bufferOffset, count));
        public override int Read(Span<byte> buffer)
        {
            var count = (int)Math.Min(buffer.Length, length - position);
            if (count == 0) return 0;
            source.Position = offset + position;
            var read = source.Read(buffer[..count]);
            position += read;
            return read;
        }
        public override long Seek(long value, SeekOrigin origin)
        {
            var target = origin switch { SeekOrigin.Begin => value, SeekOrigin.Current => checked(position + value), SeekOrigin.End => checked(length + value), _ => throw new ArgumentOutOfRangeException(nameof(origin)) };
            if (target < 0 || target > length) throw new IOException("Seek outside the embedded asset package.");
            return position = target;
        }
        public override void Flush() { }
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
    }
}

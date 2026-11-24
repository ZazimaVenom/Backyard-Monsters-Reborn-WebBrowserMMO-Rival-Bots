namespace BackyardMonsters.Client.Core;

public enum RangeKind { Full, Partial, Unsatisfiable }

public readonly record struct SingleByteRange(RangeKind Kind, long Start, long Length)
{
    public long End => Start + Length - 1;

    public static SingleByteRange Parse(string? header, long fileLength)
    {
        if (fileLength < 0) throw new ArgumentOutOfRangeException(nameof(fileLength));
        var full = new SingleByteRange(RangeKind.Full, 0, fileLength);
        if (string.IsNullOrWhiteSpace(header) || !header.StartsWith("bytes=", StringComparison.OrdinalIgnoreCase)) return full;
        var value = header[6..].Trim();
        // Multiple ranges may legally be ignored; the caller sends the complete representation.
        if (value.Contains(',')) return full;
        var parts = value.Split('-');
        if (parts.Length != 2) return full;
        if (parts[0].Length == 0)
        {
            if (!TryNumber(parts[1], out var suffix)) return full;
            if (suffix == 0 || fileLength == 0) return new(RangeKind.Unsatisfiable, 0, 0);
            var count = Math.Min(suffix, fileLength);
            return new(RangeKind.Partial, fileLength - count, count);
        }
        if (!TryNumber(parts[0], out var start)) return full;
        var end = fileLength - 1;
        if (parts[1].Length > 0 && !TryNumber(parts[1], out end)) return full;
        if (start >= fileLength || start > end) return new(RangeKind.Unsatisfiable, 0, 0);
        end = Math.Min(end, fileLength - 1);
        return new(RangeKind.Partial, start, end - start + 1);
    }

    private static bool TryNumber(string value, out long number)
    {
        number = 0;
        return value.Length > 0 && value.All(c => c >= '0' && c <= '9') && long.TryParse(value, out number);
    }
}

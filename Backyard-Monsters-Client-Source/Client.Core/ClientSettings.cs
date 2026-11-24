using System.Text.Json;

namespace BackyardMonsters.Client.Core;

public sealed class ClientSettings
{
    public string ServerAddress { get; set; } = Core.ServerAddress.DefaultInput;
    public List<string> SavedServers { get; set; } = [Core.ServerAddress.DefaultInput];
    public bool LocalAssetsEnabled { get; set; } = true;
    public bool Muted { get; set; }

    public void Remember(string address)
    {
        var normalized = Core.ServerAddress.Normalize(address).AbsoluteUri;
        ServerAddress = normalized;
        SavedServers = NormalizeSavedServers(new[] { normalized }.Concat(SavedServers ?? []));
    }

    internal static List<string> NormalizeSavedServers(IEnumerable<string>? addresses)
    {
        var result = new List<string>();
        foreach (var address in addresses ?? [])
        {
            string normalized;
            try { normalized = Core.ServerAddress.Normalize(address).AbsoluteUri; }
            catch (ArgumentException) { continue; }
            if (!result.Contains(normalized, StringComparer.Ordinal)) result.Add(normalized);
            if (result.Count == 20) break;
        }
        return result;
    }
}

public sealed class SettingsStore(string path)
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true, PropertyNameCaseInsensitive = true };
    public string Path { get; } = System.IO.Path.GetFullPath(path);

    public ClientSettings Load()
    {
        if (!File.Exists(Path)) return new();
        try
        {
            var result = JsonSerializer.Deserialize<ClientSettings>(File.ReadAllText(Path), JsonOptions) ?? new();
            try { Core.ServerAddress.Normalize(result.ServerAddress); }
            catch (ArgumentException) { result.ServerAddress = Core.ServerAddress.DefaultInput; }
            result.SavedServers = ClientSettings.NormalizeSavedServers(result.SavedServers);
            if (result.SavedServers.Count == 0) result.SavedServers.Add(Core.ServerAddress.DefaultInput);
            return result;
        }
        catch (JsonException) { return new(); }
        catch (IOException) { return new(); }
        catch (UnauthorizedAccessException) { return new(); }
    }

    public void Save(ClientSettings settings)
    {
        Directory.CreateDirectory(System.IO.Path.GetDirectoryName(Path)!);
        var temp = Path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            File.WriteAllText(temp, JsonSerializer.Serialize(settings, JsonOptions));
            File.Move(temp, Path, true);
        }
        finally { if (File.Exists(temp)) File.Delete(temp); }
    }
}

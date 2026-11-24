namespace BackyardMonsters.Client.Core;

public static class ServerAddress
{
    public const string DefaultInput = "127.0.0.1";

    public static Uri Normalize(string input)
    {
        var value = (input ?? "").Trim();
        if (string.IsNullOrWhiteSpace(value)) throw new ArgumentException("Enter a server address.");
        if (value.Any(char.IsControl) || value.Contains('\\'))
            throw new ArgumentException("The server address contains an invalid character.");
        if (!value.Contains("://", StringComparison.Ordinal)) value = "http://" + value;
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) ||
            (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps) ||
            string.IsNullOrWhiteSpace(uri.Host) || !string.IsNullOrEmpty(uri.UserInfo))
            throw new ArgumentException("Use an HTTP or HTTPS server address without a username or password.");
        var builder = new UriBuilder(uri) { Fragment = "" };
        var leaf = uri.AbsolutePath.Split('/').LastOrDefault() ?? "";
        if (!uri.AbsolutePath.EndsWith('/') && !leaf.Contains('.')) builder.Path += "/";
        return builder.Uri;
    }

    public static bool SameOrigin(Uri a, Uri b) =>
        string.Equals(a.Scheme, b.Scheme, StringComparison.OrdinalIgnoreCase) &&
        string.Equals(a.IdnHost, b.IdnHost, StringComparison.OrdinalIgnoreCase) && a.Port == b.Port;
}

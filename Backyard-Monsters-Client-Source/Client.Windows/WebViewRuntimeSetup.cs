using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Security.Cryptography.X509Certificates;
using Microsoft.Web.WebView2.Core;

namespace BackyardMonsters.Client;

/// <summary>Installs Microsoft's browser runtime when it is missing, without command scripts.</summary>
internal static class WebViewRuntimeSetup
{
    private const string BootstrapperUrl = "https://go.microsoft.com/fwlink/p/?LinkId=2124703";
    private const long MaximumInstallerBytes = 20 * 1024 * 1024;
    private static readonly SemaphoreSlim SetupLock = new(1, 1);

    public static async Task EnsureInstalledAsync(Action<string> progress)
    {
        if (RuntimeAvailable()) return;
        await SetupLock.WaitAsync();
        string? workDirectory = null;
        try
        {
            if (RuntimeAvailable()) return;
            if (!OperatingSystem.IsWindows() || !Environment.Is64BitOperatingSystem)
                throw new InvalidOperationException("This client needs 64-bit Windows.");

            progress("Setting up the game browser. Downloading Microsoft Edge WebView2…");
            workDirectory = Path.Combine(Path.GetTempPath(), "BackyardMonsters-WebView2-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(workDirectory);
            var installer = Path.Combine(workDirectory, "MicrosoftEdgeWebview2Setup.exe");
            await DownloadAsync(installer);

            progress("Checking the Microsoft browser installer…");
            await Task.Run(() => VerifyMicrosoftSignature(installer));

            progress("Installing Microsoft Edge WebView2. This first-time setup can take a few minutes…");
            var startInfo = new ProcessStartInfo(installer)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                WindowStyle = ProcessWindowStyle.Hidden,
                WorkingDirectory = workDirectory
            };
            startInfo.ArgumentList.Add("/silent");
            startInfo.ArgumentList.Add("/install");
            using var process = Process.Start(startInfo)
                ?? throw new InvalidOperationException("The Microsoft browser installer could not start. Please try Connect again.");
            using var installTimeout = new CancellationTokenSource(TimeSpan.FromMinutes(5));
            try { await process.WaitForExitAsync(installTimeout.Token); }
            catch (OperationCanceledException)
            {
                // Do not terminate Microsoft's installer halfway through an installation.
                throw new InvalidOperationException("Microsoft's browser setup is still taking time. Let it finish, then close and reopen the client.");
            }
            for (var attempt = 0; attempt < 10; attempt++)
            {
                if (RuntimeAvailable())
                {
                    progress("The game browser is ready. Connecting to your realm…");
                    return;
                }
                await Task.Delay(1000);
            }
            throw new InvalidOperationException($"Microsoft Edge WebView2 setup finished with code {process.ExitCode}, but the browser runtime is not available yet. Restart Windows and try again, or install the WebView2 Runtime from Microsoft's website.");
        }
        catch (HttpRequestException ex)
        {
            throw new InvalidOperationException("The game browser needs a one-time Microsoft WebView2 download. Check your internet connection, then press Connect again.", ex);
        }
        catch (OperationCanceledException ex)
        {
            throw new InvalidOperationException("The Microsoft WebView2 download timed out. Check your internet connection, then press Connect again.", ex);
        }
        catch (Win32Exception ex)
        {
            throw new InvalidOperationException("Windows could not start the Microsoft browser installer. Check your Windows security or organisation's installation policy, then try again.", ex);
        }
        finally
        {
            if (workDirectory is not null)
            {
                try { Directory.Delete(workDirectory, true); }
                catch (IOException) { }
                catch (UnauthorizedAccessException) { }
            }
            SetupLock.Release();
        }
    }

    private static bool RuntimeAvailable()
    {
        try { return !string.IsNullOrWhiteSpace(CoreWebView2Environment.GetAvailableBrowserVersionString()); }
        catch (WebView2RuntimeNotFoundException) { return false; }
    }

    private static async Task DownloadAsync(string destination)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromMinutes(5));
        using var client = new HttpClient { Timeout = Timeout.InfiniteTimeSpan };
        using var response = await client.GetAsync(BootstrapperUrl, HttpCompletionOption.ResponseHeadersRead, timeout.Token);
        response.EnsureSuccessStatusCode();
        if (response.RequestMessage?.RequestUri?.Scheme != Uri.UriSchemeHttps)
            throw new InvalidOperationException("Microsoft's browser setup download did not use a secure connection. It was not run.");
        if (response.Content.Headers.ContentLength is > MaximumInstallerBytes or 0)
            throw new InvalidOperationException("The browser installer download had an unexpected size. It was not run.");
        await using var source = await response.Content.ReadAsStreamAsync(timeout.Token);
        await using var output = new FileStream(destination, FileMode.CreateNew, FileAccess.Write, FileShare.None, 81920, true);
        var buffer = new byte[81920];
        long length = 0;
        int read;
        while ((read = await source.ReadAsync(buffer, timeout.Token)) != 0)
        {
            length += read;
            if (length > MaximumInstallerBytes)
                throw new InvalidOperationException("The browser installer download was unexpectedly large. It was not run.");
            await output.WriteAsync(buffer.AsMemory(0, read), timeout.Token);
        }
        if (length == 0) throw new InvalidOperationException("The browser installer download was empty. Please try Connect again.");
    }

    private static void VerifyMicrosoftSignature(string filename)
    {
        var fileInfo = new WinTrustFileInfo
        {
            StructureSize = (uint)Marshal.SizeOf<WinTrustFileInfo>(),
            FilePath = filename
        };
        var filePointer = Marshal.AllocHGlobal(Marshal.SizeOf<WinTrustFileInfo>());
        var marshaled = false;
        try
        {
            Marshal.StructureToPtr(fileInfo, filePointer, false);
            marshaled = true;
            var data = new WinTrustData
            {
                StructureSize = (uint)Marshal.SizeOf<WinTrustData>(),
                UiChoice = 2, // WTD_UI_NONE: verification never opens a Windows trust dialog.
                UnionChoice = 1, // WTD_CHOICE_FILE
                FileInfo = filePointer,
                StateAction = 1, // WTD_STATEACTION_VERIFY
                ProviderFlags = 0x80 // WTD_REVOCATION_CHECK_CHAIN_EXCLUDE_ROOT
            };
            var action = new Guid("00AAC56B-CD44-11d0-8CC2-00C04FC295EE"); // WINTRUST_ACTION_GENERIC_VERIFY_V2
            int result;
            try { result = WinVerifyTrust(new IntPtr(-1), ref action, ref data); }
            finally
            {
                data.StateAction = 2; // WTD_STATEACTION_CLOSE releases trust-provider state.
                _ = WinVerifyTrust(new IntPtr(-1), ref action, ref data);
            }
            if (result != 0)
                throw new InvalidOperationException("Windows could not verify the Microsoft browser installer's signature. It was not run. Check the computer's date, internet access and Windows updates, then try again.");

            // A valid signature from an unrelated software publisher is not sufficient.
            // X509CertificateLoader does not extract Authenticode signers from a PE file.
#pragma warning disable SYSLIB0057
            using var certificate = X509Certificate.CreateFromSignedFile(filename);
#pragma warning restore SYSLIB0057
            using var signer = X509CertificateLoader.LoadCertificate(certificate.GetRawCertData());
            var fields = signer.SubjectName.Decode(X500DistinguishedNameFlags.UseNewLines)
                .Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
            if (!fields.Contains("O=Microsoft Corporation", StringComparer.Ordinal))
                throw new InvalidOperationException("The browser installer was not signed by Microsoft Corporation. It was not run.");
        }
        finally
        {
            if (marshaled) Marshal.DestroyStructure<WinTrustFileInfo>(filePointer);
            Marshal.FreeHGlobal(filePointer);
        }
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct WinTrustFileInfo
    {
        public uint StructureSize;
        [MarshalAs(UnmanagedType.LPWStr)] public string FilePath;
        public IntPtr FileHandle;
        public IntPtr KnownSubject;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct WinTrustData
    {
        public uint StructureSize;
        public IntPtr PolicyCallbackData;
        public IntPtr SipClientData;
        public uint UiChoice;
        public uint RevocationChecks;
        public uint UnionChoice;
        public IntPtr FileInfo;
        public uint StateAction;
        public IntPtr StateData;
        public IntPtr UrlReference;
        public uint ProviderFlags;
        public uint UiContext;
        public IntPtr SignatureSettings;
    }

    [DllImport("wintrust.dll", ExactSpelling = true, SetLastError = false)]
    private static extern int WinVerifyTrust(IntPtr window, ref Guid action, ref WinTrustData data);
}

using System.IO;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Input;
using System.Windows.Threading;
using BackyardMonsters.Client.Core;
using Microsoft.Web.WebView2.Core;

namespace BackyardMonsters.Client;

public partial class MainWindow : Window
{
    private static readonly string DataRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BackyardMonstersClient");
    private readonly SettingsStore store = new(Path.Combine(DataRoot, "settings.json"));
    private readonly DispatcherTimer meter = new() { Interval = TimeSpan.FromSeconds(1) };
    private readonly CancellationTokenSource lifetime = new();
    private ClientSettings settings = new();
    private AssetCatalog? catalog;
    private Uri? activeServer;
    private Task? browserInitialization;
    private bool browserConfigured;
    private int connectAttempt;
    private int sessionGeneration;
    private ulong currentNavigationId;
    private bool sessionLocal;
    private bool versionMismatch;
    private bool closing;
    private bool preparingAssets = true;
    private bool fullscreen;
    private WindowState previousWindowState;
    private long localRequests;
    private long localBytes;
    private long fallbackRequests;
    private int navigationGeneration;

    public MainWindow()
    {
        InitializeComponent();
        settings = store.Load();
        RefreshServers(settings.ServerAddress);
        LocalAssetsToggle.IsChecked = settings.LocalAssetsEnabled;
        UpdateMuteLabel();
        ConnectButton.IsEnabled = false;
        SetStatus("Preparing your local game assets…");
        PackStatusText.Text = "PREPARING LOCAL ASSETS";
        ConnectionText.Text = "GETTING YOUR BACKYARD READY";
        Loaded += PrepareAssetsOnLoad;
        meter.Tick += (_, _) => UpdatePackStatus();
        meter.Start();
        PreviewKeyDown += OnKeyDown;
        Closed += (_, _) =>
        {
            closing = true;
            lifetime.Cancel();
            ++connectAttempt;
            ++sessionGeneration;
            ++navigationGeneration;
            meter.Stop();
            try { GameView.Dispose(); }
            catch (Exception ex) when (ex is InvalidOperationException or COMException) { Log(ex); }
        };
    }

    private async void PrepareAssetsOnLoad(object sender, RoutedEventArgs e)
    {
        Loaded -= PrepareAssetsOnLoad;
        try
        {
            var executable = Environment.ProcessPath ?? throw new IOException("The client executable could not be located.");
            var root = await EmbeddedAssetPack.EnsureAsync(executable, DataRoot, ReportSetupProgress, lifetime.Token);
            if (closing) return;
            catalog = AssetCatalog.Load(Path.Combine(root, "assets-manifest.json"), Path.Combine(root, "Assets"));
            SetStatus($"Ready. Choose your server and connect. All {catalog.Count:N0} game assets are stored on this PC.");
        }
        catch (OperationCanceledException) when (closing) { return; }
        catch (Exception ex)
        {
            if (closing) return;
            SetStatus("Local assets could not be prepared. Check free disk space and download the complete EXE again. Connecting now will use server assets. " + ex.Message, true);
            Log(ex);
        }
        finally
        {
            if (!closing)
            {
                preparingAssets = false;
                ConnectButton.IsEnabled = true;
                ConnectionText.Text = "READY TO EXPLORE";
                UpdatePackStatus();
            }
        }
    }

    private void ReportSetupProgress(string message)
    {
        if (closing || Dispatcher.HasShutdownStarted) return;
        if (Dispatcher.CheckAccess()) SetStatus(message);
        else Dispatcher.BeginInvoke(new Action(() => { if (!closing) SetStatus(message); }));
    }

    private void RefreshServers(string selected)
    {
        ServerBox.ItemsSource = null;
        ServerBox.ItemsSource = settings.SavedServers.ToArray();
        ServerBox.Text = selected;
    }

    private bool SaveSettings()
    {
        try { store.Save(settings); return true; }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        { SetStatus("This setting could not be saved. Check that your Windows user profile is writable.", true); Log(ex); return false; }
    }

    private void SaveServer_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            settings.Remember(ServerBox.Text);
            RefreshServers(settings.ServerAddress);
            if (SaveSettings()) SetStatus("Server saved. Choose it from the address list next time.");
        }
        catch (ArgumentException ex) { SetStatus(ex.Message, true); }
    }

    private void ForgetServer_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var address = ServerAddress.Normalize(ServerBox.Text).AbsoluteUri;
            settings.SavedServers.RemoveAll(s => ServerAddress.Normalize(s).AbsoluteUri == address);
            settings.ServerAddress = settings.SavedServers.FirstOrDefault() ?? ServerAddress.DefaultInput;
            RefreshServers(settings.ServerAddress);
            if (SaveSettings()) SetStatus("Saved address removed. Your current game session is unchanged.");
        }
        catch (ArgumentException ex) { SetStatus(ex.Message, true); }
    }

    private async void Connect_Click(object sender, RoutedEventArgs e) => await ConnectAsync();

    private async Task ConnectAsync()
    {
        if (!ConnectButton.IsEnabled) return;
        Uri target;
        try { target = ServerAddress.Normalize(ServerBox.Text); }
        catch (ArgumentException ex) { SetStatus(ex.Message, true); return; }
        var attempt = ++connectAttempt;
        ConnectButton.IsEnabled = false;
        ConnectionText.Text = "OPENING YOUR REALM";
        SetStatus("Starting the game browser…");
        try
        {
            await EnsureBrowserAsync();
            if (closing || attempt != connectAttempt) return;
            settings.Remember(target.AbsoluteUri);
            RefreshServers(settings.ServerAddress);
            var saved = SaveSettings();
            ++sessionGeneration;
            currentNavigationId = 0;
            activeServer = target;
            sessionLocal = settings.LocalAssetsEnabled && catalog is not null;
            versionMismatch = false;
            localRequests = localBytes = fallbackRequests = 0;
            HomePanel.Visibility = Visibility.Collapsed;
            GamePanel.Visibility = Visibility.Visible;
            GameView.CoreWebView2.IsMuted = settings.Muted;
            GameView.CoreWebView2.Navigate(target.AbsoluteUri);
            SetStatus(saved ? "Connecting to your server. This address has been saved." : "Connecting. The address could not be saved to your Windows profile.", !saved);
        }
        catch (WebView2RuntimeNotFoundException ex)
        {
            if (closing || attempt != connectAttempt) return;
            ConnectionText.Text = "BROWSER SETUP NEEDED";
            SetStatus("The game browser is not installed yet. Connect to the internet and press Connect again to finish automatic setup.", true);
            Log(ex);
        }
        catch (Exception ex)
        {
            if (closing || attempt != connectAttempt) return;
            ConnectionText.Text = "UNABLE TO OPEN";
            SetStatus("The game browser could not start. " + ex.Message + " Press Connect to retry. Details are in the client log.", true);
            Log(ex);
        }
        finally { if (!closing) ConnectButton.IsEnabled = true; }
    }

    private async Task EnsureBrowserAsync()
    {
        if (browserConfigured && GameView.CoreWebView2 is not null) return;
        browserInitialization ??= InitializeBrowserAsync();
        try { await browserInitialization; }
        catch { browserInitialization = null; throw; }
    }

    private async Task InitializeBrowserAsync()
    {
        if (GameView.CoreWebView2 is null)
        {
            Directory.CreateDirectory(DataRoot);
            await WebViewRuntimeSetup.EnsureInstalledAsync(ReportSetupProgress);
            if (closing) throw new ObjectDisposedException(nameof(MainWindow));
            var environment = await CoreWebView2Environment.CreateAsync(null, Path.Combine(DataRoot, "Browser"));
            if (closing) throw new ObjectDisposedException(nameof(MainWindow));
            await GameView.EnsureCoreWebView2Async(environment);
        }
        if (closing) throw new ObjectDisposedException(nameof(MainWindow));
        var core = GameView.CoreWebView2 ?? throw new InvalidOperationException("The browser did not initialize.");
        core.Settings.AreHostObjectsAllowed = false;
        core.Settings.IsWebMessageEnabled = false;
        core.Settings.AreDefaultContextMenusEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.Settings.IsPasswordAutosaveEnabled = false;
        core.Settings.IsGeneralAutofillEnabled = false;
        core.Settings.UserAgent += " BackyardMonstersRehatchedClient/1.2";
        core.AddWebResourceRequestedFilter("*", CoreWebView2WebResourceContext.All, CoreWebView2WebResourceRequestSourceKinds.All);
        core.WebResourceRequested += OnResourceRequested;
        core.NavigationStarting += OnNavigationStarting;
        core.NavigationCompleted += OnNavigationCompleted;
        core.NewWindowRequested += (_, e) =>
        {
            e.Handled = true;
            SetStatus("This page tried to open another window. Use the server address field to open a different realm.");
        };
        core.PermissionRequested += (_, e) => e.State = CoreWebView2PermissionState.Deny;
        core.DownloadStarting += (_, e) => { e.Cancel = true; SetStatus("Downloads are disabled inside the game client. Use your normal browser for downloads."); };
        core.ProcessFailed += (_, e) =>
        {
            ConnectionText.Text = "BROWSER INTERRUPTED";
            SetStatus("The game browser stopped (" + e.ProcessFailedKind + "). Close and reopen the client to reconnect.", true);
            ++sessionGeneration;
            ++navigationGeneration;
            Log(new Exception("WebView2 process failure: " + e.ProcessFailedKind));
        };
        browserConfigured = true;
    }

    private void OnNavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (e.Uri == "about:blank") return;
        if (activeServer is null || !Uri.TryCreate(e.Uri, UriKind.Absolute, out var target) || !ServerAddress.SameOrigin(activeServer, target))
        {
            e.Cancel = true;
            SetStatus("The page redirected to a different server. Enter that server's address in the toolbar if you want to connect to it.", true);
            return;
        }
        currentNavigationId = e.NavigationId;
        ++sessionGeneration;
        ConnectionText.Text = "CONNECTING";
        var generation = ++navigationGeneration;
        _ = ShowSlowConnectionAsync(generation);
    }

    private async Task ShowSlowConnectionAsync(int generation)
    {
        await Task.Delay(TimeSpan.FromSeconds(25));
        if (!closing && generation == navigationGeneration && activeServer is not null)
            SetStatus("The server is taking a while to respond. Check its address, port, and that PHP/MySQL are running. You can retry or return Home.", true);
    }

    private void OnNavigationCompleted(object? sender, CoreWebView2NavigationCompletedEventArgs e)
    {
        if (e.NavigationId != currentNavigationId) return;
        ++navigationGeneration;
        if (closing || activeServer is null || GameView.Source?.AbsoluteUri == "about:blank") return;
        if (!e.IsSuccess || e.HttpStatusCode >= 400)
        {
            ConnectionText.Text = "CONNECTION NEEDS ATTENTION";
            SetStatus($"Could not load the game page ({(e.HttpStatusCode >= 400 ? "HTTP " + e.HttpStatusCode : e.WebErrorStatus.ToString())}). Check the server address and game folder, then press Reload.", true);
            return;
        }
        ConnectionText.Text = "REALM PAGE LOADED";
        if (versionMismatch) ShowVersionNotice();
        else SetStatus(sessionLocal ? "Game page loaded. Bundled assets stay local; your account and multiplayer requests go to the server." : "Game page loaded using server assets.");
    }

    private async void OnResourceRequested(object? sender, CoreWebView2WebResourceRequestedEventArgs e)
    {
        var generation = sessionGeneration;
        try
        {
            var server = activeServer;
            var pack = catalog;
            if (closing || !sessionLocal || server is null || pack is null) return;
            var request = e.Request;
            var uri = request.Uri;
            var method = request.Method;
            if ((method != "GET" && method != "HEAD") || !pack.IsAssetRequest(server, uri)) return;
            if (pack.IsVersionMismatch(uri))
            {
                // Invalidate outstanding reads before any more local responses can commit.
                sessionLocal = false;
                versionMismatch = true;
                var fallbackGeneration = ++sessionGeneration;
                ShowVersionNotice();
                if (localRequests > 0)
                {
                    // Already executed local modules cannot be replaced in the current document.
                    // Start a clean server-only document once, outside the resource callback.
                    _ = Dispatcher.BeginInvoke(new Action(() =>
                    {
                        if (!closing && sessionGeneration == fallbackGeneration && activeServer == server && versionMismatch)
                            ReloadGame();
                    }));
                }
                return;
            }
            var rangeHeader = request.Headers.Contains("Range") ? request.Headers.GetHeader("Range") : null;
            // Dispose completes the deferral, including early returns. Keep it inside the try
            // because completion can fail if the window/browser closes during the disk read.
            using (e.GetDeferral())
            {
                var result = await Task.Run(() =>
                {
                    if (!pack.TryResolve(server, uri, method, out var asset)) return (Asset: (LocalAsset?)null, Range: default(SingleByteRange), Bytes: Array.Empty<byte>());
                    var range = SingleByteRange.Parse(method == "HEAD" ? null : rangeHeader, asset.Size);
                    if (range.Kind == RangeKind.Unsatisfiable || method == "HEAD") return (Asset: (LocalAsset?)asset, Range: range, Bytes: Array.Empty<byte>());
                    // MemoryStream remains available for asynchronous COM reads without open file handles.
                    var bytes = new byte[checked((int)range.Length)];
                    using var file = File.OpenRead(asset.FullPath);
                    file.Seek(range.Start, SeekOrigin.Begin);
                    file.ReadExactly(bytes);
                    return (Asset: (LocalAsset?)asset, Range: range, Bytes: bytes);
                });
                if (closing || generation != sessionGeneration || !sessionLocal || activeServer != server) return;
                if (result.Asset is null) { ++fallbackRequests; return; }
                var asset = result.Asset;
                var range = result.Range;
                var status = range.Kind switch { RangeKind.Partial => 206, RangeKind.Unsatisfiable => 416, _ => 200 };
                var headers = $"Content-Type: {asset.MimeType}\r\nContent-Length: {(status == 416 ? 0 : range.Length)}\r\nAccept-Ranges: bytes\r\nCache-Control: no-store\r\nX-Content-Type-Options: nosniff\r\nX-Backyard-Asset: local\r\n";
                if (status == 206) headers += $"Content-Range: bytes {range.Start}-{range.End}/{asset.Size}\r\n";
                if (status == 416) headers += $"Content-Range: bytes */{asset.Size}\r\n";
                e.Response = GameView.CoreWebView2.Environment.CreateWebResourceResponse(new MemoryStream(result.Bytes, false), status,
                    status == 200 ? "OK" : status == 206 ? "Partial Content" : "Range Not Satisfiable", headers);
                ++localRequests;
                localBytes += result.Bytes.LongLength;
            }
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException or OverflowException or COMException or ArgumentException)
        {
            if (!closing && generation == sessionGeneration) ++fallbackRequests;
            Log(ex);
        }
    }

    private void ShowVersionNotice() => SetStatus("This server uses a different asset version. Local assets are paused for this session; the game will use server assets. Update the asset pack to use local files again.", true);

    private void UpdatePackStatus()
    {
        if (preparingAssets) { PackStatusText.Text = "PREPARING LOCAL ASSETS"; return; }
        if (catalog is null) { PackStatusText.Text = "SERVER ASSETS · LOCAL PACK UNAVAILABLE"; return; }
        if (activeServer is null) PackStatusText.Text = $"LOCAL PACK · {catalog.Count:N0} FILES · {catalog.TotalBytes / 1048576d:N0} MB · GAME {catalog.GameVersion}";
        else if (!sessionLocal) PackStatusText.Text = versionMismatch ? "SERVER ASSETS · PACK VERSION DIFFERS" : "SERVER ASSETS · LOCAL MODE OFF";
        else PackStatusText.Text = $"LOCAL · {localRequests:N0} REQUESTS · {localBytes / 1048576d:N1} MB SERVED" + (fallbackRequests > 0 ? $" · {fallbackRequests:N0} SERVER FALLBACKS" : "");
    }

    private void Home_Click(object sender, RoutedEventArgs e)
    {
        ++connectAttempt;
        ++sessionGeneration;
        ++navigationGeneration;
        currentNavigationId = 0;
        activeServer = null;
        sessionLocal = false;
        TryBrowserAction(() =>
        {
            GameView.CoreWebView2?.Stop();
            GameView.CoreWebView2?.Navigate("about:blank");
        });
        GamePanel.Visibility = Visibility.Collapsed;
        HomePanel.Visibility = Visibility.Visible;
        ConnectionText.Text = "READY TO EXPLORE";
        SetStatus("Choose a saved server or enter a new address. Your login cookies are kept on this Windows profile.");
        UpdatePackStatus();
    }

    private void Reload_Click(object sender, RoutedEventArgs e)
    {
        if (activeServer is null) { _ = ConnectAsync(); return; }
        ReloadGame();
    }

    private void ReloadGame()
    {
        if (closing || activeServer is null) return;
        ++sessionGeneration;
        ++navigationGeneration;
        currentNavigationId = 0;
        SetStatus("Reloading the game page…");
        TryBrowserAction(() => GameView.CoreWebView2.Reload());
    }

    private void TryBrowserAction(Action action)
    {
        if (closing) return;
        try { action(); }
        catch (Exception ex) when (ex is InvalidOperationException or COMException)
        {
            SetStatus("The game browser is no longer responding. Close and reopen the client to reconnect.", true);
            Log(ex);
        }
    }

    private void LocalAssets_Click(object sender, RoutedEventArgs e)
    {
        settings.LocalAssetsEnabled = LocalAssetsToggle.IsChecked == true;
        SaveSettings();
        if (activeServer is not null)
        {
            sessionLocal = settings.LocalAssetsEnabled && catalog is not null && !versionMismatch;
            ReloadGame();
            SetStatus(versionMismatch ? "This server requires a different asset pack. Server assets remain active for this session." : "Asset mode changed. Reloading the current game page.");
        }
        else SetStatus(settings.LocalAssetsEnabled ? "Local assets will be used on your next connection." : "Server assets will be used on your next connection.");
        UpdatePackStatus();
    }

    private void Mute_Click(object sender, RoutedEventArgs e)
    {
        settings.Muted = !settings.Muted;
        TryBrowserAction(() => { if (GameView.CoreWebView2 is not null) GameView.CoreWebView2.IsMuted = settings.Muted; });
        UpdateMuteLabel();
        SaveSettings();
        SetStatus(settings.Muted ? "Client sound muted." : "Client sound unmuted. Turn on Sound inside the game to enable its music and effects.");
    }
    private void UpdateMuteLabel() => MuteButton.Content = settings.Muted ? "Unmute" : "Mute";

    private void Fullscreen_Click(object sender, RoutedEventArgs e) => ToggleFullscreen();
    private void ToggleFullscreen()
    {
        if (!fullscreen)
        {
            previousWindowState = WindowState;
            WindowState = WindowState.Normal;
            System.Windows.Shell.WindowChrome.SetWindowChrome(this, null);
            ResizeMode = ResizeMode.NoResize;
            WindowState = WindowState.Maximized;
        }
        else
        {
            ResizeMode = ResizeMode.CanResize;
            System.Windows.Shell.WindowChrome.SetWindowChrome(this, new System.Windows.Shell.WindowChrome { CaptionHeight = 62, ResizeBorderThickness = new Thickness(6), GlassFrameThickness = new Thickness(0), CornerRadius = new CornerRadius(0), UseAeroCaptionButtons = false });
            WindowState = previousWindowState;
        }
        fullscreen = !fullscreen;
    }
    private void Minimize_Click(object sender, RoutedEventArgs e) => WindowState = WindowState.Minimized;
    private void Maximize_Click(object sender, RoutedEventArgs e)
    {
        if (fullscreen) ToggleFullscreen();
        else WindowState = WindowState == WindowState.Maximized ? WindowState.Normal : WindowState.Maximized;
    }
    private void Close_Click(object sender, RoutedEventArgs e) => Close();
    private void OnKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.F11) { ToggleFullscreen(); e.Handled = true; }
        else if (e.Key == Key.Escape && fullscreen) { ToggleFullscreen(); e.Handled = true; }
        else if (e.Key == Key.R && Keyboard.Modifiers == ModifierKeys.Control)
        {
            // Browser accelerator callbacks are synchronous; invoke browser APIs afterwards.
            Dispatcher.BeginInvoke(new Action(ReloadGame));
            e.Handled = true;
        }
        else if (e.Key == Key.L && Keyboard.Modifiers == ModifierKeys.Control) { ServerBox.Focus(); e.Handled = true; }
        else if (e.Key == Key.Enter && ServerBox.IsKeyboardFocusWithin) { _ = ConnectAsync(); e.Handled = true; }
    }
    private void SetStatus(string message, bool warning = false)
    {
        StatusText.Text = message;
        StatusText.Foreground = new System.Windows.Media.SolidColorBrush((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(warning ? "#E4BA71" : "#A7BCA9"));
    }
    private static void Log(Exception error)
    {
        try
        {
            Directory.CreateDirectory(DataRoot);
            var file = Path.Combine(DataRoot, "client.log");
            if (File.Exists(file) && new FileInfo(file).Length > 2_000_000) File.Move(file, file + ".previous", true);
            File.AppendAllText(file, $"[{DateTimeOffset.Now:O}] {error}\n");
        }
        catch (IOException) { }
        catch (UnauthorizedAccessException) { }
    }
}

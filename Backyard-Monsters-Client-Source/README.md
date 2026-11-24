# Backyard Monsters Reborn — client source

This is the separate, editable source package for the Windows x64 game client. It includes the C# code, custom window and toolbar, game icon, tests, build tools, and all **1,136 game assets** from Backyard Monsters Reborn **0.13.0**. The existing precompiled client is unchanged.

## Build the full client folder

1. Extract this entire source ZIP to a writable folder on a Windows x64 PC.
2. Double-click **Build-Client.cmd**.
3. When the build finishes, open **out\Backyard-Monsters-Client\BackyardMonstersClient.exe**.

The builder uses an installed **.NET 10 SDK**, or downloads Microsoft's SDK into the source folder if it is missing. Internet access is required for the SDK and NuGet packages. Visual Studio is optional. The build runs the automated checks and creates a self-contained client folder containing the EXE, supporting DLLs, the `Assets` folder, and its manifest. Players do not need the SDK or a separate .NET runtime.

**Distribute the whole generated client folder.** Players launch its EXE directly; they do not run build scripts. The default build produces the full folder, with assets stored beside the executable.

## Edit the client

| Location | Purpose |
| --- | --- |
| `Client.Windows/MainWindow.xaml` | Custom window, toolbar, server selector, and launcher design |
| `Client.Windows/MainWindow.xaml.cs` | Connection controls and WebView2 integration |
| `Client.Windows/Art` | Client artwork and application icon |
| `Client.Core` | Saved addresses, local asset routing, integrity checks, and byte ranges |
| `Client.Core.Tests` | Automated core checks |
| `EmbeddedPack.Tests` | Checks for the optional standalone asset format |
| `Assets` and `assets-manifest.json` | Included game asset pack used by the build |
| `scripts` | Build and asset packaging tools |
| `THIRD-PARTY` | Microsoft licence and notice files |

Edit the C# or XAML files, then run `Build-Client.cmd` again. Paths are portable; there is no fixed drive-letter requirement. Build logs are written to `logs`. The builder stages its output before replacing an existing successful build.

## Use assets from another game release

The included assets are ready to use. To build with assets from an updated copy of the game, open Command Prompt in this source folder and run:

```bat
Build-Client.cmd -GameSource "C:\xampp\htdocs\Backyard-Monsters-Reborn" -GameVersion "0.13.0"
```

Use that game's actual version. `GameSource` must contain `public\assets`. The builder copies static assets and regenerates the SHA-256 manifest; it does not change the game server. Update the client asset pack when changing the server's game scripts, styles, sounds, or artwork.

The PHP/MySQL game server is separate from this client source. The client connects to a running server; it does not create or run the server database.

## Optional standalone build

Only if you specifically want one executable with an embedded asset pack:

```bat
Build-Client.cmd -Standalone
```

The normal build is the full client folder. The optional standalone format extracts its embedded assets automatically. Its footer is currently located at the end of the EXE; Authenticode signing requires updating the reader to account for the certificate table before signing that format.

## Running the built client

Open `BackyardMonstersClient.exe`, type the server address in the toolbar, and click **Connect**. The initial address is `127.0.0.1`. **Connect** remembers the address; **Save** stores an address without connecting. The address remains editable while the client is open.

For the supplied local PHP launcher, use `http://127.0.0.1:8080/`. For an XAMPP game folder, use its full path, such as `http://127.0.0.1/Backyard-Monsters-Reborn/`. Other players need the server's reachable address.

The game uses Microsoft Edge WebView2. If its runtime is missing, the client obtains Microsoft's runtime automatically when connecting; that download requires internet access. Assets load locally, while login, multiplayer, and saves use the selected server. Saved addresses and browser data are stored under `%LOCALAPPDATA%\BackyardMonstersClient`.

## Validation

The Windows x64 client has been cross-compiled successfully. Automated core and embedded-pack checks pass, all included assets have been verified, and the PowerShell scripts have passed syntax checks. Actual Windows build bootstrap, window rendering, audio playback, and live multiplayer login still require a Windows end-to-end check.

Game artwork comes from the supplied game source. Microsoft .NET and WebView2 licence and notice files are included under `THIRD-PARTY` and copied into the generated client package.

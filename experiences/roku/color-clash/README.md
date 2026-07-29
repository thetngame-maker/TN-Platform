# Color Clash Roku — v0.0.1

This is the smallest working Roku SceneGraph test for Color Clash.

## What this build proves

- Roku recognizes the package manifest.
- The SceneGraph app launches.
- The Roku remote reaches the app.
- Left/right changes the bot count from 1–3.
- Up/down changes Easy/Normal difficulty.
- OK confirms that remote input works.

No game engine, backend, phone pairing, or networking is included yet.

## Build the sideload ZIP on macOS or Linux

From this directory:

```bash
chmod +x package.sh
./package.sh
```

The script creates:

```text
dist/color-clash-roku-v0.0.1.zip
```

The ZIP root must contain `manifest`, `source/`, and `components/`. Do not zip the `color-clash` folder itself.

## Manual packaging alternative

```bash
zip -r color-clash-roku-v0.0.1.zip manifest source components \
  -x "*/.DS_Store" "*/__MACOSX/*" "*/._*"
```

## Sideload

1. Put the Roku and computer on the same network.
2. Open the Roku Development Application Installer at `http://ROKU-IP`.
3. Upload `dist/color-clash-roku-v0.0.1.zip`.
4. Select **Install with zip**.
5. The app should launch automatically.

## Expected screen

The screen displays **COLOR CLASH**, bot and difficulty settings, and a green **PRESS OK TO TEST** button. Pressing OK changes the status to confirm remote input.

## Debug console

```bash
telnet ROKU-IP 8085
```

Copy any BrightScript or XML error shown there when reporting a failed launch.

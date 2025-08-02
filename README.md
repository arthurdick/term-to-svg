# term-to-svg: Animated Terminal Session Recorder

![term-to-svg Demo](demo.svg)

`term-to-svg` is a command-line PHP tool that converts terminal session recordings (made with the `script` command) into highly customizable, animated SVG files. This allows you to easily embed interactive and visually appealing terminal demonstrations directly into your project `README`s, websites, or documentation.

Unlike GIF animations, SVG files are vector-based, resulting in sharper visuals at any zoom level, smaller file sizes, and the ability to be manipulated with CSS and JavaScript.

## Features

  * **High Fidelity Playback**: Accurately interprets ANSI escape codes for cursor movement, color changes, inverse video, character/line manipulation, scroll regions, and screen clearing to reproduce your terminal session as precisely as possible.
  * **Animated SVG Output**: Generates a single SVG file that animates the terminal session, making it ideal for web embedding. The animations are powered by SMIL (Synchronized Multimedia Integration Language).
  * **Configurable**: Supports configuration for terminal dimensions, font size, font family, and default colors.
  * **Automatic Geometry Detection**: Can automatically detect terminal dimensions from the `script` log file if available.
  * **Lightweight**: A single PHP script with no external library dependencies beyond a standard PHP installation.

## Why animated SVG?

  * **Crisp Quality**: Vector graphics scale perfectly to any size without pixelation.
  * **Smaller File Sizes**: Often significantly smaller than equivalent GIF recordings, especially for longer sessions.
  * **Web-Friendly**: Easily embeddable in HTML, Markdown (like GitHub READMEs), and other web contexts.
  * **Pausable and Loopable**: The generated SVG freezes on the last frame for 5 seconds by default and then loops seamlessly.

## Usage

### 1\. Record your terminal session

Use the standard `script` command available on most Unix-like systems to record your terminal session. It's crucial to use the `--timing` option to capture the timing information alongside the typescript.

```bash
script --timing=rec.time rec.log
```

  * `rec.log`: This file will contain the raw terminal output (typescript).
  * `rec.time`: This file will contain the timing information (delays between output chunks).

After running the command, you'll be dropped into a subshell. Perform the commands you want to record. When you're finished, type `exit` to end the recording session.

Example:

```bash
script --timing=my_session.time my_session.log
# Now you are in a recorded shell. Type your commands:
ls -la
echo "Hello, term-to-svg!"
git status
# ...
exit
```

### 2\. Convert to SVG

Once you have your `rec.log` and `rec.time` files, run the `term-to-svg.php` script:

```bash
php term-to-svg.php <typescript_file> <timing_file> <output_svg_file>
```

Replace `<typescript_file>`, `<timing_file>`, and `<output_svg_file>` with your actual file names.

Example:

```bash
php term-to-svg.php my_session.log my_session.time output.svg
```

Upon successful conversion, you'll see a message like: `✅ Successfully generated animated SVG: output.svg`

## Configuration

The script includes a `CONFIG` array at the top of the `term-to-svg.php` file that you can modify to customize the output SVG:

```php
const CONFIG = [
    'rows' => 24,           // Default number of terminal rows
    'cols' => 80,           // Default number of terminal columns
    'font_size' => 14,      // Font size in pixels
    'line_height_factor' => 1.2, // Line height as a factor of font_size
    'font_family' => 'Menlo, Monaco, "Courier New", monospace', // CSS font-family stack
    'default_fg' => '#e0e0e0', // Default text color (light gray)
    'default_bg' => '#1a1a1a', // Terminal background color (dark gray)
];
```

**Note on Geometry Detection**: If your `rec.log` file was generated with a `script` version that includes `COLUMNS` and `LINES` information in its first line (e.g., `COLUMNS="80" LINES="24"`), the script will automatically use these dimensions, overriding the `rows` and `cols` in the `CONFIG` array. A warning will be issued if auto-detection fails.

## Requirements

  * PHP 7.4 or higher
  * The `mbstring` PHP extension
  * A Unix-like operating system with the `script` command available.

## Contributing

Contributions are welcome\! If you find a bug or have a feature request, please open an issue or submit a pull request.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

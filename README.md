# term-to-svg: Animated Terminal Session Recorder

![term-to-svg Demo](demo.svg)

`term-to-svg` is a command-line PHP tool that converts terminal session recordings (made with the `script` command) into highly customizable, animated SVG files. This allows you to easily embed interactive and visually appealing terminal demonstrations directly into your project `README`s, websites, or documentation.

Unlike GIF animations, SVG files are vector-based, resulting in sharper visuals at any zoom level, smaller file sizes, and the ability to be manipulated with CSS and JavaScript.

-----

## Features

  * **High Fidelity Playback**: Accurately interprets ANSI escape codes for cursor movement, color changes, inverse video, character/line manipulation, scroll regions, and screen clearing to reproduce your terminal session as precisely as possible.
  * **Animated SVG Output**: Generates a single SVG file that animates the terminal session, making it ideal for web embedding. The animations are powered by SMIL (Synchronized Multimedia Integration Language).
  * **Configurable**: Supports configuration for terminal dimensions, font size, font family, and default colors.
  * **Automatic Geometry Detection**: Can automatically detect terminal dimensions from the `script` log file if available.
  * **Lightweight**: A single-class PHP script with no external runtime dependencies.

-----

## Installation

Install the tool globally using Composer:

```bash
composer global require arthurdick/term-to-svg
```

Make sure your Composer `bin` directory (`~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`) is in your system's `PATH`.

-----

## Usage

### 1\. Record your terminal session

Use the standard `script` command available on most Unix-like systems to record your terminal session. It's crucial to use the `--timing` option to capture the timing information alongside the typescript.

```bash
script --timing=rec.time rec.log
```

  * `rec.log`: This file will contain the raw terminal output (typescript).
  * `rec.time`: This file will contain the timing information (delays between output chunks).

After running the command, you'll be dropped into a subshell. Perform the commands you want to record. When you're finished, type `exit` to end the recording session.

### 2\. Convert to SVG

Once you have your `rec.log` and `rec.time` files, run the `term-to-svg` command:

```bash
term-to-svg <typescript_file> <timing_file> <output_svg_file>
```

Example:

```bash
term-to-svg my_session.log my_session.time output.svg
```

Upon successful conversion, you'll see a message like: `✅ Successfully generated animated SVG: output.svg`

-----

## Configuration

To customize the output, you can create your own executable script. The default configuration is a public constant within the `TerminalToSvgConverter` class.

Example custom script:

```php
#!/usr/bin/env php
<?php

use ArthurDick\TermToSvg\TerminalToSvgConverter;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Get default config
$config = TerminalToSvgConverter::CONFIG;

// 2. Modify it
$config['font_family'] = 'Fira Code, monospace';
$config['font_size'] = 16;
$config['default_bg'] = '#282a36'; // Dracula theme

// 3. Run the converter with your custom config
$converter = new TerminalToSvgConverter($argv[1], $argv[2], $config);
$svgContent = $converter->convert();
file_put_contents($argv[3], $svgContent);
```

**Note on Geometry Detection**: If your `rec.log` file was generated with a `script` version that includes `COLUMNS` and `LINES` information in its first line (e.g., `COLUMNS="80" LINES="24"`), the script will automatically use these dimensions, overriding the `rows` and `cols` in your configuration.

-----

## Requirements

  * PHP 7.4 or higher.
  * The `mbstring` PHP extension.
  * A Unix-like operating system with the `script` command available.

-----

## Contributing

Contributions are welcome\! If you find a bug or have a feature request, please open an issue or submit a pull request.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

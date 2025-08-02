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
  * **Lightweight**: A lightweight PHP application with no external runtime dependencies.

-----

## Installation & Usage

There are three ways to use `term-to-svg`, depending on your needs.

### Method 1: Standalone PHAR (Recommended)

This method provides a single, executable file, but **requires PHP to be installed** on your system.

**Best for:** Most users, including non-developers, who want a simple way to run the tool.

1.  **Download:** Grab the latest `term-to-svg.phar` file from the project's **Releases** page on GitHub.
2.  **Make Executable:** Open your terminal and make the downloaded file executable.
    ```bash
    chmod +x term-to-svg.phar
    ```
3.  **Run:** You can now run the tool directly.
    ```bash
    ./term-to-svg.phar my_session.log my_session.time output.svg
    ```

#### Making it Globally Available (Optional)

To run `term-to-svg` from any directory without typing `./term-to-svg.phar`, you can move it to a directory in your system's `PATH`.

1.  **Move & Rename:** Move the phar to a common location for binaries and rename it for convenience. `/usr/local/bin` is a good choice.
    ```bash
    sudo mv term-to-svg.phar /usr/local/bin/term-to-svg
    ```
2.  **Verify:** Close and reopen your terminal, then check if the command is available:
    ```bash
    term-to-svg --version
    ```

If it's not found, ensure `/usr/local/bin` is in your `PATH`. You can check with `echo $PATH`. If it's missing, add it to your shell's startup file (e.g., `~/.bashrc`, `~/.zshrc`):

````
```bash
export PATH="/usr/local/bin:$PATH"
```
````

### Method 2: Global Install with Composer

**Best for:** PHP developers who want the `term-to-svg` command to be available system-wide.

1.  **Install:** Use Composer to install the tool globally.
    ```bash
    composer global require arthurdick/term-to-svg
    ```
2.  **Update PATH:** Make sure your Composer `bin` directory is in your system's `PATH`.
3.  **Run:**
    ```bash
    term-to-svg my_session.log my_session.time output.svg
    ```

### Method 3: From Source

**Best for:** Developers who want to contribute to the project or modify the source code.

1.  **Clone:** Clone the repository to your local machine.
    ```bash
    git clone https://github.com/arthurdick/term-to-svg.git
    cd term-to-svg
    ```
2.  **Install Dependencies:**
    ```bash
    composer install
    ```
3.  **Run:** Execute the script using PHP.
    ```bash
    php bin/term-to-svg my_session.log my_session.time output.svg
    ```

-----

## The Recording Process

No matter how you installed the tool, the recording process is the same.

1.  **Record:** Use the standard `script` command with the `--timing` option.
    ```bash
    script --timing=rec.time rec.log
    ```
2.  **Perform Actions:** A subshell will start. Perform the commands you want to record.
3.  **Exit:** When you're finished, type `exit` to end the recording session.

You will now have two files: `rec.log` (the terminal output) and `rec.time` (the timing information), ready for conversion.

-----

## Configuration

To customize the output, you can create your own executable script. The default configuration is available as a public constant in the `ArthurDick\TermToSvg\Config` class.

Example custom script:

```php
#!/usr/bin/env php
<?php

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalToSvgConverter;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Get default config
$config = Config::DEFAULTS;

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

# term-to-svg: Animated Terminal Session Recorder

![term-to-svg Demo](demo.svg)

`term-to-svg` is a command-line PHP tool that converts terminal session recordings (made with the `script` command) into highly customizable, animated SVG files. This allows you to easily embed interactive and visually appealing terminal demonstrations directly into your project `README`s, websites, or documentation.

Unlike GIF animations, SVG files are vector-based, resulting in sharper visuals at any zoom level, smaller file sizes, and the ability to be manipulated with CSS and JavaScript.

-----

## Features

  * **High Fidelity Playback**: Accurately interprets ANSI escape codes for cursor movement, color changes, inverse video, character/line manipulation, scroll regions, and screen clearing to reproduce your terminal session as precisely as possible.
  * **Multiple Animation Engines**: Choose between two animation engines:
    * **CSS Animations (Default)**: A modern, efficient engine that is widely supported and ideal for web embedding.
    * **SMIL**: A powerful, self-contained animation engine that is also well-supported.
  * **Configurable**: Supports configuration for terminal dimensions, font size, font family, and default colors via command-line flags.
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
    ./term-to-svg.phar -t my_session.log -i my_session.time -o output.svg
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

```bash
export PATH="/usr/local/bin:$PATH"
```

### Method 2: Global Install with Composer

**Best for:** PHP developers who want the `term-to-svg` command to be available system-wide.

1.  **Install:** Use Composer to install the tool globally.
    ```bash
    composer global require arthurdick/term-to-svg
    ```
2.  **Update PATH:** Make sure your Composer `bin` directory is in your system's `PATH`.
3.  **Run:**
    ```bash
    term-to-svg -t my_session.log -i my_session.time -o output.svg
    ```

### Method 3: From Source

**Best for:** Developers who want to contribute to the project or modify the source code.

1.  **Clone:** Clone the repository to your local machine.
    ```bash
    git clone [https://github.com/arthurdick/term-to-svg.git](https://github.com/arthurdick/term-to-svg.git)
    cd term-to-svg
    ```
2.  **Install Dependencies:**
    ```bash
    composer install
    ```
3.  **Run:** Execute the script using PHP.
    ```bash
    php bin/term-to-svg -t my_session.log -i my_session.time -o output.svg
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

You can customize the output by using command-line flags. Run `term-to-svg --help` to see all available options.

```
Usage: term-to-svg [options]

Options:
  -t, --typescript_file <file>  Path to the typescript file (required).
  -i, --timing_file <file>      Path to the timing file (required).
  -o, --output_file <file>      Path to the output SVG file (required).
  --generator <css|smil>    Animation generator to use (css or smil). Default: css.
  --id <string>             ID to use for the root SVG element.
  --rows <number>           Number of terminal rows.
  --cols <number>           Number of terminal columns.
  --font_size <number>      Font size.
  --line_height_factor <float> Line height factor.
  --font_width_factor <float> Font width factor.
  --font_family <string>    Font family.
  --default_fg <hex>        Default foreground color.
  --default_bg <hex>        Default background color.
  --animation_pause_seconds <number> Animation pause in seconds at the end.
  --poster-at <time|end>    Generate a non-animated SVG of a single frame at a specific time or at the end.
  --interactive             Enable interactive player controls.
  -v, --version             Display the version number.
  -h, --help                Display this help message.
```

**Note on Geometry Detection**: If your `rec.log` file was generated with a `script` version that includes `COLUMNS` and `LINES` information in its first line (e.g., `COLUMNS="80" LINES="24"`), the script will automatically use these dimensions, overriding the `--rows` and `--cols` flags.

-----

## Requirements

  * PHP 7.4 or higher.
  * The `mbstring` PHP extension.
  * A Unix-like operating system with the `script` command available.

-----

## Contributing

Contributions are welcome! If you find a bug or have a feature request, please open an issue or submit a pull request.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

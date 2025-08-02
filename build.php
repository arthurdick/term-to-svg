<?php

// build.php

$pharFile = 'term-to-svg.phar';

// Clean up any old phar
if (file_exists($pharFile)) {
    unlink($pharFile);
}

$p = new Phar($pharFile);

// Start buffering. This is essential for setting the stub later.
$p->startBuffering();

// Add all files from the 'src' and 'vendor' directories.
// The second parameter of buildFromIterator strips the base path,
// ensuring files are added to the PHAR root.
$p->buildFromIterator(
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/src', FilesystemIterator::SKIP_DOTS)
    ),
    __DIR__
);
$p->buildFromIterator(
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/vendor', FilesystemIterator::SKIP_DOTS)
    ),
    __DIR__
);


// Add the main executable script and the license file.
$p->addFile('bin/term-to-svg', 'bin/term-to-svg');
$p->addFile('LICENSE', 'LICENSE');

// Create the default stub to run the bin script.
// It's important to use a shebang for command-line execution.
$stub = "#!/usr/bin/env php \n" . $p->createDefaultStub('bin/term-to-svg');
$p->setStub($stub);

// Stop buffering and write changes to disk.
$p->stopBuffering();

// Make the file executable
chmod($pharFile, 0755);

echo "✅ Successfully built {$pharFile}\n";

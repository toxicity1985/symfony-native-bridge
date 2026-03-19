# Example 03 — File Converter

> Convert images between formats with native open/save dialogs, a progress UI, and a secondary "About" window.

## What it demonstrates

- **Multi-file open dialog** with extension filters
- **Folder picker** dialog
- **Reveal in Finder / Explorer** after conversion
- **Secondary window** (About panel) opened from PHP — multi-window management
- **StorageManager** to persist last output folder and conversion history across sessions
- Native **notification** with conversion summary

## Run it

```bash
cd examples/03-file-converter
composer install          # requires ext-gd
php bin/console native:install
php bin/console native:serve
```

## Key code

```php
// Multi-file picker with filters
$paths = $this->dialog->openFile(
    title:    'Select images',
    filters:  [['name' => 'Images', 'extensions' => ['jpg','png','webp']]],
    multiple: true,
);

// Open a secondary window (e.g. About panel)
$this->window->open(
    url: 'http://127.0.0.1:8765/about-window',
    options: new WindowOptions(width: 400, height: 300, resizable: false),
);

// Persist data between sessions via native key-value store
$this->storage->set('last_output_dir', $outputDir);
$dir = $this->storage->get('last_output_dir', '');

// Reveal the output in Finder / Explorer
$this->app->showItemInFolder($outputPath);
```
